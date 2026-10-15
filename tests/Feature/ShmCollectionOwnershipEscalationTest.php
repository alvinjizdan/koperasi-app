<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmCollectionActivity;
use App\Models\ShmCollectionCase;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanSettlement;
use App\Models\ShmSettlementDiscountPolicy;
use App\Models\User;
use App\Services\Shm\ShmCollectionActivityService;
use App\Services\Shm\ShmCollectionCaseService;
use App\Services\Shm\ShmLoanPaymentAllocationService;
use App\Services\Shm\ShmLoanPaymentService;
use App\Services\Shm\ShmLoanSettlementService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Ticket 96 — Collection Assignment & Escalation Authority (fondasi aman).
 *
 * Mengunci: kepemilikan Marketing tetap otoritatif dari pengajuan asal
 * (tanpa fallback user), payload case mengekspos owner + konteks eskalasi
 * secara SERVER-DERIVED, ESCALATED hanya melalui graph transisi T92/T94 yang
 * sama, dan TIDAK ADA eskalasi otomatis dari pembayaran/alokasi/aktivitas/
 * settlement. Otorisasi mutasi tetap owner-only (shm.view_all tidak bypass);
 * guard finansial resolusi T95 tidak berubah.
 *
 * Manual escalation oleh otoritas BM/PD TIDAK diimplementasikan: role
 * teknis Branch Manager / President Director tidak ada (contract T91 —
 * `technical_role_exists` false; role teknis = admin/kasir/marketing/
 * pengawas) sehingga pemetaan otoritas tidak dapat dilakukan tanpa
 * mengarang role/permission baru — PENDING BUSINESS CONFIRMATION. Transisi
 * → ESCALATED yang sudah ada (pemilik, graph T92) TIDAK diubah.
 */
