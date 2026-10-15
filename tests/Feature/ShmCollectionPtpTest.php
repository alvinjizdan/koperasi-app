<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\ShmCollectionActivity;
use App\Models\ShmCollectionCase;
use App\Models\ShmCollectionPtp;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\User;
use App\Services\Shm\ShmCollectionActivityService;
use App\Services\Shm\ShmCollectionCaseService;
use App\Services\Shm\ShmLoanPaymentAllocationService;
use App\Services\Shm\ShmLoanPaymentService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Ticket 97 — Collection Promise to Pay (PTP) contract & follow-up.
 *
 * Mengunci: PTP adalah entitas persisten pada Collection Case (tanggal
 * janji + nominal + catatan + pembuat + status), pencatatan WAJIB case
 * berstatus PROMISE_TO_PAY tanpa transisi status otomatis, otorisasi
 * owner-only dengan actor dari session, SATU PTP OPEN per case (guard
 * service + partial unique index; riwayat terminal tidak memblokir PTP
 * baru), transisi manual OPEN → FULFILLED|MISSED|CANCELLED (terminal,
 * tanpa reopen) dengan audit `shm.collection.ptp.*`, dan TANPA efek samping
 * finansial/lifecycle (tanpa pembayaran/settlement/barubayar; saldo
 * pinjaman, status case, guard resolusi T95, ownership T96 tidak berubah).
 *
 * YANG TIDAK DIUJI SEBAGAI PERILAKU (karena sengaja TIDAK ada — PENDING
 * BUSINESS CONFIRMATION): grace period, MISSED/fulfilled otomatis dari
 * tanggal/pembayaran, jumlah percobaan maksimum, penalty, reminder/
 * notifikasi, eskalasi otomatis, collector, matching pembayaran ke PTP.
 */
