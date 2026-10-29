<?php

namespace Tests\Feature;

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
use App\Services\Shm\ShmLoanSettlementService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * T108 — BATAS TRANSAKSI pembayaran pelunasan (settlement) vs alokasi
 * angsuran pinjaman SHM (P1-08).
 *
 * Kedua domain berbagi tabel `shm_loan_payments` tanpa kolom tipe baru:
 * discriminator = tautan `shm_loan_settlements.shm_loan_payment_id`
 * (pelunasan) vs baris `shm_loan_payment_allocations` (angsuran). Kontrak
 * yang dikunci suite ini:
 *
 * 1. Pembayaran reguler HANYA mengalokasi ke angsuran; settlement tidak
 *    tersentuh.
 * 2. Pembayaran yang sudah ditautkan ke settlement DITOLAK oleh alokasi
 *    (uang pelunasan tidak pernah masuk perhitungan angsuran).
 * 3. Rekaman pembayaran ≠ verifikasi ≠ completion: penutupan pinjaman
 *    hanya melalui completion.
 * 4. Pembayaran yang SUDAH teralokasi ke angsuran DITOLAK sebagai
 *    pembayaran pelunasan (uang yang sama tidak terhitung dua kali).
 * 5. Cross-contamination dua arah diuji pada pinjaman yang sama.
 * 6. Komponen outstanding angsuran tidak berkurang karena pembayaran
 *    settlement sebelum completion; completion menutup lewat jalurnya
 *    sendiri tanpa membuat baris alokasi.
 * 7. Penggantian pembayaran settlement (verifikasi gagal → tautkan
 *    pembayaran baru) tetap bekerja; pembayaran lama yang dilepas
 *    kembali ke domain reguler.
 */
class ShmSettlementPaymentBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->user = $this->userWithPermissions([
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
            'name' => 't108-'.Str::uuid(),
            'display_name' => 'T108 Tester',
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id')
        );

        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * Pinjaman aktif 100jt (eligible discount) dengan dua angsuran:
     * #1 jatuh tempo tempo lalu, #2 masa depan.
     */
    private function activeLoanWithSchedule(): ShmLoan
    {
        $application = ShmLoanApplication::query()->create([
            'application_number' => 'PENGAJ-T108-'.Str::upper(Str::random(10)),
            'shm_customer_id' => ShmCustomer::factory()->create()->id,
            'application_date' => now()->toDateString(),
            'principal' => '100000000',
            'tenor_months' => 2,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $this->user->id,
            'status' => 'approved',
        ]);

        $loan = ShmLoan::factory()->create([
            'shm_customer_id' => $application->shm_customer_id,
            'shm_loan_application_id' => $application->id,
            'principal' => '100000000',
            'tenor_months' => 2,
        ]);

        foreach ([now()->subMonth()->startOfMonth(), now()->addMonth()->startOfMonth()] as $index => $due) {
            $loan->installments()->create([
                'installment_number' => $index + 1,
                'due_date' => $due->toDateString(),
                'principal_amount' => 5_000_000,
                'interest_amount' => 1_500_000,
                'total_amount' => 6_500_000,
                'paid_amount' => 0,
                'paid_interest_amount' => 0,
                'paid_principal_amount' => 0,
                'remaining_amount' => 6_500_000,
                'status' => ShmLoanInstallment::STATUS_SCHEDULED,
            ]);
        }

        return $loan;
    }

    private function policy(): ShmSettlementDiscountPolicy
    {
        return ShmSettlementDiscountPolicy::query()->create([
            'min_remaining_months' => 1,
            'max_remaining_months' => 3,
            'discount_percent' => '10.00',
            'is_active' => true,
        ]);
    }

    private function paymentFor(ShmLoan $loan, string $amount): ShmLoanPayment
    {
        return app(ShmLoanPaymentService::class)->create($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
        ], (int) $this->user->id);
    }

    /**
     * Ajukan settlement → pending. Kewajiban fixture: 10jt pokok + 1,5jt
     * due + 1,35jt future setelah diskon 10% = 12.850.000.
     */
    private function requested(ShmLoan $loan): ShmLoanSettlement
    {
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.store', $loan), ['penalty' => 0])
            ->assertSessionHas('success');

        return ShmLoanSettlement::query()
            ->where('shm_loan_id', $loan->id)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    // ------------------------------------------------------------------
    // 1. Pembayaran reguler → hanya angsuran; settlement tidak tersentuh
    // ------------------------------------------------------------------

    public function test_regular_payment_allocates_only_to_installments_and_leaves_settlement_untouched(): void
    {
        $loan = $this->activeLoanWithSchedule();
        $this->policy();
        $settlement = $this->requested($loan);

        $payment = $this->paymentFor($loan, '2000000');

        // T004 / T158 lock: alokasi reguler DITOLAK selama status settlement PENDING
        try {
            app(ShmLoanPaymentAllocationService::class)->allocate($payment);
            $this->fail('Alokasi reguler harus ditolak selama settlement PENDING (T004 lock).');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment', $exception->errors());
            $this->assertStringContainsString('dikunci', $exception->errors()['payment'][0]);
        }

        // Tidak ada alokasi angsuran yang terbentuk
        $this->assertDatabaseCount('shm_loan_payment_allocations', 0);
        $this->assertSame(0, $payment->refresh()->allocations()->count());

        // Settlement TIDAK tersentuh: tetap pending, tanpa tautan pembayaran
        $settlement->refresh();
        $this->assertSame(ShmLoanSettlement::STATUS_PENDING, $settlement->status);
        $this->assertNull($settlement->shm_loan_payment_id);

        // Pinjaman tetap aktif
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
    }

    // ------------------------------------------------------------------
    // 2. Pembayaran settlement DITOLAK alokasi (defect literal P1-08)
    // ------------------------------------------------------------------

    public function test_settlement_payment_cannot_be_allocated_to_installments(): void
    {
        $loan = $this->activeLoanWithSchedule();
        $this->policy();
        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $payment = $this->paymentFor($loan, (string) $settlement->settlement_amount);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment->id])
            ->assertSessionHas('success');

        $installmentsBefore = $loan->installments()
            ->orderBy('installment_number')
            ->get()
            ->map(fn (ShmLoanInstallment $installment): array => $installment->only([
                'paid_amount', 'paid_interest_amount', 'paid_principal_amount', 'remaining_amount', 'status',
            ]))
            ->all();

        try {
            app(ShmLoanPaymentAllocationService::class)->allocate($payment);
            $this->fail('Pembayaran settlement seharusnya DITOLAK oleh alokasi angsuran.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment', $exception->errors());
            $this->assertStringContainsString('pembayaran pelunasan', $exception->errors()['payment'][0]);
        }

        // Tidak ada satu pun jejak alokasi; angsuran tidak berubah.
        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());
        $this->assertSame($installmentsBefore, $loan->installments()
            ->orderBy('installment_number')
            ->get()
            ->map(fn (ShmLoanInstallment $installment): array => $installment->only([
                'paid_amount', 'paid_interest_amount', 'paid_principal_amount', 'remaining_amount', 'status',
            ]))
            ->all());

        // Settlement tetap pada jalurnya sendiri.
        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_RECORDED, $settlement->refresh()->status);
    }

    public function test_allocate_endpoint_rejects_settlement_payment_with_session_error(): void
    {
        $loan = $this->activeLoanWithSchedule();
        $this->policy();
        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $payment = $this->paymentFor($loan, (string) $settlement->settlement_amount);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment->id]);

        $this->actingAs($this->user)
            ->post(route('shm.loans.payments.allocate', $payment))
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());
    }

    // ------------------------------------------------------------------
    // 3. Rekaman ≠ verifikasi ≠ completion; penutupan hanya di completion
    // ------------------------------------------------------------------

    public function test_payment_recorded_does_not_complete_settlement_or_close_loan(): void
    {
        $loan = $this->activeLoanWithSchedule();
        $this->policy();
        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $payment = $this->paymentFor($loan, (string) $settlement->settlement_amount);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment->id]);

        // Rekaman pembayaran: BELUM verifikasi, BELUM completed, pinjaman aktif.
        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_RECORDED, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);

        // Verifikasi eksplisit: tetap bukan completion, pinjaman masih aktif.
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.payment.verify', $loan));
        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_VERIFIED, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);

        // Completion = satu-satunya jalur penutupan.
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.complete', $loan));
        $this->assertSame(ShmLoanSettlement::STATUS_COMPLETED, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_CLOSED, $loan->refresh()->status);
    }

    // ------------------------------------------------------------------
    // 4. Arah cermin: pembayaran angsuran teralokasi DITOLAK sebagai
    //    pembayaran pelunasan
    // ------------------------------------------------------------------

    public function test_allocated_installment_payment_cannot_fulfill_settlement(): void
    {
        $loan = $this->activeLoanWithSchedule();
        $this->policy();

        // Pembayaran REGULER yang dialokasikan sebelum settlement dimulai (PBD-01 aturan 2).
        $installmentPayment = $this->paymentFor($loan, '6500000');
        app(ShmLoanPaymentAllocationService::class)->allocate($installmentPayment);
        $this->assertSame(1, ShmLoanPaymentAllocation::query()->count());

        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        try {
            app(ShmLoanSettlementService::class)
                ->recordPayment($loan, $installmentPayment->id, (int) $this->user->id);
            $this->fail('Pembayaran angsuran teralokasi seharusnya DITOLAK sebagai pembayaran pelunasan.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment', $exception->errors());
            $this->assertStringContainsString('sudah teralokasi ke angsuran', $exception->errors()['payment'][0]);
        }

        // Settlement tidak menerima tautan; angsuran tetap teralokasi normal.
        $this->assertNull($settlement->refresh()->shm_loan_payment_id);
        $this->assertSame(ShmLoanSettlement::STATUS_APPROVED, $settlement->status);
        $this->assertSame(1, ShmLoanPaymentAllocation::query()->count());

        // Pembayaran BARU tetap diterima — guard selektif, bukan blanket.
        $settlementPayment = $this->paymentFor($loan, (string) $settlement->settlement_amount);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $settlementPayment->id])
            ->assertSessionHas('success');
        $this->assertSame(
            $settlementPayment->id,
            (int) $settlement->refresh()->shm_loan_payment_id,
        );
    }

    // ------------------------------------------------------------------
    // 5. Cross-contamination: masing-masing domain hanya memengaruhi
    //    domainnya sendiri
    // ------------------------------------------------------------------

    public function test_cross_contamination_each_domain_only_affects_its_own(): void
    {
        $loan = $this->activeLoanWithSchedule();
        $this->policy();

        // Domain angsuran: pembayaran A dialokasikan sebelum settlement dimulai.
        $installmentPayment = $this->paymentFor($loan, '2000000');
        app(ShmLoanPaymentAllocationService::class)->allocate($installmentPayment);

        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        // Domain pelunasan: pembayaran B ditautkan ke settlement.
        $settlementPayment = $this->paymentFor($loan, (string) $settlement->settlement_amount);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $settlementPayment->id]);

        // Silang Arah 1: B (pelunasan) DITOLAK alokasi angsuran.
        try {
            app(ShmLoanPaymentAllocationService::class)->allocate($settlementPayment);
            $this->fail('Pembayaran settlement seharusnya ditolak alokasi angsuran.');
        } catch (ValidationException) {
            // Diharapkan — guard T108.
        }

        // Silang Arah 2: A (angsuran teralokasi) DITOLAK sebagai pelunasan.
        try {
            app(ShmLoanSettlementService::class)
                ->recordPayment($loan, $installmentPayment->id, (int) $this->user->id);
            $this->fail('Pembayaran angsuran teralokasi seharusnya ditolak recordPayment.');
        } catch (ValidationException) {
            // Diharapkan — guard cermin T108.
        }

        // Integritas: alokasi hanya milik A; tautan hanya milik B.
        $this->assertSame(
            [$installmentPayment->id],
            ShmLoanPaymentAllocation::query()->pluck('shm_loan_payment_id')->unique()->all(),
        );
        $this->assertSame(
            $settlementPayment->id,
            (int) $settlement->refresh()->shm_loan_payment_id,
        );
        $this->assertSame(0, $settlementPayment->allocations()->count());
        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_RECORDED, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
    }

    // ------------------------------------------------------------------
    // 6. Isolasi kueri: outstanding angsuran tidak dihitung dari
    //    pembayaran settlement; completion tidak membuat baris alokasi
    // ------------------------------------------------------------------

    public function test_settlement_payment_does_not_reduce_installment_outstanding(): void
    {
        $loan = $this->activeLoanWithSchedule();
        $this->policy();
        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $payment = $this->paymentFor($loan, (string) $settlement->settlement_amount);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment->id]);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.payment.verify', $loan));

        // Setelah rekaman + verifikasi: outstanding angsuran MASIH penuh —
        // pembayaran settlement tidak mengurangi kewajiban angsuran.
        $loan->installments->each(function (ShmLoanInstallment $installment): void {
            $this->assertEquals(0, (float) $installment->paid_amount);
            $this->assertEquals(6_500_000, (float) $installment->remaining_amount);
        });
        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());

        // Completion menutup angsuran lewat jalur settlement sendiri —
        // tetap TANPA baris alokasi (uang tidak dialokasikan dua jalur).
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.complete', $loan));

        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());
        $loan->installments()->orderBy('installment_number')->get()->each(function (ShmLoanInstallment $installment): void {
            $this->assertSame(ShmLoanInstallment::STATUS_PAID, $installment->status);
            $this->assertEquals(0, (float) $installment->remaining_amount);
        });
    }

    // ------------------------------------------------------------------
    // 7. Penggantian pembayaran settlement: tautan boleh dipindah dari
    //    payment_recorded; pembayaran lama yang dilepas kembali reguler
    // ------------------------------------------------------------------

    public function test_replaced_settlement_payment_is_released_back_to_regular_domain(): void
    {
        $loan = $this->activeLoanWithSchedule();
        $this->policy();
        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        // Pembayaran kurang → ditautkan → verifikasi ditolak.
        $short = $this->paymentFor($loan, '10000000');
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $short->id]);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment.verify', $loan))
            ->assertSessionHas('error');
        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_RECORDED, $settlement->refresh()->status);

        // Selama masih ditautkan: diblokir dari alokasi angsuran.
        try {
            app(ShmLoanPaymentAllocationService::class)->allocate($short);
            $this->fail('Pembayaran yang masih ditautkan settlement seharusnya ditolak alokasi.');
        } catch (ValidationException) {
            // Diharapkan.
        }

        // Penggantian: tautan pindah ke pembayaran penuh (kontrak T89B).
        $exact = $this->paymentFor($loan, (string) $settlement->settlement_amount);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $exact->id])
            ->assertSessionHas('success');
        $this->assertSame($exact->id, (int) $settlement->refresh()->shm_loan_payment_id);

        // Pembayaran lama yang dilepas kembali ke domain reguler:
        // kini boleh dialokasikan (tidak ada double count — uang lama
        // tidak pernah memenuhi kewajiban settlement).
        app(ShmLoanPaymentAllocationService::class)->allocate($short);
        $this->assertSame(
            [$short->id],
            ShmLoanPaymentAllocation::query()->pluck('shm_loan_payment_id')->unique()->all(),
        );

        // Penggantian tetap terverifikasi dan selesai pada jalurnya.
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.payment.verify', $loan));
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.complete', $loan));
        $this->assertSame(ShmLoanSettlement::STATUS_COMPLETED, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_CLOSED, $loan->refresh()->status);
    }
}
