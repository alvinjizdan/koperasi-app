<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Role;
use App\Models\SavingProduct;
use App\Models\SavingsAccount;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Models\User;
use App\Services\Shm\ShmLoanDisbursementService;
use App\Services\Shm\ShmLoanPaymentService;
use App\Services\Shm\ShmLoanSettlementService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithShmDisbursement;
use Tests\TestCase;

/**
 * T89C — SHM Customer Source from Koperasi Member Test Suite.
 *
 * Menguji:
 * 1. Nasabah SHM wajib memilih anggota koperasi sebagai sumber.
 * 2. Anggota aktif dapat membuat nasabah SHM.
 * 3. Anggota tidak valid / nonaktif ditolak.
 * 4. Anggota duplikat (sudah punya nasabah aktif) ditolak.
 * 5. Pengajuan pinjaman & pencairan menelusuri anggota sumber.
 * 6. Potongan pencairan (Simpanan Pokok & Wajib) dikreditkan ke rekening simpanan anggota sumber.
 * 7. Overpayment settlement dikreditkan ke Simpanan Pokok anggota sumber secara idempoten.
 */
class ShmCustomerMemberIntegrationTest extends TestCase
{
    use InteractsWithShmDisbursement;
    use RefreshDatabase;

    private User $admin;

    private User $marketing;

    private SavingProduct $principalProduct;

