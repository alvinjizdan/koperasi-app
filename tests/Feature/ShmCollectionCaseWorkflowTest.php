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
use App\Models\User;
use App\Services\Shm\ShmCollectionCaseService;
use App\Services\Shm\ShmLoanPaymentAllocationService;
use App\Services\Shm\ShmLoanPaymentService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Ticket 94 — Collection Case workflow & status transition (HTTP + payload).
 *
 * Mengunci: endpoint transisi tunggal memakai graph `ALLOWED_TRANSITIONS` T92
 * (contract T91 menyerahkan transition table ke ticket implementasi), target
 * status dibatasi Form Request ke 5 status lifecycle (string arbitrer
 * ditolak), otorisasi mutation = PEMILIK case saja (admin/shm.view_all tidak
 * bypass), aktor dari session (actor/owner/loan/resolved_at frontend DIABAIKAN),
 * resolved_at dikontrol server (hanya terisi saat memasuki RESOLVED dan
 * permanen — RESOLVED terminal tanpa reopen), audit TUNGGAL
 * `shm.collection.case.status_changed` hanya pada keberhasilan, payload
 * detail menyediakan status + `allowed_transitions` (kosong untuk
 * non-pemilik & RESOLVED), dan JAMINAN keras: aktivitas PROMISE_TO_PAY/PAID/
 * UNABLE_TO_PAY TIDAK pernah mengubah status case secara otomatis.
 *
 * Tidak ada pengujian detail PTP (amount/tanggal/fulfillment — PROMISE_TO_PAY
 * murni state workflow), escalation authority BM/PD, penalty, notification,
 * maupun assignment — semuanya PENDING dan sengaja tidak diimplementasikan.
 *
 * Sejak Ticket 95 transisi → RESOLVED dijaga guard finansial (sisa tunggakan
 * overdue harus 0); fixture yang menguji jalur RESOLVED karenanya melunasi
 * tunggakan lebih dulu lewat payment/allocation engine ASLI — perilaku yang
 * diuji (graph, terminal, resolved_at, audit, otorisasi) tidak berubah.
 */
