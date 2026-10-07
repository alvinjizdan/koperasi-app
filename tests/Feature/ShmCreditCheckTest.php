<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Enums\ShmCreditCheckOutcome;
use App\Enums\ShmCreditCheckStatus;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCreditCheck;
use App\Models\ShmCustomer;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Ticket 47 — Credit Checking SHM sebagai workflow operasional.
 *
 * Credit Checking di sini adalah workflow MANUAL: Belum Dimulai -> Sedang
 * Berjalan -> Selesai, dengan hasil pencatatan PASS / REVIEW / FAIL.
 *
 * Yang diuji: daftar Proses Marketing, penyelesaian hasil, penjagaan transisi,
 * otorisasi per permission, audit satu event per aksi, integrasi ke halaman
 * pengajuan, dan jaminan bahwa identitas nasabah diambil dari `shm_customers`
 * (bukan tabel members).
 *
 * TIDAK ada integrasi BI Checking/SLIK/OJK, skor, ambang angka, atau keputusan
 * kelayakan otomatis yang diuji di sini karena memang tidak dibangun.
 */
class ShmCreditCheckTest extends TestCase
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

        $this->customer = ShmCustomer::factory()->create(['name' => 'Nasabah Credit Checking']);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /**
     * Pengajuan milik Marketing yang sudah dikirim (tahap proses).
     */
    private function submittedApplicationFor(User $user, ?ShmCustomer $customer = null): ShmApplication
    {
        $this->actingAs($user)
            ->post(route('shm.applications.store'), [
                'shm_customer_id' => ($customer ?? $this->customer)->id,
                'notes' => 'Pengajuan credit checking',
            ])
            ->assertSessionHasNoErrors();

        $application = ShmApplication::query()->latest('id')->firstOrFail();

        $this->actingAs($user)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();

        return $application->refresh();
    }

    /**
     * Pengajuan yang credit checking-nya sudah dimulai.
     *
     * @return array{0: ShmApplication, 1: ShmCreditCheck}
     */
    private function startedCreditCheckFor(User $user, ?ShmCustomer $customer = null): array
    {
        $application = $this->submittedApplicationFor($user, $customer);

        $this->actingAs($user)
            ->post(route('shm.applications.credit-checking.start', $application->id))
            ->assertSessionHasNoErrors();

        // Baris credit checking milik pengajuan ini, bukan baris pertama di
        // tabel: satu pengajuan hanya punya satu baris (indeks unik).
        return [
            $application->refresh(),
            ShmCreditCheck::query()->where('shm_application_id', $application->id)->firstOrFail(),
        ];
    }

    /**
     * Credit checking yang sudah selesai dengan hasil tertentu.
     *
     * @return array{0: ShmApplication, 1: ShmCreditCheck}
     */
    private function completedCreditCheckFor(
        User $user,
        string $outcome = 'pass',
        ?ShmCustomer $customer = null,
    ): array {
        [$application, $creditCheck] = $this->startedCreditCheckFor($user, $customer);

        $this->actingAs($user)
            ->post(route('shm.credit-checks.complete', $creditCheck->id), [
                'outcome' => $outcome,
                'result' => 'Ringkasan hasil pemeriksaan',
                'notes' => 'Catatan pemeriksaan',
            ])
            ->assertSessionHasNoErrors();

        return [$application, $creditCheck->refresh()];
    }

    // -----------------------------------------------------------------
    // A. Proses Marketing: daftar ERP
    // -----------------------------------------------------------------

    public function test_marketing_can_open_proses_marketing(): void
    {
        $application = $this->submittedApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.credit-checks.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/CreditChecks/Index')
                ->has('credit_checks.data', 1)
                ->where('credit_checks.data.0.application_number', $application->application_number)
                ->where('credit_checks.data.0.customer_name', 'Nasabah Credit Checking')
                // Pengajuan yang belum dimulai ditawarkan aksi "Mulai Checking".
                ->where('credit_checks.data.0.action', 'start')
                ->where('credit_checks.data.0.credit_check_id', null)
            );
    }

    public function test_proses_marketing_lists_own_applications_only(): void
    {
        $mine = $this->submittedApplicationFor($this->marketing);
        $theirs = $this->submittedApplicationFor($this->otherMarketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.credit-checks.index'))
            ->assertOk()
            ->assertSee($mine->application_number)
            ->assertDontSee($theirs->application_number);
    }

    public function test_proses_marketing_can_be_searched(): void
    {
        $otherCustomer = ShmCustomer::factory()->create(['name' => 'Nasabah Lain Sekali']);
        $this->submittedApplicationFor($this->marketing);
        $this->submittedApplicationFor($this->marketing, $otherCustomer);

        $this->actingAs($this->marketing)
            ->get(route('shm.credit-checks.index', ['search' => 'Nasabah Credit Checking']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('credit_checks.data', 1)
                ->where('credit_checks.data.0.customer_name', 'Nasabah Credit Checking'));
    }

    public function test_proses_marketing_can_be_filtered_by_status(): void
    {
        [$completedApplication] = $this->completedCreditCheckFor($this->marketing);
        [$runningApplication] = $this->startedCreditCheckFor($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.credit-checks.index', ['status' => 'in_progress']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('credit_checks.data', 1)
                ->where('credit_checks.data.0.application_number', $runningApplication->application_number));

        $this->actingAs($this->marketing)
            ->get(route('shm.credit-checks.index', ['status' => 'completed']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('credit_checks.data', 1)
                ->where('credit_checks.data.0.application_number', $completedApplication->application_number));
    }

    public function test_proses_marketing_is_paginated_and_sortable(): void
    {
        $this->submittedApplicationFor($this->marketing);
        $this->submittedApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.credit-checks.index', ['per_page' => 10, 'sort' => 'customer', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sort.key', 'customer')
                ->where('sort.direction', 'asc')
                ->where('credit_checks.per_page', 10)
            );
    }

    /**
     * Setiap header yang ditandai sortable di halaman harus diterima server.
     * Tanpa whitelist yang lengkap, klik header tidak berefek tanpa error.
     */
    public function test_proses_marketing_accepts_every_sortable_column(): void
    {
        $this->completedCreditCheckFor($this->marketing);

        foreach (['application_number', 'customer', 'status', 'started_at', 'completed_at', 'updated_at'] as $column) {
            $this->actingAs($this->marketing)
                ->get(route('shm.credit-checks.index', ['sort' => $column, 'direction' => 'desc']))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('sort.key', $column)
                    ->where('sort.direction', 'desc')
                );
        }
    }

    public function test_hasil_marketing_accepts_every_sortable_column(): void
    {
        $this->completedCreditCheckFor($this->marketing);

        foreach (['application_number', 'customer', 'outcome', 'completed_at', 'updated_at'] as $column) {
            $this->actingAs($this->marketing)
                ->get(route('shm.credit-checks.results', ['sort' => $column, 'direction' => 'desc']))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('sort.key', $column)
                    ->where('sort.direction', 'desc')
                );
        }
    }

    // -----------------------------------------------------------------
    // B. Mulai checking
    // -----------------------------------------------------------------

    public function test_marketing_can_start_credit_checking(): void
    {
        $application = $this->submittedApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.credit-checking.start', $application->id))
            ->assertSessionHasNoErrors();

        $creditCheck = ShmCreditCheck::query()->firstOrFail();

        $this->assertSame(ShmCreditCheckStatus::InProgress, $creditCheck->status);
        $this->assertSame($this->marketing->id, $creditCheck->filled_by);
        $this->assertNotNull($creditCheck->started_at);
        // Belum selesai: hasil dan waktu selesai masih kosong.
        $this->assertNull($creditCheck->outcome);
        $this->assertNull($creditCheck->checked_at);

        // Membuka daftar adalah aksi Mulai Checking yang sama, bukan status
        // tersembunyi: pengajuan ikut masuk ke tahap credit checking.
        $this->assertSame(ShmApplicationStatus::CreditChecking, $application->refresh()->status);
    }

    public function test_start_creates_exactly_one_credit_check_row(): void
    {
        [$application] = $this->startedCreditCheckFor($this->marketing);

        // Indeks unik shm_application_id menjaga satu baris per pengajuan.
        $this->assertSame(1, ShmCreditCheck::query()
            ->where('shm_application_id', $application->id)
            ->count());
    }

    public function test_cannot_start_credit_checking_on_draft_application(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.store'), [
                'shm_customer_id' => $this->customer->id,
                'notes' => 'Masih draft',
            ])
            ->assertSessionHasNoErrors();

        $application = ShmApplication::query()->latest('id')->firstOrFail();

        // Pengajuan draft belum masuk tahap proses.
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.credit-checking.start', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(0, ShmCreditCheck::query()->count());
    }

    // -----------------------------------------------------------------
    // C. Penjagaan transisi
    // -----------------------------------------------------------------

    public function test_transition_map_forbids_skipping_in_progress(): void
    {
        $this->assertSame(
            [ShmCreditCheckStatus::InProgress],
            ShmCreditCheckStatus::Pending->allowedTransitions()
        );

        $this->assertTrue(ShmCreditCheckStatus::Pending->canTransitionTo(ShmCreditCheckStatus::InProgress));
        $this->assertFalse(ShmCreditCheckStatus::Pending->canTransitionTo(ShmCreditCheckStatus::Completed));
        $this->assertFalse(ShmCreditCheckStatus::Completed->canTransitionTo(ShmCreditCheckStatus::InProgress));
        $this->assertFalse(ShmCreditCheckStatus::Completed->canTransitionTo(ShmCreditCheckStatus::Completed));
        $this->assertTrue(ShmCreditCheckStatus::Completed->isFinal());
    }

    public function test_pending_credit_checking_cannot_jump_to_completed(): void
    {
        // Baris credit checking yang ada tanpa pernah dimulai. Ini keadaan awal
        // kolom status (bawaan 'pending'), jadi penjagaannya diuji langsung.
        $application = $this->submittedApplicationFor($this->marketing);

        $creditCheck = ShmCreditCheck::query()->create([
            'shm_application_id' => $application->id,
            'status' => ShmCreditCheckStatus::Pending->value,
        ]);

        $this->actingAs($this->marketing)
            ->post(route('shm.credit-checks.complete', $creditCheck->id), [
                'outcome' => 'pass',
                'result' => 'Mencoba melompat tahap',
            ])
            ->assertSessionHasErrors('status');

        $creditCheck->refresh();

        $this->assertSame(ShmCreditCheckStatus::Pending, $creditCheck->status);
        $this->assertNull($creditCheck->outcome);
        $this->assertNull($creditCheck->checked_at);
    }

    public function test_completed_credit_checking_cannot_be_reopened(): void
    {
        [, $creditCheck] = $this->completedCreditCheckFor($this->marketing);

        // Update setelah selesai ditolak.
        $this->actingAs($this->marketing)
            ->put(route('shm.credit-checks.update', $creditCheck->id), [
                'outcome' => 'fail',
                'result' => 'Mengubah hasil final',
            ])
            ->assertSessionHasErrors('status');

        // Menyelesaikan dua kali ditolak.
        $this->actingAs($this->marketing)
            ->post(route('shm.credit-checks.complete', $creditCheck->id), [
                'outcome' => 'fail',
                'result' => 'Menyelesaikan dua kali',
            ])
            ->assertSessionHasErrors('status');

        $creditCheck->refresh();

        $this->assertSame(ShmCreditCheckStatus::Completed, $creditCheck->status);
        $this->assertSame(ShmCreditCheckOutcome::Pass, $creditCheck->outcome);
        $this->assertSame('Ringkasan hasil pemeriksaan', $creditCheck->result);
    }

    public function test_restarting_an_in_progress_credit_checking_is_rejected(): void
    {
        [$application] = $this->startedCreditCheckFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.credit-checking.start', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(1, ShmCreditCheck::query()->count());
    }

    // -----------------------------------------------------------------
    // D. Input hasil
    // -----------------------------------------------------------------

    public function test_marketing_can_update_checking_result_while_running(): void
    {
        [, $creditCheck] = $this->startedCreditCheckFor($this->marketing);

        $this->actingAs($this->marketing)
            ->put(route('shm.credit-checks.update', $creditCheck->id), [
                'outcome' => 'review',
                'result' => 'Perlu pengecekan tambahan',
                'notes' => 'Menunggu konfirmasi',
            ])
            ->assertSessionHasNoErrors();

        $creditCheck->refresh();

        $this->assertSame(ShmCreditCheckOutcome::Review, $creditCheck->outcome);
        $this->assertSame('Perlu pengecekan tambahan', $creditCheck->result);
        $this->assertSame('Menunggu konfirmasi', $creditCheck->notes);
        // Perubahan sementara tidak menyelesaikan proses.
        $this->assertSame(ShmCreditCheckStatus::InProgress, $creditCheck->status);
        $this->assertNull($creditCheck->checked_at);
    }

    public function test_outcome_only_accepts_pass_review_fail(): void
    {
        [, $creditCheck] = $this->startedCreditCheckFor($this->marketing);

        $this->actingAs($this->marketing)
            ->put(route('shm.credit-checks.update', $creditCheck->id), [
                'outcome' => 'sangat_baik',
            ])
            ->assertSessionHasErrors('outcome');

        $this->assertNull($creditCheck->refresh()->outcome);
    }

    public function test_complete_requires_outcome(): void
    {
        [, $creditCheck] = $this->startedCreditCheckFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.credit-checks.complete', $creditCheck->id), [
                'result' => 'Ringkasan tanpa hasil',
            ])
            ->assertSessionHasErrors('outcome');

        $creditCheck->refresh();

        $this->assertSame(ShmCreditCheckStatus::InProgress, $creditCheck->status);
        $this->assertNull($creditCheck->checked_at);
    }

    public function test_complete_requires_result_summary(): void
    {
        [, $creditCheck] = $this->startedCreditCheckFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.credit-checks.complete', $creditCheck->id), [
                'outcome' => 'pass',
            ])
            ->assertSessionHasErrors('result');

        $this->assertSame(ShmCreditCheckStatus::InProgress, $creditCheck->refresh()->status);
    }

    public function test_complete_records_outcome_time_and_checker(): void
    {
        [$application, $creditCheck] = $this->startedCreditCheckFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.credit-checks.complete', $creditCheck->id), [
                'outcome' => 'fail',
                'result' => 'Ditemukan ketidaksesuaian data',
                'notes' => 'Perlu klarifikasi nasabah',
            ])
            ->assertSessionHasNoErrors();

        $creditCheck->refresh();

        $this->assertSame(ShmCreditCheckStatus::Completed, $creditCheck->status);
        $this->assertSame(ShmCreditCheckOutcome::Fail, $creditCheck->outcome);
        $this->assertSame('Ditemukan ketidaksesuaian data', $creditCheck->result);
        $this->assertSame($this->marketing->id, $creditCheck->filled_by);
        $this->assertNotNull($creditCheck->checked_at);
        // Waktu selesai tidak boleh mendahului waktu mulai.
        $this->assertTrue($creditCheck->checked_at->greaterThanOrEqualTo($creditCheck->started_at));

        // Menyelesaikan credit checking BUKAN keputusan atas pengajuan.
        $application->refresh();
        $this->assertSame(ShmApplicationStatus::CreditChecking, $application->status);
        $this->assertNull($application->approved_at);
        $this->assertNull($application->rejected_at);
    }

    public function test_checker_and_timestamps_come_from_server_not_client(): void
    {
        [, $creditCheck] = $this->startedCreditCheckFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.credit-checks.complete', $creditCheck->id), [
                'outcome' => 'pass',
                'result' => 'Ringkasan',
                // Dikirim klien, harus diabaikan server.
                'filled_by' => $this->otherMarketing->id,
                'checked_at' => '2000-01-01 00:00:00',
                'status' => 'in_progress',
            ])
            ->assertSessionHasNoErrors();

        $creditCheck->refresh();

        $this->assertSame($this->marketing->id, $creditCheck->filled_by);
        $this->assertSame(ShmCreditCheckStatus::Completed, $creditCheck->status);
        $this->assertTrue($creditCheck->checked_at->year >= now()->year);
    }

    // -----------------------------------------------------------------
    // E. Hasil Marketing
    // -----------------------------------------------------------------

    public function test_hasil_marketing_lists_completed_checking(): void
    {
        [$application, $creditCheck] = $this->completedCreditCheckFor($this->marketing, 'review');

        $this->actingAs($this->marketing)
            ->get(route('shm.credit-checks.results'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/CreditChecks/Results')
                ->has('results.data', 1)
                ->where('results.data.0.id', $creditCheck->id)
                ->where('results.data.0.application_number', $application->application_number)
                ->where('results.data.0.customer_name', 'Nasabah Credit Checking')
                ->where('results.data.0.outcome', 'review')
                ->where('results.data.0.outcome_label', 'REVIEW')
                ->where('results.data.0.checker', $this->marketing->name)
            );
    }

    public function test_hasil_marketing_never_shows_unfinished_checking(): void
    {
        [$runningApplication] = $this->startedCreditCheckFor($this->marketing);
        $notStartedApplication = $this->submittedApplicationFor($this->marketing);
        [$completedApplication] = $this->completedCreditCheckFor($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.credit-checks.results'))
            ->assertOk()
            ->assertSee($completedApplication->application_number)
            ->assertDontSee($runningApplication->application_number)
            ->assertDontSee($notStartedApplication->application_number);
    }

    public function test_hasil_marketing_can_be_filtered_by_outcome(): void
    {
        [, $passed] = $this->completedCreditCheckFor($this->marketing, 'pass');
        [, $failed] = $this->completedCreditCheckFor($this->marketing, 'fail');

        $this->actingAs($this->marketing)
            ->get(route('shm.credit-checks.results', ['outcome' => 'fail']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('results.data', 1)
                ->where('results.data.0.id', $failed->id));

        $this->actingAs($this->marketing)
            ->get(route('shm.credit-checks.results', ['outcome' => 'pass']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('results.data', 1)
                ->where('results.data.0.id', $passed->id));
    }

    // -----------------------------------------------------------------
    // F. Halaman detail credit checking
    // -----------------------------------------------------------------

    public function test_credit_check_detail_page_renders(): void
    {
        [$application, $creditCheck] = $this->startedCreditCheckFor($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.credit-checks.show', $creditCheck->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/CreditChecks/Show')
                ->where('credit_check.id', $creditCheck->id)
                ->where('credit_check.status', 'in_progress')
                ->where('credit_check.status_label', 'Sedang Berjalan')
                ->where('credit_check.checker', $this->marketing->name)
                ->where('application.application_number', $application->application_number)
                ->where('customer.name', 'Nasabah Credit Checking')
                ->where('permissions.can_manage', true)
            );
    }

    // -----------------------------------------------------------------
    // G. Integrasi ke halaman pengajuan
    // -----------------------------------------------------------------

    public function test_application_detail_shows_credit_checking_status(): void
    {
        [$application] = $this->startedCreditCheckFor($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.applications.show', $application->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('credit_check.status', 'in_progress')
                ->where('credit_check.status_label', 'Sedang Berjalan')
                ->where('credit_check.checker', $this->marketing->name)
                ->where('permissions.can_manage_credit_check', true)
            );
    }

    public function test_application_detail_shows_completed_outcome(): void
    {
        [$application, $creditCheck] = $this->completedCreditCheckFor($this->marketing, 'review');

        $this->actingAs($this->marketing)
            ->get(route('shm.applications.show', $application->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('credit_check.id', $creditCheck->id)
                ->where('credit_check.status', 'completed')
                ->where('credit_check.outcome', 'review')
                ->where('credit_check.outcome_label', 'REVIEW')
                ->where('credit_check.checker', $this->marketing->name)
                ->whereNot('credit_check.completed_at', null)
            );
    }

    public function test_application_detail_does_not_create_credit_check_automatically(): void
    {
        $application = $this->submittedApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.applications.show', $application->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('credit_check', null));

        $this->assertSame(0, ShmCreditCheck::query()->count());
    }

    // -----------------------------------------------------------------
    // H. Otorisasi
    // -----------------------------------------------------------------

    public function test_pengawas_has_no_credit_checking_access(): void
    {
        [$application, $creditCheck] = $this->startedCreditCheckFor($this->marketing);

        $this->actingAs($this->pengawas)
            ->get(route('shm.credit-checks.index'))
            ->assertForbidden();

        $this->actingAs($this->pengawas)
            ->get(route('shm.credit-checks.results'))
            ->assertForbidden();

        $this->actingAs($this->pengawas)
            ->get(route('shm.credit-checks.show', $creditCheck->id))
            ->assertForbidden();

        $this->actingAs($this->pengawas)
            ->post(route('shm.applications.credit-checking.start', $application->id))
            ->assertForbidden();
    }

    public function test_marketing_cannot_open_other_marketing_credit_check(): void
    {
        [, $creditCheck] = $this->startedCreditCheckFor($this->marketing);

        $this->actingAs($this->otherMarketing)
            ->get(route('shm.credit-checks.show', $creditCheck->id))
            ->assertForbidden();

        $this->actingAs($this->otherMarketing)
            ->put(route('shm.credit-checks.update', $creditCheck->id), ['outcome' => 'pass'])
            ->assertForbidden();

        $this->actingAs($this->otherMarketing)
            ->post(route('shm.credit-checks.complete', $creditCheck->id), [
                'outcome' => 'pass',
                'result' => 'Menyelesaikan milik orang lain',
            ])
            ->assertForbidden();

        $this->assertSame(ShmCreditCheckStatus::InProgress, $creditCheck->refresh()->status);
    }

    public function test_admin_can_monitor_all_credit_checking(): void
    {
        [$application, $creditCheck] = $this->startedCreditCheckFor($this->marketing);

        $this->actingAs($this->admin)
            ->get(route('shm.credit-checks.index'))
            ->assertOk()
            ->assertSee($application->application_number);

        $this->actingAs($this->admin)
            ->get(route('shm.credit-checks.show', $creditCheck->id))
            ->assertOk();
    }

    public function test_superadmin_follows_existing_bypass(): void
    {
        $this->actingAs($this->superadmin)
            ->get(route('shm.credit-checks.index'))
            ->assertOk();

        $this->actingAs($this->superadmin)
            ->get(route('shm.credit-checks.results'))
            ->assertOk();
    }

    public function test_marketing_role_holds_credit_checking_permissions(): void
    {
        $this->assertTrue($this->marketing->hasPermission('shm.credit_checking.view'));
        $this->assertTrue($this->marketing->hasPermission('shm.credit_checking.update'));

        // Batas kewenangan lain tidak ikut terbuka.
        $this->assertFalse($this->marketing->hasPermission('shm.view_all'));
        $this->assertFalse($this->marketing->hasPermission('shm.approval.decide'));
        $this->assertFalse($this->marketing->hasPermission('shm.disburse'));
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        // Sejak Ticket 48 tamu yang membuka halaman SHM diarahkan ke pintu
        // masuk SHM, bukan ke halaman login koperasi.
        $this->get(route('shm.credit-checks.index'))->assertRedirect(route('shm.login'));
    }

    // -----------------------------------------------------------------
    // I. Audit: satu aksi = satu event
    // -----------------------------------------------------------------

    public function test_starting_is_audited_once(): void
    {
        $this->startedCreditCheckFor($this->marketing);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.credit_check.started')
            ->count());

        // Perpindahan tahap pengajuan punya event sendiri dan tidak menambah
        // event credit checking kedua.
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.application.status_changed')
            ->count());
    }

    public function test_update_is_audited_once(): void
    {
        [, $creditCheck] = $this->startedCreditCheckFor($this->marketing);

        $this->actingAs($this->marketing)
            ->put(route('shm.credit-checks.update', $creditCheck->id), ['outcome' => 'review']);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.credit_check.updated')
            ->count());
    }

    public function test_completion_is_audited_once(): void
    {
        $this->completedCreditCheckFor($this->marketing);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.credit_check.completed')
            ->count());

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.credit_check.started')
            ->count());
    }

    public function test_rejected_actions_write_no_audit_event(): void
    {
        [, $creditCheck] = $this->completedCreditCheckFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.credit-checks.complete', $creditCheck->id), [
                'outcome' => 'fail',
                'result' => 'Percobaan kedua',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.credit_check.completed')
            ->count());
    }

    // -----------------------------------------------------------------
    // J. Data safety & pemisahan dari anggota koperasi
    // -----------------------------------------------------------------

    public function test_customer_identity_comes_from_shm_customers(): void
    {
        $member = Member::factory()->create(['full_name' => 'Anggota Koperasi Bukan Nasabah']);

        [, $creditCheck] = $this->startedCreditCheckFor($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.credit-checks.show', $creditCheck->id))
            ->assertOk()
            ->assertSee('Nasabah Credit Checking')
            ->assertDontSee('Anggota Koperasi Bukan Nasabah')
            ->assertDontSee($member->member_number);
    }

    public function test_credit_check_table_has_no_member_or_financial_columns(): void
    {
        $this->assertTrue(Schema::hasTable('shm_credit_checks'));

        $this->assertFalse(
            Schema::hasColumn('shm_credit_checks', 'member_id'),
            'Credit checking SHM tidak boleh bergantung pada tabel members.'
        );

        foreach (['score', 'credit_score', 'amount', 'interest_rate', 'penalty'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('shm_credit_checks', $column),
                "shm_credit_checks tidak boleh punya kolom {$column}."
            );
        }
    }

    public function test_credit_checking_does_not_touch_member_data(): void
    {
        $member = Member::factory()->create(['full_name' => 'Anggota Koperasi Tetap']);
        $countBefore = Member::query()->count();

        $this->completedCreditCheckFor($this->marketing);

        $this->assertSame($countBefore, Member::query()->count());
        $this->assertSame('Anggota Koperasi Tetap', $member->refresh()->full_name);
    }

    public function test_credit_check_relation_to_application_and_customer_is_intact(): void
    {
        [$application, $creditCheck] = $this->completedCreditCheckFor($this->marketing);

        $this->assertSame($application->id, $creditCheck->application->id);
        $this->assertSame($this->customer->id, $creditCheck->application->customer->id);
        $this->assertSame($this->customer->customer_number, $creditCheck->application->customer->customer_number);
        $this->assertSame($this->marketing->id, $creditCheck->checker->id);
    }
}
