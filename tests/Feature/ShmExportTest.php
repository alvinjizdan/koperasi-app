<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Ticket 55 - Modul Export (pemantauan data operasional).
 *
 * Menguji empat halaman READ-ONLY (masuk / approve / reject / pencairan)
 * yang memetakan pengajuan ke status/kolom existing. Modul ini tidak boleh
 * mengubah status, membuat approval, atau menulis audit.
 *
 * Yang TIDAK diuji di sini karena bukan bagian ticket: ekspor file (CSV/Excel/
 * PDF), kolom nominal, permission baru, atau workflow baru.
 */
class ShmExportTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $admin;

    private User $marketing;

    private User $otherMarketing;

    private User $pengawas;

    private ShmCustomer $customer;

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

        $this->customer = ShmCustomer::factory()->create();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /**
     * Buat pengajuan milik Marketing tertentu melalui HTTP agar workflow
     * pembuatan dan audit aslinya ikut berjalan.
     */
    private function createApplicationFor(User $user, array $overrides = []): ShmApplication
    {
        $this->actingAs($user)
            ->post(route('shm.applications.store'), array_merge([
                'shm_customer_id' => $this->customer->id,
                'notes' => 'Pengajuan export test',
            ], $overrides))
            ->assertSessionHasNoErrors();

        return ShmApplication::query()->latest('id')->firstOrFail();
    }

    /**
     * Pengajuan fixture yang sudah masuk alur (submitted).
     */
    private function submittedApplication(User $owner): ShmApplication
    {
        $application = $this->createApplicationFor($owner);
        // Kolom timestamp workflow tidak fillable, jadi dipakai forceFill
        // (pola fixture: tahap diatur langsung untuk posisi uji).
        $application->forceFill(['status' => ShmApplicationStatus::Submitted, 'submitted_at' => now()])->save();
        $application->refresh();

        return $application;
    }

    /**
     * Pengajuan fixture dengan memorandum kategori tertentu.
     */
    private function withMemorandum(ShmApplication $application, string $type): ShmApplication
    {
        ShmMemorandum::query()->create([
            'shm_application_id' => $application->id,
            'created_by' => $application->marketing_id,
            'type' => $type,
            'status' => ShmMemorandum::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $application->refresh();

        return $application;
    }

    private static function categories(): array
    {
        return ['masuk', 'approve', 'reject', 'pencairan'];
    }

    // -----------------------------------------------------------------
    // A. Akses modul: permission existing `shm.view`
    // -----------------------------------------------------------------

    public function test_authorized_users_can_open_all_four_export_routes(): void
    {
        foreach (self::categories() as $category) {
            $this->actingAs($this->admin)
                ->get(route("shm.exports.{$category}"))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Shm/Exports/ExportList')
                    ->where('category', $category)
                );

            $this->actingAs($this->superadmin)
                ->get(route("shm.exports.{$category}"))
                ->assertOk();
        }
    }

    public function test_export_uses_shm_view_permission_not_approval_permission(): void
    {
        // Marketing hanya memegang shm.view (bukan approval.decide) dan tetap
        // dapat membuka export; pengawas tidak memegang shm.view.
        $this->actingAs($this->marketing)
            ->get(route('shm.exports.masuk'))
            ->assertOk();

        $this->actingAs($this->pengawas)
            ->get(route('shm.exports.masuk'))
            ->assertForbidden();
    }

    public function test_export_routes_live_under_shm_export_prefix(): void
    {
        $expected = [
            'shm.exports.masuk' => 'shm/export/masuk',
            'shm.exports.approve' => 'shm/export/approve',
            'shm.exports.reject' => 'shm/export/reject',
            'shm.exports.pencairan' => 'shm/export/pencairan',
        ];

        foreach ($expected as $name => $uri) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route {$name} harus terdaftar.");
            $this->assertSame($uri, $route->uri());
        }
    }

    // -----------------------------------------------------------------
    // B. Mapping kategori ke status existing
    // -----------------------------------------------------------------

    public function test_masuk_lists_submitted_applications(): void
    {
        $submitted = $this->submittedApplication($this->marketing);

        // Draft tidak pernah disubmit sehingga tidak termasuk "masuk".
        $this->createApplicationFor($this->marketing);

        $this->actingAs($this->admin)
            ->get(route('shm.exports.masuk'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('applications.data', 1)
                ->where('applications.data.0.application_number', $submitted->application_number)
            );
    }

    public function test_approve_lists_only_approved_status(): void
    {
        $approved = $this->submittedApplication($this->marketing);
        $approved->forceFill(['status' => ShmApplicationStatus::Approved, 'approved_at' => now()])->save();

        $this->submittedApplication($this->marketing);
        $rejected = $this->submittedApplication($this->marketing);
        $rejected->update(['status' => ShmApplicationStatus::Rejected, 'rejected_at' => now()]);

        $this->actingAs($this->admin)
            ->get(route('shm.exports.approve'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('applications.data', 1)
                ->where('applications.data.0.application_number', $approved->application_number)
            );
    }

    public function test_reject_lists_only_rejected_status(): void
    {
        $rejected = $this->submittedApplication($this->marketing);
        $rejected->forceFill(['status' => ShmApplicationStatus::Rejected, 'rejected_at' => now()])->save();

        $this->submittedApplication($this->marketing);

        $this->actingAs($this->admin)
            ->get(route('shm.exports.reject'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('applications.data', 1)
                ->where('applications.data.0.application_number', $rejected->application_number)
            );
    }

    public function test_pencairan_lists_applications_with_disbursed_at(): void
    {
        $disbursed = $this->submittedApplication($this->marketing);
        $disbursed->forceFill([
            'status' => ShmApplicationStatus::Disbursed,
            'approved_at' => now(),
            'disbursed_at' => now(),
        ])->save();

        // approved namun belum dicairkan tidak masuk kategori pencairan.
        $this->submittedApplication($this->marketing);

        $this->actingAs($this->admin)
            ->get(route('shm.exports.pencairan'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('applications.data', 1)
                ->where('applications.data.0.application_number', $disbursed->application_number)
                ->where('applications.data.0.disbursed_at', fn ($value) => $value !== null)
            );
    }

    // -----------------------------------------------------------------
    // C. Search, filter, sorting, pagination
    // -----------------------------------------------------------------

    public function test_search_by_application_number(): void
    {
        $target = $this->submittedApplication($this->marketing);
        $this->submittedApplication($this->marketing);

        $this->actingAs($this->admin)
            ->get(route('shm.exports.masuk', ['search' => $target->application_number]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('applications.data', 1)
                ->where('applications.data.0.application_number', $target->application_number)
            );
    }

    public function test_search_by_customer_name_and_number(): void
    {
        $customer = ShmCustomer::factory()->create([
            'name' => 'Budi Santoso Export',
            'customer_number' => 'NSB-EXPORT-001',
        ]);

        $application = $this->submittedApplication($this->marketing);
        $application->forceFill(['shm_customer_id' => $customer->id])->save();

        $this->submittedApplication($this->marketing);

        foreach (['Budi Santoso Export', 'NSB-EXPORT-001'] as $term) {
            $this->actingAs($this->admin)
                ->get(route('shm.exports.masuk', ['search' => $term]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('applications.data', 1)
                    ->where('applications.data.0.id', $application->id)
                );
        }
    }

    public function test_memorandum_type_filter(): void
    {
        $shgb = $this->withMemorandum(
            $this->submittedApplication($this->marketing),
            'shm_shgb'
        );
        $this->withMemorandum($this->submittedApplication($this->marketing), 'buyback');

        foreach (['shm_shgb' => $shgb] as $type => $expected) {
            $this->actingAs($this->admin)
                ->get(route('shm.exports.masuk', ['type' => $type]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('applications.data', 1)
                    ->where('applications.data.0.id', $expected->id)
                );
        }
    }

    public function test_sorting_is_whitelisted_and_defaults_to_updated_at_desc(): void
    {
        $older = $this->submittedApplication($this->marketing);
        $newer = $this->submittedApplication($this->marketing);
        $older->forceFill(['updated_at' => now()->subHour()])->save();

        $this->actingAs($this->admin)
            ->get(route('shm.exports.masuk'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('applications.data.0.id', $newer->id)
                ->where('applications.data.1.id', $older->id)
            );

        $this->actingAs($this->admin)
            ->get(route('shm.exports.masuk', ['sort' => 'submitted_at', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('applications.data.0.id', $older->id)
            );

        // Kolom di luar whitelist diabaikan dan jatuh ke fallback.
        $this->actingAs($this->admin)
            ->get(route('shm.exports.masuk', ['sort' => 'shm_customer_id', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sort.key', null)
            );
    }

    public function test_pagination_and_query_string(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->submittedApplication($this->marketing);
        }

        $this->actingAs($this->admin)
            ->get(route('shm.exports.masuk', ['per_page' => 10]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('applications.data', 10)
                ->where('applications.per_page', 10)
                ->where('applications.last_page', 2)
            );

        // Nilai per_page di luar whitelist jatuh ke bawaan.
        $this->actingAs($this->admin)
            ->get(route('shm.exports.masuk', ['per_page' => 999]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('applications.per_page', 25)
            );
    }

    // -----------------------------------------------------------------
    // D. Ownership scope & direct URL protection
    // -----------------------------------------------------------------

    public function test_marketing_without_view_all_only_sees_own_applications(): void
    {
        $mine = $this->submittedApplication($this->marketing);
        $this->submittedApplication($this->otherMarketing);

        foreach (self::categories() as $category) {
            $this->actingAs($this->marketing)
                ->get(route("shm.exports.{$category}"))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('applications.data', $category === 'masuk' ? 1 : 0)
                    ->when($category === 'masuk', fn ($page) => $page
                        ->where('applications.data.0.id', $mine->id)
                    )
                );
        }
    }

    // -----------------------------------------------------------------
    // E. Export bersifat read-only
    // -----------------------------------------------------------------

    public function test_export_does_not_change_status_or_create_records(): void
    {
        $application = $this->submittedApplication($this->marketing);
        $statusBefore = $application->status;

        foreach (self::categories() as $category) {
            $this->actingAs($this->admin)
                ->get(route("shm.exports.{$category}"))
                ->assertOk();
        }

        $application->refresh();

        $this->assertSame($statusBefore, $application->status);
        $this->assertDatabaseCount('shm_approvals', 0);
        $this->assertSame(
            AuditLog::query()->where('auditable_type', ShmApplication::class)->count(),
            0,
            'Export tidak boleh menulis audit log.'
        );
    }

    public function test_unknown_category_is_not_found(): void
    {
        // Tidak ada route wildcard: /shm/export/semua tidak terdaftar.
        $this->actingAs($this->admin)
            ->get('/shm/export/semua')
            ->assertNotFound();
    }
}