    private SavingProduct $mandatoryProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->value('id'));

        $this->marketing = User::factory()->create(['email_verified_at' => now()]);
        $this->marketing->roles()->attach(Role::where('name', 'marketing')->value('id'));

        // Buat produk simpanan pokok dan wajib untuk koperasi
        $this->principalProduct = SavingProduct::create([
            'code' => 'SP-01',
            'name' => 'Simpanan Pokok',
            'category' => SavingProduct::CATEGORY_PRINCIPAL,
            'description' => 'Simpanan Pokok Anggota',
            'is_active' => true,
        ]);

        $this->mandatoryProduct = SavingProduct::create([
            'code' => 'SW-01',
            'name' => 'Simpanan Wajib',
            'category' => SavingProduct::CATEGORY_MANDATORY,
            'description' => 'Simpanan Wajib Anggota',
            'is_active' => true,
        ]);
    }

    private function createMember(array $attributes = []): Member
    {
        return Member::create(array_merge([
            'member_number' => 'ANG-'.Str::upper(Str::random(6)),
            'full_name' => 'Budi Santoso '.Str::random(4),
            'identity_number' => '3201'.random_int(100000000000, 999999999999),
            'phone' => '08123456789',
            'address' => 'Jl. Koperasi No. 10',
            'joined_at' => now()->toDateString(),
            'status' => 'active',
        ], $attributes));
    }

    // =========================================================================
    // 1. MEMBER RELATIONSHIP & CREATION
    // =========================================================================

    public function test_shm_customer_requires_member_source(): void
    {
        $this->actingAs($this->admin)
            ->post(route('shm.customers.store'), [
                'name' => 'Nasabah Tanpa Member',
            ])
            ->assertSessionHasErrors('member_id');
    }

    // =========================================================================
    // 1b. T89F-2 — TIDAK BOLEH ADA FALLBACK PEMILIHAN ANGGOTA
    // =========================================================================

    public function test_missing_member_id_creates_no_customer_and_no_member(): void
    {
        $beforeMembers = Member::query()->count();

        $this->actingAs($this->admin)
            ->post(route('shm.customers.store'), [
                'name' => 'Nasabah Tanpa Member Sumber',
            ])
            ->assertSessionHasErrors('member_id');

        $this->assertDatabaseCount('shm_customers', 0);
        $this->assertSame($beforeMembers, Member::query()->count());
        $this->assertDatabaseMissing('shm_customers', ['name' => 'Nasabah Tanpa Member Sumber']);
    }

    public function test_unknown_member_id_is_rejected_without_fallback(): void
    {
        $member = $this->createMember();
        $unknownMemberId = $member->id + 999;

        $this->actingAs($this->admin)
            ->post(route('shm.customers.store'), [
                'member_id' => $unknownMemberId,
                'name' => 'Nasabah Member Tidak Ada',
            ])
            ->assertSessionHasErrors('member_id');

        $this->assertDatabaseCount('shm_customers', 0);
        $this->assertDatabaseMissing('shm_customers', ['member_id' => $unknownMemberId]);
        // Tidak jatuh ke anggota valid yang tersedia.
        $this->assertDatabaseMissing('shm_customers', ['member_id' => $member->id]);
        $this->assertDatabaseCount('members', 1);
    }

    public function test_missing_member_id_never_assigns_another_active_member(): void
    {
        // Dua anggota aktif: A (dibuat pertama) dan B. Permintaan tanpa
        // member_id tidak boleh memilih salah satunya secara otomatis.
        $memberA = $this->createMember();
        $memberB = $this->createMember();

        $this->actingAs($this->admin)
            ->post(route('shm.customers.store'), [
                'name' => 'Nasabah Tanpa Pilihan Anggota',
            ])
            ->assertSessionHasErrors('member_id');

        $this->assertDatabaseCount('shm_customers', 0);
        $this->assertDatabaseMissing('shm_customers', ['member_id' => $memberA->id]);
        $this->assertDatabaseMissing('shm_customers', ['member_id' => $memberB->id]);
        $this->assertDatabaseCount('members', 2);
    }

    public function test_valid_member_can_create_shm_customer(): void
    {
        $member = $this->createMember();

        $this->actingAs($this->admin)
            ->post(route('shm.customers.store'), [
                'member_id' => $member->id,
                'name' => $member->full_name,
                'phone' => '0899999999',
            ])
            ->assertRedirect();

        $customer = ShmCustomer::where('member_id', $member->id)->first();
        $this->assertNotNull($customer);
        $this->assertSame($member->id, $customer->member_id);
        $this->assertSame($member->full_name, $customer->name);
        $this->assertTrue($customer->is_active);
    }

    public function test_inactive_member_is_rejected_as_shm_customer_source(): void
    {
        $member = $this->createMember(['status' => 'inactive']);

        $this->actingAs($this->admin)
            ->post(route('shm.customers.store'), [
                'member_id' => $member->id,
                'name' => $member->full_name,
            ])
            ->assertSessionHasErrors('member_id');

        $this->assertDatabaseMissing('shm_customers', ['member_id' => $member->id]);
    }

    public function test_duplicate_active_shm_customer_for_same_member_is_rejected(): void
    {
        $member = $this->createMember();

        // Customer pertama berhasil
        $this->actingAs($this->admin)
            ->post(route('shm.customers.store'), [
                'member_id' => $member->id,
                'name' => $member->full_name,
            ]);

        $this->assertDatabaseCount('shm_customers', 1);

        // Percobaan customer kedua untuk anggota yang sama ditolak
        $this->actingAs($this->admin)
            ->post(route('shm.customers.store'), [
                'member_id' => $member->id,
                'name' => 'Nama Kedua',
            ])
            ->assertSessionHasErrors('member_id');

        $this->assertDatabaseCount('shm_customers', 1);
    }

    // =========================================================================
    // 2. DISBURSEMENT TO MEMBER SAVINGS ACCOUNTS
    // =========================================================================

    public function test_disbursement_credits_principal_and_mandatory_savings_to_member_accounts(): void
    {
        $member = $this->createMember();

        $customer = ShmCustomer::create([
            'member_id' => $member->id,
            'customer_number' => 'NSB-001',
            'name' => $member->full_name,
            'is_active' => true,
        ]);

        $product = ShmLoanProduct::factory()->create([
            'code' => 'PROD-DISB-'.Str::random(4),
        ]);

        // Mirror application & approval
        $shmApp = ShmApplication::create([
            'application_number' => 'MIRROR-001',
            'shm_customer_id' => $customer->id,
            'marketing_id' => $this->admin->id,
            'status' => 'approved',
        ]);
        $shmApp->approvals()->create([
            'stage' => 'approval',
            'status' => 'approved',
            'decided_by' => $this->admin->id,
            'decided_at' => now(),
        ]);

        $application = ShmLoanApplication::create([
            'application_number' => 'APP-001',
            'shm_customer_id' => $customer->id,
            'shm_application_id' => $shmApp->id,
            'shm_loan_product_id' => $product->id,
            'marketing_id' => $this->admin->id,
            'principal' => 50_000_000,
            'approved_amount' => 50_000_000,
            'tenor_months' => 10,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'application_date' => now()->toDateString(),
            'status' => 'approved',
        ]);

        // T002C — sesi kasir aktif milik pelaku pencairan WAJIB (F-01).
        $this->openCashierSessionFor($this->admin);

        // Jalankan pencairan
        $service = app(ShmLoanDisbursementService::class);
        $loan = $service->disburse($application, $this->admin->id);

        $this->assertNotNull($loan);

        // Verifikasi Simpanan Pokok (Rp100.000) masuk ke rekening anggota
        $principalAccount = SavingsAccount::where('member_id', $member->id)
            ->where('saving_product_id', $this->principalProduct->id)
            ->first();
        $this->assertNotNull($principalAccount);
        $this->assertEquals(100_000, (float) $principalAccount->balance);

        // Verifikasi Simpanan Wajib (Rp25.000 x 10 bulan = Rp250.000) masuk ke rekening anggota
        $mandatoryAccount = SavingsAccount::where('member_id', $member->id)
            ->where('saving_product_id', $this->mandatoryProduct->id)
            ->first();
        $this->assertNotNull($mandatoryAccount);
        $this->assertEquals(250_000, (float) $mandatoryAccount->balance);

        // Verifikasi mutasi transaksi tercatat
        $this->assertDatabaseHas('savings_transactions', [
            'savings_account_id' => $principalAccount->id,
            'amount' => 100000,
            'type' => 'deposit',
        ]);
        $this->assertDatabaseHas('savings_transactions', [
            'savings_account_id' => $mandatoryAccount->id,
            'amount' => 250000,
            'type' => 'deposit',
        ]);
    }

    // =========================================================================
    // 3. SETTLEMENT OVERPAYMENT POSTING TO SIMPANAN POKOK
    // =========================================================================

    public function test_settlement_overpayment_posts_to_member_principal_savings(): void
    {
        $member = $this->createMember();

        $customer = ShmCustomer::create([
            'member_id' => $member->id,
            'customer_number' => 'NSB-SET-001',
            'name' => $member->full_name,
            'is_active' => true,
        ]);

        $product = ShmLoanProduct::factory()->create([
            'code' => 'PROD-SET',
        ]);

        $application = ShmLoanApplication::create([
            'application_number' => 'APP-SET-001',
            'shm_customer_id' => $customer->id,
            'shm_loan_product_id' => $product->id,
            'marketing_id' => $this->admin->id,
            'principal' => 30_000_000,
            'tenor_months' => 2,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'application_date' => now()->toDateString(),
            'status' => 'approved',
        ]);

        $loan = ShmLoan::create([
            'shm_customer_id' => $customer->id,
            'shm_loan_application_id' => $application->id,
            'shm_loan_product_id' => $product->id,
            'loan_number' => 'LOAN-SET-001',
            'principal' => 30_000_000,
            'tenor_months' => 2,
            'status' => 'active',
            'disbursed_at' => now()->subMonth(),
        ]);

        // Angsuran
        $loan->installments()->create([
            'installment_number' => 1,
            'due_date' => now()->subDays(5)->toDateString(),
            'principal_amount' => 15_000_000,
            'interest_amount' => 500_000,
            'total_amount' => 15_500_000,
            'remaining_amount' => 15_500_000,
            'status' => 'scheduled',
        ]);
        $loan->installments()->create([
            'installment_number' => 2,
            'due_date' => now()->addMonth()->toDateString(),
            'principal_amount' => 15_000_000,
            'interest_amount' => 500_000,
            'total_amount' => 15_500_000,
            'remaining_amount' => 15_500_000,
            'status' => 'scheduled',
        ]);

        $settlementService = app(ShmLoanSettlementService::class);
        $settlement = $settlementService->request($loan, ['penalty' => 0], $this->admin->id);
        $settlementService->approve($loan, $this->admin->id);

        // Kewajiban = 30jt pokok + 500k due + 500k future = 31jt.
        // Nasabah membayar 32jt (Overpayment = 1jt).
        $paymentService = app(ShmLoanPaymentService::class);
        $payment = $paymentService->create($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => 32_000_000,
        ], $this->admin->id);

        $settlementService->recordPayment($loan, $payment->id, $this->admin->id);
        $settlementService->verifyPayment($loan, $this->admin->id);

        // Selesaikan settlement
        $settlementService->complete($loan, $this->admin->id);

        // Verifikasi pinjaman ditutup dan principal tidak berubah
        $this->assertSame('closed', $loan->refresh()->status);
        $this->assertEquals(30_000_000, (float) $loan->principal);

        // Verifikasi overpayment Rp1.000.000 masuk ke Simpanan Pokok anggota sumber
        $principalAccount = SavingsAccount::where('member_id', $member->id)
            ->where('saving_product_id', $this->principalProduct->id)
            ->first();
        $this->assertNotNull($principalAccount);
        $this->assertEquals(1_000_000, (float) $principalAccount->balance);

        $this->assertDatabaseHas('savings_transactions', [
            'savings_account_id' => $principalAccount->id,
            'amount' => 1000000,
            'type' => 'deposit',
            'idempotency_key' => 'shm-settlement-overpayment:'.$settlement->id,
        ]);

        // Retry idempotency: tidak membuat transaksi simpanan ganda
        $settlementService->complete($loan, $this->admin->id);
        $this->assertDatabaseCount('savings_transactions', 1);
        $this->assertEquals(1_000_000, (float) $principalAccount->refresh()->balance);
    }
}
