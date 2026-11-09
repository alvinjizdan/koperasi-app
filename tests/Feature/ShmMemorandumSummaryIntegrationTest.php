<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Feature Test untuk Ticket 5 & Ticket 6 — Memorandum Summary Final Audit & Data Integrity Verification.
 *
 * Menguji:
 * 1. Scope A: Data & Relationship Integrity (1:1, non-duplicate, type integrity, rejection mismatch).
 * 2. Scope B: Query & KPI Invariant (Total Apps = SHGB + Buyback = With Memo + Without Memo).
 * 3. Scope C: Edit & Delete Safety (Non-draft uneditable, delete memo keeps application, status updates).
 * 4. Scope D: Security, Authorization & IDOR (Guest redirect, unauthorized 403, marketing isolation, shm.view_all).
 */
class ShmMemorandumSummaryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketing;

    private User $otherMarketing;

    private ShmCustomer $customerA;

    private ShmCustomer $customerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id);
        }

        $this->marketing = User::factory()->create();
        $marketingRole = Role::where('name', 'marketing')->first();
        if ($marketingRole) {
            $this->marketing->roles()->attach($marketingRole->id);
        }

        $this->otherMarketing = User::factory()->create();
        if ($marketingRole) {
            $this->otherMarketing->roles()->attach($marketingRole->id);
        }

        $this->customerA = ShmCustomer::create([
            'customer_number' => 'CUST-A-001',
            'name' => 'Budi Setiawan',
            'nik' => '3201000000000001',
            'phone' => '081234560001',
            'is_active' => true,
        ]);

        $this->customerB = ShmCustomer::create([
            'customer_number' => 'CUST-B-002',
            'name' => 'Siti Aminah',
            'nik' => '3201000000000002',
            'phone' => '081234560002',
            'is_active' => true,
        ]);
    }

    private function createShmApplication(array $attributes = []): ShmApplication
    {
        return ShmApplication::create(array_merge([
            'application_number' => 'APP-TEST-'.uniqid(),
            'type' => 'shgb',
            'shm_customer_id' => $this->customerA->id,
            'marketing_id' => $this->admin->id,
            'status' => ShmApplicationStatus::Draft,
            'loan_amount' => 50_000_000,
            'loan_period_months' => 12,
            'interest_rate_percent' => 1.5,
            'purpose' => 'Modal Usaha',
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    public function test_guest_cannot_access_memorandum_summary(): void
    {
        $response = $this->get(route('shm.memorandums.summary.index'));
        $response->assertRedirect(route('shm.login'));
    }

    public function test_user_without_permission_cannot_access_memorandum_summary(): void
    {
        $unauthorizedUser = User::factory()->create();
        $response = $this->actingAs($unauthorizedUser)->get(route('shm.memorandums.summary.index'));
        $response->assertStatus(403);
    }

    public function test_summary_displays_all_applications_regardless_of_status_and_memorandum_existence(): void
    {
        // 1. SHGB Draft (tanpa memo)
        $app1 = $this->createShmApplication([
            'application_number' => 'APP-SHGB-DRAFT',
            'type' => 'shgb',
            'status' => ShmApplicationStatus::Draft,
        ]);

        // 2. Buyback Submitted (tanpa memo)
        $app2 = $this->createShmApplication([
            'application_number' => 'APP-BUYBACK-SUBMITTED',
            'type' => 'buyback',
            'status' => ShmApplicationStatus::Submitted,
        ]);

        // 3. SHGB Completed dengan memorandum DRAFT
        $app3 = $this->createShmApplication([
            'application_number' => 'APP-SHGB-COMPLETED',
            'type' => 'shgb',
            'status' => ShmApplicationStatus::Completed,
            'completed_at' => now(),
        ]);
        $memo3 = ShmMemorandum::create([
            'shm_application_id' => $app3->id,
            'type' => 'shm_shgb',
            'status' => ShmMemorandum::STATUS_DRAFT,
            'created_by' => $this->admin->id,
        ]);

        // 4. Buyback Completed dengan memorandum SUBMITTED
        $app4 = $this->createShmApplication([
            'application_number' => 'APP-BUYBACK-COMPLETED',
            'type' => 'buyback',
            'status' => ShmApplicationStatus::Completed,
            'completed_at' => now(),
        ]);
        $memo4 = ShmMemorandum::create([
            'shm_application_id' => $app4->id,
            'type' => 'buyback',
            'status' => ShmMemorandum::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.summary.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Memorandum/SummaryIndex')
            ->has('items.data', 4)
            ->where('stats.total_applications', 4)
            ->where('stats.total_shgb', 2)
            ->where('stats.total_buyback', 2)
            ->where('stats.with_memorandum', 2)
            ->where('stats.without_memorandum', 2)
        );
    }

    public function test_kpi_mathematical_invariants_hold(): void
    {
        // 3 SHGB (2 draft, 1 completed with memo)
        $this->createShmApplication(['type' => 'shgb', 'status' => ShmApplicationStatus::Draft]);
        $this->createShmApplication(['type' => 'shgb', 'status' => ShmApplicationStatus::Submitted]);
        $shgbDone = $this->createShmApplication(['type' => 'shgb', 'status' => ShmApplicationStatus::Completed, 'completed_at' => now()]);
        ShmMemorandum::create([
            'shm_application_id' => $shgbDone->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => ShmMemorandum::STATUS_SUBMITTED,
            'created_by' => $this->admin->id,
        ]);

        // 2 Buyback (1 draft, 1 completed with memo)
        $this->createShmApplication(['type' => 'buyback', 'status' => ShmApplicationStatus::Draft]);
        $bbDone = $this->createShmApplication(['type' => 'buyback', 'status' => ShmApplicationStatus::Completed, 'completed_at' => now()]);
        ShmMemorandum::create([
            'shm_application_id' => $bbDone->id,
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.summary.index'));
        $response->assertStatus(200);

        $stats = $response->viewData('page')['props']['stats'];

        // Invariant 1: total = shgb + buyback
        $this->assertSame($stats['total_applications'], $stats['total_shgb'] + $stats['total_buyback']);
        // Invariant 2: total = with_memorandum + without_memorandum
        $this->assertSame($stats['total_applications'], $stats['with_memorandum'] + $stats['without_memorandum']);
        $this->assertSame(5, $stats['total_applications']);
        $this->assertSame(3, $stats['total_shgb']);
        $this->assertSame(2, $stats['total_buyback']);
        $this->assertSame(2, $stats['with_memorandum']);
        $this->assertSame(3, $stats['without_memorandum']);
    }

    public function test_summary_guarantees_one_row_per_application_without_duplicates(): void
    {
        $app = $this->createShmApplication([
            'application_number' => 'APP-UNIQUE-ROW',
            'type' => 'shgb',
            'status' => ShmApplicationStatus::Completed,
            'completed_at' => now(),
        ]);

        ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => 'shm_shgb',
            'status' => ShmMemorandum::STATUS_DRAFT,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.summary.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Memorandum/SummaryIndex')
            ->has('items.data', 1)
            ->where('items.data.0.application_number', 'APP-UNIQUE-ROW')
            ->where('items.data.0.memorandum.status', ShmMemorandum::STATUS_DRAFT)
            ->where('stats.total_applications', 1)
            ->where('stats.with_memorandum', 1)
        );
    }

    public function test_filter_by_application_type(): void
    {
        $this->createShmApplication(['application_number' => 'APP-SHGB-1', 'type' => 'shgb']);
        $this->createShmApplication(['application_number' => 'APP-BUYBACK-1', 'type' => 'buyback']);

        // Filter SHGB
        $responseShgb = $this->actingAs($this->admin)->get(route('shm.memorandums.summary.index', ['type' => 'shgb']));
        $responseShgb->assertStatus(200);
        $responseShgb->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.application_number', 'APP-SHGB-1')
        );

        // Filter Buyback
        $responseBb = $this->actingAs($this->admin)->get(route('shm.memorandums.summary.index', ['type' => 'buyback']));
        $responseBb->assertStatus(200);
        $responseBb->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.application_number', 'APP-BUYBACK-1')
        );
    }

    public function test_filter_by_application_status(): void
    {
        $this->createShmApplication(['application_number' => 'APP-DRAFT-1', 'status' => ShmApplicationStatus::Draft]);
        $this->createShmApplication(['application_number' => 'APP-COMPLETED-1', 'status' => ShmApplicationStatus::Completed]);

        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.summary.index', ['status' => ShmApplicationStatus::Completed->value]));
        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.application_number', 'APP-COMPLETED-1')
        );
    }

    public function test_filter_by_memorandum_status(): void
    {
        // 1. None
        $appNone = $this->createShmApplication(['application_number' => 'APP-NO-MEMO']);

        // 2. Draft
        $appDraft = $this->createShmApplication(['application_number' => 'APP-MEMO-DRAFT', 'status' => ShmApplicationStatus::Completed]);
        ShmMemorandum::create([
            'shm_application_id' => $appDraft->id,
            'type' => 'shm_shgb',
            'status' => ShmMemorandum::STATUS_DRAFT,
            'created_by' => $this->admin->id,
        ]);

        // 3. Submitted
        $appSubmitted = $this->createShmApplication(['application_number' => 'APP-MEMO-SUBMITTED', 'status' => ShmApplicationStatus::Completed]);
        ShmMemorandum::create([
            'shm_application_id' => $appSubmitted->id,
            'type' => 'shm_shgb',
            'status' => ShmMemorandum::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        // Test none
        $responseNone = $this->actingAs($this->admin)->get(route('shm.memorandums.summary.index', ['memorandum_status' => 'none']));
        $responseNone->assertStatus(200);
        $responseNone->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.application_number', 'APP-NO-MEMO')
        );

        // Test draft
        $responseDraft = $this->actingAs($this->admin)->get(route('shm.memorandums.summary.index', ['memorandum_status' => 'draft']));
        $responseDraft->assertStatus(200);
        $responseDraft->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.application_number', 'APP-MEMO-DRAFT')
        );

        // Test submitted
        $responseSubmitted = $this->actingAs($this->admin)->get(route('shm.memorandums.summary.index', ['memorandum_status' => 'submitted']));
        $responseSubmitted->assertStatus(200);
        $responseSubmitted->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.application_number', 'APP-MEMO-SUBMITTED')
        );
    }

    public function test_search_by_application_number_customer_name_and_nik(): void
    {
        $appA = $this->createShmApplication([
            'application_number' => 'APP-SEARCH-A-999',
            'shm_customer_id' => $this->customerA->id,
        ]);

        $appB = $this->createShmApplication([
            'application_number' => 'APP-SEARCH-B-888',
            'shm_customer_id' => $this->customerB->id,
        ]);

        // Search by application number
        $respNum = $this->actingAs($this->admin)->get(route('shm.memorandums.summary.index', ['search' => '999']));
        $respNum->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.application_number', 'APP-SEARCH-A-999')
        );

        // Search by customer name
        $respName = $this->actingAs($this->admin)->get(route('shm.memorandums.summary.index', ['search' => 'Siti Aminah']));
        $respName->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.application_number', 'APP-SEARCH-B-888')
        );

        // Search by customer NIK
        $respNik = $this->actingAs($this->admin)->get(route('shm.memorandums.summary.index', ['search' => '3201000000000001']));
        $respNik->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.application_number', 'APP-SEARCH-A-999')
        );
    }

    public function test_marketing_user_scoped_to_own_applications(): void
    {
        $myApp = $this->createShmApplication([
            'application_number' => 'APP-MY-OWN',
            'marketing_id' => $this->marketing->id,
            'created_by' => $this->marketing->id,
        ]);

        $otherApp = $this->createShmApplication([
            'application_number' => 'APP-OTHER-AGENT',
            'marketing_id' => $this->otherMarketing->id,
            'created_by' => $this->otherMarketing->id,
        ]);

        $response = $this->actingAs($this->marketing)->get(route('shm.memorandums.summary.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.application_number', 'APP-MY-OWN')
            ->where('stats.total_applications', 1)
        );
    }

    public function test_non_draft_application_cannot_be_edited_via_route(): void
    {
        $completedApp = $this->createShmApplication([
            'status' => ShmApplicationStatus::Completed,
            'marketing_id' => $this->admin->id,
        ]);

        // Attempt direct GET edit route
        $responseEdit = $this->actingAs($this->admin)->get(route('shm.applications.edit', $completedApp->id));
        $responseEdit->assertStatus(422);

        // Attempt direct PUT update route
        $responseUpdate = $this->actingAs($this->admin)->put(route('shm.applications.update', $completedApp->id), [
            'notes' => 'Attempt illegal update on completed app',
        ]);
        $responseUpdate->assertStatus(422);
    }

    public function test_delete_memorandum_preserves_application_source_and_updates_summary_status(): void
    {
        $app = $this->createShmApplication([
            'application_number' => 'APP-DELETE-MEMO-TEST',
            'type' => 'shgb',
            'status' => ShmApplicationStatus::Completed,
            'completed_at' => now(),
        ]);

        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'created_by' => $this->admin->id,
        ]);

        $this->assertDatabaseHas('shm_memorandums', ['id' => $memo->id]);
        $this->assertDatabaseHas('shm_applications', ['id' => $app->id]);

        // Destroy memorandum
        $delResponse = $this->actingAs($this->admin)->delete(route('shm.memorandums.shm-shgb.destroy', $memo->id));
        $delResponse->assertRedirect(route('shm.memorandums.shm-shgb.index'));

        // ShmMemorandum is deleted
        $this->assertDatabaseMissing('shm_memorandums', ['id' => $memo->id]);
        // ShmApplication is completely preserved!
        $this->assertDatabaseHas('shm_applications', ['id' => $app->id]);

        // Summary now reflects the application as having no memorandum
        $summaryResp = $this->actingAs($this->admin)->get(route('shm.memorandums.summary.index'));
        $summaryResp->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.application_number', 'APP-DELETE-MEMO-TEST')
            ->where('items.data.0.has_memorandum', false)
            ->where('items.data.0.memorandum', null)
            ->where('stats.with_memorandum', 0)
            ->where('stats.without_memorandum', 1)
        );
    }

    public function test_user_cannot_delete_memorandum_outside_ownership_scope(): void
    {
        // Application owned by other marketing
        $otherApp = $this->createShmApplication([
            'marketing_id' => $this->otherMarketing->id,
            'status' => ShmApplicationStatus::Completed,
            'completed_at' => now(),
        ]);

        $memo = ShmMemorandum::create([
            'shm_application_id' => $otherApp->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'created_by' => $this->otherMarketing->id,
        ]);

        // Marketing tries to delete otherMarketing's memorandum
        $response = $this->actingAs($this->marketing)->delete(route('shm.memorandums.shm-shgb.destroy', $memo->id));
        $response->assertStatus(403);

        // Memorandum remains intact
        $this->assertDatabaseHas('shm_memorandums', ['id' => $memo->id]);
    }
}
