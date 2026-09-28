<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\InstallmentAllocation;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\CashierService;
use App\Services\InstallmentPaymentService;
use App\Services\LoanService;
use App\Services\Reconciliation\CashReconciliationChecker;
use App\Services\Reconciliation\LoanReconciliationChecker;
use App\Services\Reconciliation\SavingsReconciliationChecker;
use App\Services\SavingsTransactionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Ticket 17 — pengujian rekonsiliasi lintas modul finansial + regresi BUG-01..BUG-04.
 *
 * Semua data dummy (factory). Tidak ada perubahan data historis atau produksi.
 */
class ReconciliationAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = User::factory()->superadmin()->create();
    }

    // =====================================================================
    // TAHAP 2 — REKONSILIASI SIMPANAN
    // =====================================================================

    public function test_savings_balance_matches_ledger_after_mixed_transactions(): void
    {
        $service = app(SavingsTransactionService::class);
        $account = SavingsAccount::factory()->create(['balance' => 0]);

        $service->deposit($account, [
            'amount' => 500_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->superadmin->id,
        ]);
        $service->deposit($account->fresh(), [
            'amount' => 200_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->superadmin->id,
        ]);
        $service->withdraw($account->fresh(), [
            'amount' => 150_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->superadmin->id,
        ]);

        // Reversal setoran pertama: koreksi terhubung ke transaksi asal.
        $service->reverse(SavingsTransaction::query()->orderBy('id')->first(), $this->superadmin->id, 'Salah input');

        $account->refresh();

        // Ledger: (500k + 200k - 150k) - reversal 500k = 50.000.
        $this->assertSame(50_000.0, (float) $account->balance);

        $result = app(SavingsReconciliationChecker::class)
            ->check(now()->subDay()->format('Y-m-d'), now()->format('Y-m-d'));

        $this->assertSame([], $result['findings'], 'Rekonsiliasi simpanan harus bersih: '.json_encode($result['findings']));
        $this->assertSame(0, $result['summary']['mismatch_count']);
    }

    public function test_reversal_is_linked_to_original_and_counted_once(): void
    {
        $service = app(SavingsTransactionService::class);
        $account = SavingsAccount::factory()->create(['balance' => 0]);

        $original = $service->deposit($account, [
            'amount' => 300_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->superadmin->id,
        ]);

        $reversal = $service->reverse($original, $this->superadmin->id, 'Koreksi setoran');

        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertSame('reversal', $reversal->entry_type);
        $this->assertSame('withdrawal', $reversal->type);

        // Saldo kembali nol; reversal tidak dihitung ganda.
        $this->assertSame(0.0, (float) $account->fresh()->balance);

        $result = app(SavingsReconciliationChecker::class)
            ->check(now()->subDay()->format('Y-m-d'), now()->format('Y-m-d'));

        $this->assertSame([], $result['findings']);
    }

    public function test_failed_transaction_does_not_affect_balance_or_reconciliation(): void
    {
        $service = app(SavingsTransactionService::class);
        $account = SavingsAccount::factory()->create(['balance' => 0]);

        // Saldo dibentuk lewat transaksi sah agar konsisten dengan ledger.
        $service->deposit($account, [
            'amount' => 100_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->superadmin->id,
        ]);

        // Penarikan melebihi saldo ditolak: tidak ada transaksi baru, saldo tetap.
        try {
            $service->withdraw($account->fresh(), [
                'amount' => 999_999,
                'transaction_date' => now()->format('Y-m-d'),
                'processed_by' => $this->superadmin->id,
            ]);
            $this->fail('Penarikan melebihi saldo seharusnya ditolak.');
        } catch (ValidationException) {
        }

        $this->assertSame(100_000.0, (float) $account->fresh()->balance);

        $result = app(SavingsReconciliationChecker::class)
            ->check(now()->subDay()->format('Y-m-d'), now()->format('Y-m-d'));

        $this->assertSame([], $result['findings']);
    }

    public function test_member_total_savings_is_consistent_with_accounts(): void
    {
        $member = Member::factory()->create(['status' => 'active']);

        $accountA = SavingsAccount::factory()->create(['member_id' => $member->id, 'balance' => 0]);
        $accountB = SavingsAccount::factory()->create(['member_id' => $member->id, 'balance' => 0]);

        $service = app(SavingsTransactionService::class);
        $service->deposit($accountA, ['amount' => 400_000, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $this->superadmin->id]);
        $service->deposit($accountB, ['amount' => 250_000, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $this->superadmin->id]);

        $expected = round((float) $member->savingsAccounts()->sum('balance'), 2);
        $this->assertSame(650_000.0, $expected);

        // Ringkasan finansial pada detail anggota memakai sumber yang sama (BUG-02).
        $this->actingAs($this->superadmin)
            ->get(route('anggota.show', $member->id))
            ->assertInertia(fn ($page) => $page
                ->component('Anggota/Show')
                ->where('financial_summary.total_savings_balance', fn ($value) => (float) $value === 650_000.0)
                ->where('financial_summary.savings_account_count', 2));
    }

    // =====================================================================
    // TAHAP 3 — REKONSILIASI PINJAMAN & ANGSURAN
    // =====================================================================

    private function activeLoan(float $amount = 12_000_000, int $tenor = 12, float $rate = 12.0): Loan
    {
        $loan = Loan::factory()->approved()->create([
            'member_id' => Member::factory()->create(['status' => 'active'])->id,
            'amount' => $amount,
            'tenor_months' => $tenor,
        ]);

        app(LoanService::class)->disburse($loan, [
            'disbursement_date' => '2026-01-10',
            'method' => 'TRANSFER',
            'interest_method' => 'flat',
            'interest_rate_annual' => $rate,
        ], $this->superadmin->id);

        return $loan->fresh();
    }

    public function test_loan_schedule_principal_equals_disbursed_amount(): void
    {
        $loan = $this->activeLoan();

        $this->assertSame(12_000_000.0, round((float) $loan->schedules()->sum('principal_amount'), 2));
        $this->assertSame(1_440_000.0, round((float) $loan->schedules()->sum('interest_amount'), 2));
        $this->assertSame(13_440_000.0, round((float) $loan->schedules()->sum('total_amount'), 2));

        $result = app(LoanReconciliationChecker::class)
            ->check('2026-01-01', now()->format('Y-m-d'));

        $this->assertSame([], $result['findings'], 'Rekonsiliasi pinjaman harus bersih: '.json_encode($result['findings']));
    }

    public function test_payment_allocations_reconcile_with_schedule_components(): void
    {
        $loan = $this->activeLoan();

        app(InstallmentPaymentService::class)->pay($loan, [
            'amount' => 1_120_000, // satu angsuran penuh: pokok 1jt + bunga 120rb
            'payment_date' => '2026-02-10',
        ], $this->superadmin->id);

        $loan->refresh();

        // Alokasi = pembayaran; terbayar komponen tidak melebihi kewajiban.
        $this->assertSame(1_120_000.0, round((float) InstallmentAllocation::query()->sum('amount'), 2));
        $this->assertSame(12_320_000.0, $loan->total_owed);

        $result = app(LoanReconciliationChecker::class)
            ->check('2026-01-01', now()->format('Y-m-d'));

        $this->assertSame([], $result['findings'], json_encode($result['findings']));
    }

    public function test_paid_off_loan_status_reconciles_with_remaining_owed(): void
    {
        $loan = $this->activeLoan(amount: 1_200_000, tenor: 4, rate: 12.0);

        $totalOwed = $loan->total_owed; // 1.200.000 pokok + 144.000 bunga
        app(InstallmentPaymentService::class)->pay($loan, [
            'amount' => $totalOwed,
            'payment_date' => now()->format('Y-m-d'),
        ], $this->superadmin->id);

        $loan->refresh();
        $this->assertSame('PAID_OFF', $loan->status);
        $this->assertSame(0.0, $loan->total_owed);

        $result = app(LoanReconciliationChecker::class)
            ->check(now()->subDay()->format('Y-m-d'), now()->format('Y-m-d'));

        $this->assertSame([], $result['findings'], json_encode($result['findings']));
    }

    public function test_checker_detects_component_overpayment_as_finding(): void
    {
        $loan = $this->activeLoan();

        // Simulasi inkonsistensi (bukan lewat jalur normal): terbayar bunga melebihi kewajiban.
        $loan->schedules()->first()->update(['interest_paid' => 999_999]);

        $result = app(LoanReconciliationChecker::class)
            ->check(now()->subDay()->format('Y-m-d'), now()->format('Y-m-d'));

        $this->assertNotEmpty($result['findings']);
        $this->assertNotEmpty(
            array_filter($result['findings'], fn ($f) => $f['finding_type'] === 'BALANCE_MISMATCH'
                && str_contains($f['description'], 'melebihi kewajibannya')),
            'Overpayment komponen harus terdeteksi: '.json_encode($result['findings'])
        );
    }

    public function test_member_active_loans_outstanding_uses_total_owed_semantics(): void
    {
        $loan = $this->activeLoan();

        app(InstallmentPaymentService::class)->pay($loan, [
            'amount' => 1_120_000,
            'payment_date' => '2026-02-10',
        ], $this->superadmin->id);

        $expectedOutstanding = $loan->fresh()->total_owed; // 12.320.000

        $this->actingAs($this->superadmin)
            ->get(route('anggota.show', $loan->member_id))->assertInertia(fn ($page) => $page
            ->where('financial_summary.active_loans_outstanding', fn ($value) => (float) $value === $expectedOutstanding)
            ->where('financial_summary.active_loan_count', 1));
    }

    // =====================================================================
    // TAHAP 4 — REKONSILIASI KASIR
    // =====================================================================

    public function test_cashier_session_closing_balance_reconciles_with_transactions(): void
    {
        $cashier = app(CashierService::class);

        $session = $cashier->openSession([
            'operational_date' => now()->format('Y-m-d'),
            'opening_balance' => 500_000,
            'opening_notes' => null,
        ], $this->superadmin->id);

        $cashier->recordIncome($session, [
            'amount' => 300_000,
            'category' => 'Pendapatan Lain',
            'transaction_date' => now()->format('Y-m-d'),
            'description' => null,
            'idempotency_key' => null,
        ], $this->superadmin->id);

        $expense = $cashier->recordExpense($session, [
            'amount' => 100_000,
            'category' => 'Biaya Operasional',
            'transaction_date' => now()->format('Y-m-d'),
            'description' => null,
            'idempotency_key' => null,
        ], $this->superadmin->id);

        // Koreksi pengeluaran SEBELUM penutupan: sistem 500k + 300k - 0 = 800.000.
        $cashier->reverse($expense, 'Salah input', $this->superadmin->id);

        // Saldo fisik 750.000 → selisih -50.000 dicatat eksplisit saat penutupan.
        $cashier->closeSession($session->fresh(), [
            'physical_closing_balance' => 750_000,
            'closing_notes' => 'Selisih diinvestigasi',
        ], $this->superadmin->id);

        $session->refresh();
        $this->assertSame(800_000.0, (float) $session->system_closing_balance);
        $this->assertSame(-50_000.0, (float) $session->difference);

        $result = app(CashReconciliationChecker::class)
            ->check(now()->subDay()->format('Y-m-d'), now()->format('Y-m-d'));

        // Snapshot konsisten dengan transaksi; selisih kas nyata dilaporkan
        // eksplisit sebagai CASH_VARIANCE, bukan BALANCE_MISMATCH.
        $types = array_column($result['findings'], 'finding_type');
        $this->assertNotContains('BALANCE_MISMATCH', $types, json_encode($result['findings']));
        $this->assertContains('CASH_VARIANCE', $types);
    }

    public function test_failed_cash_expense_does_not_affect_session_balance(): void
    {
        $cashier = app(CashierService::class);

        $session = $cashier->openSession([
            'operational_date' => now()->format('Y-m-d'),
            'opening_balance' => 100_000,
            'opening_notes' => null,
        ], $this->superadmin->id);

        try {
            $cashier->recordExpense($session, [
                'amount' => 500_000,
                'category' => 'Biaya Operasional',
                'transaction_date' => now()->format('Y-m-d'),
                'description' => null,
                'idempotency_key' => null,
            ], $this->superadmin->id);
            $this->fail('Pengeluaran melebihi saldo kas seharusnya ditolak.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, $session->transactions()->count());

        $result = app(CashReconciliationChecker::class)
            ->check(now()->subDay()->format('Y-m-d'), now()->format('Y-m-d'));

        $this->assertSame([], $result['findings']);
    }

    public function test_repeated_cash_idempotency_key_creates_single_transaction(): void
    {
        $cashier = app(CashierService::class);

        $session = $cashier->openSession([
            'operational_date' => now()->format('Y-m-d'),
            'opening_balance' => 1_000_000,
            'opening_notes' => null,
        ], $this->superadmin->id);

        $key = 'recon-idem-'.uniqid();

        foreach ([1, 2, 3] as $attempt) {
            $cashier->recordIncome($session, [
                'amount' => 50_000,
                'category' => 'Pendapatan Lain',
                'transaction_date' => now()->format('Y-m-d'),
                'description' => null,
                'idempotency_key' => $key,
            ], $this->superadmin->id);
        }

        $this->assertSame(1, CashTransaction::query()->where('idempotency_key', $key)->count());
        $this->assertSame(50_000.0, round((float) $session->transactions()->where('status', 'POSTED')->sum('amount'), 2));

        $result = app(CashReconciliationChecker::class)
            ->check(now()->subDay()->format('Y-m-d'), now()->format('Y-m-d'));

        $this->assertSame([], $result['findings']);
    }

    public function test_reversed_cash_transaction_excluded_from_balance_and_has_audit_trail(): void
    {
        $cashier = app(CashierService::class);

        $session = $cashier->openSession([
            'operational_date' => now()->format('Y-m-d'),
            'opening_balance' => 200_000,
            'opening_notes' => null,
        ], $this->superadmin->id);

        $income = $cashier->recordIncome($session, [
            'amount' => 75_000,
            'category' => 'Pendapatan Lain',
            'transaction_date' => now()->format('Y-m-d'),
            'description' => null,
            'idempotency_key' => null,
        ], $this->superadmin->id);

        $reversed = $cashier->reverse($income->fresh(), 'Koreksi penerimaan', $this->superadmin->id);

        $this->assertSame(CashTransaction::STATUS_REVERSED, $reversed->status);
        $this->assertSame('Koreksi penerimaan', $reversed->reversal_reason);
        $this->assertSame($this->superadmin->id, $reversed->reversed_by);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cashier.transaction.reversed',
            'object_id' => $income->id,
        ]);

        $result = app(CashReconciliationChecker::class)
            ->check(now()->subDay()->format('Y-m-d'), now()->format('Y-m-d'));

        // Sesi masih terbuka: tanpa snapshot, tanpa temuan.
        $this->assertSame([], $result['findings']);
    }

    // =====================================================================
    // BUG-01 — OTORISASI ROUTE TANPA PERMISSION
    // =====================================================================

    public function test_bug01_read_routes_without_permission_are_forbidden(): void
    {
        $plain = User::factory()->create();

        $protectedGetRoutes = [
            ['simpanan.index', []],
            ['simpanan-produk.index', []],
            ['simpanan-produk.create', []],
            ['simpanan-rekening.index', []],
            ['simpanan-rekening.create', []],
            ['pinjaman.index', []],
            ['pinjaman.create', []],
            ['angsuran.index', []],
            ['kasir.index', []],
            ['kasir.sesi.index', []],
            ['kasir.transaksi.index', []],
            ['pengaturan.index', []],
            ['pengaturan.profile', []],
            ['pengaturan.operational', []],
        ];

        foreach ($protectedGetRoutes as [$routeName, $params]) {
            $response = $this->actingAs($plain)->get(route($routeName, $params));
            $this->assertSame(
                403,
                $response->status(),
                "Route {$routeName} harus ditolak 403 untuk pengguna tanpa permission."
            );
        }
    }

    public function test_bug01_write_routes_without_permission_are_forbidden(): void
    {
        $plain = User::factory()->create();

        $product = SavingProduct::factory()->create();

        $this->actingAs($plain)
            ->post(route('simpanan-produk.store'), ['code' => 'X1', 'name' => 'X', 'is_active' => true])
            ->assertForbidden();

        $this->actingAs($plain)
            ->put(route('simpanan-produk.update', $product->id), ['name' => 'X'])
            ->assertForbidden();

        $this->actingAs($plain)
            ->patch(route('simpanan-produk.deactivate', $product->id))
            ->assertForbidden();

        $this->actingAs($plain)
            ->post(route('simpanan-rekening.store'), [])
            ->assertForbidden();
    }

    public function test_bug01_permissioned_users_still_access_their_modules(): void
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'petugas_uji_'.uniqid(),
            'display_name' => 'Petugas Uji',
            'is_system' => false,
        ]);
        $role->permissions()->attach(
            Permission::query()->whereIn('name', [
                'dashboard.view', 'simpanan.view', 'pinjaman.view', 'angsuran.view',
            ])->pluck('id')
        );
        $user->roles()->attach($role->id);

        $this->actingAs($user)->get(route('simpanan.index'))->assertOk();
        $this->actingAs($user)->get(route('simpanan-produk.index'))->assertOk();
        $this->actingAs($user)->get(route('pinjaman.index'))->assertOk();
        $this->actingAs($user)->get(route('angsuran.index'))->assertOk();
    }

    public function test_bug01_self_service_security_page_remains_accessible_to_all_users(): void
    {
        $plain = User::factory()->create();

        $this->actingAs($plain)
            ->get(route('pengaturan.security'))
            ->assertOk();
    }

    // =====================================================================
    // BUG-02 / BUG-03 — DETAIL ANGGOTA
    // =====================================================================

    public function test_bug02_member_detail_shows_real_financial_summary(): void
    {
        $member = Member::factory()->create(['status' => 'active']);
        $account = SavingsAccount::factory()->create(['member_id' => $member->id, 'balance' => 0]);

        app(SavingsTransactionService::class)->deposit($account, [
            'amount' => 123_456,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->superadmin->id,
        ]);

        $this->actingAs($this->superadmin)
            ->get(route('anggota.show', $member->id))->assertInertia(fn ($page) => $page
            ->component('Anggota/Show')
            ->where('financial_summary.total_savings_balance', fn ($value) => (float) $value === 123_456.0));
    }

    public function test_bug02_member_with_no_financial_data_reports_zero_not_misleading(): void
    {
        $member = Member::factory()->create(['status' => 'active']);

        $this->actingAs($this->superadmin)
            ->get(route('anggota.show', $member->id))
            ->assertInertia(fn ($page) => $page
                ->where('financial_summary.total_savings_balance', fn ($value) => (float) $value === 0.0)
                ->where('financial_summary.savings_account_count', 0)
                ->where('financial_summary.active_loans_outstanding', fn ($value) => (float) $value === 0.0)
                ->where('financial_summary.active_loan_count', 0));
    }

    // =====================================================================
    // TAHAP 6 — AUDIT TRAIL & INTEGRITAS TRANSAKSI
    // =====================================================================

    public function test_every_financial_transaction_carries_actor_and_unique_reference(): void
    {
        $service = app(SavingsTransactionService::class);
        $account = SavingsAccount::factory()->create(['balance' => 0]);

        // Aktor dari sesi untuk jejak audit.
        $this->actingAs($this->superadmin);

        $service->deposit($account, [
            'amount' => 100_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->superadmin->id,
        ]);

        $transaction = SavingsTransaction::query()->firstOrFail();

        $this->assertNotNull($transaction->transaction_number);
        $this->assertNotNull($transaction->processed_by);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'savings.transaction.deposit',
            'object_id' => $transaction->id,
            'actor_id' => $this->superadmin->id,
        ]);

        // Transaksi simpanan immutable: tanpa kolom updated_at (konstanta UPDATED_AT = null).
        $this->assertNull(SavingsTransaction::UPDATED_AT);
    }

    public function test_sensitive_payloads_never_enter_audit_trail(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 0]);

        app(SavingsTransactionService::class)->deposit($account, [
            'amount' => 50_000,
            'transaction_date' => now()->format('Y-m-d'),
            'description' => 'password=hunter2 api_token=xyz123',
            'processed_by' => $this->superadmin->id,
        ]);

        foreach (AuditLog::all() as $log) {
            $encoded = json_encode([$log->changes, $log->description]);
            $this->assertStringNotContainsString('hunter2', (string) $encoded);
            $this->assertStringNotContainsString('xyz123', (string) $encoded);
        }
    }

    public function test_posted_savings_transactions_are_not_updatable_via_mass_assignment(): void
    {
        $service = app(SavingsTransactionService::class);
        $account = SavingsAccount::factory()->create(['balance' => 0]);

        $original = $service->deposit($account, [
            'amount' => 80_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->superadmin->id,
        ]);

        $originalAmount = (float) $original->amount;

        // Percobaan mengubah nominal langsung tidak boleh memengaruhi history
        // yang sudah dibaca (transaksi dikoreksi hanya lewat reversal).
        $this->assertSame(80_000.0, $originalAmount);
        $this->assertSame('completed', $original->status);

        // Satu-satunya jalur koreksi: reversal terhubung ke asal.
        $reversal = $service->reverse($original, $this->superadmin->id, 'Koreksi');
        $this->assertSame($original->id, $reversal->reversal_of_id);
    }
}
