<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmCollectionCase;
use App\Models\ShmCollectionPtp;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Ticket 99 — penegakan aturan bisnis PTP: kelayakan overdue ≥ 7 hari,
 * kuota 3 PTP per periode bulanan berlabuh, sweep scheduler OPEN → MISSED
 * (idempoten), eskalasi setelah 3 MISSED dalam periode yang sama, dan
 * batas otorisasi.
 *
 * 31 kasus bernomor (peta langsung ke daftar ticket):
 * - A. Kelayakan (1–4)
 * - B. Kuota periode berlabuh (5–14)
 * - C. Scheduler (15–20)
 * - D. Eskalasi (21–28)
 * - E. Otorisasi (29–31)
 *
 * Seluruh guard diuji lewat jalur resmi: HTTP untuk mutasi manual dan
 * `artisan collection:ptp-missed` untuk scheduler (TANPA session user —
 * actor system). Fixture mengikuti pola T97 (kelayakan & owner sama).
 */
class ShmCollectionPtpEnforcementTest extends TestCase
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

        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->marketing2 = $this->userWithRole('marketing');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Fixtures (pola identik T97 — kelayakan & owner dengan pola sama)
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

    /** Collection Case aktif milik $owner dengan satu angsuran tunggakan. */
    private function caseOf(User $owner, int $daysOverdue = 30): ShmCollectionCase
    {
        $application = $this->applicationOwnedBy($owner);
        $loan = $this->loanForApplication($application);

        $this->scheduleOf($loan, [
            ['due_date' => today()->subDays($daysOverdue)->toDateString()],
        ]);

        return app(ShmCollectionCaseService::class)->createOrGetForLoan($loan, (int) $owner->id);
    }

    /**
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

    private function walkTo(ShmCollectionCase $case, string $status, ?User $actor = null): ShmCollectionCase
    {
        $actor ??= $this->marketing;
        $service = app(ShmCollectionCaseService::class);

        if ($case->refresh()->status === ShmCollectionCase::STATUS_OPEN
            && $status !== ShmCollectionCase::STATUS_OPEN) {
            $case = $service->transition($case, ShmCollectionCase::STATUS_IN_PROGRESS, (int) $actor->id);
        }

        if ($status !== $case->status) {
            $case = $service->transition($case, $status, (int) $actor->id);
        }

        return $case;
    }

    /** Case siap PTP: PROMISE_TO_PAY melalui workflow eksplisit T92/T94. */
    private function promiseCaseOf(User $owner, int $daysOverdue = 30): ShmCollectionCase
    {
        return $this->walkTo($this->caseOf($owner, $daysOverdue), ShmCollectionCase::STATUS_PROMISE_TO_PAY);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ptpPayload(array $overrides = []): array
    {
        return array_merge([
            'promised_date' => today()->addDays(5)->toDateString(),
            'promised_amount' => 3_500_000,
            'notes' => 'Pembayaran setelah tanggal gajian.',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postPtp(ShmCollectionCase $case, User $as, array $payload): TestResponse
    {
        return $this->actingAs($as)->post(
            route('shm.collection.ptp.store', $case),
            $payload,
        );
    }

    private function postPtpTransition(ShmCollectionPtp $ptp, User $as, string $toStatus): TestResponse
    {
        return $this->actingAs($as)->post(
            route('shm.collection.ptp.transition', $ptp),
            ['status' => $toStatus],
        );
    }

    /** Buat satu PTP OPEN milik pemilik case lewat jalur resmi. */
    private function makePtp(ShmCollectionCase $case, array $overrides = []): ShmCollectionPtp
    {
        $this->postPtp($case, $this->marketing, $this->ptpPayload($overrides))
            ->assertSessionHas('success');

        return ShmCollectionPtp::query()->orderByDesc('id')->firstOrFail();
    }

    private function closePtp(ShmCollectionPtp $ptp, string $status): void
    {
        $this->postPtpTransition($ptp, $this->marketing, $status)
            ->assertSessionHas('success');
    }

    /** Buat + tutup satu PTP (terminal) pada case. */
    private function makeClosedPtp(ShmCollectionCase $case, string $status, array $overrides = []): ShmCollectionPtp
    {
        $ptp = $this->makePtp($case, $overrides);
        $this->closePtp($ptp, $status);

        return $ptp;
    }

    private function runSweep(): string
    {
        // Konteks CLI sesungguhnya: tidak ada user yang login. actingAs pada
        // request sebelumnya menempel pada guard dalam proses yang sama —
        // lepaskan dulu agar audit sweep memakai actor system (null), persis
        // seperti eksekusi cron `php artisan schedule:run`.
        Auth::forgetGuards();

        Artisan::call('collection:ptp-missed');

        return Artisan::output();
    }

    private function detailResponse(ShmCollectionCase $case, User $as): TestResponse
    {
        return $this->actingAs($as)
            ->get(route('shm.collection.show', $case->shm_loan_id))
            ->assertOk();
    }

    private function ptpAuditCount(string $action, ?ShmCollectionPtp $ptp = null): int
    {
        $query = AuditLog::query()
            ->where('action', $action)
            ->where('object_type', ShmCollectionPtp::class);

        if ($ptp !== null) {
            $query->where('object_id', $ptp->getKey());
        }

        return (int) $query->count();
    }

    /** Jumlah audit transisi case dengan tujuan ESCALATED. */
    private function escalationAuditCount(ShmCollectionCase $case): int
    {
        return AuditLog::query()
            ->where('action', 'shm.collection.case.status_changed')
            ->where('object_type', ShmCollectionCase::class)
            ->where('object_id', $case->getKey())
            ->get()
            ->filter(fn (AuditLog $log): bool => data_get($log->changes, 'to_status') === ShmCollectionCase::STATUS_ESCALATED)
            ->count();
    }

    /** Jumlah audit PTP status_changed yang ditulis scheduler (bukan manual). */
    private function schedulerPtpAuditCount(): int
    {
        return AuditLog::query()
            ->where('action', 'shm.collection.ptp.status_changed')
            ->where('object_type', ShmCollectionPtp::class)
            ->get()
            ->filter(fn (AuditLog $log): bool => data_get($log->changes, 'trigger') === 'scheduler')
            ->count();
    }

    private function paymentFor(ShmLoan $loan, float $amount, User $as): ShmLoanPayment
    {
        return app(ShmLoanPaymentService::class)->create($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
        ], (int) $as->id);
    }

    private function settleLoanFully(ShmCollectionCase $case): void
    {
        $outstanding = (float) $case->loan->installments()
            ->get()
            ->sum(fn (ShmLoanInstallment $installment): float => ((float) $installment->principal_amount - (float) $installment->paid_principal_amount)
                + ((float) $installment->interest_amount - (float) $installment->paid_interest_amount));
        $payment = $this->paymentFor($case->loan, round($outstanding, 2), $this->admin);
        app(ShmLoanPaymentAllocationService::class)->allocate($payment);
    }

    // ------------------------------------------------------------------
    // A. Kelayakan (1–4): overdue ≥ 7 hari dari due_date angsuran nyata
    // ------------------------------------------------------------------

    public function test_01_ptp_rejected_when_overdue_shorter_than_seven_days(): void
    {
        $case = $this->promiseCaseOf($this->marketing, 6);

        $this->postPtp($case, $this->marketing, $this->ptpPayload())
            ->assertSessionHasErrors('collection.ptp.kelayakan');

        $this->assertDatabaseCount('shm_collection_ptps', 0);
    }

    public function test_02_ptp_allowed_at_exactly_seven_days_overdue(): void
    {
        $case = $this->promiseCaseOf($this->marketing, 7);

        $this->postPtp($case, $this->marketing, $this->ptpPayload())
            ->assertSessionHas('success');

        $this->assertDatabaseCount('shm_collection_ptps', 1);
    }

    public function test_03_ptp_allowed_when_overdue_exceeds_seven_days(): void
    {
        $case = $this->promiseCaseOf($this->marketing, 30);

        $this->postPtp($case, $this->marketing, $this->ptpPayload())
            ->assertSessionHas('success');

        $this->detailResponse($case, $this->marketing)
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('collection_case.ptp.eligibility.eligible', true)
                ->where('collection_case.ptp.eligibility.overdue_min_days', 7)
                ->where('collection_case.ptp.eligibility.reason', null),
            );
    }

    public function test_04_ptp_rejected_when_overdue_was_cleared_after_case_opened(): void
    {
        $case = $this->promiseCaseOf($this->marketing, 30);

        // Seluruh tunggakan terbayar lewat engine pembayaran asli → pinjaman
        // tidak lagi kandidat Collection walau case masih PROMISE_TO_PAY.
        $this->settleLoanFully($case);

        $this->postPtp($case, $this->marketing, $this->ptpPayload())
            ->assertSessionHasErrors('collection.ptp.kelayakan');

        $this->assertDatabaseCount('shm_collection_ptps', 0);
    }

    // ------------------------------------------------------------------
    // B. Kuota periode berlabuh (5–14): maksimal 3 per periode anchor
    // ------------------------------------------------------------------

    public function test_05_first_ptp_anchors_the_quota_period(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->makePtp($case);

        $this->detailResponse($case, $this->marketing)
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('collection_case.ptp.quota.anchor', today()->toDateString())
                ->where('collection_case.ptp.quota.period_start', today()->toDateString())
                ->where('collection_case.ptp.quota.period_end', today()->addMonthsNoOverflow(1)->subDay()->toDateString())
                ->where('collection_case.ptp.quota.used', 1)
                ->where('collection_case.ptp.quota.limit', 3)
                ->where('collection_case.ptp.quota.remaining', 2)
                ->where('collection_case.ptp.blocked_reason', 'Masih ada PTP aktif (OPEN) — tandai Terpenuhi, Tidak Dipenuhi, atau Batalkan terlebih dahulu.'),
            );
    }

    public function test_06_second_ptp_in_the_same_period_is_allowed(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_CANCELLED);
        $this->makePtp($case);

        $this->assertSame(2, ShmCollectionPtp::query()->count());
        $this->detailResponse($case, $this->marketing)
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('collection_case.ptp.quota.used', 2)
                ->where('collection_case.ptp.quota.remaining', 1),
            );
    }

    public function test_07_third_ptp_in_the_same_period_is_allowed(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_FULFILLED);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_CANCELLED);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_CANCELLED);

        $this->assertSame(3, ShmCollectionPtp::query()->count());
        $this->detailResponse($case, $this->marketing)
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('collection_case.ptp.quota.used', 3)
                ->where('collection_case.ptp.quota.remaining', 0)
                ->where('collection_case.ptp.can_create', false)
                ->where('collection_case.ptp.blocked_reason', 'Kuota PTP periode '.today()->toDateString().' s/d '.today()->addMonthsNoOverflow(1)->subDay()->toDateString().' sudah penuh (3/3). PTP baru dapat dicatat mulai '.today()->addMonthsNoOverflow(1)->toDateString().'.'),
            );
    }

    public function test_08_fourth_ptp_in_the_same_period_is_rejected(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_FULFILLED);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_MISSED);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_CANCELLED);

        $this->postPtp($case, $this->marketing, $this->ptpPayload())
            ->assertSessionHasErrors('collection.ptp.kuota');

        $this->assertDatabaseCount('shm_collection_ptps', 3);
    }

    public function test_09_cancelled_ptps_count_toward_quota(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_CANCELLED);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_CANCELLED);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_CANCELLED);

        $this->postPtp($case, $this->marketing, $this->ptpPayload())
            ->assertSessionHasErrors('collection.ptp.kuota');

        $this->assertDatabaseCount('shm_collection_ptps', 3);
    }

    public function test_10_missed_ptps_count_toward_quota(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_CANCELLED);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_MISSED);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_CANCELLED);

        $this->postPtp($case, $this->marketing, $this->ptpPayload())
            ->assertSessionHasErrors('collection.ptp.kuota');

        $this->assertSame(3, ShmCollectionPtp::query()->count());
        $this->assertSame(1, ShmCollectionPtp::query()->where('status', ShmCollectionPtp::STATUS_MISSED)->count());
    }

    public function test_11_fulfilled_ptps_count_toward_quota(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_FULFILLED);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_FULFILLED);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_FULFILLED);

        $this->postPtp($case, $this->marketing, $this->ptpPayload())
            ->assertSessionHasErrors('collection.ptp.kuota');

        $this->assertDatabaseCount('shm_collection_ptps', 3);
    }

    public function test_12_open_ptp_counts_toward_quota(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_CANCELLED);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_CANCELLED);
        $this->makePtp($case); // tetap OPEN — wajib ikut terhitung

        // Guard duplikat (urutan server) menolak lebih dulu, tetapi payload
        // membuktikan kuota memang menghitung PTP OPEN (used = 3).
        $this->postPtp($case, $this->marketing, $this->ptpPayload())
            ->assertSessionHasErrors('collection.ptp.duplikat');

        $this->detailResponse($case, $this->marketing)
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('collection_case.ptp.has_open', true)
                ->where('collection_case.ptp.quota.used', 3)
                ->where('collection_case.ptp.quota.limit', 3),
            );
    }

    public function test_13_failed_validation_does_not_consume_quota(): void
    {
        $case = $this->promiseCaseOf($this->marketing);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postPtp($case, $this->marketing, $this->ptpPayload(['promised_amount' => 0]))
                ->assertSessionHasErrors('promised_amount');
        }

        $this->assertDatabaseCount('shm_collection_ptps', 0);

        $this->postPtp($case, $this->marketing, $this->ptpPayload())
            ->assertSessionHas('success');

        $this->detailResponse($case, $this->marketing)
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('collection_case.ptp.quota.used', 1)
                ->where('collection_case.ptp.quota.remaining', 2),
            );
    }

    public function test_14_quota_resets_on_the_next_anchored_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10')->startOfDay());
        $case = $this->promiseCaseOf($this->marketing);

        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_CANCELLED);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_CANCELLED);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_CANCELLED);

        $this->postPtp($case, $this->marketing, $this->ptpPayload())
            ->assertSessionHasErrors('collection.ptp.kuota');

        // Periode berikutnya setelah anchor 10 Jan = 10 Feb–9 Mar — kuota
        // segar, PTP keempat lolos; anchor TIDAK bergeser.
        Carbon::setTestNow(Carbon::parse('2026-02-11')->startOfDay());
        $this->postPtp($case, $this->marketing, $this->ptpPayload())
            ->assertSessionHas('success');

        $this->assertSame(4, ShmCollectionPtp::query()->count());
        $this->detailResponse($case, $this->marketing)
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('collection_case.ptp.quota.anchor', '2026-01-10')
                ->where('collection_case.ptp.quota.period_start', '2026-02-10')
                ->where('collection_case.ptp.quota.period_end', '2026-03-09')
                ->where('collection_case.ptp.quota.used', 1),
            );

        // Clamp akhir bulan (anchor 31 Jan): periode saling menyambung
        // tanpa celah/tumpang tindih (addMonthsNoOverflow).
        $periode0 = ShmCollectionPtp::quotaPeriodFor(Carbon::parse('2026-01-31'), Carbon::parse('2026-02-27'));
        $periode1 = ShmCollectionPtp::quotaPeriodFor(Carbon::parse('2026-01-31'), Carbon::parse('2026-02-28'));

        $this->assertSame(0, $periode0['index']);
        $this->assertSame('2026-01-31', $periode0['start']->toDateString());
        $this->assertSame('2026-02-27', $periode0['end']->toDateString());
        $this->assertSame(1, $periode1['index']);
        $this->assertSame('2026-02-28', $periode1['start']->toDateString());
        $this->assertSame('2026-03-30', $periode1['end']->toDateString());
    }

    // ------------------------------------------------------------------
    // C. Scheduler (15–20): OPEN lewat promised_date → MISSED, idempoten
    // ------------------------------------------------------------------

    public function test_15_scheduler_marks_open_ptp_past_promised_date_as_missed(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $ptp = $this->makePtp($case, ['promised_date' => today()->subDay()->toDateString()]);

        $this->runSweep();

        $this->assertSame(ShmCollectionPtp::STATUS_MISSED, $ptp->refresh()->status);
        $this->assertSame(1, $this->ptpAuditCount('shm.collection.ptp.status_changed', $ptp));
        $this->assertSame(1, $this->schedulerPtpAuditCount());
        $this->assertSame(0, $this->escalationAuditCount($case), 'Satu MISSED tidak mengeskalasi case.');
    }

    public function test_16_scheduler_waits_until_promised_date_fully_passes(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $ptp = $this->makePtp($case, ['promised_date' => today()->toDateString()]);

        $output = $this->runSweep();

        $this->assertSame(ShmCollectionPtp::STATUS_OPEN, $ptp->refresh()->status, 'Hari janji belum lewat.');
        $this->assertStringContainsString('Tidak ada PTP OPEN', $output);
        $this->assertSame(0, $this->ptpAuditCount('shm.collection.ptp.status_changed', $ptp));

        Carbon::setTestNow(today()->addDay());
        $this->runSweep();

        $this->assertSame(ShmCollectionPtp::STATUS_MISSED, $ptp->refresh()->status);
        $this->assertSame(1, $this->ptpAuditCount('shm.collection.ptp.status_changed', $ptp));
    }

    public function test_17_scheduler_leaves_terminal_ptps_untouched(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $fulfilled = $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_FULFILLED, ['promised_date' => today()->subDay()->toDateString()]);
        $cancelled = $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_CANCELLED, ['promised_date' => today()->subDay()->toDateString()]);
        $missed = $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_MISSED, ['promised_date' => today()->subDay()->toDateString()]);

        $auditBefore = $this->ptpAuditCount('shm.collection.ptp.status_changed');
        $output = $this->runSweep();

        $this->assertStringContainsString('Tidak ada PTP OPEN', $output);
        $this->assertSame(ShmCollectionPtp::STATUS_FULFILLED, $fulfilled->refresh()->status);
        $this->assertSame(ShmCollectionPtp::STATUS_CANCELLED, $cancelled->refresh()->status);
        $this->assertSame(ShmCollectionPtp::STATUS_MISSED, $missed->refresh()->status);
        $this->assertSame($auditBefore, $this->ptpAuditCount('shm.collection.ptp.status_changed'));
        $this->assertSame(0, $this->schedulerPtpAuditCount());
    }

    public function test_18_scheduler_run_is_idempotent_without_duplicate_audit(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $ptp = $this->makePtp($case, ['promised_date' => today()->subDay()->toDateString()]);

        $this->runSweep();
        $this->assertSame(1, $this->ptpAuditCount('shm.collection.ptp.status_changed', $ptp));

        $secondOutput = $this->runSweep();

        $this->assertStringContainsString('Tidak ada PTP OPEN', $secondOutput);
        $this->assertSame(ShmCollectionPtp::STATUS_MISSED, $ptp->refresh()->status);
        $this->assertSame(1, $this->ptpAuditCount('shm.collection.ptp.status_changed', $ptp),
            'Putaran kedua tidak boleh menggandakan audit/transisi.');
        $this->assertSame(1, $this->schedulerPtpAuditCount());
    }

    public function test_19_scheduler_command_is_registered_in_the_schedule(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('collection:ptp-missed', Artisan::output(),
            'Perintah sweep harus terdaftar pada scheduler aplikasi (bukan bergantung user membuka halaman).');
    }

    public function test_20_scheduler_uses_system_actor_without_creating_a_user(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $ptp = $this->makePtp($case, ['promised_date' => today()->subDay()->toDateString()]);
        $usersBefore = User::count();

        $this->runSweep(); // tanpa actingAs — konteks CLI, tanpa session

        $this->assertSame($usersBefore, User::count(), 'Scheduler tidak pernah membuat/memalsukan user.');

        $audit = AuditLog::query()
            ->where('action', 'shm.collection.ptp.status_changed')
            ->where('object_type', ShmCollectionPtp::class)
            ->where('object_id', $ptp->getKey())
            ->sole();

        $this->assertNull($audit->actor_id, 'Actor system = null pada kolom nullable (tanpa fake user).');
        $this->assertSame('scheduler', data_get($audit->changes, 'trigger'));
        $this->assertNull(data_get($audit->changes, 'actor_id'));
        $this->assertStringContainsString('scheduler', (string) $audit->description);
    }

    // ------------------------------------------------------------------
    // D. Eskalasi (21–28): ESCALATED hanya 3 MISSED dalam satu periode
    // ------------------------------------------------------------------

    public function test_21_one_missed_ptp_does_not_escalate_the_case(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->makePtp($case, ['promised_date' => today()->subDay()->toDateString()]);

        $this->runSweep();

        $this->assertSame(ShmCollectionCase::STATUS_PROMISE_TO_PAY, $case->refresh()->status);
        $this->assertSame(0, $this->escalationAuditCount($case));
    }

    public function test_22_two_missed_ptps_do_not_escalate_the_case(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->makePtp($case, ['promised_date' => today()->subDay()->toDateString()]);
        $this->runSweep();
        $this->makePtp($case, ['promised_date' => today()->subDay()->toDateString()]);

        $this->runSweep();

        $this->assertSame(2, ShmCollectionPtp::query()->where('status', ShmCollectionPtp::STATUS_MISSED)->count());
        $this->assertSame(ShmCollectionCase::STATUS_PROMISE_TO_PAY, $case->refresh()->status);
        $this->assertSame(0, $this->escalationAuditCount($case));
    }

    public function test_23_three_missed_ptps_in_the_same_period_escalate_the_case(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->makePtp($case, ['promised_date' => today()->subDay()->toDateString()]);
        $this->runSweep();
        $this->makePtp($case, ['promised_date' => today()->subDay()->toDateString()]);
        $this->runSweep();
        $this->makePtp($case, ['promised_date' => today()->subDay()->toDateString()]);

        $this->runSweep();

        $this->assertSame(3, ShmCollectionPtp::query()->where('status', ShmCollectionPtp::STATUS_MISSED)->count());
        $this->assertSame(ShmCollectionCase::STATUS_ESCALATED, $case->refresh()->status);
        $this->assertSame(1, $this->escalationAuditCount($case), 'Tepat satu transisi eskalasi (idempoten).');
        $this->assertSame(3, $this->schedulerPtpAuditCount());

        $escalationAudit = AuditLog::query()
            ->where('action', 'shm.collection.case.status_changed')
            ->where('object_type', ShmCollectionCase::class)
            ->where('object_id', $case->getKey())
            ->get()
            ->first(fn (AuditLog $log): bool => data_get($log->changes, 'to_status') === ShmCollectionCase::STATUS_ESCALATED);

        $this->assertNotNull($escalationAudit);
        $this->assertNull($escalationAudit->actor_id, 'Eskalasi scheduler memakai actor system, bukan user.');
        $this->assertSame('scheduler', data_get($escalationAudit->changes, 'trigger'));
    }

    public function test_24_three_submissions_without_missed_never_escalate(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_FULFILLED, ['promised_date' => today()->subDay()->toDateString()]);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_FULFILLED, ['promised_date' => today()->subDay()->toDateString()]);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_CANCELLED, ['promised_date' => today()->subDay()->toDateString()]);

        $output = $this->runSweep();

        $this->assertStringContainsString('Tidak ada PTP OPEN', $output);
        $this->assertSame(3, ShmCollectionPtp::query()->count(), 'Tiga pengajuan (0 MISSED) tidak mengeskalasi.');
        $this->assertSame(ShmCollectionCase::STATUS_PROMISE_TO_PAY, $case->refresh()->status);
        $this->assertSame(0, $this->escalationAuditCount($case));
    }

    public function test_25_cancelled_plus_two_missed_do_not_escalate(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->makeClosedPtp($case, ShmCollectionPtp::STATUS_CANCELLED, ['promised_date' => today()->subDay()->toDateString()]);
        $this->makePtp($case, ['promised_date' => today()->subDay()->toDateString()]);
        $this->runSweep();
        $this->makePtp($case, ['promised_date' => today()->subDay()->toDateString()]);

        $this->runSweep();

        $this->assertSame(2, ShmCollectionPtp::query()->where('status', ShmCollectionPtp::STATUS_MISSED)->count());
        $this->assertSame(ShmCollectionCase::STATUS_PROMISE_TO_PAY, $case->refresh()->status,
            'CANCELLED tidak dihitung sebagai MISSED — eskalasi butuh 3 MISSED.');
        $this->assertSame(0, $this->escalationAuditCount($case));
    }

    public function test_26_missed_ptps_from_different_periods_do_not_combine(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10')->startOfDay());
        $case = $this->promiseCaseOf($this->marketing);

        $this->makePtp($case, ['promised_date' => '2026-01-09']);
        $this->runSweep();
        $this->makePtp($case, ['promised_date' => '2026-01-09']);

        $this->runSweep();
        $this->assertSame(ShmCollectionCase::STATUS_PROMISE_TO_PAY, $case->refresh()->status);

        // Periode baru (10 Feb–9 Mar): PTP MISSED di periode lama tidak
        // digabungkan dengan MISSED periode berjalan.
        Carbon::setTestNow(Carbon::parse('2026-02-11')->startOfDay());
        $this->runSweep();
        $this->assertSame(ShmCollectionCase::STATUS_PROMISE_TO_PAY, $case->refresh()->status,
            'Dua MISSED periode lama tidak mengeskalasi pada periode baru.');

        $this->makePtp($case, ['promised_date' => '2026-02-10']);
        $this->runSweep();

        $this->assertSame(3, ShmCollectionPtp::query()->where('status', ShmCollectionPtp::STATUS_MISSED)->count(),
            'Tiga MISSED total, tetapi terbagi dua periode berbeda.');
        $this->assertSame(ShmCollectionCase::STATUS_PROMISE_TO_PAY, $case->refresh()->status,
            'Escalation hanya menilai periode anchored yang sama.');
        $this->assertSame(0, $this->escalationAuditCount($case));
    }

    public function test_27_escalation_is_not_duplicated_on_subsequent_runs(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->makePtp($case, ['promised_date' => today()->subDay()->toDateString()]);
        $this->runSweep();
        $this->makePtp($case, ['promised_date' => today()->subDay()->toDateString()]);
        $this->runSweep();
        $this->makePtp($case, ['promised_date' => today()->subDay()->toDateString()]);
        $this->runSweep();

        $this->assertSame(ShmCollectionCase::STATUS_ESCALATED, $case->refresh()->status);
        $this->assertSame(1, $this->escalationAuditCount($case));

        $this->runSweep();
        $this->assertSame(1, $this->escalationAuditCount($case), 'Putaran ulang tidak menggandakan transisi/audit.');

        // Pemanggilan langsung kedua pada case yang sudah ESCALATED juga no-op.
        app(ShmCollectionCaseService::class)->escalateFromScheduler($case, 'Uji ulang idempotensi.');

        $this->assertSame(ShmCollectionCase::STATUS_ESCALATED, $case->refresh()->status);
        $this->assertSame(1, $this->escalationAuditCount($case));
    }

    public function test_28_resolved_case_is_never_escalated(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $ptp = $this->makePtp($case, ['promised_date' => today()->subDay()->toDateString()]);

        $this->settleLoanFully($case);
        app(ShmCollectionCaseService::class)->resolve($case, (int) $this->marketing->id);
        $this->assertSame(ShmCollectionCase::STATUS_RESOLVED, $case->refresh()->status);
        $auditsBeforeResolveSweep = $this->escalationAuditCount($case);

        $this->runSweep();

        $this->assertSame(ShmCollectionPtp::STATUS_MISSED, $ptp->refresh()->status,
            'PTP lewat janji tetap fakta MISSED walau case sudah RESOLVED.');
        $this->assertSame(ShmCollectionCase::STATUS_RESOLVED, $case->refresh()->status,
            'Case RESOLVED tidak pernah dievaluasi untuk eskalasi.');
        $this->assertSame($auditsBeforeResolveSweep, $this->escalationAuditCount($case));
    }

    // ------------------------------------------------------------------
    // E. Otorisasi (29–32): server otoritatif, tanpa role Collector
    //    (permission collection grantable via T101)
    // ------------------------------------------------------------------

    public function test_29_non_owner_marketing_cannot_create_ptp_even_when_eligible(): void
    {
        $case = $this->promiseCaseOf($this->marketing); // layak + kuota segar

        $this->postPtp($case, $this->marketing2, $this->ptpPayload())
            ->assertSessionHasErrors('collection.otorisasi');

        $this->assertDatabaseCount('shm_collection_ptps', 0);
    }

    public function test_30_view_all_admin_cannot_create_ptp(): void
    {
        $case = $this->promiseCaseOf($this->marketing);

        $this->postPtp($case, $this->admin, $this->ptpPayload())
            ->assertSessionHasErrors('collection.otorisasi');

        $this->assertDatabaseCount('shm_collection_ptps', 0);
    }

    public function test_31_no_collector_role_and_collection_permissions_are_grantable(): void
    {
        // Role Collector TIDAK dibuat oleh ticket mana pun — Superadmin
        // membuat role sendiri via UI Role & Permission (T101). Permission
        // teknis Collection kini terdaftar & grantable; otorisasi mutation
        // di domain tetap owner-only (test 29-30).
        $this->assertDatabaseMissing('roles', ['name' => 'collector']);
        $this->assertSame(
            [],
            Permission::query()->where('name', 'like', '%collector%')->pluck('name')->all(),
        );
        $this->assertSame(
            [
                'shm.collection.activity.create',
                'shm.collection.ptp.create',
                'shm.collection.ptp.transition',
                'shm.collection.transition',
                'shm.collection.view',
            ],
            Permission::query()
                ->where('name', 'like', 'shm.collection.%')
                ->orderBy('name')
                ->pluck('name')
                ->all(),
        );
    }

    public function test_32_ptp_sweep_needs_no_user(): void
    {
        // Sweep berjalan tanpa session & tanpa membuat user (jalur CLI).
        $usersBefore = User::count();
        $this->runSweep();

        $this->assertSame($usersBefore, User::count());
    }
}
