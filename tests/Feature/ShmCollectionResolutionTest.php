<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\ShmCollectionActivity;
use App\Models\ShmCollectionCase;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanPaymentAllocation;
use App\Models\User;
use App\Services\Shm\ShmCollectionCaseService;
use App\Services\Shm\ShmLoanPaymentAllocationService;
use App\Services\Shm\ShmLoanPaymentService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Ticket 95 — Collection payment visibility & resolution readiness.
 *
 * Mengunci: progres pembayaran & sisa tunggakan overdue SEMUA server-derived
 * dari agregat SQL tunggal yang memakai formula/predikat T90 (kewajiban →
 * alokasi → sisa), resolution_ready/can_resolve dihitung server (frontend
 * tidak pernah menghitung kesiapan), pembayaran TIDAK PERNAH mengresolusi
 * case otomatis (status tetap sampai aksi eksplisit), guard finansial di
 * ShmCollectionCaseService::transition() menolak RESOLVED selama sisa > 0
 * (input readiness/sisa dari frontend diabaikan), resolusi sukses = tepat
 * SATU audit status_changed existing (blocked = nol), otorisasi tetap
 * pemilik saja (shm.view_all tidak bypass), dan pemisahan keras dipertahankan
 * (payment/allocation/settlement/loan tidak diubah oleh Collection — GET
 * maupun resolusi). Aktivitas PAID/PROMISE_TO_PAY/UNABLE_TO_PAY tetap tidak
 * pernah mengubah status case.
 *
 * Tanpa PTP detail, assignment/escalation, permission baru, maupun migrasi.
 */
class ShmCollectionResolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketing;

    private User $marketing2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        // admin = pemegang shm.view_all (penonton non-pemilik); marketing =
        // pemilik case; marketing2 = Marketing non-pemilik.
        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->marketing2 = $this->userWithRole('marketing');
    }

    // ------------------------------------------------------------------
    // Fixtures (pola T92/T94 + payment/allocation engine T86-T88)
    // ------------------------------------------------------------------

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(
            Role::where('name', $roleName)->firstOrFail(),
        );

        return $user;
    }

    private function applicationOwnedBy(User $user): ShmLoanApplication
    {
        $customer = ShmCustomer::factory()->create();

        return ShmLoanApplication::query()->create([
            'application_number' => 'PENGAJ-PIN-'.Str::upper(Str::random(10)),
            'shm_customer_id' => $customer->id,
            'application_date' => now()->toDateString(),
            'principal' => '100000000',
            'tenor_months' => 12,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $user->id,
            'status' => 'approved',
        ]);
    }

    private function loanForApplication(ShmLoanApplication $application): ShmLoan
    {
        return ShmLoan::factory()->create([
            'shm_customer_id' => $application->shm_customer_id,
            'shm_loan_application_id' => $application->id,
            'principal' => $application->principal,
            'tenor_months' => $application->tenor_months,
            'first_due_date' => $application->first_due_date,
        ]);
    }

    /**
     * Buat baris jadwal angsuran persis seperti hasil ShmRepaymentScheduleService.
     *
     * @param  array<int, array{due_date: string, principal?: float|int|string, interest?: float|int|string}>  $specs
     */
    private function scheduleOf(ShmLoan $loan, array $specs): void
    {
        foreach ($specs as $index => $spec) {
            $principal = (string) ($spec['principal'] ?? 1_000_000);
            $interest = (string) ($spec['interest'] ?? 200_000);
            $total = (string) ((float) $principal + (float) $interest);

            $loan->installments()->create([
                'installment_number' => $index + 1,
                'due_date' => $spec['due_date'],
                'principal_amount' => $principal,
                'interest_amount' => $interest,
                'total_amount' => $total,
                'paid_amount' => 0,
                'paid_interest_amount' => 0,
                'paid_principal_amount' => 0,
                'remaining_amount' => $total,
                'status' => ShmLoanInstallment::STATUS_SCHEDULED,
            ]);
        }
    }

    /**
     * Collection Case aktif milik $owner dengan satu tunggakan 1.200.000
     * (pokok 1.000.000 + bunga 200.000) lewat service T92 — jalur resmi.
     *
     * @param  array<int, array{due_date: string, principal?: float|int|string, interest?: float|int|string}>|null  $scheduleSpecs
     */
    private function caseOf(User $owner, ?array $scheduleSpecs = null): ShmCollectionCase
    {
        $application = $this->applicationOwnedBy($owner);
        $loan = $this->loanForApplication($application);

        $this->scheduleOf($loan, $scheduleSpecs ?? [
            ['due_date' => today()->subDays(30)->toDateString()],
        ]);

        return app(ShmCollectionCaseService::class)->createOrGetForLoan($loan, (int) $owner->id);
    }

    /**
     * Bayar + alokasi memakai engine finansial ASLI (bukan update kolom
     * langsung) sehingga paid split sama dengan produksi.
     */
    private function payAndAllocate(ShmLoan $loan, float $amount, User $as): ShmLoanPayment
    {
        $payment = app(ShmLoanPaymentService::class)->create($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
        ], (int) $as->id);

        app(ShmLoanPaymentAllocationService::class)->allocate($payment);

        return $payment;
    }

    private function service(): ShmCollectionCaseService
    {
        return app(ShmCollectionCaseService::class);
    }

    /**
     * Bawa case ke status via jalur transisi valid; sebelum RESOLVED seluruh
     * tunggakan dilunasi lebih dulu (guard finansial T95).
     */
    private function walkTo(ShmCollectionCase $case, string $status): ShmCollectionCase
    {
        $walk = match ($status) {
            ShmCollectionCase::STATUS_OPEN => [],
            ShmCollectionCase::STATUS_IN_PROGRESS => [ShmCollectionCase::STATUS_IN_PROGRESS],
            ShmCollectionCase::STATUS_PROMISE_TO_PAY => [
                ShmCollectionCase::STATUS_IN_PROGRESS,
                ShmCollectionCase::STATUS_PROMISE_TO_PAY,
            ],
            ShmCollectionCase::STATUS_ESCALATED => [
                ShmCollectionCase::STATUS_IN_PROGRESS,
                ShmCollectionCase::STATUS_ESCALATED,
            ],
            ShmCollectionCase::STATUS_RESOLVED => [
                ShmCollectionCase::STATUS_IN_PROGRESS,
                ShmCollectionCase::STATUS_RESOLVED,
            ],
        };

        foreach ($walk as $toStatusStep) {
            if ($toStatusStep === ShmCollectionCase::STATUS_RESOLVED) {
                $this->settleOverdue($case);
            }

            $case = $this->service()->transition($case, $toStatusStep, (int) $this->marketing->id);
        }

        return $case;
    }

    /**
     * Lunasi seluruh tunggakan overdue pinjaman case (prasyarat guard T95).
     */
    private function settleOverdue(ShmCollectionCase $case): void
    {
        $outstanding = (float) $case->loan->installments()
            ->get()
            ->sum(fn (ShmLoanInstallment $installment): float => ((float) $installment->principal_amount - (float) $installment->paid_principal_amount)
                + ((float) $installment->interest_amount - (float) $installment->paid_interest_amount));

        if ($outstanding <= 0) {
            return;
        }

        $this->payAndAllocate($case->loan, round($outstanding, 2), $this->admin);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postTransition(ShmCollectionCase $case, User $as, array $payload): TestResponse
    {
        return $this->actingAs($as)->post(
            route('shm.collection.cases.transition', $case),
            $payload,
        );
    }

    private function postActivity(ShmCollectionCase $case, User $as, string $result): TestResponse
    {
        return $this->actingAs($as)->post(
            route('shm.collection.activities.store', $case),
            [
                'activity_type' => ShmCollectionActivity::TYPE_PHONE_CALL,
                'result' => $result,
                'activity_at' => now()->subDay()->startOfMinute()->format('Y-m-d H:i:s'),
            ],
        );
    }

    private function statusChangeAuditCount(ShmCollectionCase $case): int
    {
        return AuditLog::query()
            ->where('action', 'shm.collection.case.status_changed')
            ->where('object_type', ShmCollectionCase::class)
            ->where('object_id', $case->id)
            ->count();
    }

    private function getDetail(ShmCollectionCase $case, User $as): TestResponse
    {
        return $this->actingAs($as)->get(route('shm.collection.show', $case->shm_loan_id));
    }

    // ------------------------------------------------------------------
    // A. Payment visibility (progres + riwayat dari data tersimpan)
    // ------------------------------------------------------------------

    public function test_detail_page_exposes_payment_progress_and_history_after_partial_payment(): void
    {
        $case = $this->caseOf($this->marketing);
        $payment = $this->payAndAllocate($case->loan, 500_000, $this->admin);

        $this->getDetail($case, $this->marketing)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Shm/Collection/Show')
                ->where('collection.payment_progress.overdue_principal', 1000000)
                ->where('collection.payment_progress.overdue_interest', 200000)
                ->where('collection.payment_progress.overdue_total', 1200000)
                ->where('collection.payment_progress.allocated_interest', 200000)
                ->where('collection.payment_progress.allocated_principal', 300000)
                ->where('collection.payment_progress.allocated_total', 500000)
                ->where('collection.payment_progress.remaining_overdue', 700000)
                ->where('collection.payment_progress.latest_payment.payment_date', today()->toDateString())
                ->where('collection.payment_progress.latest_payment.payment_reference', $payment->payment_reference)
                ->has('collection.payments', 1)
                ->where('collection.payments.0.payment_reference', $payment->payment_reference)
                ->where('collection.payments.0.allocated_total', 500000)
                ->where('collection.payments.0.unallocated', 0));
    }

    public function test_payment_history_distinguishes_allocated_and_unallocated_payments(): void
    {
        $case = $this->caseOf($this->marketing);
        $allocated = $this->payAndAllocate($case->loan, 500_000, $this->admin);

        // Pembayaran kedua TIDAK dialokasikan — nilai alokasi/tidak terpakai
        // tetap terbaca apa adanya dari baris payment tersimpan.
        $unallocated = app(ShmLoanPaymentService::class)->create($case->loan, [
            'payment_date' => now()->toDateString(),
            'amount' => 300_000,
        ], (int) $this->admin->id);

        // Relasi payments = payment_date desc, id desc → pembayaran kedua
        // (belum dialokasikan) tampil paling atas.
        $this->getDetail($case, $this->marketing)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Shm/Collection/Show')
                ->has('collection.payments', 2)
                ->where('collection.payments.0.payment_reference', $unallocated->payment_reference)
                ->where('collection.payments.0.allocated_total', 0)
                ->where('collection.payments.0.unallocated', 300000)
                ->where('collection.payments.1.payment_reference', $allocated->payment_reference)
                ->where('collection.payments.1.allocated_total', 500000)
                ->where('collection.payments.1.unallocated', 0)
                ->where('collection.payment_progress.latest_payment.payment_reference', $unallocated->payment_reference)
                ->where('collection.payment_progress.remaining_overdue', 700000));
    }

    // ------------------------------------------------------------------
    // B. Remaining overdue (server-derived, tanpa pembayaran / parsial /
    //    penuh / banyak pembayaran / banyak angsuran overdue)
    // ------------------------------------------------------------------

    public function test_remaining_overdue_is_full_obligation_without_payment(): void
    {
        $case = $this->caseOf($this->marketing);

        $this->getDetail($case, $this->marketing)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Shm/Collection/Show')
                ->where('collection.payment_progress.overdue_total', 1200000)
                ->where('collection.payment_progress.allocated_total', 0)
                ->where('collection.payment_progress.remaining_overdue', 1200000)
                // Konsistensi: sisa progres = ringkasan tunggakan T90 (satu
                // formula/predikat — tidak ada sumber angka kedua).
                ->where('collection.overdue.total_outstanding', 1200000)
                ->where('collection_case.remaining_overdue', 1200000));
    }

    public function test_remaining_overdue_decreases_with_partial_payment(): void
    {
        $case = $this->caseOf($this->marketing);
        $this->payAndAllocate($case->loan, 500_000, $this->admin);

        $this->getDetail($case, $this->marketing)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Shm/Collection/Show')
                ->where('collection.payment_progress.remaining_overdue', 700000)
                // Identitas: kewajiban − teralokasi = sisa.
                ->where('collection.payment_progress.overdue_total', 1200000)
                ->where('collection.payment_progress.allocated_total', 500000)
                ->where('collection.overdue.total_outstanding', 700000));
    }

    public function test_remaining_overdue_is_zero_after_full_overdue_payment(): void
    {
        $case = $this->caseOf($this->marketing);
        $this->payAndAllocate($case->loan, 1_200_000, $this->admin);

        $this->getDetail($case, $this->marketing)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Shm/Collection/Show')
                ->where('collection.payment_progress.remaining_overdue', 0)
                // Riwayat kewajiban/alokasi tetap terekam meski sisa 0.
                ->where('collection.payment_progress.overdue_total', 1200000)
                ->where('collection.payment_progress.allocated_total', 1200000)
                ->where('collection.overdue.total_outstanding', 0));
    }

    public function test_remaining_overdue_sums_multiple_overdue_installments_and_payments(): void
    {
        $case = $this->caseOf($this->marketing, [
            ['due_date' => today()->subDays(30)->toDateString()],
            ['due_date' => today()->subDays(10)->toDateString()],
        ]);

        $this->payAndAllocate($case->loan, 700_000, $this->admin);
        $this->payAndAllocate($case->loan, 500_000, $this->admin);

        $this->getDetail($case, $this->marketing)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Shm/Collection/Show')
                ->where('collection.payment_progress.overdue_total', 2400000)
                ->where('collection.payment_progress.allocated_total', 1200000)
                ->where('collection.payment_progress.remaining_overdue', 1200000)
                ->where('collection.overdue.installments_count', 1)
                ->where('collection.overdue.total_outstanding', 1200000));
    }

    // ------------------------------------------------------------------
    // C. Resolution readiness (server-derived; future-only tidak menghalangi;
    //    case RESOLVED bukan aksi readiness)
    // ------------------------------------------------------------------

    public function test_resolution_ready_is_false_and_not_actionable_while_overdue_remains(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_IN_PROGRESS);

        $this->getDetail($case, $this->marketing)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Shm/Collection/Show')
                ->where('collection_case.status', ShmCollectionCase::STATUS_IN_PROGRESS)
                ->where('collection_case.resolution_ready', false)
                ->where('collection_case.can_resolve', false)
                ->where('collection_case.remaining_overdue', 1200000));
    }

    public function test_full_payment_makes_resolution_ready_without_auto_resolving_case(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_IN_PROGRESS);
        $auditsBefore = $this->statusChangeAuditCount($case);
        $this->payAndAllocate($case->loan, 1_200_000, $this->admin);

        $this->getDetail($case, $this->marketing)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Shm/Collection/Show')
                ->where('collection_case.resolution_ready', true)
                ->where('collection_case.can_resolve', true)
                ->where('collection_case.remaining_overdue', 0)
                // JAMINAN: readiness ≠ resolusi — status & resolved_at tidak
                // berubah sampai aksi eksplisit.
                ->where('collection_case.status', ShmCollectionCase::STATUS_IN_PROGRESS)
                ->where('collection_case.resolved_at', null));

        $case->refresh();
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->status);
        $this->assertNull($case->resolved_at);
        // Pembayaran & GET tidak menambah audit status_changed (hanya audit
        // transisi setup OPEN → IN_PROGRESS yang sudah ada).
        $this->assertSame($auditsBefore, $this->statusChangeAuditCount($case));
    }

    public function test_future_obligations_do_not_block_resolution_readiness(): void
    {
        $case = $this->caseOf($this->marketing, [
            ['due_date' => today()->subDays(30)->toDateString()],
            ['due_date' => today()->addDays(30)->toDateString()],
        ]);
        // Lunasi TUNGGAKAN (baris lewat tempo) saja — angsuran masa depan
        // sengaja tidak dibayar.
        $this->payAndAllocate($case->loan, 1_200_000, $this->admin);

        // Pinjaman sudah bukan kandidat T90 (tunggakan lunas) tetapi halaman
        // tetap terbuka karena case masih ada (guard detail T95).
        $this->getDetail($case, $this->marketing)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Shm/Collection/Show')
                ->where('collection.payment_progress.remaining_overdue', 0)
                ->where('collection_case.resolution_ready', true)
                ->where('collection_case.can_resolve', true));
    }

    public function test_resolved_case_is_never_resolution_ready(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_RESOLVED);

        $this->getDetail($case, $this->marketing)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Shm/Collection/Show')
                ->where('collection_case.status', ShmCollectionCase::STATUS_RESOLVED)
                ->where('collection_case.resolution_ready', false)
                ->where('collection_case.can_resolve', false)
                ->where('collection_case.allowed_transitions', []));
    }

    // ------------------------------------------------------------------
    // D. Guard resolusi (backend otoritatif; spoof frontend diabaikan)
    // ------------------------------------------------------------------

    public function test_resolved_transition_is_rejected_while_overdue_remains(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_IN_PROGRESS);
        $auditsBefore = $this->statusChangeAuditCount($case);

        $response = $this->postTransition($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_RESOLVED,
        ]);

        $response->assertSessionHasErrors('collection.resolusi');
        $case->refresh();
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->status);
        $this->assertNull($case->resolved_at);
        $this->assertSame($auditsBefore, $this->statusChangeAuditCount($case));
    }

    public function test_resolved_transition_succeeds_after_full_payment_and_ignores_spoofed_readiness_fields(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_IN_PROGRESS);
        $auditsBefore = $this->statusChangeAuditCount($case);
        $this->payAndAllocate($case->loan, 1_200_000, $this->admin);

        // Field readiness/sisa dari frontend TIDAK diterima: meski dikirim
        // nilai yang menutupi (remaining 999999999 / ready false), keputusan
        // tetap milik server (hitung ulang → sisa 0 → lolos).
        $response = $this->postTransition($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_RESOLVED,
            'resolution_ready' => false,
            'remaining_overdue' => 999999999,
            'outstanding_amount' => 999999999,
            'paid_amount' => 999999999,
            'payment_reference' => 'SPOOF-REF',
        ]);

        $response
            ->assertRedirect(route('shm.collection.show', $case->shm_loan_id))
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(ShmCollectionCase::STATUS_RESOLVED, $case->status);
        $this->assertNotNull($case->resolved_at);
        $this->assertTrue($case->resolved_at->isToday());

        // Tepat SATU audit status_changed BARU (resolusi) di atas audit setup
        // OPEN → IN_PROGRESS yang sudah ada — event & field tetap yang sama.
        $this->assertSame($auditsBefore + 1, $this->statusChangeAuditCount($case));

        $log = AuditLog::query()
            ->where('action', 'shm.collection.case.status_changed')
            ->where('object_id', $case->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $log->changes['from_status']);
        $this->assertSame(ShmCollectionCase::STATUS_RESOLVED, $log->changes['to_status']);
        $this->assertSame((int) $this->marketing->id, $log->changes['actor_id']);
        $this->assertSame((int) $this->marketing->id, $log->actor_id);
        $this->assertSame($case->shm_loan_id, $log->changes['shm_loan_id']);
        $this->assertArrayNotHasKey('resolution_ready', $log->changes);
        $this->assertArrayNotHasKey('remaining_overdue', $log->changes);
    }

    public function test_frontend_cannot_spoof_readiness_to_bypass_financial_guard(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_IN_PROGRESS);
        $auditsBefore = $this->statusChangeAuditCount($case);

        // Sisa masih 1.200.000, tetapi frontend mengaku ready + sisa 0.
        $response = $this->postTransition($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_RESOLVED,
            'resolution_ready' => true,
            'remaining_overdue' => 0,
        ]);

        $response->assertSessionHasErrors('collection.resolusi');
        $case->refresh();
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->status);
        $this->assertNull($case->resolved_at);
        $this->assertSame($auditsBefore, $this->statusChangeAuditCount($case));
    }

    // ------------------------------------------------------------------
    // E. Otorisasi (pemilik saja; shm.view_all tidak bypass)
    // ------------------------------------------------------------------

    public function test_owner_cannot_resolve_when_not_ready(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_IN_PROGRESS);

        // Pemilik lolos otorisasi (bukan error otorisasi) tetapi DITOLAK
        // guard finansial.
        $this->postTransition($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_RESOLVED,
        ])->assertSessionHasErrors('collection.resolusi');

        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->refresh()->status);
    }

    public function test_non_owner_marketing_cannot_resolve_even_when_ready(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_IN_PROGRESS);
        $this->payAndAllocate($case->loan, 1_200_000, $this->admin);
        $auditsBefore = $this->statusChangeAuditCount($case);

        $this->postTransition($case, $this->marketing2, [
            'status' => ShmCollectionCase::STATUS_RESOLVED,
        ])->assertSessionHasErrors('collection.otorisasi');

        $case->refresh();
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->status);
        $this->assertNull($case->resolved_at);
        $this->assertSame($auditsBefore, $this->statusChangeAuditCount($case));
    }

    public function test_view_all_admin_cannot_resolve_even_when_ready(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_IN_PROGRESS);
        $this->payAndAllocate($case->loan, 1_200_000, $this->admin);
        $auditsBefore = $this->statusChangeAuditCount($case);

        $this->postTransition($case, $this->admin, [
            'status' => ShmCollectionCase::STATUS_RESOLVED,
        ])->assertSessionHasErrors('collection.otorisasi');

        $case->refresh();
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->status);
        $this->assertSame($auditsBefore, $this->statusChangeAuditCount($case));
    }

    public function test_non_owner_viewer_sees_financial_readiness_without_resolution_actions(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_IN_PROGRESS);
        $this->payAndAllocate($case->loan, 1_200_000, $this->admin);

        // admin (shm.view_all) melihat fakta finansial (readiness) tetapi
        // tanpa aksi: allowed_transitions & can_resolve tetap kosong/false.
        $this->getDetail($case, $this->admin)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Shm/Collection/Show')
                ->where('collection_case.can_record', false)
                ->where('collection_case.allowed_transitions', [])
                ->where('collection_case.can_resolve', false)
                ->where('collection_case.resolution_ready', true)
                ->where('collection_case.remaining_overdue', 0));
    }

    // ------------------------------------------------------------------
    // F. Side effects (payment/allocation/settlement/loan tak tersentuh;
    //    tanpa resolusi otomatis)
    // ------------------------------------------------------------------

    public function test_collection_get_requests_do_not_mutate_financial_or_case_state(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_IN_PROGRESS);
        $this->payAndAllocate($case->loan, 500_000, $this->admin);

        $paymentsBefore = ShmLoanPayment::query()->count();
        $allocationsBefore = ShmLoanPaymentAllocation::query()->count();
        $settlementsBefore = DB::table('shm_loan_settlements')->count();
        $auditsBefore = AuditLog::query()->count();
        $paidBefore = [
            'principal' => (float) $case->loan->installments()->sum('paid_principal_amount'),
            'interest' => (float) $case->loan->installments()->sum('paid_interest_amount'),
        ];
        $caseUpdatedAtBefore = $case->refresh()->updated_at;

        $this->actingAs($this->marketing)->get(route('shm.collection.index'))->assertOk();
        $this->getDetail($case, $this->marketing)->assertOk();

        $this->assertSame($paymentsBefore, ShmLoanPayment::query()->count());
        $this->assertSame($allocationsBefore, ShmLoanPaymentAllocation::query()->count());
        $this->assertSame($settlementsBefore, DB::table('shm_loan_settlements')->count());
        $this->assertSame($auditsBefore, AuditLog::query()->count());
        $this->assertSame($paidBefore['principal'], (float) $case->loan->installments()->sum('paid_principal_amount'));
        $this->assertSame($paidBefore['interest'], (float) $case->loan->installments()->sum('paid_interest_amount'));
        $this->assertTrue($caseUpdatedAtBefore->equalTo($case->refresh()->updated_at));
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $case->loan->refresh()->status);
    }

    public function test_payment_flow_reaches_resolution_ready_then_explicit_resolve(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_IN_PROGRESS);

        // 1–2. Pembayaran parsial + dialokasikan → sisa berkurang, belum siap.
        $this->payAndAllocate($case->loan, 500_000, $this->admin);
        $this->getDetail($case, $this->marketing)->assertInertia(fn (Assert $page): Assert => $page
            ->where('collection.payment_progress.remaining_overdue', 700000)
            ->where('collection_case.resolution_ready', false));

        // 3. Pelunasan tunggakan → sisa 0.
        $this->payAndAllocate($case->loan, 700_000, $this->admin);
        $this->getDetail($case, $this->marketing)->assertInertia(fn (Assert $page): Assert => $page
            ->where('collection.payment_progress.remaining_overdue', 0)
            ->where('collection_case.resolution_ready', true)
            ->where('collection_case.status', ShmCollectionCase::STATUS_IN_PROGRESS));

        // 4–5. Case TIDAK otomatis RESOLVED; readiness menjadi true.
        $case->refresh();
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->status);
        $this->assertNull($case->resolved_at);

        // 6–7. Aksi eksplisit pemilik → RESOLVED.
        $this->postTransition($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_RESOLVED,
        ])->assertSessionHas('success');

        $this->assertSame(ShmCollectionCase::STATUS_RESOLVED, $case->refresh()->status);
        $this->assertSame(2, ShmLoanPayment::query()->count());
    }

    public function test_resolution_leaves_loan_active_settlement_and_payments_untouched(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_IN_PROGRESS);
        $this->payAndAllocate($case->loan, 1_200_000, $this->admin);

        $this->postTransition($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_RESOLVED,
        ])->assertSessionHas('success');

        $case->loan->refresh();
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $case->loan->status);
        $this->assertDatabaseCount('shm_loan_settlements', 0);
        $this->assertDatabaseCount('shm_loan_payments', 1);
        $this->assertSame(1_000_000.0, (float) $case->loan->installments()->sum('paid_principal_amount'));
        $this->assertSame(200_000.0, (float) $case->loan->installments()->sum('paid_interest_amount'));
    }

    // ------------------------------------------------------------------
    // G. Independensi aktivitas (PAID/PTP/UNABLE tidak pernah auto)
    // ------------------------------------------------------------------

    public function test_paid_activity_does_not_resolve_ready_case(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_IN_PROGRESS);
        $auditsBefore = $this->statusChangeAuditCount($case);
        $this->payAndAllocate($case->loan, 1_200_000, $this->admin);

        $this->postActivity($case, $this->marketing, ShmCollectionActivity::RESULT_PAID)
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->status);
        $this->assertNull($case->resolved_at);
        $this->assertSame($auditsBefore, $this->statusChangeAuditCount($case));

        // Readiness tetap flag UI — bukan status: case menunggu aksi eksplisit.
        $this->getDetail($case, $this->marketing)->assertInertia(fn (Assert $page): Assert => $page
            ->where('collection_case.resolution_ready', true)
            ->where('collection_case.status', ShmCollectionCase::STATUS_IN_PROGRESS));
    }

    public function test_promise_to_pay_activity_does_not_change_status(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_IN_PROGRESS);

        $this->postActivity($case, $this->marketing, ShmCollectionActivity::RESULT_PROMISE_TO_PAY)
            ->assertSessionHas('success');

        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->refresh()->status);
    }

    public function test_unable_to_pay_activity_does_not_escalate_case(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_IN_PROGRESS);

        $this->postActivity($case, $this->marketing, ShmCollectionActivity::RESULT_UNABLE_TO_PAY)
            ->assertSessionHas('success');

        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->refresh()->status);
    }

    // ------------------------------------------------------------------
    // H. Audit — sukses = tepat satu status_changed; gagal = nol (covered
    //    bersama guard di bagian D)
    // ------------------------------------------------------------------

    public function test_blocked_resolution_writes_no_status_changed_audit(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_IN_PROGRESS);
        $auditsBefore = $this->statusChangeAuditCount($case);

        $this->postTransition($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_RESOLVED,
        ])->assertSessionHasErrors('collection.resolusi');

        $this->assertSame($auditsBefore, $this->statusChangeAuditCount($case));
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->refresh()->status);
    }

    // ------------------------------------------------------------------
    // Akses halaman detail (guard T95: kandidat ATAU punya case)
    // ------------------------------------------------------------------

    public function test_detail_page_stays_accessible_after_overdue_paid_while_case_exists(): void
    {
        $case = $this->caseOf($this->marketing);
        $this->payAndAllocate($case->loan, 1_200_000, $this->admin);

        // Bukan kandidat T90 lagi — halaman tetap terbuka karena case ada
        // (alur resolusi harus bisa dijangkau pemilik).
        $this->getDetail($case, $this->marketing)->assertOk();

        // Tapi pinjaman TANPA case dan tanpa tunggakan tetap 404 (T90).
        $future = ShmLoan::factory()->create();
        $future->installments()->create([
            'installment_number' => 1,
            'due_date' => today()->addDays(30)->toDateString(),
            'principal_amount' => 1_000_000,
            'interest_amount' => 200_000,
            'total_amount' => 1_200_000,
            'paid_amount' => 0,
            'paid_interest_amount' => 0,
            'paid_principal_amount' => 0,
            'remaining_amount' => 1_200_000,
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        $this->actingAs($this->admin)
            ->get(route('shm.collection.show', $future))
            ->assertNotFound();
    }
}