class ShmCollectionPtpTest extends TestCase
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

        // admin = pemegang shm.view_all; marketing2 = Marketing non-pemilik.
        // Keduanya tidak otomatis boleh memutasi PTP/case bukan miliknya.
        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->marketing2 = $this->userWithRole('marketing');
    }

    // ------------------------------------------------------------------
    // Fixtures (pola identik T92–T96 — kelayakan & owner dengan pola sama)
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
     * Collection Case aktif milik $owner (jalur resmi service T92): satu
     * angsuran tunggakan 1.200.000 (pokok 1.000.000 + bunga 200.000).
     */
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

    /**
     * Bawa case ke status target melalui workflow service T92/T94 (satu
     * langkah bila tujuan = IN_PROGRESS; dua langkah bila PROMISE_TO_PAY).
     */
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

    /**
     * Case siap PTP: status PROMISE_TO_PAY melalui workflow eksplisit
     * (bukan otomatis — inilah jalur satu-satunya).
     */
    private function promiseCaseOf(User $owner): ShmCollectionCase
    {
        return $this->walkTo($this->caseOf($owner), ShmCollectionCase::STATUS_PROMISE_TO_PAY);
    }

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

    private function caseStatusAuditCount(ShmCollectionCase $case): int
    {
        return AuditLog::query()
            ->where('action', 'shm.collection.case.status_changed')
            ->where('object_type', ShmCollectionCase::class)
            ->where('object_id', $case->getKey())
            ->count();
    }

    private function paymentFor(ShmLoan $loan, float $amount, User $as): ShmLoanPayment
    {
        return app(ShmLoanPaymentService::class)->create($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
        ], (int) $as->id);
    }

    // ------------------------------------------------------------------
    // A. Pembuatan PTP (entitas persisten pada case PROMISE_TO_PAY)
    // ------------------------------------------------------------------

    public function test_owner_can_create_ptp_and_actor_comes_from_session(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $promisedDate = today()->addDays(7)->toDateString();

        $this->postPtp($case, $this->marketing, $this->ptpPayload([
            'promised_date' => $promisedDate,
            'promised_amount' => '3500000.50',
            'notes' => 'Pembayaran setelah tanggal gajian.',
        ]))
            ->assertRedirect(route('shm.collection.show', $case->shm_loan_id))
            ->assertSessionHas('success');

        $ptp = ShmCollectionPtp::query()->sole();
        $this->assertSame($case->getKey(), (int) $ptp->shm_collection_case_id);
        $this->assertSame($this->marketing->id, (int) $ptp->created_by, 'Actor wajib dari session, bukan payload.');
        $this->assertSame(ShmCollectionPtp::STATUS_OPEN, $ptp->status);
        $this->assertSame($promisedDate, $ptp->promised_date->toDateString());
        $this->assertSame(3_500_000.50, round((float) $ptp->promised_amount, 2));
        $this->assertSame('Pembayaran setelah tanggal gajian.', $ptp->notes);
    }

    public function test_detail_payload_exposes_ptp_history_creator_and_flags(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->postPtp($case, $this->marketing, $this->ptpPayload());

        // Pemirsa admin (shm.view_all): riwayat PTP terlihat (view-only),
        // tanpa hak membuat maupun transisi apa pun.
        $this->actingAs($this->admin)
            ->get(route('shm.collection.show', $case->shm_loan_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('collection_case.ptp.history.0.status', ShmCollectionPtp::STATUS_OPEN)
                ->where('collection_case.ptp.history.0.creator.id', $this->marketing->id)
                ->where('collection_case.ptp.history.0.creator.name', $this->marketing->name)
                ->where('collection_case.ptp.has_open', true)
                ->where('collection_case.ptp.can_create', false, 'Non-pemilik tidak boleh membuat PTP.')
                ->where('collection_case.ptp.history.0.allowed_transitions', [], 'Non-pemilik tidak ditawari aksi.'),
            );
    }

    public function test_notes_is_optional(): void
    {
        $case = $this->promiseCaseOf($this->marketing);

        $this->postPtp($case, $this->marketing, $this->ptpPayload(['notes' => null]))
            ->assertRedirect(route('shm.collection.show', $case->shm_loan_id));

        $this->assertNull(ShmCollectionPtp::query()->sole()->notes);
    }

    public function test_zero_amount_is_rejected(): void
    {
        $case = $this->promiseCaseOf($this->marketing);

        $this->postPtp($case, $this->marketing, $this->ptpPayload(['promised_amount' => 0]))
            ->assertSessionHasErrors('promised_amount');

        $this->assertDatabaseCount('shm_collection_ptps', 0);
    }

    public function test_negative_amount_is_rejected(): void
    {
        $case = $this->promiseCaseOf($this->marketing);

        $this->postPtp($case, $this->marketing, $this->ptpPayload(['promised_amount' => -1000]))
            ->assertSessionHasErrors('promised_amount');

        $this->assertDatabaseCount('shm_collection_ptps', 0);
    }

    public function test_missing_promised_date_is_rejected(): void
    {
        $case = $this->promiseCaseOf($this->marketing);

        $this->postPtp($case, $this->marketing, $this->ptpPayload(['promised_date' => '']))
            ->assertSessionHasErrors('promised_date');

        $this->assertDatabaseCount('shm_collection_ptps', 0);
    }

    public function test_missing_amount_is_rejected(): void
    {
        $case = $this->promiseCaseOf($this->marketing);

        $this->postPtp($case, $this->marketing, $this->ptpPayload(['promised_amount' => '']))
            ->assertSessionHasErrors('promised_amount');

        $this->assertDatabaseCount('shm_collection_ptps', 0);
    }

    public function test_malformed_date_is_rejected(): void
    {
        $case = $this->promiseCaseOf($this->marketing);

        $this->postPtp($case, $this->marketing, $this->ptpPayload(['promised_date' => '32-13-2026']))
            ->assertSessionHasErrors('promised_date');

        $this->assertDatabaseCount('shm_collection_ptps', 0);
    }

    public function test_creation_writes_exactly_one_ptp_audit_and_no_case_audit(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $caseAuditsAfterWalk = $this->caseStatusAuditCount($case);

        $this->postPtp($case, $this->marketing, $this->ptpPayload())
            ->assertSessionHas('success');

        $ptp = ShmCollectionPtp::query()->sole();
        $this->assertSame(1, $this->ptpAuditCount('shm.collection.ptp.created', $ptp));
        $this->assertSame($caseAuditsAfterWalk, $this->caseStatusAuditCount($case),
            'Pencatatan PTP tidak menulis audit status case.');

        $changes = AuditLog::query()
            ->where('action', 'shm.collection.ptp.created')
            ->where('object_id', $ptp->getKey())
            ->first()
            ->changes;

        $this->assertSame($this->marketing->id, (int) $changes['actor_id']);
        $this->assertSame($case->getKey(), (int) $changes['shm_collection_case_id']);
        $this->assertNotEmpty($changes['promised_date']);
        $this->assertSame(ShmCollectionPtp::STATUS_OPEN, $changes['status']);
    }

    // ------------------------------------------------------------------
    // B. Status case: wajib PROMISE_TO_PAY, tanpa transisi otomatis
    // ------------------------------------------------------------------

    public function test_ptp_creation_rejected_when_case_not_in_promise_to_pay(): void
    {
        $case = $this->walkTo($this->caseOf($this->marketing), ShmCollectionCase::STATUS_IN_PROGRESS);

        $this->postPtp($case, $this->marketing, $this->ptpPayload())
            ->assertSessionHasErrors('collection.ptp.status');

        $this->assertDatabaseCount('shm_collection_ptps', 0);
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->refresh()->status);
    }

    public function test_ptp_creation_does_not_change_case_status(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $caseAuditsAfterWalk = $this->caseStatusAuditCount($case);

        $this->postPtp($case, $this->marketing, $this->ptpPayload())
            ->assertSessionHas('success');

        $this->assertSame(ShmCollectionCase::STATUS_PROMISE_TO_PAY, $case->refresh()->status);
        $this->assertSame($caseAuditsAfterWalk, $this->caseStatusAuditCount($case));
    }

    // ------------------------------------------------------------------
    // C. Duplikat OPEN: guard service + lapisan database
    // ------------------------------------------------------------------

    public function test_existing_open_ptp_blocks_another_open_ptp(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->postPtp($case, $this->marketing, $this->ptpPayload());

        $this->postPtp($case, $this->marketing, $this->ptpPayload())
            ->assertSessionHasErrors('collection.ptp.duplikat');

        $this->assertDatabaseCount('shm_collection_ptps', 1);
    }

    public function test_terminal_ptp_does_not_block_a_new_ptp(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->postPtp($case, $this->marketing, $this->ptpPayload());
        $first = ShmCollectionPtp::query()->sole();

        $this->postPtpTransition($first, $this->marketing, ShmCollectionPtp::STATUS_CANCELLED)
            ->assertSessionHas('success');

        $this->postPtp($case, $this->marketing, $this->ptpPayload([
            'promised_date' => today()->addDays(10)->toDateString(),
        ]))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('shm_collection_ptps', 2);
        $this->assertSame(1, ShmCollectionPtp::query()
            ->where('status', ShmCollectionPtp::STATUS_OPEN)
            ->count());
    }

    public function test_database_blocks_second_open_ptp_for_same_case(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->postPtp($case, $this->marketing, $this->ptpPayload());

        try {
            // Guard service sengaja dilewati: membuktikan partial unique
            // index adalah lapisan database kedua untuk SATU PTP OPEN.
            ShmCollectionPtp::query()->create([
                'shm_collection_case_id' => $case->getKey(),
                'created_by' => $this->marketing->id,
                'promised_date' => today()->addDays(9)->toDateString(),
                'promised_amount' => 1_000_000,
                'status' => ShmCollectionPtp::STATUS_OPEN,
            ]);
            $this->fail('Database seharusnya memblokir PTP OPEN kedua pada case yang sama.');
        } catch (QueryException) {
            $this->assertSame(1, ShmCollectionPtp::query()->count());
        }
    }

    // ------------------------------------------------------------------
    // D. Otorisasi (pemilik saja; shm.view_all tidak bypass; actor session)
    // ------------------------------------------------------------------

    public function test_non_owner_marketing_cannot_create_ptp(): void
    {
        $case = $this->promiseCaseOf($this->marketing);

        $this->postPtp($case, $this->marketing2, $this->ptpPayload())
            ->assertSessionHasErrors('collection.otorisasi');

        $this->assertDatabaseCount('shm_collection_ptps', 0);
    }

    public function test_view_all_admin_cannot_create_ptp(): void
    {
        $case = $this->promiseCaseOf($this->marketing);

        $this->postPtp($case, $this->admin, $this->ptpPayload())
            ->assertSessionHasErrors('collection.otorisasi');

        $this->assertDatabaseCount('shm_collection_ptps', 0);
    }

    public function test_actor_spoofing_via_payload_is_ignored(): void
    {
        $case = $this->promiseCaseOf($this->marketing);

        $this->postPtp($case, $this->marketing, $this->ptpPayload([
            'created_by' => $this->marketing2->id,
            'user_id' => 999,
            'status' => ShmCollectionPtp::STATUS_FULFILLED,
        ]))
            ->assertSessionHas('success');

        $ptp = ShmCollectionPtp::query()->sole();
        $this->assertSame($this->marketing->id, (int) $ptp->created_by);
        $this->assertSame(ShmCollectionPtp::STATUS_OPEN, $ptp->status,
            'Payload tidak pernah menentukan status awal PTP.');
    }

    public function test_non_owner_cannot_transition_ptp(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->postPtp($case, $this->marketing, $this->ptpPayload());
        $ptp = ShmCollectionPtp::query()->sole();

        $this->postPtpTransition($ptp, $this->marketing2, ShmCollectionPtp::STATUS_MISSED)
            ->assertSessionHasErrors('collection.otorisasi');

        $this->assertSame(ShmCollectionPtp::STATUS_OPEN, $ptp->refresh()->status);
    }

    public function test_view_all_admin_cannot_transition_ptp(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->postPtp($case, $this->marketing, $this->ptpPayload());
        $ptp = ShmCollectionPtp::query()->sole();

        $this->postPtpTransition($ptp, $this->admin, ShmCollectionPtp::STATUS_FULFILLED)
            ->assertSessionHasErrors('collection.otorisasi');

        $this->assertSame(ShmCollectionPtp::STATUS_OPEN, $ptp->refresh()->status);
    }

    // ------------------------------------------------------------------
    // E. Mutasi status manual (OPEN → terminal; tanpa reopen)
    // ------------------------------------------------------------------

    public function test_open_ptp_can_be_marked_fulfilled(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->postPtp($case, $this->marketing, $this->ptpPayload());
        $ptp = ShmCollectionPtp::query()->sole();

        $this->postPtpTransition($ptp, $this->marketing, ShmCollectionPtp::STATUS_FULFILLED)
            ->assertRedirect(route('shm.collection.show', $case->shm_loan_id))
            ->assertSessionHas('success');

        $this->assertSame(ShmCollectionPtp::STATUS_FULFILLED, $ptp->refresh()->status);
    }

    public function test_open_ptp_can_be_marked_missed(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->postPtp($case, $this->marketing, $this->ptpPayload());
        $ptp = ShmCollectionPtp::query()->sole();

        $this->postPtpTransition($ptp, $this->marketing, ShmCollectionPtp::STATUS_MISSED)
            ->assertSessionHas('success');

        $this->assertSame(ShmCollectionPtp::STATUS_MISSED, $ptp->refresh()->status);
    }

    public function test_open_ptp_can_be_cancelled(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->postPtp($case, $this->marketing, $this->ptpPayload());
        $ptp = ShmCollectionPtp::query()->sole();

        $this->postPtpTransition($ptp, $this->marketing, ShmCollectionPtp::STATUS_CANCELLED)
            ->assertSessionHas('success');

        $this->assertSame(ShmCollectionPtp::STATUS_CANCELLED, $ptp->refresh()->status);
    }

    public function test_terminal_ptp_cannot_be_reopened_or_changed(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->postPtp($case, $this->marketing, $this->ptpPayload());
        $ptp = ShmCollectionPtp::query()->sole();

        $this->postPtpTransition($ptp, $this->marketing, ShmCollectionPtp::STATUS_FULFILLED)
            ->assertSessionHas('success');

        foreach ([ShmCollectionPtp::STATUS_MISSED, ShmCollectionPtp::STATUS_CANCELLED] as $target) {
            $this->postPtpTransition($ptp, $this->marketing, $target)
                ->assertSessionHasErrors('collection.ptp.status');

            $this->assertSame(ShmCollectionPtp::STATUS_FULFILLED, $ptp->refresh()->status);
        }

        $this->assertSame(1, $this->ptpAuditCount('shm.collection.ptp.status_changed', $ptp));
    }

    public function test_invalid_status_target_is_rejected_by_form_request(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->postPtp($case, $this->marketing, $this->ptpPayload());
        $ptp = ShmCollectionPtp::query()->sole();

        foreach (['OPEN', 'BOGUS', ''] as $target) {
            $this->postPtpTransition($ptp, $this->marketing, $target)
                ->assertSessionHasErrors('status');

            $this->assertSame(ShmCollectionPtp::STATUS_OPEN, $ptp->refresh()->status);
        }
    }

    public function test_each_transition_writes_exactly_one_ptp_audit_without_case_audit(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->postPtp($case, $this->marketing, $this->ptpPayload());
        $ptp = ShmCollectionPtp::query()->sole();
        $caseAuditsBefore = $this->caseStatusAuditCount($case);

        $this->postPtpTransition($ptp, $this->marketing, ShmCollectionPtp::STATUS_MISSED)
            ->assertSessionHas('success');

        $this->assertSame(1, $this->ptpAuditCount('shm.collection.ptp.status_changed', $ptp));

        $changes = AuditLog::query()
            ->where('action', 'shm.collection.ptp.status_changed')
            ->where('object_id', $ptp->getKey())
            ->first()
            ->changes;

        $this->assertSame(ShmCollectionPtp::STATUS_OPEN, $changes['previous_status']);
        $this->assertSame(ShmCollectionPtp::STATUS_MISSED, $changes['status']);
        $this->assertSame($this->marketing->id, (int) $changes['actor_id']);
        $this->assertSame($caseAuditsBefore, $this->caseStatusAuditCount($case),
            'Mutasi PTP tidak menulis audit status case.');
    }

    // ------------------------------------------------------------------
    // F. Independensi finansial & regression guard
    // ------------------------------------------------------------------

    public function test_payment_does_not_automatically_fulfill_or_miss_ptp(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->postPtp($case, $this->marketing, $this->ptpPayload());
        $ptp = ShmCollectionPtp::query()->sole();

        // Pelunasan penuh lewat engine pembayaran ASLI — status PTP tidak
        // pernah disentuh oleh pembayaran (matching = PENDING).
        $outstanding = (float) $case->loan->installments()
            ->get()
            ->sum(fn (ShmLoanInstallment $installment): float => ((float) $installment->principal_amount - (float) $installment->paid_principal_amount)
                + ((float) $installment->interest_amount - (float) $installment->paid_interest_amount));
        $payment = $this->paymentFor($case->loan, round($outstanding, 2), $this->admin);
        app(ShmLoanPaymentAllocationService::class)->allocate($payment);

        $this->assertSame(ShmCollectionPtp::STATUS_OPEN, $ptp->refresh()->status,
            'Pembayaran tidak pernah menentukan FULFILLED/MISSED otomatis.');
        $this->assertSame(1, ShmCollectionPtp::query()
            ->where('status', ShmCollectionPtp::STATUS_OPEN)
            ->count());
        $this->assertSame(1, $this->ptpAuditCount('shm.collection.ptp.created', $ptp));
        $this->assertSame(0, $this->ptpAuditCount('shm.collection.ptp.status_changed', $ptp));
    }

    public function test_ptp_does_not_modify_payment_allocation_or_loan_balance(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $remainingBefore = round((float) $case->loan->installments()->sum('remaining_amount'), 2);
        $paidBefore = round((float) $case->loan->installments()->sum('paid_amount'), 2);

        $this->postPtp($case, $this->marketing, $this->ptpPayload())
            ->assertSessionHas('success');
        $ptp = ShmCollectionPtp::query()->sole();

        $this->postPtpTransition($ptp, $this->marketing, ShmCollectionPtp::STATUS_FULFILLED)
            ->assertSessionHas('success');

        $this->assertDatabaseCount('shm_loan_payments', 0);
        $this->assertDatabaseCount('shm_loan_settlements', 0);
        $this->assertSame($remainingBefore, round((float) $case->loan->installments()->sum('remaining_amount'), 2));
        $this->assertSame($paidBefore, round((float) $case->loan->installments()->sum('paid_amount'), 2));
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $case->loan->refresh()->status);
    }

    public function test_t95_resolution_guard_remains_intact_with_ptp_recorded(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->postPtp($case, $this->marketing, $this->ptpPayload());

        // Guard finansial T95 tetap berlaku: resolusi saat tunggakan masih
        // ada DITOLAK meski PTP tercatat.
        try {
            app(ShmCollectionCaseService::class)->resolve($case, (int) $this->marketing->id);
            $this->fail('Resolusi saat tunggakan masih ada seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collection.resolusi', $exception->errors());
        }

        $this->assertSame(ShmCollectionCase::STATUS_PROMISE_TO_PAY, $case->refresh()->status);
    }

    public function test_collection_activity_still_works_on_ptp_case(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->postPtp($case, $this->marketing, $this->ptpPayload());

        $activity = app(ShmCollectionActivityService::class)->create($case, (int) $this->marketing->id, [
            'activity_type' => ShmCollectionActivity::TYPE_PHONE_CALL,
            'result' => ShmCollectionActivity::RESULT_PROMISE_TO_PAY,
            'activity_at' => now()->toDateTimeString(),
            'notes' => 'Follow-up janji bayar tercatat.',
        ]);

        $this->assertSame($case->getKey(), (int) $activity->shm_collection_case_id);
        // Aktivitas PROMISE_TO_PAY tetap RIWAYAT: tidak membuat/mengubah PTP.
        $this->assertDatabaseCount('shm_collection_ptps', 1);
        $this->assertSame(ShmCollectionCase::STATUS_PROMISE_TO_PAY, $case->refresh()->status);
    }

    public function test_t96_ownership_and_escalation_payload_intact_with_ptp(): void
    {
        $case = $this->promiseCaseOf($this->marketing);
        $this->postPtp($case, $this->marketing, $this->ptpPayload());

        $authorities = config('shm-business-rule-contract.collection_workflow.escalation.authorities');

        $this->actingAs($this->marketing)
            ->get(route('shm.collection.show', $case->shm_loan_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('collection_case.owner.user_id', $this->marketing->id)
                ->where('collection_case.owner.context', 'marketing')
                ->where('collection_case.escalation.is_escalated', false)
                ->where('collection_case.escalation.authorities', $authorities)
                ->where('collection_case.escalation.assigned_authority', null)
                ->where('collection_case.ptp.history.0.status', ShmCollectionPtp::STATUS_OPEN)
                ->where('collection_case.ptp.can_create', false, 'PTP OPEN sudah ada — form disembunyikan.')
                ->where('collection_case.can_record', true),
            );
    }
}
