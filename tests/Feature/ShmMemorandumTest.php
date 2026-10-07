<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCreditCheck;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Ticket 49 — Modul Memorandum Marketing: SHM/SHGB & Buyback.
 *
 * Menguji: daftar (search/filter/sort/pagination), detail dengan konteks,
 * pembuatan dan perubahan lewat modul baru, otorisasi server-side, integrasi
 * section memorandum pada halaman pengajuan, gate tahap workflow, dan audit
 * tepat satu kali per aksi. Semuanya memakai ShmMemorandum existing dan
 * permission shm.memorandum.update yang sudah ada; tidak ada permission,
 * tabel, atau status baru.
 */
class ShmMemorandumTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $admin;

    private User $marketing;

    private User $otherMarketing;

    private User $pengawas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = $this->userWithRole('superadmin');
        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->otherMarketing = $this->userWithRole('marketing');
        $this->pengawas = $this->userWithRole('pengawas');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /**
     * Pengajuan milik Marketing yang sudah berada di tahap Memorandum,
     * melalui endpoint transisi yang sah (bukan update status langsung).
     */
    private function applicationAtMemorandumStage(User $owner): ShmApplication
    {
        $this->actingAs($owner)
            ->post(route('shm.applications.store'), [
                'shm_customer_id' => ShmCustomer::factory()->create()->id,
                'notes' => 'Pengajuan memorandum',
            ])
            ->assertSessionHasNoErrors();

        $application = ShmApplication::query()->latest('id')->firstOrFail();

        $this->actingAs($owner)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->post(route('shm.applications.start-credit-checking', $application->id))
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->post(route('shm.applications.start-memorandum', $application->id))
            ->assertSessionHasNoErrors();

        return $application->refresh();
    }

    /**
     * Catat memorandum lewat endpoint existing Ticket 44 (memakai service).
     */
    private function recordMemorandumFor(ShmApplication $application, array $overrides = []): ShmMemorandum
    {
        $this->actingAs($this->marketing)
            ->put(route('shm.applications.memorandum', $application->id), array_merge([
                'type' => ShmMemorandum::TYPE_SHM_SHGB,
                'status' => ShmMemorandum::STATUS_DRAFT,
                'notes' => 'Naskah memorandum',
            ], $overrides))
            ->assertSessionHasNoErrors();

        return $application->memorandums()->firstOrFail();
    }

    // -----------------------------------------------------------------
    // A. Otorisasi
    // -----------------------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('shm.memorandums.index'))
            ->assertRedirect(route('shm.login'));
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $this->actingAs($this->pengawas)
            ->get(route('shm.memorandums.index'))
            ->assertForbidden();
    }

    public function test_admin_can_access_module(): void
    {
        $this->actingAs($this->admin)
            ->get(route('shm.memorandums.index'))
            ->assertOk();
    }

    public function test_superadmin_follows_existing_bypass(): void
    {
        $this->actingAs($this->superadmin)
            ->get(route('shm.memorandums.index'))
            ->assertOk();
    }

    public function test_marketing_sees_only_memorandums_of_own_applications(): void
    {
        $application = $this->applicationAtMemorandumStage($this->marketing);
        $this->recordMemorandumFor($application);

        $otherApplication = $this->applicationAtMemorandumStage($this->otherMarketing);
        $this->actingAs($this->otherMarketing)
            ->put(route('shm.applications.memorandum', $otherApplication->id), [
                'type' => ShmMemorandum::TYPE_BUYBACK,
                'status' => ShmMemorandum::STATUS_DRAFT,
            ]);

        $this->actingAs($this->marketing)
            ->get(route('shm.memorandums.index'))
            ->assertOk()
            ->assertSee($application->application_number)
            ->assertDontSee($otherApplication->application_number);
    }

    // -----------------------------------------------------------------
    // B. Pembuatan kedua kategori
    // -----------------------------------------------------------------

    public function test_shm_shgb_memorandum_can_be_created(): void
    {
        $application = $this->applicationAtMemorandumStage($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.memorandums.store'), [
                'shm_application_id' => $application->id,
                'type' => ShmMemorandum::TYPE_SHM_SHGB,
                'status' => ShmMemorandum::STATUS_DRAFT,
                'notes' => 'Memorandum SHM/SHGB',
            ])
            ->assertSessionHasNoErrors();

        $memorandum = $application->memorandums()->firstOrFail();

        $this->assertSame(ShmMemorandum::TYPE_SHM_SHGB, $memorandum->type);
        $this->assertSame($this->marketing->id, $memorandum->created_by);
        $this->assertSame($application->id, $memorandum->shm_application_id);
    }

    public function test_buyback_memorandum_can_be_created(): void
    {
        $application = $this->applicationAtMemorandumStage($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.memorandums.store'), [
                'shm_application_id' => $application->id,
                'type' => ShmMemorandum::TYPE_BUYBACK,
                'status' => ShmMemorandum::STATUS_SUBMITTED,
            ])
            ->assertSessionHasNoErrors();

        $memorandum = $application->memorandums()->firstOrFail();

        $this->assertSame(ShmMemorandum::TYPE_BUYBACK, $memorandum->type);
        $this->assertTrue($memorandum->isSubmitted());
        $this->assertNotNull($memorandum->submitted_at);
    }

    public function test_store_rejects_unknown_application(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.memorandums.store'), [
                'shm_application_id' => 999999,
                'type' => ShmMemorandum::TYPE_SHM_SHGB,
                'status' => ShmMemorandum::STATUS_DRAFT,
            ])
            ->assertSessionHasErrors('shm_application_id');
    }

    public function test_store_rejects_unknown_type(): void
    {
        $application = $this->applicationAtMemorandumStage($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.memorandums.store'), [
                'shm_application_id' => $application->id,
                'type' => 'settlement',
                'status' => ShmMemorandum::STATUS_DRAFT,
            ])
            ->assertSessionHasErrors('type');

        $this->assertSame(0, ShmMemorandum::query()->count());
    }

    public function test_store_requires_type_on_module_endpoint(): void
    {
        $application = $this->applicationAtMemorandumStage($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.memorandums.store'), [
                'shm_application_id' => $application->id,
                'status' => ShmMemorandum::STATUS_DRAFT,
            ])
            ->assertSessionHasErrors('type');
    }

    public function test_memorandum_stays_one_row_per_application(): void
    {
        $application = $this->applicationAtMemorandumStage($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.memorandums.store'), [
                'shm_application_id' => $application->id,
                'type' => ShmMemorandum::TYPE_SHM_SHGB,
                'status' => ShmMemorandum::STATUS_DRAFT,
            ])
            ->assertSessionHasNoErrors();

        // Pencatatan kedua pada pengajuan yang sama memperbarui baris yang
        // sama (perilaku existing), bukan menambah baris kedua.
        $this->actingAs($this->marketing)
            ->post(route('shm.memorandums.store'), [
                'shm_application_id' => $application->id,
                'type' => ShmMemorandum::TYPE_BUYBACK,
                'status' => ShmMemorandum::STATUS_SUBMITTED,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $application->memorandums()->count());
        $this->assertSame(ShmMemorandum::TYPE_BUYBACK, $application->memorandums()->first()->type);
    }

    public function test_other_marketing_cannot_store_memorandum_for_foreign_application(): void
    {
        $application = $this->applicationAtMemorandumStage($this->marketing);

        $this->actingAs($this->otherMarketing)
            ->post(route('shm.memorandums.store'), [
                'shm_application_id' => $application->id,
                'type' => ShmMemorandum::TYPE_SHM_SHGB,
                'status' => ShmMemorandum::STATUS_DRAFT,
            ])
            ->assertNotFound();

        $this->assertSame(0, ShmMemorandum::query()->count());
    }

    // -----------------------------------------------------------------
    // C. Gate tahap workflow
    // -----------------------------------------------------------------

    public function test_store_is_blocked_outside_memorandum_stage(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.store'), [
                'shm_customer_id' => ShmCustomer::factory()->create()->id,
            ])
            ->assertSessionHasNoErrors();

        $application = ShmApplication::query()->latest('id')->firstOrFail();

        $this->actingAs($this->marketing)
            ->post(route('shm.memorandums.store'), [
                'shm_application_id' => $application->id,
                'type' => ShmMemorandum::TYPE_SHM_SHGB,
                'status' => ShmMemorandum::STATUS_DRAFT,
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(0, ShmMemorandum::query()->count());
    }

    // -----------------------------------------------------------------
    // D. Daftar: search, filter, sort, pagination
    // -----------------------------------------------------------------

    public function test_list_is_empty_without_memorandums(): void
    {
        $this->actingAs($this->admin)
            ->get(route('shm.memorandums.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Memorandum/Index')
                ->has('memorandums.data', 0)
            );
    }

    public function test_list_can_be_searched_by_application_number(): void
    {
        $application = $this->applicationAtMemorandumStage($this->marketing);
        $this->recordMemorandumFor($application);

        $other = $this->applicationAtMemorandumStage($this->marketing);
        $this->recordMemorandumFor($other);

        $this->actingAs($this->admin)
            ->get(route('shm.memorandums.index', ['search' => $application->application_number]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('memorandums.data', 1));
    }

    public function test_list_can_be_filtered_by_type(): void
    {
        $first = $this->applicationAtMemorandumStage($this->marketing);
        $this->recordMemorandumFor($first, ['type' => ShmMemorandum::TYPE_SHM_SHGB]);

        $second = $this->applicationAtMemorandumStage($this->marketing);
        $this->recordMemorandumFor($second, ['type' => ShmMemorandum::TYPE_BUYBACK]);

        $this->actingAs($this->admin)
            ->get(route('shm.memorandums.index', ['type' => ShmMemorandum::TYPE_BUYBACK]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('memorandums.data', 1)
                ->where('memorandums.data.0.type', ShmMemorandum::TYPE_BUYBACK)
            );
    }

    public function test_list_can_be_filtered_by_status(): void
    {
        $first = $this->applicationAtMemorandumStage($this->marketing);
        $this->recordMemorandumFor($first, ['status' => ShmMemorandum::STATUS_DRAFT]);

        $second = $this->applicationAtMemorandumStage($this->marketing);
        $this->recordMemorandumFor($second, ['status' => ShmMemorandum::STATUS_SUBMITTED]);

        $this->actingAs($this->admin)
            ->get(route('shm.memorandums.index', ['status' => ShmMemorandum::STATUS_SUBMITTED]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('memorandums.data', 1)
                ->where('memorandums.data.0.status', ShmMemorandum::STATUS_SUBMITTED)
            );
    }

    public function test_list_can_be_sorted(): void
    {
        $first = $this->applicationAtMemorandumStage($this->marketing);
        $this->recordMemorandumFor($first);

        $second = $this->applicationAtMemorandumStage($this->marketing);
        $this->recordMemorandumFor($second);

        $this->actingAs($this->admin)
            ->get(route('shm.memorandums.index', ['sort' => 'created_at', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sort.key', 'created_at')
                ->where('sort.direction', 'asc')
            );
    }

    public function test_list_is_paginated(): void
    {
        // per_page hanya menerima whitelist 10/25/50/100, jadi buat 11 baris
        // lalu minta 10 per halaman.
        for ($i = 0; $i < 11; $i++) {
            $application = $this->applicationAtMemorandumStage($this->marketing);
            $this->recordMemorandumFor($application);
        }

        $this->actingAs($this->admin)
            ->get(route('shm.memorandums.index', ['per_page' => 10]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('memorandums.data', 10));
    }

    // -----------------------------------------------------------------
    // E. Detail & konteks
    // -----------------------------------------------------------------

    public function test_detail_shows_type_status_and_creator(): void
    {
        $application = $this->applicationAtMemorandumStage($this->marketing);
        $memorandum = $this->recordMemorandumFor($application);

        $this->actingAs($this->admin)
            ->get(route('shm.memorandums.show', $memorandum->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Memorandum/Show')
                ->where('memorandum.type', ShmMemorandum::TYPE_SHM_SHGB)
                ->where('memorandum.status', ShmMemorandum::STATUS_DRAFT)
                ->where('application.id', $application->id)
                ->has('application.customer.name')
                ->has('properties')
                ->has('history')
            );
    }

    public function test_detail_shows_credit_check_context(): void
    {
        $application = $this->applicationAtMemorandumStage($this->marketing);
        $memorandum = $this->recordMemorandumFor($application);

        // Baris credit checking mungkin belum ada bila tahapnya dilewati;
        // buat lewat endpoint yang sah lalu pastikan detail memorandum
        // mengirimnya sebagai referensi tanpa aturan otomatis apa pun.

        // Baris credit checking dibuat saat tahap dimulai (start-credit-checking).
        $creditCheck = ShmCreditCheck::query()
            ->where('shm_application_id', $application->id)
            ->first();

        if ($creditCheck === null) {
            $this->actingAs($this->marketing)
                ->post(route('shm.applications.credit-checking.start', $application->id))
                ->assertSessionHasNoErrors();

            $creditCheck = ShmCreditCheck::query()
                ->where('shm_application_id', $application->id)
                ->firstOrFail();
        }

        $this->actingAs($this->admin)
            ->get(route('shm.memorandums.show', $memorandum->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Konteks credit checking dikirim sebagai referensi. Pada tahap
                // memorandum statusnya sedang berjalan dan outcome boleh kosong.
                ->where('credit_check.id', $creditCheck->id)
                ->where('credit_check.status', 'in_progress')
            );
    }

    public function test_other_marketing_cannot_open_foreign_memorandum(): void
    {
        $application = $this->applicationAtMemorandumStage($this->marketing);
        $memorandum = $this->recordMemorandumFor($application);

        $this->actingAs($this->otherMarketing)
            ->get(route('shm.memorandums.show', $memorandum->id))
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // F. Perubahan memorandum
    // -----------------------------------------------------------------

    public function test_memorandum_can_be_updated_via_module(): void
    {
        $application = $this->applicationAtMemorandumStage($this->marketing);
        $memorandum = $this->recordMemorandumFor($application);

        $this->actingAs($this->marketing)
            ->put(route('shm.memorandums.update', $memorandum->id), [
                'shm_application_id' => $application->id,
                'type' => ShmMemorandum::TYPE_BUYBACK,
                'status' => ShmMemorandum::STATUS_SUBMITTED,
                'notes' => 'Naskah diperbarui',
            ])
            ->assertSessionHasNoErrors();

        $memorandum->refresh();

        $this->assertSame(ShmMemorandum::TYPE_BUYBACK, $memorandum->type);
        $this->assertSame(ShmMemorandum::STATUS_SUBMITTED, $memorandum->status);
        $this->assertSame('Naskah diperbarui', $memorandum->notes);
    }

    public function test_update_validates_type_and_status(): void
    {
        $application = $this->applicationAtMemorandumStage($this->marketing);
        $memorandum = $this->recordMemorandumFor($application);

        $this->actingAs($this->marketing)
            ->put(route('shm.memorandums.update', $memorandum->id), [
                'shm_application_id' => $application->id,
                'type' => 'bukan-kategori',
                'status' => 'approved',
            ])
            ->assertSessionHasErrors(['type', 'status']);

        $this->assertSame(ShmMemorandum::TYPE_SHM_SHGB, $memorandum->refresh()->type);
    }

    // -----------------------------------------------------------------
    // G. Audit: satu aksi = satu event
    // -----------------------------------------------------------------

    public function test_store_is_audited_once(): void
    {
        $application = $this->applicationAtMemorandumStage($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.memorandums.store'), [
                'shm_application_id' => $application->id,
                'type' => ShmMemorandum::TYPE_SHM_SHGB,
                'status' => ShmMemorandum::STATUS_DRAFT,
            ])
            ->assertSessionHasNoErrors();

        $memorandum = $application->memorandums()->firstOrFail();

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.memorandum.updated')
            ->where('object_type', ShmMemorandum::class)
            ->where('object_id', $memorandum->id)
            ->count());
    }

    public function test_update_is_audited_once(): void
    {
        $application = $this->applicationAtMemorandumStage($this->marketing);
        $memorandum = $this->recordMemorandumFor($application);

        // Pencatatan awal lewat helper sudah menulis satu event; perubahan
        // lewat modul harus menambah TEPAT SATU event (tidak ada audit ganda
        // dari controller + service).
        $before = AuditLog::query()
            ->where('action', 'shm.memorandum.updated')
            ->where('object_type', ShmMemorandum::class)
            ->where('object_id', $memorandum->id)
            ->count();

        $this->actingAs($this->marketing)
            ->put(route('shm.memorandums.update', $memorandum->id), [
                'shm_application_id' => $application->id,
                'type' => ShmMemorandum::TYPE_BUYBACK,
                'status' => ShmMemorandum::STATUS_SUBMITTED,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($before + 1, AuditLog::query()
            ->where('action', 'shm.memorandum.updated')
            ->where('object_type', ShmMemorandum::class)
            ->where('object_id', $memorandum->id)
            ->count());
    }

    // -----------------------------------------------------------------
    // H. Hygiene route & larangan finansial
    // -----------------------------------------------------------------

    public function test_memorandum_routes_live_under_shm_prefix(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->getName() ?? '', 'shm.memorandums.')) {
                continue;
            }

            $this->assertTrue(
                str_starts_with($route->uri(), 'shm/memorandum'),
                "Route {$route->getName()} harus di bawah shm/memorandum, bukan {$route->uri()}."
            );
        }

        foreach (['index', 'create', 'store', 'show', 'edit', 'update'] as $action) {
            $this->assertTrue(Route::has("shm.memorandums.{$action}"));
        }
    }

    public function test_memorandum_table_stays_free_of_financial_columns(): void
    {
        foreach (['amount', 'interest_rate', 'margin', 'discount', 'penalty', 'installment'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('shm_memorandums', $column),
                "shm_memorandums tidak boleh punya kolom {$column}."
            );
        }
    }
}
