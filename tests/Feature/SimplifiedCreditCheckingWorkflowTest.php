<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCreditCheck;
use App\Models\ShmCustomer;
use App\Models\User;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\MakroSystemRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimplifiedCreditCheckingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;

    private User $marketingOther;

    private User $branchManager;

    private User $direkturUtama;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(MakroPhysicalRoleSeeder::class);
        $this->seed(MakroSystemRoleSeeder::class);

        $marketingRole = Role::where('name', 'marketing_makro')->first() ?? Role::where('name', 'marketing')->firstOrFail();
        $bmRole = Role::where('name', 'branch_manager_makro')->first() ?? Role::where('name', 'branch_manager')->firstOrFail();
        $dirutRole = Role::where('name', 'direktur_utama_makro')->first() ?? Role::where('name', 'direktur_utama')->firstOrFail();
        $superadminRole = Role::where('name', 'superadmin')->firstOrFail();

        $this->marketing = User::factory()->create();
        $this->marketing->roles()->attach($marketingRole);

        $this->marketingOther = User::factory()->create();
        $this->marketingOther->roles()->attach($marketingRole);

        $this->branchManager = User::factory()->create();
        $this->branchManager->roles()->attach($bmRole);

        $this->direkturUtama = User::factory()->create();
        $this->direkturUtama->roles()->attach($dirutRole);

        $this->superadmin = User::factory()->create();
        $this->superadmin->roles()->attach($superadminRole);
    }

    private function createCreditCheck(string $status = 'drafted', ?User $owner = null): ShmCreditCheck
    {
        $owner = $owner ?? $this->marketing;
        $customer = ShmCustomer::factory()->create();

        $app = ShmApplication::create([
            'application_number' => 'APP-CC09-'.uniqid(),
            'shm_customer_id' => $customer->id,
            'marketing_id' => $owner->id,
            'type' => 'shgb',
            'status' => 'credit_checking',
        ]);

        return ShmCreditCheck::create([
            'shm_application_id' => $app->id,
            'status' => $status,
            'filled_by' => $owner->id,
            'started_at' => now(),
        ]);
    }

    public function test_branch_manager_can_transition_drafted_to_approve(): void
    {
        $check = $this->createCreditCheck('drafted');

        $response = $this->actingAs($this->branchManager)
            ->postJson(route('shm.credit-checks.transition', $check->id), [
                'status' => 'approve',
                'notes' => 'Disetujui oleh Branch Manager.',
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'status' => 'approve',
                'status_label' => 'Approve',
            ]);

        $this->assertDatabaseHas('shm_credit_checks', [
            'id' => $check->id,
            'status' => 'approve',
            'filled_by' => $this->branchManager->id,
            'notes' => 'Disetujui oleh Branch Manager.',
        ]);
    }

    public function test_branch_manager_can_transition_drafted_to_rejected(): void
    {
        $check = $this->createCreditCheck('drafted');

        $response = $this->actingAs($this->branchManager)
            ->postJson(route('shm.credit-checks.transition', $check->id), [
                'status' => 'rejected',
                'notes' => 'Mohon lengkapi slip gaji terbaru.',
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'status' => 'rejected',
                'status_label' => 'Rejected',
            ]);

        $this->assertDatabaseHas('shm_credit_checks', [
            'id' => $check->id,
            'status' => 'rejected',
            'filled_by' => $this->branchManager->id,
            'notes' => 'Mohon lengkapi slip gaji terbaru.',
        ]);
    }

    public function test_marketing_can_transition_rejected_to_drafted_for_owned_application(): void
    {
        $check = $this->createCreditCheck('rejected', $this->marketing);

        $response = $this->actingAs($this->marketing)
            ->postJson(route('shm.credit-checks.transition', $check->id), [
                'status' => 'drafted',
                'notes' => 'Slip gaji telah diperbarui.',
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'status' => 'drafted',
                'status_label' => 'Drafted',
            ]);

        $this->assertDatabaseHas('shm_credit_checks', [
            'id' => $check->id,
            'status' => 'drafted',
            'filled_by' => $this->marketing->id,
        ]);
    }

    public function test_marketing_cannot_transition_rejected_to_drafted_for_other_marketing_application(): void
    {
        $check = $this->createCreditCheck('rejected', $this->marketing);

        $response = $this->actingAs($this->marketingOther)
            ->postJson(route('shm.credit-checks.transition', $check->id), [
                'status' => 'drafted',
            ]);

        $response->assertForbidden();
    }

    public function test_marketing_cannot_self_approve(): void
    {
        $check = $this->createCreditCheck('drafted', $this->marketing);

        $response = $this->actingAs($this->marketing)
            ->postJson(route('shm.credit-checks.transition', $check->id), [
                'status' => 'approve',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('shm_credit_checks', [
            'id' => $check->id,
            'status' => 'drafted',
        ]);
    }

    public function test_branch_manager_can_transition_approve_to_completed(): void
    {
        $check = $this->createCreditCheck('approve');

        $response = $this->actingAs($this->branchManager)
            ->postJson(route('shm.credit-checks.transition', $check->id), [
                'status' => 'completed',
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'status' => 'completed',
                'status_label' => 'Completed',
            ]);

        $this->assertDatabaseHas('shm_credit_checks', [
            'id' => $check->id,
            'status' => 'completed',
            'filled_by' => $this->branchManager->id,
        ]);

        $fresh = $check->fresh();
        $this->assertNotNull($fresh->checked_at);
    }

    public function test_completed_status_is_terminal_and_cannot_transition(): void
    {
        $check = $this->createCreditCheck('completed');

        $response = $this->actingAs($this->branchManager)
            ->postJson(route('shm.credit-checks.transition', $check->id), [
                'status' => 'approve',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $responseDraft = $this->actingAs($this->branchManager)
            ->postJson(route('shm.credit-checks.transition', $check->id), [
                'status' => 'drafted',
            ]);

        $responseDraft->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_process_table_index_includes_available_transitions_based_on_role_and_status(): void
    {
        $draftCheck = $this->createCreditCheck('drafted', $this->marketing);
        $approveCheck = $this->createCreditCheck('approve', $this->marketing);
        $rejectedCheck = $this->createCreditCheck('rejected', $this->marketing);
        $completedCheck = $this->createCreditCheck('completed', $this->marketing);

        // Sebagai Branch Manager
        $bmResponse = $this->actingAs($this->branchManager)
            ->get(route('shm.credit-checks.index'));

        $bmResponse->assertOk();
        $items = collect($bmResponse->original->getData()['page']['props']['credit_checks']['data']);

        $draftRow = $items->firstWhere('credit_check_id', $draftCheck->id);
        $this->assertNotNull($draftRow);
        $draftValues = collect($draftRow['available_transitions'])->pluck('value')->all();
        $this->assertEqualsCanonicalizing(['approve', 'rejected'], $draftValues);

        $approveRow = $items->firstWhere('credit_check_id', $approveCheck->id);
        $this->assertNotNull($approveRow);
        $approveValues = collect($approveRow['available_transitions'])->pluck('value')->all();
        $this->assertEqualsCanonicalizing(['completed'], $approveValues);

        $completedRow = $items->firstWhere('credit_check_id', $completedCheck->id);
        $this->assertNotNull($completedRow);
        $this->assertEmpty($completedRow['available_transitions']);

        // Sebagai Marketing
        $mResponse = $this->actingAs($this->marketing)
            ->get(route('shm.credit-checks.index'));

        $mResponse->assertOk();
        $mItems = collect($mResponse->original->getData()['page']['props']['credit_checks']['data']);

        $mDraftRow = $mItems->firstWhere('credit_check_id', $draftCheck->id);
        $this->assertNotNull($mDraftRow);
        $this->assertEmpty($mDraftRow['available_transitions']); // Marketing cannot approve/reject

        $mRejectedRow = $mItems->firstWhere('credit_check_id', $rejectedCheck->id);
        $this->assertNotNull($mRejectedRow);
        $mRejectedValues = collect($mRejectedRow['available_transitions'])->pluck('value')->all();
        $this->assertEqualsCanonicalizing(['drafted'], $mRejectedValues); // Marketing can return to drafted
    }
}
