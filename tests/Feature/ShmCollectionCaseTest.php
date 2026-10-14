<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\ShmCollectionCase;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\User;
use App\Services\Shm\ShmCollectionCaseService;
use App\Services\Shm\ShmLoanPaymentAllocationService;
use App\Services\Shm\ShmLoanPaymentService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * Ticket 92 — Collection Case persistence & lifecycle workflow.
 *
 * Mengunci: pembuatan case lewat domain service (kelayakan identik T90),
 * initial status OPEN + opened_at, initial owner = Marketing dari pengajuan
 * asal (TANPA fallback user random/auto-create), SATU active case per pinjaman
 * (idempotensi service + partial unique index), tabel transisi persis baseline
 * T92/constract T91, RESOLVED permanen tanpa reopening, resolved_at hanya
 * terisi pada RESOLVED, otorisasi mutation = pemilik case saja (tanpa fallback
 * admin/shm.view_all), audit creation & transisi transactional, rollback
 * tanpa parsial, serta pemisahan keras dari status pinjaman/payment/settlement.
 *
 * Tidak ada pengujian detail Promise-to-Pay, escalation authority BM/PD,
 * penalty, notification, cadence, maupun assignment — semuanya masih PENDING
 * dan sengaja tidak diimplementasikan di T92.
 *
 * Sejak Ticket 95 transisi → RESOLVED dijaga guard finansial (sisa tunggakan
 * overdue harus 0 — contract T91 Rule 10). Fixture jalur RESOLVED karenanya
 * melunasi tunggakan lebih dulu lewat payment/allocation engine ASLI;
 * perilaku yang dikunci T92 (graph, terminal, resolved_at, audit,
 * otorisasi, pemisahan domain finansial) tetap diuji tanpa perubahan arti.
 */