class ShmCollectionOwnershipEscalationTest extends TestCase
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
        // Keduanya tidak otomatis boleh memutasi case bukan miliknya.
        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->marketing2 = $this->userWithRole('marketing');
    }

    // ------------------------------------------------------------------
    // Fixtures (pola identik T92/T94 — kelayakan & owner dengan pola sama)
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

    private function transitionTo(ShmCollectionCase $case, string $toStatus, ?User $actor = null): ShmCollectionCase
    {
        $actor ??= $this->marketing;

        return app(ShmCollectionCaseService::class)->transition($case, $toStatus, (int) $actor->id);
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertHttpTransitionRejected(ShmCollectionCase $case, User $as, array $payload, string $errorKey): void
    {
        $before = $case->refresh()->status;

        $this->postTransition($case, $as, $payload)
            ->assertSessionHasErrors($errorKey);

        $this->assertSame($before, $case->refresh()->status);
    }

    private function statusChangeAuditCount(ShmCollectionCase $case): int
    {
        return AuditLog::query()
            ->where('action', 'shm.collection.case.status_changed')
            ->where('object_type', ShmCollectionCase::class)
            ->where('object_id', $case->id)
            ->count();
    }

    private function recordActivity(ShmCollectionCase $case, string $result, User $actor): ShmCollectionActivity
    {
        return app(ShmCollectionActivityService::class)->create($case, (int) $actor->id, [
            'activity_type' => ShmCollectionActivity::TYPE_PHONE_CALL,
            'result' => $result,
            'activity_at' => now()->toDateTimeString(),
        ]);
    }

    // ------------------------------------------------------------------
    // A. Ownership — pemilik Marketing otoritatif, terekspos di payload
    // ------------------------------------------------------------------

    public function test_detail_payload_exposes_originating_marketing_owner_with_context(): void
    {
        $case = $this->caseOf($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.collection.show', $case->shm_loan_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Shm/Collection/Show')
                ->where('collection_case.owner.user_id', $this->marketing->id)
                ->where('collection_case.owner.name', $this->marketing->name)
                ->where('collection_case.owner.context', 'marketing')
                ->where('collection_case.marketing_id', $this->marketing->id),
            );
    }

    public function test_owner_is_marketing_not_first_or_random_user_even_for_other_viewer(): void
    {
        $case = $this->caseOf($this->marketing);

        // Pemirsa admin (shm.view_all) — pemilik payload TETAP Marketing
        // asal, bukan pemirsa dan bukan user pertama di database.
        $this->actingAs($this->admin)
            ->get(route('shm.collection.show', $case->shm_loan_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('collection_case.owner.user_id', $this->marketing->id)
                ->where('collection_case.owner.name', $this->marketing->name)
                ->where('collection_case.can_record', false),
            );

        $firstUserId = (int) User::query()->orderBy('id')->value('id');
        $this->assertSame($this->admin->id, $firstUserId, 'Fixture: admin = user pertama di database.');
        $this->assertSame($this->marketing->id, (int) $case->marketing_id);
        $this->assertNotSame($firstUserId, (int) $case->marketing_id);
        $this->assertNotSame($this->admin->id, (int) $case->marketing_id);
    }

    public function test_loan_application_without_marketing_owner_is_rejected_without_fallback(): void
    {
        $application = $this->applicationOwnedBy($this->marketing);
        $application->marketing_id = null;
        $application->save();

        $loan = $this->loanForApplication($application);
        $this->scheduleOf($loan, [
            ['due_date' => today()->subDays(30)->toDateString()],
        ]);

        try {
            app(ShmCollectionCaseService::class)->createOrGetForLoan($loan, (int) $this->marketing->id);
            $this->fail('Kasus tanpa Marketing owner seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collection.pemilik', $exception->errors());
        }

        // TIDAK ada case yang dibuat — tanpa fallback user sama sekali.
        $this->assertDatabaseCount('shm_collection_cases', 0);
    }

    // ------------------------------------------------------------------
    // B. Authorization — otorisasi mutasi owner-only tetap utuh
    // ------------------------------------------------------------------

    public function test_owner_can_still_transition_case(): void
    {
        $case = $this->caseOf($this->marketing);

        $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS);

        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->refresh()->status);
    }

    public function test_non_owner_marketing_cannot_mutate_case(): void
    {
        $case = $this->caseOf($this->marketing);

        $this->assertHttpTransitionRejected($case, $this->marketing2, [
            'status' => ShmCollectionCase::STATUS_ESCALATED,
        ], 'collection.otorisasi');

        $this->assertSame(ShmCollectionCase::STATUS_OPEN, $case->refresh()->status);
    }

    public function test_view_all_admin_cannot_mutate_case(): void
    {
        $case = $this->caseOf($this->marketing);

        $this->assertHttpTransitionRejected($case, $this->admin, [
            'status' => ShmCollectionCase::STATUS_IN_PROGRESS,
        ], 'collection.otorisasi');
    }

    public function test_activity_authorization_remains_owner_only(): void
    {
        $case = $this->caseOf($this->marketing);

        try {
            $this->recordActivity($case, ShmCollectionActivity::RESULT_UNABLE_TO_PAY, $this->marketing2);
            $this->fail('Non-pemilik seharusnya tidak bisa mencatat aktivitas.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collection.otorisasi', $exception->errors());
        }

        $activity = $this->recordActivity($case, ShmCollectionActivity::RESULT_CONTACTED, $this->marketing);
        $this->assertSame((int) $this->marketing->id, (int) $activity->user_id);
        $this->assertDatabaseCount('shm_collection_activities', 1);
    }

    // ------------------------------------------------------------------
    // C. Escalation — status otoritatif, tanpa eskalasi otomatis
    // ------------------------------------------------------------------

    public function test_escalated_status_is_reachable_only_through_allowed_transition(): void
    {
        $case = $this->caseOf($this->marketing);

        // OPEN → ESCALATED tidak ada di graph T92 — ditolak, tetap OPEN.
        try {
            $this->transitionTo($case, ShmCollectionCase::STATUS_ESCALATED);
            $this->fail('Transisi OPEN → ESCALATED di luar graph seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collection.status', $exception->errors());
        }

        $this->assertSame(ShmCollectionCase::STATUS_OPEN, $case->refresh()->status);
        $this->assertSame(0, $this->statusChangeAuditCount($case));
    }

    public function test_payment_and_allocation_do_not_escalate_case(): void
    {
        $case = $this->caseOf($this->marketing);
        $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS);
        $auditsAfterWalk = $this->statusChangeAuditCount($case);

        $this->allocate($this->paymentFor($case->loan, 500_000, $this->admin));

        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->refresh()->status);
        $this->assertSame($auditsAfterWalk, $this->statusChangeAuditCount($case));
    }

    public function test_promise_to_pay_activity_does_not_escalate_case(): void
    {
        $case = $this->caseOf($this->marketing);
        $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS);

        $this->recordActivity($case, ShmCollectionActivity::RESULT_PROMISE_TO_PAY, $this->marketing);

        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->refresh()->status);
    }

    public function test_paid_activity_does_not_escalate_case(): void
    {
        $case = $this->caseOf($this->marketing);
        $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS);

        $this->recordActivity($case, ShmCollectionActivity::RESULT_PAID, $this->marketing);

        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->refresh()->status);
    }

    public function test_unable_to_pay_activity_does_not_escalate_case(): void
    {
        $case = $this->caseOf($this->marketing);
        $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS);

        $this->recordActivity($case, ShmCollectionActivity::RESULT_UNABLE_TO_PAY, $this->marketing);

        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->refresh()->status);
    }

    public function test_settlement_request_does_not_escalate_case(): void
    {
        $case = $this->caseOf($this->marketing);
        $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS);
        $auditsAfterWalk = $this->statusChangeAuditCount($case);

        // Angsuran masa depan + policy diskon → pengajuan pelunasan valid.
        $case->loan->installments()->create([
            'installment_number' => 2,
            'due_date' => today()->addMonth()->startOfMonth()->toDateString(),
            'principal_amount' => '1000000',
            'interest_amount' => '200000',
            'total_amount' => '1200000',
            'paid_amount' => 0,
            'paid_interest_amount' => 0,
            'paid_principal_amount' => 0,
            'remaining_amount' => '1200000',
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);
        ShmSettlementDiscountPolicy::query()->create([
            'min_remaining_months' => 0,
            'max_remaining_months' => 12,
            'discount_percent' => 10,
            'is_active' => true,
        ]);

        $settlement = app(ShmLoanSettlementService::class)->request($case->loan, [
            'penalty' => 0,
        ], (int) $this->admin->id);

        $this->assertSame(ShmLoanSettlement::STATUS_PENDING, $settlement->status);
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->refresh()->status);
        $this->assertSame($auditsAfterWalk, $this->statusChangeAuditCount($case));
    }

    public function test_escalation_writes_exactly_one_status_changed_audit(): void
    {
        $case = $this->caseOf($this->marketing);
        $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS);
        $auditsBefore = $this->statusChangeAuditCount($case);

        $this->transitionTo($case, ShmCollectionCase::STATUS_ESCALATED);

        $this->assertSame(ShmCollectionCase::STATUS_ESCALATED, $case->refresh()->status);
        $this->assertSame($auditsBefore + 1, $this->statusChangeAuditCount($case));
    }

    // ------------------------------------------------------------------
    // D. Financial safety — eskalasi tidak menyentuh domain finansial
    // ------------------------------------------------------------------

    public function test_escalation_does_not_touch_financial_state_or_close_loan(): void
    {
        $case = $this->caseOf($this->marketing);
        $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS);

        $paidPrincipalBefore = (float) $case->loan->installments()->sum('paid_principal_amount');
        $paidInterestBefore = (float) $case->loan->installments()->sum('paid_interest_amount');

        $this->transitionTo($case, ShmCollectionCase::STATUS_ESCALATED);

        $case->loan->refresh();

        $this->assertSame(ShmLoan::STATUS_ACTIVE, $case->loan->status);
        $this->assertDatabaseCount('shm_loan_payments', 0);
        $this->assertDatabaseCount('shm_loan_settlements', 0);
        $this->assertSame($paidPrincipalBefore, (float) $case->loan->installments()->sum('paid_principal_amount'));
        $this->assertSame($paidInterestBefore, (float) $case->loan->installments()->sum('paid_interest_amount'));
    }

    public function test_resolution_guard_remains_intact_after_escalation(): void
    {
        $case = $this->caseOf($this->marketing);
        $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS);
        $this->transitionTo($case, ShmCollectionCase::STATUS_ESCALATED);

        // Guard finansial T95 tetap berlaku pada case ESCALATED: resolusi
        // saat tunggakan masih ada DITOLAK.
        try {
            app(ShmCollectionCaseService::class)->resolve($case, (int) $this->marketing->id);
            $this->fail('Resolusi saat tunggakan masih ada seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collection.resolusi', $exception->errors());
        }

        $this->assertSame(ShmCollectionCase::STATUS_ESCALATED, $case->refresh()->status);

        // Setelah tunggakan lunas (engine pembayaran asli), resolusi manual
        // pemilik tetap berfungsi — ESCALATED → RESOLVED ada di graph.
        $this->settleOverdue($case);
        app(ShmCollectionCaseService::class)->resolve($case, (int) $this->marketing->id);

        $this->assertSame(ShmCollectionCase::STATUS_RESOLVED, $case->refresh()->status);
    }

    // ------------------------------------------------------------------
    // E. Konteks eskalasi payload — baca saja, tanpa assignee
    // ------------------------------------------------------------------

    public function test_escalation_context_is_exposed_read_only_without_assignee(): void
    {
        $authorities = config('shm-business-rule-contract.collection_workflow.escalation.authorities');

        // Case normal: bukan eskalasi, otoritas BM/PD dari contract, tanpa
        // assignee (assignment = PENDING BUSINESS CONFIRMATION).
        $case = $this->caseOf($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.collection.show', $case->shm_loan_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('collection_case.escalation.is_escalated', false)
                ->where('collection_case.escalation.authorities', $authorities)
                ->where('collection_case.escalation.assigned_authority', null),
            );

        // Case ESCALATED: state true, otoritas tetap daftar bisnis contract
        // (BUKAN nama orang), assignee tetap null.
        $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS);
        $this->transitionTo($case, ShmCollectionCase::STATUS_ESCALATED);

        $this->actingAs($this->marketing)
            ->get(route('shm.collection.show', $case->shm_loan_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('collection_case.status', ShmCollectionCase::STATUS_ESCALATED)
                ->where('collection_case.escalation.is_escalated', true)
                ->where('collection_case.escalation.authorities', $authorities)
                ->where('collection_case.escalation.assigned_authority', null),
            );
    }

    public function test_detail_get_request_with_escalation_context_writes_no_audit(): void
    {
        $case = $this->caseOf($this->marketing);
        $this->transitionTo($case, ShmCollectionCase::STATUS_IN_PROGRESS);
        $this->transitionTo($case, ShmCollectionCase::STATUS_ESCALATED);

        $before = AuditLog::query()->count();

        $this->actingAs($this->marketing)
            ->get(route('shm.collection.show', $case->shm_loan_id))
            ->assertOk();

        $this->assertSame($before, AuditLog::query()->count());
    }

    // ------------------------------------------------------------------
    // F. Pemetaan teknis BM/PD — tidak ada, sehingga authority escalation
    //    manual TIDAK diimplementasikan (dokumentasi eksplisit PENDING)
    // ------------------------------------------------------------------

    public function test_bm_pd_technical_roles_and_escalation_permissions_do_not_exist(): void
    {
        // Contract T91: role teknis BM/PD belum ada — T96 tidak membuatnya.
        $this->assertFalse(
            (bool) config('shm-business-rule-contract.collection_workflow.roles.branch_manager.technical_role_exists'),
        );
        $this->assertFalse(
            (bool) config('shm-business-rule-contract.collection_workflow.roles.president_director.technical_role_exists'),
        );
        $this->assertNull(
            config('shm-business-rule-contract.collection_workflow.roles.branch_manager.technical_role_key'),
        );
        $this->assertNull(
            config('shm-business-rule-contract.collection_workflow.roles.president_director.technical_role_key'),
        );

        // Tidak ada role teknis BM/PD di database (hanya role existing).
        $this->assertSame(
            [],
            Role::query()->whereIn('name', ['branch_manager', 'president_director'])->pluck('name')->all(),
        );

        // T96 tidak menambahkan permission eskalasi/assignment apa pun pada
        // modul SHM (permission 'assign' lain yang ada berasal dari modul
        // pengguna — di luar scope Collection, tidak disentuh T96).
        $this->assertSame(
            [],
            Permission::query()
                ->where('name', 'like', 'shm.%')
                ->where(function ($query): void {
                    $query->where('name', 'like', '%escalat%')
                        ->orWhere('name', 'like', '%assign%');
                })
                ->pluck('name')
                ->all(),
        );

        // T99: threshold eskalasi jalur PTP CONFIRMED (3 PTP MISSED per
        // periode anchored — dari ticket, bukan tebakan); threshold non-PTP
        // tetap tanpa angka otomatis (faktor manusia).
        $this->assertStringContainsString(
            '3 PTP MISSED',
            (string) config('shm-business-rule-contract.collection_workflow.escalation.threshold'),
        );
        $this->assertSame(
            'CONDITIONAL',
            config('shm-business-rule-contract.collection_workflow.escalation.automatic_escalation'),
        );
        $this->assertSame(
            3,
            config('shm-business-rule-contract.collection_workflow.escalation.missed_ptp_threshold'),
        );
        $this->assertTrue((bool) config('shm-business-rule-contract.collection_workflow.escalation.approval_workflow_unchanged'));
        $this->assertStringContainsString(
            'PENDING BUSINESS CONFIRMATION',
            (string) config('shm-business-rule-contract.collection_workflow.escalation.assignment_algorithm'),
        );
    }
}
