<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCreditCheck;
use App\Models\ShmCreditCheckException;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\User;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\MakroSystemRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CreditCheckingRemediationAndPermissionAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;

    private User $marketingNonOwner;

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

        $this->marketing = User::factory()->create(['name' => 'Marketing Owner']);
        $this->marketing->roles()->attach($marketingRole);

        $this->marketingNonOwner = User::factory()->create(['name' => 'Marketing Non-Owner']);
        $this->marketingNonOwner->roles()->attach($marketingRole);

        $this->branchManager = User::factory()->create(['name' => 'Branch Manager']);
        $this->branchManager->roles()->attach($bmRole);

        $this->direkturUtama = User::factory()->create(['name' => 'President Director']);
        $this->direkturUtama->roles()->attach($dirutRole);

        $this->superadmin = User::factory()->create(['name' => 'Superadmin Admin']);
        $this->superadmin->roles()->attach($superadminRole);
    }

    private function makeShmApplication(string $type = 'shm_shgb', ?User $owner = null): ShmApplication
    {
        $owner = $owner ?? $this->marketing;
        $customer = ShmCustomer::factory()->create();

        return ShmApplication::create([
            'application_number' => 'APP-CC11-'.uniqid(),
            'shm_customer_id' => $customer->id,
            'marketing_id' => $owner->id,
            'type' => $type,
            'status' => 'credit_checking',
        ]);
    }

    // =========================================================================
    // D-01: Completion Bypass UI & Endpoint Authorization
    // =========================================================================

    public function test_direktur_utama_can_authorize_completion_bypass_via_memorandum_endpoint(): void
    {
        $app = $this->makeShmApplication('shm_shgb');
        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'created_by' => $this->marketing->id,
        ]);

        $response = $this->actingAs($this->direkturUtama)
            ->post(route('shm.memorandums.completion-bypass', $memo->id), [
                'internal_justification' => 'Dispensasi prioritas bisnis mendesak Direktur Utama.',
                'official_disclosure_summary' => 'Dispensasi resmi Completion Bypass pimpinan.',
            ]);

        $response->assertSessionHas('success', 'Dispensasi Completion Bypass berhasil diterbitkan.');

        $this->assertDatabaseHas('shm_credit_check_exceptions', [
            'shm_application_id' => $app->id,
            'exception_type' => ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            'status' => ShmCreditCheckException::STATUS_AUTHORIZED,
            'authorized_by' => $this->direkturUtama->id,
            'authorized_by_name' => $this->direkturUtama->name,
            'official_disclosure_summary' => 'Dispensasi resmi Completion Bypass pimpinan.',
        ]);
    }

    public function test_superadmin_can_authorize_completion_bypass_via_memorandum_endpoint(): void
    {
        $app = $this->makeShmApplication('shm_shgb');
        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'created_by' => $this->marketing->id,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->post(route('shm.memorandums.completion-bypass', $memo->id), [
                'internal_justification' => 'Akses teknis troubleshooting administrasi sistem.',
                'official_disclosure_summary' => 'Dispensasi teknis bypass completion oleh Superadmin.',
            ]);

        $response->assertSessionHas('success', 'Dispensasi Completion Bypass berhasil diterbitkan.');

        $this->assertDatabaseHas('shm_credit_check_exceptions', [
            'shm_application_id' => $app->id,
            'exception_type' => ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            'status' => ShmCreditCheckException::STATUS_AUTHORIZED,
            'authorized_by' => $this->superadmin->id,
            'authorized_by_name' => $this->superadmin->name,
        ]);
    }

    public function test_branch_manager_and_marketing_cannot_authorize_completion_bypass_endpoint(): void
    {
        $app = $this->makeShmApplication('shm_shgb');
        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'created_by' => $this->marketing->id,
        ]);

        // Branch Manager ditolak (HTTP 403)
        $resBm = $this->actingAs($this->branchManager)
            ->post(route('shm.memorandums.completion-bypass', $memo->id), [
                'internal_justification' => 'Percobaan bypass tidak sah oleh Branch Manager.',
                'official_disclosure_summary' => 'Summary BM',
            ]);
        $resBm->assertForbidden();

        // Marketing ditolak (HTTP 403)
        $resM = $this->actingAs($this->marketing)
            ->post(route('shm.memorandums.completion-bypass', $memo->id), [
                'internal_justification' => 'Percobaan bypass tidak sah oleh Marketing AO.',
                'official_disclosure_summary' => 'Summary Marketing',
            ]);
        $resM->assertForbidden();
    }

    public function test_completion_bypass_validates_justification_and_disclosure_rules(): void
    {
        $app = $this->makeShmApplication('shm_shgb');
        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'created_by' => $this->marketing->id,
        ]);

        // Kurang dari 20 karakter
        $res = $this->actingAs($this->direkturUtama)
            ->post(route('shm.memorandums.completion-bypass', $memo->id), [
                'internal_justification' => 'Pendek',
                'official_disclosure_summary' => 'Summary resmi',
            ]);
        $res->assertSessionHasErrors(['internal_justification']);

        // Disclosure summary kosong
        $res2 = $this->actingAs($this->direkturUtama)
            ->post(route('shm.memorandums.completion-bypass', $memo->id), [
                'internal_justification' => 'Justifikasi internal rahasia yang cukup panjang.',
                'official_disclosure_summary' => '',
            ]);
        $res2->assertSessionHasErrors(['official_disclosure_summary']);
    }

    public function test_memorandum_show_page_provides_can_bypass_completion_prop_accurately(): void
    {
        $app = $this->makeShmApplication('shm_shgb');
        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'created_by' => $this->marketing->id,
        ]);

        // Berikan role dengan permission shm.memorandum.update kepada Direktur Utama untuk melihat form memorandum
        $memoUpdatePerm = Permission::where('name', 'shm.memorandum.update')->first();
        if ($memoUpdatePerm) {
            $dirutRole = $this->direkturUtama->roles()->first();
            $dirutRole?->permissions()->syncWithoutDetaching([$memoUpdatePerm->id]);
        }

        // Superadmin memperoleh can_bypass_completion = true
        $resSuper = $this->actingAs($this->superadmin)->get(route('shm.memorandums.shm-shgb.show', $memo->id));
        $resSuper->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Memorandum/ShmShgbShow')
            ->where('can_bypass_completion', true)
        );

        // Direktur Utama memperoleh can_bypass_completion = true
        $resDirut = $this->actingAs($this->direkturUtama)->get(route('shm.memorandums.shm-shgb.show', $memo->id));
        $resDirut->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Memorandum/ShmShgbShow')
            ->where('can_bypass_completion', true)
        );

        // Branch Manager memperoleh can_bypass_completion = false
        $resBm = $this->actingAs($this->branchManager)->get(route('shm.memorandums.shm-shgb.show', $memo->id));
        $resBm->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Memorandum/ShmShgbShow')
            ->where('can_bypass_completion', false)
        );

        // Marketing memperoleh can_bypass_completion = false
        $resM = $this->actingAs($this->marketing)->get(route('shm.memorandums.shm-shgb.show', $memo->id));
        $resM->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Memorandum/ShmShgbShow')
            ->where('can_bypass_completion', false)
        );
    }

    // =========================================================================
    // D-02: Credit Checking Detail Reconciliation
    // =========================================================================

    public function test_credit_checking_detail_page_delivers_reconciled_available_transitions(): void
    {
        $app = $this->makeShmApplication('shm_shgb', $this->marketing);
        $check = ShmCreditCheck::create([
            'shm_application_id' => $app->id,
            'status' => 'drafted',
            'filled_by' => $this->marketing->id,
            'started_at' => now(),
        ]);

        // Branch Manager melihat halaman detail Drafted -> available_transitions = approve, rejected
        $resBm = $this->actingAs($this->branchManager)->get(route('shm.credit-checks.show', $check->id));
        $resBm->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shm/CreditChecks/Show')
            ->has('credit_check.available_transitions', 2)
            ->where('credit_check.available_transitions.0.value', 'approve')
            ->where('credit_check.available_transitions.1.value', 'rejected')
        );

        // Superadmin melihat detail -> available_transitions = []
        $resSuper = $this->actingAs($this->superadmin)->get(route('shm.credit-checks.show', $check->id));
        $resSuper->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shm/CreditChecks/Show')
            ->has('credit_check.available_transitions', 0)
        );
    }

    // =========================================================================
    // D-03: Permission Consistency (shm.view_all vs Ownership)
    // =========================================================================

    public function test_marketing_with_view_all_permission_cannot_transition_others_rejected_check(): void
    {
        // Beri marketingNonOwner permission shm.view_all
        $viewAllPerm = Permission::where('name', 'shm.view_all')->firstOrFail();
        $this->marketingNonOwner->roles()->first()->permissions()->attach($viewAllPerm);

        $appOwned = $this->makeShmApplication('shm_shgb', $this->marketing);
        $check = ShmCreditCheck::create([
            'shm_application_id' => $appOwned->id,
            'status' => 'rejected',
            'filled_by' => $this->marketing->id,
            'started_at' => now(),
        ]);

        // Marketing non-owner BISA melihat index karena shm.view_all
        $resIndex = $this->actingAs($this->marketingNonOwner)->get(route('shm.credit-checks.index'));
        $resIndex->assertOk();
        $rows = collect($resIndex->original->getData()['page']['props']['credit_checks']['data']);
        $row = $rows->firstWhere('credit_check_id', $check->id);
        $this->assertNotNull($row);

        // Namun available_transitions pada baris tersebut HARUS KOSONG (tidak boleh transisi!)
        $this->assertEmpty($row['available_transitions']);

        // Jika mencoba request transisi langsung ke backend, HARUS DITOLAK (ValidationException / 422)
        $resTransition = $this->actingAs($this->marketingNonOwner)
            ->postJson(route('shm.credit-checks.transition', $check->id), [
                'status' => 'drafted',
            ]);

        $resTransition->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->assertSame('rejected', $check->fresh()->status->value);
    }

    public function test_marketing_owner_can_transition_rejected_to_drafted(): void
    {
        $appOwned = $this->makeShmApplication('shm_shgb', $this->marketing);
        $check = ShmCreditCheck::create([
            'shm_application_id' => $appOwned->id,
            'status' => 'rejected',
            'filled_by' => $this->marketing->id,
            'started_at' => now(),
        ]);

        $resTransition = $this->actingAs($this->marketing)
            ->postJson(route('shm.credit-checks.transition', $check->id), [
                'status' => 'drafted',
                'notes' => 'Dokumen nasabah telah dilengkapi kembali.',
            ]);

        $resTransition->assertOk();
        $this->assertSame('drafted', $check->fresh()->status->value);
    }
}