class ShmCollectionCaseTest extends TestCase
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

        // admin = pemegang shm.view_all; marketing tanpa view_all. Keduanya
        // tidak otomatis berhak memutasi case yang bukan miliknya.
        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->marketing2 = $this->userWithRole('marketing');
    }

    // ------------------------------------------------------------------
    // Fixtures (identik dengan T90 — kelayakan diuji dengan predikat sama)
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
     * Pinjaman aktif + sudah cair milik $owner dengan satu angsuran tunggakan.
     */
    private function overdueLoanOf(User $owner, int $daysOverdue = 30): ShmLoan
    {
        $application = $this->applicationOwnedBy($owner);
        $loan = $this->loanForApplication($application);

        $this->scheduleOf($loan, [
            ['due_date' => today()->subDays($daysOverdue)->toDateString()],
        ]);

        return $loan;
    }

    /**
     * Pinjaman tunggakan tanpa pengajuan asal — TIDAK memiliki Marketing
     * owner (dipakai fixture uji tanpa fallback).
     */
    private function plainOverdueLoan(int $daysOverdue = 30): ShmLoan
    {
        $loan = ShmLoan::factory()->create();

        $this->scheduleOf($loan, [
            ['due_date' => today()->subDays($daysOverdue)->toDateString()],
        ]);

        return $loan;
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

    private function paymentFor(ShmLoan $loan, float $amount, User $as): ShmLoanPayment
    {
        return app(ShmLoanPaymentService::class)->create($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
        ], (int) $as->id);
    }

    private function allocate(ShmLoanPayment $payment): void
    {
        app(ShmLoanPaymentAllocationService::class)->allocate($payment);
    }

    /**
     * Lunasi seluruh tunggakan pinjaman case lewat engine pembayaran ASLI —
     * prasyarat guard finansial T95 untuk transisi → RESOLVED.
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

        $this->allocate($this->paymentFor($case->loan, round($outstanding, 2), $this->admin));
    }

    // ------------------------------------------------------------------
    // Helpers layanan
    // ------------------------------------------------------------------

    private function service(): ShmCollectionCaseService
    {
        return app(ShmCollectionCaseService::class);
    }

    private function createCaseFor(ShmLoan $loan, User $actor): ShmCollectionCase
    {
        $this->actingAs($actor);

        return $this->service()->createOrGetForLoan($loan, (int) $actor->id);
    }

    /**
     * Case baru milik $owner (pinjaman tunggakan yang dimilikinya).
     */
    private function openCaseOf(User $owner): ShmCollectionCase
    {
        return $this->createCaseFor($this->overdueLoanOf($owner), $owner);
    }

    private function transitionTo(ShmCollectionCase $case, string $toStatus, ?User $actor = null, ?string $reason = null): ShmCollectionCase
    {
        $actor ??= $this->marketing;
        $this->actingAs($actor);

        return $this->service()->transition($case, $toStatus, (int) $actor->id, $reason);
    }

    /**
     * Case yang sudah dibawa ke $status melalui jalur transisi valid.
     */
    private function caseInStatus(string $status): ShmCollectionCase
    {
        $case = $this->openCaseOf($this->marketing);

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
                // Guard finansial T95 — tunggakan dilunasi sebelum resolusi.
                $this->settleOverdue($case);
            }

            $case = $this->transitionTo($case, $toStatusStep);
        }

        return $case;
    }

    /**
     * Pastikan operasi DITOLAK ValidationException dengan kunci yang
     * diharapkan — kegagalan guard melempar AssertionFailedError, bukan lolos.
     *
     * @param  callable(): mixed  $operation
     */
    private function assertRejected(callable $operation, string $errorKey): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());

            return;
        }

        $this->fail(sprintf('Diharapkan ValidationException dengan kunci "%s".', $errorKey));
    }

    /**
     * Transisi ditolak DAN status tersimpan tidak berubah.
     */
    private function assertTransitionRejected(ShmCollectionCase $case, string $toStatus): void
    {
        $before = $case->refresh()->status;

        $this->assertRejected(
            fn () => $this->transitionTo($case, $toStatus),
            'collection.status',
        );

        $this->assertSame(
            $before,
            $case->refresh()->status,
            sprintf('Status berubah walau transisi ditolak: %s → %s.', $before, $toStatus),
        );
    }

    // ------------------------------------------------------------------
    // A. Creation & eligibility (predikat identik T90)
    // ------------------------------------------------------------------

    public function test_eligible_overdue_loan_creates_collection_case(): void
    {
        $loan = $this->overdueLoanOf($this->marketing);
        $marketingId = $loan->application->marketing_id;

        $case = $this->createCaseFor($loan, $this->marketing);

        $this->assertModelExists($case);
        $this->assertSame($loan->id, $case->shm_loan_id);
        $this->assertSame($marketingId, $case->marketing_id);
        $this->assertSame((int) $this->marketing->id, $case->created_by);
        $this->assertDatabaseCount('shm_collection_cases', 1);
    }

    public function test_new_case_starts_open_with_opened_at_and_null_resolved_at(): void
    {
        $case = $this->openCaseOf($this->marketing);

        $this->assertSame(ShmCollectionCase::STATUS_OPEN, $case->status);
        $this->assertNotNull($case->opened_at);
        $this->assertNull($case->resolved_at);
        $this->assertTrue($case->isActive());
    }

    public function test_initial_owner_is_marketing_of_originating_application(): void
    {
        $loan = $this->overdueLoanOf($this->marketing);

        $case = $this->createCaseFor($loan, $this->marketing);

        $this->assertSame((int) $this->marketing->id, $case->marketing_id);
        $this->assertTrue($case->marketing->is($this->marketing));
        $this->assertTrue($case->loan->is($loan));
    }

    public function test_loan_without_installment_schedule_is_rejected(): void
    {
        $loan = ShmLoan::factory()->create();

        $this->assertRejected(
            fn () => $this->createCaseFor($loan, $this->marketing),
            'collection.kelayakan',
        );

        $this->assertDatabaseCount('shm_collection_cases', 0);
    }

    public function test_closed_loan_is_rejected(): void
    {
        $loan = ShmLoan::factory()->closed()->create();
        $this->scheduleOf($loan, [
            ['due_date' => today()->subDays(30)->toDateString()],
        ]);

        $this->assertRejected(
            fn () => $this->createCaseFor($loan, $this->marketing),
            'collection.kelayakan',
        );

        $this->assertDatabaseCount('shm_collection_cases', 0);
    }

    public function test_undisbursed_loan_is_rejected(): void
    {
        $loan = ShmLoan::factory()->create(['disbursed_at' => null]);
        $this->scheduleOf($loan, [
            ['due_date' => today()->subDays(30)->toDateString()],
        ]);

        $this->assertRejected(
            fn () => $this->createCaseFor($loan, $this->marketing),
            'collection.kelayakan',
        );

        $this->assertDatabaseCount('shm_collection_cases', 0);
    }

    public function test_future_only_installment_loan_is_rejected(): void
    {
        $loan = ShmLoan::factory()->create();
        $this->scheduleOf($loan, [
            ['due_date' => today()->addDays(30)->toDateString()],
        ]);

        $this->assertRejected(
            fn () => $this->createCaseFor($loan, $this->marketing),
            'collection.kelayakan',
        );

        $this->assertDatabaseCount('shm_collection_cases', 0);
    }

    public function test_fully_paid_overdue_loan_is_rejected(): void
    {
        $loan = $this->plainOverdueLoan(daysOverdue: 20);
        // Total angsuran 1.200.000 — pembayaran penuh melunasi tunggakan.
        $this->allocate($this->paymentFor($loan, 1_200_000, $this->admin));

        $this->assertRejected(
            fn () => $this->createCaseFor($loan, $this->marketing),
            'collection.kelayakan',
        );

        $this->assertDatabaseCount('shm_collection_cases', 0);
    }

    // ------------------------------------------------------------------
    // B. Idempotensi & unique active case
    // ------------------------------------------------------------------

    public function test_second_create_request_reuses_existing_active_case(): void
    {
        $loan = $this->overdueLoanOf($this->marketing);
        $first = $this->createCaseFor($loan, $this->marketing);
        $second = $this->createCaseFor($loan, $this->marketing);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('shm_collection_cases', 1);
    }

    public function test_database_blocks_second_active_case_for_same_loan(): void
    {
        $case = $this->openCaseOf($this->marketing);

        try {
            ShmCollectionCase::query()->create([
                'shm_loan_id' => $case->shm_loan_id,
                'marketing_id' => $case->marketing_id,
                'status' => ShmCollectionCase::STATUS_IN_PROGRESS,
                'opened_at' => now(),
                'resolved_at' => null,
                'created_by' => $case->created_by,
            ]);
            $this->fail('Duplikat active case untuk pinjaman sama seharusnya ditolak unique index.');
        } catch (QueryException) {
            // Partial unique index shm_collection_cases_active_unique.
        }

        $this->assertDatabaseCount('shm_collection_cases', 1);
    }

    public function test_resolved_case_does_not_block_new_case_when_still_eligible(): void
    {
        $loan = $this->overdueLoanOf($this->marketing);
        $first = $this->createCaseFor($loan, $this->marketing);

        // Guard finansial T95: resolusi hanya bila tunggakan lunas. Lunasi
        // lebih dulu, lalu jadikan angsuran BERIKUTNYA jatuh tempo lewat agar
        // pinjaman kembali eligible — premis uji ini ("masih eligible").
        $this->allocate($this->paymentFor($loan, 1_200_000, $this->admin));
        $this->service()->resolve($first, (int) $this->marketing->id);

        $loan->installments()->create([
            'installment_number' => 2,
            'due_date' => today()->subDays(5)->toDateString(),
            'principal_amount' => 1_000_000,
            'interest_amount' => 200_000,
            'total_amount' => 1_200_000,
            'paid_amount' => 0,
            'paid_interest_amount' => 0,
            'paid_principal_amount' => 0,
            'remaining_amount' => 1_200_000,
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        $second = $this->createCaseFor($loan, $this->marketing);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(ShmCollectionCase::STATUS_RESOLVED, $first->refresh()->status);
        $this->assertNotNull($first->resolved_at);
        $this->assertSame(ShmCollectionCase::STATUS_OPEN, $second->status);
        $this->assertDatabaseCount('shm_collection_cases', 2);
    }

    // ------------------------------------------------------------------
    // C. Contract tie-in: status & tabel transisi
    // ------------------------------------------------------------------

    public function test_statuses_and_transition_table_match_contract_and_baseline(): void
    {
        $this->assertSame(
            Config::get('shm-business-rule-contract.collection_workflow.statuses.values'),
            array_keys(ShmCollectionCase::ALLOWED_TRANSITIONS),
        );
        $this->assertSame(
            [
                ShmCollectionCase::STATUS_OPEN => [
                    ShmCollectionCase::STATUS_IN_PROGRESS,
                    ShmCollectionCase::STATUS_RESOLVED,
                ],
                ShmCollectionCase::STATUS_IN_PROGRESS => [
                    ShmCollectionCase::STATUS_PROMISE_TO_PAY,
                    ShmCollectionCase::STATUS_ESCALATED,
                    ShmCollectionCase::STATUS_RESOLVED,
                ],
                ShmCollectionCase::STATUS_PROMISE_TO_PAY => [
                    ShmCollectionCase::STATUS_IN_PROGRESS,
                    ShmCollectionCase::STATUS_ESCALATED,
                    ShmCollectionCase::STATUS_RESOLVED,
                ],
                ShmCollectionCase::STATUS_ESCALATED => [
                    ShmCollectionCase::STATUS_IN_PROGRESS,
                    ShmCollectionCase::STATUS_RESOLVED,
                ],
                ShmCollectionCase::STATUS_RESOLVED => [],
            ],
            ShmCollectionCase::ALLOWED_TRANSITIONS,
        );
    }

    // ------------------------------------------------------------------
    // D. Transisi yang diizinkan (baseline T92)
    // ------------------------------------------------------------------

    public function test_open_to_in_progress_is_allowed(): void
    {
        $case = $this->openCaseOf($this->marketing);

        $updated = $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS);

        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $updated->status);
        $this->assertDatabaseHas('shm_collection_cases', [
            'id' => $case->id,
            'status' => ShmCollectionCase::STATUS_IN_PROGRESS,
        ]);
        $this->assertNull($updated->resolved_at);
    }

    public function test_in_progress_to_promise_to_pay_is_allowed(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_IN_PROGRESS);

        $updated = $this->transitionTo($case, ShmCollectionCase::STATUS_PROMISE_TO_PAY);

        $this->assertSame(ShmCollectionCase::STATUS_PROMISE_TO_PAY, $updated->status);
        $this->assertNull($updated->resolved_at);
    }

    public function test_in_progress_to_escalated_is_allowed(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_IN_PROGRESS);

        $updated = $this->transitionTo($case, ShmCollectionCase::STATUS_ESCALATED);

        $this->assertSame(ShmCollectionCase::STATUS_ESCALATED, $updated->status);
        $this->assertNull($updated->resolved_at);
    }

    public function test_promise_to_pay_to_in_progress_is_allowed(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_PROMISE_TO_PAY);

        $updated = $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS);

        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $updated->status);
        $this->assertNull($updated->resolved_at);
    }

    public function test_promise_to_pay_to_escalated_is_allowed(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_PROMISE_TO_PAY);

        $updated = $this->transitionTo($case, ShmCollectionCase::STATUS_ESCALATED);

        $this->assertSame(ShmCollectionCase::STATUS_ESCALATED, $updated->status);
        $this->assertNull($updated->resolved_at);
    }

    public function test_promise_to_pay_to_resolved_is_allowed_when_explicit(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_PROMISE_TO_PAY);
        $this->settleOverdue($case);

        $updated = $this->transitionTo($case, ShmCollectionCase::STATUS_RESOLVED);

        $this->assertSame(ShmCollectionCase::STATUS_RESOLVED, $updated->status);
        $this->assertNotNull($updated->resolved_at);
    }

    public function test_in_progress_to_resolved_is_allowed_when_explicit(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_IN_PROGRESS);
        $this->settleOverdue($case);

        // Jalur resolve() — invokasi eksplisit, bukan automatic resolution.
        $updated = $this->service()->resolve($case, (int) $this->marketing->id);

        $this->assertSame(ShmCollectionCase::STATUS_RESOLVED, $updated->status);
        $this->assertNotNull($updated->resolved_at);
    }

    public function test_escalated_to_in_progress_is_allowed(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_ESCALATED);

        $updated = $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS);

        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $updated->status);
        $this->assertNull($updated->resolved_at);
    }

    public function test_escalated_to_resolved_is_allowed_when_explicit(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_ESCALATED);
        $this->settleOverdue($case);

        $updated = $this->transitionTo($case, ShmCollectionCase::STATUS_RESOLVED);

        $this->assertSame(ShmCollectionCase::STATUS_RESOLVED, $updated->status);
        $this->assertNotNull($updated->resolved_at);
    }

    public function test_open_to_resolved_is_allowed_when_explicit(): void
    {
        $case = $this->openCaseOf($this->marketing);
        $this->settleOverdue($case);

        $updated = $this->transitionTo(
            $case,
            ShmCollectionCase::STATUS_RESOLVED,
            reason: 'Penyelesaian dikonfirmasi secara eksplisit oleh service layer.',
        );

        $this->assertSame(ShmCollectionCase::STATUS_RESOLVED, $updated->status);
        $this->assertNotNull($updated->resolved_at);
    }

    // ------------------------------------------------------------------
    // E. Transisi invalid & larangan reopening
    // ------------------------------------------------------------------

    public function test_invalid_transitions_are_rejected(): void
    {
        $open = $this->openCaseOf($this->marketing);
        $this->assertTransitionRejected($open, ShmCollectionCase::STATUS_PROMISE_TO_PAY);
        $this->assertTransitionRejected($open, ShmCollectionCase::STATUS_ESCALATED);
        $this->assertTransitionRejected($open, 'NOT_A_STATUS');

        $inProgress = $this->caseInStatus(ShmCollectionCase::STATUS_IN_PROGRESS);
        $this->assertTransitionRejected($inProgress, ShmCollectionCase::STATUS_IN_PROGRESS);
        $this->assertTransitionRejected($inProgress, ShmCollectionCase::STATUS_OPEN);
    }

    public function test_resolved_case_cannot_be_reopened(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_RESOLVED);
        $resolvedAt = $case->resolved_at;

        $this->assertTransitionRejected($case, ShmCollectionCase::STATUS_OPEN);
        $this->assertTransitionRejected($case, ShmCollectionCase::STATUS_IN_PROGRESS);
        $this->assertTransitionRejected($case, ShmCollectionCase::STATUS_PROMISE_TO_PAY);
        $this->assertTransitionRejected($case, ShmCollectionCase::STATUS_ESCALATED);

        $case->refresh();
        $this->assertSame(ShmCollectionCase::STATUS_RESOLVED, $case->status);
        $this->assertTrue($resolvedAt->equalTo($case->resolved_at));
    }

    // ------------------------------------------------------------------
    // F. Timestamps lifecycle
    // ------------------------------------------------------------------

    public function test_resolved_at_stays_null_for_non_resolved_statuses(): void
    {
        $case = $this->openCaseOf($this->marketing);
        $this->assertNull($case->resolved_at);

        $case = $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS);
        $this->assertNull($case->resolved_at);

        $case = $this->transitionTo($case, ShmCollectionCase::STATUS_PROMISE_TO_PAY);
        $this->assertNull($case->resolved_at);

        $case = $this->transitionTo($case, ShmCollectionCase::STATUS_ESCALATED);
        $this->assertNull($case->resolved_at);

        $this->assertDatabaseHas('shm_collection_cases', [
            'id' => $case->id,
            'resolved_at' => null,
        ]);
    }

    public function test_resolved_at_is_set_exactly_when_becoming_resolved(): void
    {
        $case = $this->openCaseOf($this->marketing);
        $this->settleOverdue($case);
        $before = now()->startOfSecond();

        $updated = $this->transitionTo($case, ShmCollectionCase::STATUS_RESOLVED);

        $this->assertNotNull($updated->resolved_at);
        $this->assertTrue($updated->resolved_at->greaterThanOrEqualTo($before));
        $this->assertTrue($updated->resolved_at->lessThanOrEqualTo(now()->addSecond()));
        $this->assertDatabaseHas('shm_collection_cases', [
            'id' => $case->id,
            'resolved_at' => $updated->resolved_at->toDateTimeString(),
        ]);
    }

    // ------------------------------------------------------------------
    // G. Otorisasi mutation (pemilik saja; tanpa fallback)
    // ------------------------------------------------------------------

    public function test_non_owner_marketing_cannot_create_case_for_another_marketers_loan(): void
    {
        $loan = $this->overdueLoanOf($this->marketing);

        $this->assertRejected(
            fn () => $this->createCaseFor($loan, $this->marketing2),
            'collection.otorisasi',
        );

        $this->assertDatabaseCount('shm_collection_cases', 0);
    }

    public function test_non_owner_marketing_cannot_transition_case(): void
    {
        $case = $this->openCaseOf($this->marketing);

        $this->assertRejected(
            fn () => $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS, $this->marketing2),
            'collection.otorisasi',
        );

        $this->assertDatabaseHas('shm_collection_cases', [
            'id' => $case->id,
            'status' => ShmCollectionCase::STATUS_OPEN,
        ]);
    }

    public function test_view_all_admin_cannot_mutate_case_without_ownership(): void
    {
        $loan = $this->overdueLoanOf($this->marketing);
        $case = $this->createCaseFor($loan, $this->marketing);

        // shm.view_all BUKAN hak mutasi lifecycle — tanpa fallback admin.
        $this->assertRejected(
            fn () => $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS, $this->admin),
            'collection.otorisasi',
        );
        $this->assertRejected(
            fn () => $this->createCaseFor($loan, $this->admin),
            'collection.otorisasi',
        );

        $this->assertDatabaseCount('shm_collection_cases', 1);
        $this->assertDatabaseHas('shm_collection_cases', [
            'id' => $case->id,
            'status' => ShmCollectionCase::STATUS_OPEN,
        ]);
    }

    public function test_loan_without_application_is_rejected_without_fallback_assignment(): void
    {
        $loan = $this->plainOverdueLoan();
        $usersBefore = User::query()->count();

        $this->assertRejected(
            fn () => $this->createCaseFor($loan, $this->marketing),
            'collection.pemilik',
        );

        $this->assertDatabaseCount('shm_collection_cases', 0);
        // Tidak ada user pertama/marketing acak/auto-create sebagai owner.
        $this->assertSame($usersBefore, User::query()->count());
    }

    // ------------------------------------------------------------------
    // H. Audit trail (kontrak T91 Rule 14)
    // ------------------------------------------------------------------

    public function test_case_creation_writes_audit_log(): void
    {
        $loan = $this->overdueLoanOf($this->marketing);
        $case = $this->createCaseFor($loan, $this->marketing);

        $log = AuditLog::query()
            ->where('action', 'shm.collection.case.created')
            ->where('object_type', ShmCollectionCase::class)
            ->where('object_id', $case->id)
            ->firstOrFail();

        $this->assertSame($loan->id, $log->changes['shm_loan_id']);
        $this->assertSame((int) $this->marketing->id, $log->changes['marketing_id']);
        $this->assertSame(ShmCollectionCase::STATUS_OPEN, $log->changes['status']);
        $this->assertSame((int) $this->marketing->id, $log->changes['actor_id']);
        $this->assertSame((int) $this->marketing->id, $log->actor_id);
    }

    public function test_status_transition_writes_audit_log(): void
    {
        $loan = $this->overdueLoanOf($this->marketing);
        $case = $this->createCaseFor($loan, $this->marketing);

        $this->transitionTo(
            $case,
            ShmCollectionCase::STATUS_IN_PROGRESS,
            reason: 'Marketing mulai menangani tunggakan.',
        );

        $log = AuditLog::query()
            ->where('action', 'shm.collection.case.status_changed')
            ->where('object_type', ShmCollectionCase::class)
            ->where('object_id', $case->id)
            ->firstOrFail();

        $this->assertSame($loan->id, $log->changes['shm_loan_id']);
        $this->assertSame(ShmCollectionCase::STATUS_OPEN, $log->changes['from_status']);
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $log->changes['to_status']);
        $this->assertSame('Marketing mulai menangani tunggakan.', $log->changes['reason']);
        $this->assertSame((int) $this->marketing->id, $log->changes['actor_id']);
    }

    // ------------------------------------------------------------------
    // I. Integritas transaksi (rollback tanpa parsial)
    // ------------------------------------------------------------------

    public function test_creation_rolls_back_case_when_audit_fails(): void
    {
        $loan = $this->overdueLoanOf($this->marketing);
        $this->actingAs($this->marketing);
        $auditBefore = AuditLog::query()->count();

        AuditLog::creating(function (): void {
            throw new RuntimeException('Audit sengaja gagal (uji rollback T92).');
        });

        $thrown = false;

        try {
            $this->service()->createOrGetForLoan($loan, (int) $this->marketing->id);
        } catch (RuntimeException $exception) {
            $thrown = true;
            $this->assertSame('Audit sengaja gagal (uji rollback T92).', $exception->getMessage());
        }

        $this->assertTrue($thrown, 'Diharapkan exception audit membatalkan transaksi pembuatan.');
        $this->assertDatabaseCount('shm_collection_cases', 0);
        $this->assertSame($auditBefore, AuditLog::query()->count());
    }

    public function test_transition_rolls_back_status_when_audit_fails(): void
    {
        $case = $this->openCaseOf($this->marketing);
        $auditBefore = AuditLog::query()->count();

        AuditLog::creating(function (): void {
            throw new RuntimeException('Audit sengaja gagal (uji rollback T92).');
        });

        $thrown = false;

        try {
            $this->service()->transition($case, ShmCollectionCase::STATUS_IN_PROGRESS, (int) $this->marketing->id);
        } catch (RuntimeException $exception) {
            $thrown = true;
            $this->assertSame('Audit sengaja gagal (uji rollback T92).', $exception->getMessage());
        }

        $this->assertTrue($thrown, 'Diharapkan exception audit membatalkan transaksi transisi.');
        $this->assertSame(ShmCollectionCase::STATUS_OPEN, $case->refresh()->status);
        $this->assertSame($auditBefore, AuditLog::query()->count());
    }

    // ------------------------------------------------------------------
    // J. Pemisahan keras dari domain finansial
    // ------------------------------------------------------------------

    public function test_case_lifecycle_never_touches_loan_payment_or_settlement_state(): void
    {
        $loan = $this->overdueLoanOf($this->marketing);
        $case = $this->createCaseFor($loan, $this->marketing);

        $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS);

        // Guard finansial T95: resolusi butuh tunggakan lunas — pembayaran
        // dibuat fixture (engine asli), lalu SELURUH state finansial
        // di-snapshot sebelum transisi final sehingga uji ini tetap
        // membuktikan lifecycle TIDAK menyentuh domain finansial.
        $this->allocate($this->paymentFor($loan, 1_200_000, $this->admin));
        $paidPrincipalBefore = (float) $loan->installments()->sum('paid_principal_amount');
        $paidInterestBefore = (float) $loan->installments()->sum('paid_interest_amount');

        $updated = $this->transitionTo($case, ShmCollectionCase::STATUS_RESOLVED);

        $loan->refresh();

        // Status Collection = state terpisah; pinjaman TETAP active dan
        // transisi tidak menambah/mengubah baris payment maupun settlement.
        $this->assertSame(ShmCollectionCase::STATUS_RESOLVED, $updated->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->status);
        $this->assertDatabaseCount('shm_loan_payments', 1);
        $this->assertDatabaseCount('shm_loan_settlements', 0);
        $this->assertSame($paidPrincipalBefore, (float) $loan->installments()->sum('paid_principal_amount'));
        $this->assertSame($paidInterestBefore, (float) $loan->installments()->sum('paid_interest_amount'));
    }
}
