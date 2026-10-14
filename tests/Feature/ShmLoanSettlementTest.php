<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanPaymentAllocation;
use App\Models\ShmLoanSettlement;
use App\Models\ShmSettlementDiscountPolicy;
use App\Models\User;
use App\Services\Shm\ShmLoanPaymentAllocationService;
use App\Services\Shm\ShmLoanPaymentService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ticket 89 — Settlement / Pelunasan Pinjaman SHM (fundasi T89, diperbarui
 * mengikuti T89B Model B).
 *
 * Mengunci: preview READ-ONLY dari persisted state (pokok outstanding,
 * bunga jatuh tempo dari jadwal persisted, bunga masa depan hanya jadi
 * base discount bila memenuhi syarat, penalty 0 di preview, overpayment
 * tampil terpisah tanpa dikonsumsi), eligibility active+disbursed, dan
 * otorisasi server-side (shm.view + ownership untuk baca;
 * shm.settlement.create TERPISAH untuk seluruh tulisan).
 *
 * T89B MENGUBAH perilaku konfirmasi (superseded, bukan regression):
 * pengajuan kini MEMBUAT settlement pending + snapshot policy — tanpa
 * penutupan pinjaman, tanpa audit completion. Pinjaman yang ELIGIBLE tanpa
 * policy diskon TIDAK boleh diam-diam memakai 0%: ditolak eksplisit.
 * Workflow completion diuji di ShmSettlementCompletionTest.
 */
class ShmLoanSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketing;

    private User $marketing2;

    private User $settlementUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->marketing2 = $this->userWithRole('marketing');
        $this->settlementUser = $this->userWithPermissions([
            'shm.view',
            'shm.payment.create',
            'shm.settlement.create',
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $role = Role::query()->create([
            'name' => 'settlement-tester-'.Str::uuid(),
            'display_name' => 'Settlement Tester',
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id')
        );

        $user->roles()->attach($role->id);

        return $user;
    }

    private function applicationOwnedBy(User $user): ShmLoanApplication
    {
        return ShmLoanApplication::query()->create([
            'application_number' => 'PENGAJ-PIN-'.Str::upper(Str::random(10)),
            'shm_customer_id' => ShmCustomer::factory()->create()->id,
            'application_date' => now()->toDateString(),
            'principal' => '100000000',
            'tenor_months' => 12,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $user->id,
            'status' => 'approved',
        ]);
    }

    private function activeLoanOf(User $user, ?string $principal = null): ShmLoan
    {
        $application = $this->applicationOwnedBy($user);

        return ShmLoan::factory()->create([
            'shm_customer_id' => $application->shm_customer_id,
            'shm_loan_application_id' => $application->id,
            'principal' => $principal ?? $application->principal,
            'tenor_months' => $application->tenor_months,
        ]);
    }

    /**
     * Policy diskon fixture untuk rentang sisa bulan tertentu.
     */
    private function discountPolicy(int $min, int $max, string $percent): ShmSettlementDiscountPolicy
    {
        return ShmSettlementDiscountPolicy::query()->create([
            'min_remaining_months' => $min,
            'max_remaining_months' => $max,
            'discount_percent' => $percent,
            'is_active' => true,
        ]);
    }

    /**
     * Jadwal dengan dua angsuran: #1 SUDAH jatuh tempo (due tempo lalu),
     * #2 masa depan (due tempo depan) — untuk mengunci pemisahan bunga
     * due vs future pada preview.
     */
    private function scheduleWithDueAndFuture(ShmLoan $loan): iterable
    {
        $installments = collect();

        foreach ([['due' => now()->subMonth()->startOfMonth(), 'paid_i' => 0], ['due' => now()->addMonth()->startOfMonth(), 'paid_i' => 0]] as $index => $spec) {
            $installments->push($loan->installments()->create([
                'installment_number' => $index + 1,
                'due_date' => $spec['due']->toDateString(),
                'principal_amount' => 5_000_000,
                'interest_amount' => 1_500_000,
                'total_amount' => 6_500_000,
                'paid_amount' => 0,
                'paid_interest_amount' => $spec['paid_i'],
                'paid_principal_amount' => 0,
                'remaining_amount' => 6_500_000,
                'status' => ShmLoanInstallment::STATUS_SCHEDULED,
            ]));
        }

        return $installments;
    }

    private function paymentFor(ShmLoan $loan, float $amount, User $as): ShmLoanPayment
    {
        return app(ShmLoanPaymentService::class)->create($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
        ], (int) $as->id);
    }

    // ------------------------------------------------------------------
    // A. Preview authorization
    // ------------------------------------------------------------------

    public function test_authorized_user_can_preview(): void
    {
        $loan = $this->activeLoanOf($this->settlementUser);
        $this->scheduleWithDueAndFuture($loan);

        $this->actingAs($this->settlementUser)
            ->get(route('shm.loans.pelunasan.show', $loan))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Shm/Loans/Settlement'));
    }

    public function test_user_without_shm_view_is_rejected_on_preview(): void
    {
        $loan = $this->activeLoanOf($this->settlementUser);
        $this->scheduleWithDueAndFuture($loan);
        $user = $this->userWithPermissions(['dashboard.view']);

        $this->actingAs($user)
            ->get(route('shm.loans.pelunasan.show', $loan))
            ->assertForbidden();
    }

    public function test_ownership_scope_is_enforced_on_preview(): void
    {
        $loan = $this->activeLoanOf($this->marketing2);

        $this->actingAs($this->marketing)
            ->get(route('shm.loans.pelunasan.show', $loan))
            ->assertForbidden();
    }

    public function test_view_all_can_preview_cross_owner(): void
    {
        $loan = $this->activeLoanOf($this->marketing2);
        $this->scheduleWithDueAndFuture($loan);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.pelunasan.show', $loan))
            ->assertOk();
    }

    public function test_pelunasan_index_lists_eligible_loans_with_ownership_scope(): void
    {
        $this->activeLoanOf($this->marketing2);
        $own = $this->activeLoanOf($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.loans.pelunasan.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Loans/SettlementIndex')
                ->has('loans', 1)
                ->where('loans.0.id', $own->id));
    }

    // ------------------------------------------------------------------
    // B. Preview is strictly read-only
    // ------------------------------------------------------------------

    public function test_preview_does_not_mutate_any_financial_state(): void
    {
        $loan = $this->activeLoanOf($this->settlementUser);
        $installments = $this->scheduleWithDueAndFuture($loan);
        $payment = $this->paymentFor($loan, 2_000_000, $this->settlementUser);
        app(ShmLoanPaymentAllocationService::class)->allocate($payment);

        $before = [
            'settlements' => ShmLoanSettlement::query()->count(),
            'audits' => AuditLog::query()->count(),
            'allocations' => ShmLoanPaymentAllocation::query()->count(),
            'loan_status' => $loan->refresh()->status,
            'loan_principal' => (string) $loan->principal,
            'installment' => $installments->first()->refresh()->only([
                'paid_amount', 'paid_interest_amount', 'paid_principal_amount',
                'remaining_amount', 'status',
            ]),
            'payment_amount' => (string) $payment->refresh()->amount,
        ];

        // Buka preview berulang — GET saja.
        $this->actingAs($this->settlementUser)->get(route('shm.loans.pelunasan.show', $loan))->assertOk();
        $this->actingAs($this->settlementUser)->get(route('shm.loans.pelunasan.show', $loan))->assertOk();

        $this->assertSame($before['settlements'], ShmLoanSettlement::query()->count());
        $this->assertSame($before['audits'], AuditLog::query()->count());
        $this->assertSame($before['allocations'], ShmLoanPaymentAllocation::query()->count());
        $this->assertSame($before['loan_status'], $loan->refresh()->status);
        $this->assertSame($before['loan_principal'], (string) $loan->refresh()->principal);
        $this->assertSame($before['installment'], $installments->first()->refresh()->only(array_keys($before['installment'])));
        $this->assertSame($before['payment_amount'], (string) $payment->refresh()->amount);
    }

    public function test_preview_page_content_is_authoritative(): void
    {
        $loan = $this->activeLoanOf($this->settlementUser);
        $this->scheduleWithDueAndFuture($loan);
        // Pinjaman 100jt > threshold → eligible; sisa 1 bulan → policy 10%.
        $this->discountPolicy(1, 3, '10.00');
        // Pembayaran 2jt yang TIDAK dialokasikan → unallocated 2jt.
        $this->paymentFor($loan, 2_000_000, $this->settlementUser);

        $this->actingAs($this->settlementUser)
            ->get(route('shm.loans.pelunasan.show', $loan))
            ->assertInertia(function ($page): void {
                // Pokok outstanding = 2 angsuran × 5jt (belum ada alokasi).
                $page->where('preview.outstanding_principal', '10000000.00');
                // Bunga due (angsuran #1, tempo lalu) = 1,5jt — TIDAK didiskon.
                $page->where('preview.outstanding_interest_due', '1500000.00');
                // Bunga masa depan (angsuran #2) = base discount.
                $page->where('preview.remaining_interest', '1500000.00');
                $page->where('preview.remaining_months', 1);
                // Discount 10% × 1,5jt = 150rb; sisa bunga 1,35jt.
                $page->where('preview.eligible', true);
                $page->where('preview.applied', true);
                $page->where('preview.discount_status', 'applied');
                $page->where('preview.discount_amount', '150000.00');
                $page->where('preview.interest_after_discount', '1350000.00');
                $page->where('preview.policy_id', fn ($id) => $id !== null);
                // Penalty selalu 0 di preview (diinput manual saat pengajuan).
                $page->where('preview.outstanding_penalty', '0.00');
                // Overpayment tampil terpisah, TIDAK mengurangi amount.
                $page->where('preview.unallocated_payment', '2000000.00');
                // Settlement amount = pokok + bunga due + bunga setelah diskon.
                $page->where('preview.settlement_amount', '12850000.00');
                $page->has('preview.blocked_items');
                $page->where('loan.status', ShmLoan::STATUS_ACTIVE);
            });
    }

    public function test_preview_for_loan_at_or_below_threshold_has_no_discount(): void
    {
        // Plafon 30jt (<= threshold) → TIDAK eligible; bunga flat penuh.
        $loan = $this->activeLoanOf($this->settlementUser, '30000000');
        $this->scheduleWithDueAndFuture($loan);
        $this->discountPolicy(1, 3, '10.00'); // policy ada tapi tidak berlaku

        $this->actingAs($this->settlementUser)
            ->get(route('shm.loans.pelunasan.show', $loan))
            ->assertInertia(function ($page): void {
                $page->where('preview.eligible', false);
                $page->where('preview.applied', false);
                $page->where('preview.discount_status', 'not_eligible');
                $page->where('preview.discount_amount', '0.00');
                // Bunga masa depan dikenakan penuh (flat tanpa potongan).
                $page->where('preview.interest_after_discount', '1500000.00');
                // 10jt pokok (2 × 5jt) + 1,5jt due + 1,5jt future.
                $page->where('preview.settlement_amount', '13000000.00');
            });
    }

    public function test_preview_excludes_settled_interest_after_allocation(): void
    {
        // Plafon 30jt (<= threshold) → tanpa discount: perhitungan murni flat.
        $loan = $this->activeLoanOf($this->settlementUser, '30000000');
        $this->scheduleWithDueAndFuture($loan);
        // Bayar 6,5jt (angsuran #1 lunas: 5jt pokok + 1,5jt bunga due).
        $payment = $this->paymentFor($loan, 6_500_000, $this->settlementUser);
        app(ShmLoanPaymentAllocationService::class)->allocate($payment);

        $this->actingAs($this->settlementUser)
            ->get(route('shm.loans.pelunasan.show', $loan))
            ->assertInertia(function ($page): void {
                // Pokok outstanding tinggal angsuran #2 = 5jt.
                $page->where('preview.outstanding_principal', '5000000.00');
                // Bunga due sudah terbayar → 0; future = 1,5jt.
                $page->where('preview.outstanding_interest_due', '0.00');
                $page->where('preview.remaining_interest', '1500000.00');
                $page->where('preview.unallocated_payment', '0.00');
                // 5jt pokok + 1,5jt bunga masa depan penuh.
                $page->where('preview.settlement_amount', '6500000.00');
            });
    }

    // ------------------------------------------------------------------
    // C. Eligibility
    // ------------------------------------------------------------------

    public function test_closed_loan_cannot_be_previewed(): void
    {
        $loan = $this->activeLoanOf($this->settlementUser);
        $this->scheduleWithDueAndFuture($loan);
        $loan->update(['status' => ShmLoan::STATUS_CLOSED]);

        $this->actingAs($this->settlementUser)
            ->get(route('shm.loans.pelunasan.show', $loan))
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Loans/Settlement')
                ->where('loan', null));
    }

    public function test_undisbursed_loan_cannot_be_previewed(): void
    {
        $loan = $this->activeLoanOf($this->settlementUser);
        $this->scheduleWithDueAndFuture($loan);
        $loan->update(['disbursed_at' => null]);

        $this->actingAs($this->settlementUser)
            ->get(route('shm.loans.pelunasan.show', $loan))
            ->assertInertia(fn ($page) => $page->where('loan', null));
    }

    public function test_nonexistent_loan_is_rejected(): void
    {
        $this->actingAs($this->settlementUser)
            ->get(route('shm.loans.pelunasan.show', 999999))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // D. Confirmation is blocked by contract (deterministic, no mutation)
    // ------------------------------------------------------------------

    public function test_settlement_request_creates_pending_without_closing_loan(): void
    {
        $loan = $this->activeLoanOf($this->settlementUser);
        $this->scheduleWithDueAndFuture($loan);
        $this->discountPolicy(1, 3, '10.00');

        $this->actingAs($this->settlementUser)
            ->post(route('shm.loans.pelunasan.store', $loan), ['penalty' => 0])
            ->assertRedirect(route('shm.loans.pelunasan.show', $loan))
            ->assertSessionHas('success');

        $settlement = ShmLoanSettlement::query()->sole();
        $this->assertSame(ShmLoanSettlement::STATUS_PENDING, $settlement->status);
        // Pinjaman TIDAK ditutup oleh pengajuan; tidak ada audit completion.
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.settled')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.closed')->count());
        // Snapshot policy tersimpan.
        $this->assertNotNull($settlement->discount_policy_id);
        $this->assertTrue($settlement->discount_applied);
        $this->assertSame('10.00', (string) $settlement->discount_percent);
    }

    public function test_settlement_request_is_rejected_without_policy_for_eligible_loan(): void
    {
        // Pinjaman 100jt (eligible) + bunga masa depan ada + TANPA policy
        // → ditolak eksplisit; dilarang diam-diam memakai 0%.
        $loan = $this->activeLoanOf($this->settlementUser);
        $this->scheduleWithDueAndFuture($loan);

        $response = $this->actingAs($this->settlementUser)
            ->post(route('shm.loans.pelunasan.store', $loan), ['penalty' => 0]);

        $response->assertSessionHas('error', function (string $message): bool {
            $this->assertStringContainsString('policy diskon', $message);
            $this->assertStringContainsString('belum dikonfigurkan', $message);
            $this->assertStringContainsString('50.000.000', $message);

            return true;
        });

        $this->assertSame(0, ShmLoanSettlement::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.settlement.requested')->count());
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
    }

    public function test_penalty_is_manual_and_never_calculated(): void
    {
        $loan = $this->activeLoanOf($this->settlementUser);
        $this->scheduleWithDueAndFuture($loan);
        $this->discountPolicy(1, 3, '10.00');

        // Penalty manual 250rb → masuk amount; preview tetap 0.
        $this->actingAs($this->settlementUser)
            ->post(route('shm.loans.pelunasan.store', $loan), [
                'penalty' => 250_000,
                'notes' => 'Denda kebijakan kantor cabang.',
            ])
            ->assertSessionHas('success');

        $settlement = ShmLoanSettlement::query()->sole();
        $this->assertSame('250000.00', (string) $settlement->outstanding_penalty);
        // 10jt pokok + 1,5jt due + 1,35jt future setelah diskon + 250rb penalty.
        $this->assertSame('13100000.00', (string) $settlement->settlement_amount);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
    }

    public function test_confirmation_requires_settlement_permission(): void
    {
        $loan = $this->activeLoanOf($this->settlementUser);
        $this->scheduleWithDueAndFuture($loan);

        // Marketing pemilik pinjaman TANPA shm.settlement.create → 403.
        $owner = $this->userWithPermissions(['shm.view']);
        $ownedLoan = $this->activeLoanOf($owner);
        $this->scheduleWithDueAndFuture($ownedLoan);

        $this->assertFalse($owner->hasPermission('shm.settlement.create'));

        $this->actingAs($owner)
            ->post(route('shm.loans.pelunasan.store', $ownedLoan))
            ->assertForbidden();

        $this->assertSame(0, ShmLoanSettlement::query()->count());
    }

    public function test_confirmation_enforces_ownership_scope(): void
    {
        $loan = $this->activeLoanOf($this->marketing2);
        $this->scheduleWithDueAndFuture($loan);

        $this->actingAs($this->settlementUser)
            ->post(route('shm.loans.pelunasan.store', $loan))
            ->assertForbidden();

        $this->assertSame(0, ShmLoanSettlement::query()->count());
    }

    public function test_marketing_role_does_not_receive_settlement_permission(): void
    {
        // Authority pelunasan PENDING — marketing TIDAK otomatis menerimanya
        // (pola T86: payment.create juga tidak diberikan ke marketing).
        $this->assertFalse($this->marketing->hasPermission('shm.settlement.create'));
        // Admin memperolehnya lewat all_permissions.
        $this->assertTrue($this->admin->hasPermission('shm.settlement.create'));
    }

    public function test_settlement_table_exists_with_expected_schema(): void
    {
        $this->assertTrue(Schema::hasTable('shm_loan_settlements'));
        $this->assertTrue(Schema::hasTable('shm_settlement_discount_policies'));

        $columns = Schema::getColumnListing('shm_loan_settlements');
        foreach ([
            'shm_loan_id', 'settlement_reference', 'settlement_date',
            'outstanding_principal', 'outstanding_interest', 'outstanding_penalty',
            'unallocated_payment_amount', 'settlement_amount', 'status',
            'notes', 'created_by',
            // T89B — workflow + snapshot discount.
            'shm_loan_payment_id', 'payment_recorded_at', 'payment_verified_at',
            'payment_verified_by', 'discount_policy_id', 'discount_eligible',
            'discount_applied', 'remaining_months', 'discount_percent',
            'remaining_interest_amount', 'discount_base_amount',
            'discount_amount', 'interest_after_discount_amount',
            'overpayment_amount',
        ] as $expected) {
            $this->assertContains($expected, $columns);
        }
    }
}