class ShmCollectionCaseWorkflowTest extends TestCase
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
    // Fixtures (identik dengan T92/T93 — kelayakan & owner pakai pola sama)
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
     * Collection Case aktif milik $owner (lewat service T92 — jalur resmi).
     */
    private function caseOf(User $owner, int $daysOverdue = 30): ShmCollectionCase
    {
        $application = $this->applicationOwnedBy($owner);
        $loan = $this->loanForApplication($application);

        $loan->installments()->create([
            'installment_number' => 1,
            'due_date' => today()->subDays($daysOverdue)->toDateString(),
            'principal_amount' => '1000000',
            'interest_amount' => '200000',
            'total_amount' => '1200000',
            'paid_amount' => 0,
            'paid_interest_amount' => 0,
            'paid_principal_amount' => 0,
            'remaining_amount' => '1200000',
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        return app(ShmCollectionCaseService::class)->createOrGetForLoan($loan, (int) $owner->id);
    }

    private function service(): ShmCollectionCaseService
    {
        return app(ShmCollectionCaseService::class);
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

        $payment = app(ShmLoanPaymentService::class)->create($case->loan, [
            'payment_date' => now()->toDateString(),
            'amount' => round($outstanding, 2),
        ], (int) $this->admin->id);

        app(ShmLoanPaymentAllocationService::class)->allocate($payment);
    }

    /**
     * Bawa case ke $status melalui jalur transisi valid (setup — bukan uji).
     * Sebelum langkah RESOLVED tunggakan dilunasi (guard T95).
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

    private function caseInStatus(string $status): ShmCollectionCase
    {
        return $this->walkTo($this->caseOf($this->marketing), $status);
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
     * Transisi lewat HTTP DITOLAK (errors + status tidak berubah).
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertHttpTransitionRejected(ShmCollectionCase $case, User $as, array $payload, string $errorKey): void
    {
        $before = $case->refresh()->status;

        $response = $this->postTransition($case, $as, $payload);

        $response->assertSessionHasErrors($errorKey);
        $this->assertSame(
            $before,
            $case->refresh()->status,
            sprintf('Status berubah walau transisi ditolak: %s.', $before),
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

    // ------------------------------------------------------------------
    // A–D. Graph transisi per status via endpoint workflow (valid & invalid)
    // ------------------------------------------------------------------

    public function test_open_case_can_transition_to_in_progress_via_http(): void
    {
        $case = $this->caseOf($this->marketing);

        $response = $this->postTransition($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_IN_PROGRESS,
        ]);

        $response
            ->assertRedirect(route('shm.collection.show', $case->shm_loan_id))
            ->assertSessionHas('success');
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->refresh()->status);
        $this->assertNull($case->resolved_at);
    }

    public function test_open_case_rejects_invalid_transition_via_http(): void
    {
        $case = $this->caseOf($this->marketing);
        $auditsBefore = $this->statusChangeAuditCount($case);

        $this->assertHttpTransitionRejected($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_ESCALATED,
        ], 'collection.status');

        $this->assertSame($auditsBefore, $this->statusChangeAuditCount($case));
    }

    public function test_in_progress_case_can_transition_to_escalated_via_http(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_IN_PROGRESS);

        $response = $this->postTransition($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_ESCALATED,
        ]);

        $response->assertRedirect(route('shm.collection.show', $case->shm_loan_id));
        $this->assertSame(ShmCollectionCase::STATUS_ESCALATED, $case->refresh()->status);
    }

    public function test_in_progress_case_cannot_transition_back_to_open(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_IN_PROGRESS);

        $this->assertHttpTransitionRejected($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_OPEN,
        ], 'collection.status');
    }

    public function test_same_status_transition_is_rejected(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_IN_PROGRESS);

        $this->assertHttpTransitionRejected($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_IN_PROGRESS,
        ], 'collection.status');
    }

    public function test_promise_to_pay_case_can_transition_without_any_ptp_data(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_PROMISE_TO_PAY);

        // Hanya `status` — TIDAK ada amount/tanggal/grace PTP yang diminta.
        $response = $this->postTransition($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_ESCALATED,
        ]);

        $response->assertRedirect(route('shm.collection.show', $case->shm_loan_id));
        $this->assertSame(ShmCollectionCase::STATUS_ESCALATED, $case->refresh()->status);
    }

    public function test_promise_to_pay_case_cannot_transition_back_to_open(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_PROMISE_TO_PAY);

        $this->assertHttpTransitionRejected($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_OPEN,
        ], 'collection.status');
    }

    public function test_transition_endpoint_ignores_ptp_and_identity_fields_from_frontend(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_IN_PROGRESS);

        $response = $this->postTransition($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_PROMISE_TO_PAY,
            'actor_id' => $this->marketing2->id,
            'marketing_id' => $this->marketing2->id,
            'loan_id' => 999999,
            'promised_amount' => 99999999,
            'promised_date' => '2000-01-01',
            'resolved_at' => '2000-01-01 00:00:00',
        ]);

        $response->assertRedirect(route('shm.collection.show', $case->shm_loan_id));
        $case->refresh();

        // Hanya status yang berubah; identitas & timestamp diabaikan, tanpa
        // data PTP yang tersimpan (tidak ada kolom/tabel PTP di scope T94).
        $this->assertSame(ShmCollectionCase::STATUS_PROMISE_TO_PAY, $case->status);
        $this->assertNull($case->resolved_at);
        $this->assertSame((int) $this->marketing->id, (int) $case->marketing_id);

        $log = AuditLog::query()
            ->where('action', 'shm.collection.case.status_changed')
            ->where('object_id', $case->id)
            ->firstOrFail();
        $this->assertSame((int) $this->marketing->id, $log->changes['actor_id']);
        $this->assertArrayNotHasKey('promised_amount', $log->changes);
    }

    public function test_escalated_case_can_transition_back_to_in_progress_via_http(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_ESCALATED);

        $response = $this->postTransition($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_IN_PROGRESS,
        ]);

        $response->assertRedirect(route('shm.collection.show', $case->shm_loan_id));
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->refresh()->status);
    }

    public function test_escalated_case_cannot_transition_to_promise_to_pay(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_ESCALATED);

        $this->assertHttpTransitionRejected($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_PROMISE_TO_PAY,
        ], 'collection.status');
    }

    // ------------------------------------------------------------------
    // E. RESOLVED terminal — tanpa reopen
    // ------------------------------------------------------------------

    public function test_resolved_case_rejects_all_transitions_and_cannot_reopen(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_RESOLVED);
        $resolvedAt = $case->resolved_at;

        foreach ([
            ShmCollectionCase::STATUS_OPEN,
            ShmCollectionCase::STATUS_IN_PROGRESS,
            ShmCollectionCase::STATUS_PROMISE_TO_PAY,
            ShmCollectionCase::STATUS_ESCALATED,
            ShmCollectionCase::STATUS_RESOLVED,
        ] as $target) {
            $this->assertHttpTransitionRejected($case, $this->marketing, ['status' => $target], 'collection.status');
        }

        $this->assertSame(ShmCollectionCase::STATUS_RESOLVED, $case->refresh()->status);
        $this->assertTrue($resolvedAt->equalTo($case->resolved_at), 'resolved_at permanen pada case RESOLVED.');
    }

    // ------------------------------------------------------------------
    // F. Otorisasi (pemilik saja; shm.view_all tidak bypass)
    // ------------------------------------------------------------------

    public function test_non_owner_marketing_cannot_transition_case_via_http(): void
    {
        $case = $this->caseOf($this->marketing);
        $auditsBefore = $this->statusChangeAuditCount($case);

        $this->assertHttpTransitionRejected($case, $this->marketing2, [
            'status' => ShmCollectionCase::STATUS_IN_PROGRESS,
        ], 'collection.otorisasi');

        $this->assertSame($auditsBefore, $this->statusChangeAuditCount($case));
    }

    public function test_view_all_admin_cannot_bypass_owner_authorization(): void
    {
        $case = $this->caseOf($this->marketing);

        $this->assertHttpTransitionRejected($case, $this->admin, [
            'status' => ShmCollectionCase::STATUS_IN_PROGRESS,
        ], 'collection.otorisasi');
        $this->assertSame(ShmCollectionCase::STATUS_OPEN, $case->refresh()->status);
    }

    // ------------------------------------------------------------------
    // G. Audit transisi (tepat satu pada sukses; nol pada kegagalan)
    // ------------------------------------------------------------------

    public function test_successful_transition_writes_exactly_one_status_changed_audit(): void
    {
        $case = $this->caseOf($this->marketing);

        $this->postTransition($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_IN_PROGRESS,
        ]);

        $this->assertSame(1, $this->statusChangeAuditCount($case));

        $log = AuditLog::query()
            ->where('action', 'shm.collection.case.status_changed')
            ->where('object_id', $case->id)
            ->firstOrFail();

        $this->assertSame(ShmCollectionCase::STATUS_OPEN, $log->changes['from_status']);
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $log->changes['to_status']);
        $this->assertSame((int) $this->marketing->id, $log->changes['actor_id']);
        $this->assertSame((int) $this->marketing->id, $log->actor_id);
        $this->assertSame($case->shm_loan_id, $log->changes['shm_loan_id']);
    }

    public function test_failed_transition_writes_no_audit(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_ESCALATED);
        $auditsBefore = $this->statusChangeAuditCount($case);

        $this->postTransition($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_OPEN,
        ])->assertSessionHasErrors('collection.status');

        $this->assertSame($auditsBefore, $this->statusChangeAuditCount($case));
    }

    // ------------------------------------------------------------------
    // H. resolved_at sepenuhnya dikontrol server
    // ------------------------------------------------------------------

    public function test_resolved_at_is_set_by_server_when_entering_resolved(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_IN_PROGRESS);
        // Guard finansial T95 — tunggakan dilunasi sebelum resolusi eksplisit.
        $this->settleOverdue($case);
        $before = now();

        $this->postTransition($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_RESOLVED,
        ])->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(ShmCollectionCase::STATUS_RESOLVED, $case->status);
        $this->assertNotNull($case->resolved_at);
        $this->assertFalse($case->resolved_at->lt($before->subSecond()));
        $this->assertFalse($case->resolved_at->gt(now()->addSecond()));
    }

    public function test_resolved_at_stays_null_for_non_resolved_transition(): void
    {
        $case = $this->caseOf($this->marketing);

        $this->postTransition($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_IN_PROGRESS,
        ]);

        $this->assertNull($case->refresh()->resolved_at);
    }

    public function test_frontend_supplied_resolved_at_is_ignored(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_IN_PROGRESS);
        // Guard finansial T95 — tunggakan dilunasi sebelum resolusi eksplisit.
        $this->settleOverdue($case);

        $this->postTransition($case, $this->marketing, [
            'status' => ShmCollectionCase::STATUS_RESOLVED,
            'resolved_at' => '2000-01-01 00:00:00',
        ]);

        $case->refresh();
        $this->assertSame(ShmCollectionCase::STATUS_RESOLVED, $case->status);
        $this->assertFalse(
            $case->resolved_at->equalTo(Carbon::parse('2000-01-01 00:00:00')),
            'resolved_at frontend tidak boleh dipakai.',
        );
        $this->assertTrue($case->resolved_at->isToday());
    }

    // ------------------------------------------------------------------
    // I. Independensi aktivitas — tanpa efek samping lifecycle (T93 tetap)
    // ------------------------------------------------------------------

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

    public function test_promise_to_pay_activity_does_not_auto_transition_case(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_IN_PROGRESS);

        $this->postActivity($case, $this->marketing, ShmCollectionActivity::RESULT_PROMISE_TO_PAY)
            ->assertSessionHas('success');

        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->refresh()->status);
    }

    public function test_paid_activity_does_not_auto_resolve_case(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_IN_PROGRESS);

        $this->postActivity($case, $this->marketing, ShmCollectionActivity::RESULT_PAID)
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->status);
        $this->assertNull($case->resolved_at);
        $this->assertDatabaseCount('shm_loan_payments', 0);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $case->loan->status);
    }

    public function test_unable_to_pay_activity_does_not_auto_escalate_case(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_IN_PROGRESS);

        $this->postActivity($case, $this->marketing, ShmCollectionActivity::RESULT_UNABLE_TO_PAY)
            ->assertSessionHas('success');

        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->refresh()->status);
    }

    // ------------------------------------------------------------------
    // J. Payload detail (sumber aksi UI: status + allowed_transitions)
    // ------------------------------------------------------------------

    public function test_detail_page_exposes_status_and_allowed_transitions_for_owner(): void
    {
        $case = $this->caseOf($this->marketing);

        $response = $this->actingAs($this->marketing)
            ->get(route('shm.collection.show', $case->shm_loan_id));

        $response->assertInertia(fn (Assert $page): Assert => $page
            ->component('Shm/Collection/Show')
            ->where('collection_case.status', ShmCollectionCase::STATUS_OPEN)
            ->where('collection_case.can_record', true)
            ->where('collection_case.resolved_at', null)
            ->where('collection_case.allowed_transitions', [
                ShmCollectionCase::STATUS_IN_PROGRESS,
                ShmCollectionCase::STATUS_RESOLVED,
            ]));
    }

    public function test_detail_page_hides_allowed_transitions_for_non_owner_viewer(): void
    {
        $case = $this->caseOf($this->marketing);

        // admin (shm.view_all) dapat MELIHAT halaman detail tetapi bukan
        // pemilik — tidak menerima daftar aksi transisi sama sekali.
        $response = $this->actingAs($this->admin)
            ->get(route('shm.collection.show', $case->shm_loan_id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): Assert => $page
            ->component('Shm/Collection/Show')
            ->where('collection_case.can_record', false)
            ->where('collection_case.allowed_transitions', []));
    }

    public function test_detail_page_resolved_case_exposes_no_transitions_even_for_owner(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_RESOLVED);

        $this->actingAs($this->marketing)
            ->get(route('shm.collection.show', $case->shm_loan_id))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Shm/Collection/Show')
                ->where('collection_case.status', ShmCollectionCase::STATUS_RESOLVED)
                ->where('collection_case.allowed_transitions', []));
    }

    public function test_unknown_status_target_is_rejected_by_request_validation(): void
    {
        $case = $this->caseOf($this->marketing);

        $response = $this->postTransition($case, $this->marketing, [
            'status' => 'CLOSED',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame(ShmCollectionCase::STATUS_OPEN, $case->refresh()->status);
    }

    // ------------------------------------------------------------------
    // K. GET tidak pernah memutasi
    // ------------------------------------------------------------------

    public function test_detail_page_get_request_does_not_mutate_case(): void
    {
        $case = $this->caseInStatus(ShmCollectionCase::STATUS_IN_PROGRESS);
        $auditsBefore = $this->statusChangeAuditCount($case);
        $updatedAtBefore = $case->updated_at;

        $this->actingAs($this->marketing)
            ->get(route('shm.collection.show', $case->shm_loan_id))
            ->assertOk();

        $case->refresh();
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->status);
        $this->assertTrue($updatedAtBefore->equalTo($case->updated_at));
        $this->assertSame($auditsBefore, $this->statusChangeAuditCount($case));
    }
}
