<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\SavingProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Reconciliation\SavingsReconciliationChecker;
use App\Services\SavingsTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Ticket 20 — penguatan modul simpanan.
 * Data dummy; tidak mengubah data historis.
 */
class SavingsEnhancementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SavingsTransactionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->superadmin()->create();
        $this->service = app(SavingsTransactionService::class);
    }

    private function openAccount(string $category = 'voluntary', float $balance = 0): SavingsAccount
    {
        $product = SavingProduct::factory()->create(['category' => $category]);
        $member = Member::factory()->create(['status' => 'active']);

        return SavingsAccount::factory()->create([
            'member_id' => $member->id,
            'saving_product_id' => $product->id,
            'balance' => $balance,
        ]);
    }

    // =====================================================================
    // A. KATEGORI & PRODUK
    // =====================================================================

    public function test_all_three_categories_can_be_created_and_retrieved(): void
    {
        foreach (['principal', 'mandatory', 'voluntary'] as $category) {
            $product = SavingProduct::factory()->create(['category' => $category]);
            $this->assertSame($category, $product->fresh()->category);
        }

        foreach (SavingProduct::CATEGORIES as $value => $label) {
            $this->assertNotNull(SavingProduct::where('category', $value)->first(), "Kategori {$value} harus tersedia.");
        }
    }

    public function test_invalid_category_is_rejected_on_create(): void
    {
        $this->actingAs($this->user);

        $response = $this->post(route('simpanan-produk.store'), [
            'code' => 'X-TEST',
            'name' => 'Produk Kategori Aneh',
            'category' => 'hajatan', // tidak valid
        ]);

        $response->assertSessionHasErrors('category');
        $this->assertDatabaseMissing('saving_products', ['code' => 'X-TEST']);
    }

    public function test_invalid_category_is_rejected_on_update(): void
    {
        $this->actingAs($this->user);
        $product = SavingProduct::factory()->create(['category' => 'voluntary']);

        $response = $this->put(route('simpanan-produk.update', $product->id), [
            'code' => $product->code,
            'name' => $product->name,
            'category' => 'kolektif',
        ]);

        $response->assertSessionHasErrors('category');
        $this->assertSame('voluntary', $product->fresh()->category);
    }

    public function test_inactive_product_cannot_be_used_for_new_transactions(): void
    {
        // Ticket 20 gap yang ditutup: sebelumnya produk nonaktif masih bisa bertransaksi.
        $account = $this->openAccount('voluntary', 500_000);
        $account->savingProduct->update(['is_active' => false]);

        try {
            $this->service->deposit($account->fresh(), [
                'amount' => 100_000,
                'transaction_date' => now()->format('Y-m-d'),
                'processed_by' => $this->user->id,
            ]);
            $this->fail('Transaksi pada produk nonaktif seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('dinonaktifkan', $e->errors()['account'][0]);
        }

        $this->assertSame(500_000.0, (float) $account->fresh()->balance);
    }

    public function test_history_remains_accessible_after_product_deactivation(): void
    {
        $account = $this->openAccount('voluntary', 0);

        $this->service->deposit($account, [
            'amount' => 200_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->user->id,
        ]);

        $account->savingProduct->update(['is_active' => false]);

        // Rekening + histori tetap dapat diakses (authorized user).
        $this->actingAs($this->user)
            ->get(route('simpanan-rekening.show', $account->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Simpanan/Rekening/Show')
                ->has('account')
                ->has('transactions'));

        $this->assertSame(1, $account->fresh()->transactions()->count());
    }

    public function test_existing_records_retain_relationships_after_category_change(): void
    {
        $account = $this->openAccount('voluntary', 0);
        $productId = $account->saving_product_id;

        $this->service->deposit($account, [
            'amount' => 100_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->user->id,
        ]);

        // Kategori produk diubah: rekening & transaksi tidak terpengaruh.
        $account->savingProduct->update(['category' => 'mandatory']);

        $account->refresh();
        $this->assertSame($productId, $account->saving_product_id);
        $this->assertSame(100_000.0, (float) $account->balance);
        $this->assertSame(1, $account->transactions()->count());
    }

    // =====================================================================
    // B. ELIGIBILITY REKENING & ANGGOTA
    // =====================================================================

    public function test_active_member_and_account_can_transact(): void
    {
        $account = $this->openAccount('voluntary', 0);

        $transaction = $this->service->deposit($account, [
            'amount' => 100_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->user->id,
        ]);

        $this->assertSame('completed', $transaction->status);
        $this->assertSame(100_000.0, (float) $account->fresh()->balance);
    }

    public function test_inactive_member_cannot_initiate_new_transactions(): void
    {
        $account = $this->openAccount('voluntary', 500_000);
        $account->member->update(['status' => 'inactive']);

        $this->expectException(ValidationException::class);
        $this->service->deposit($account->fresh(), [
            'amount' => 50_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->user->id,
        ]);
    }

    public function test_inactive_account_cannot_initiate_new_transactions(): void
    {
        $account = $this->openAccount('voluntary', 500_000);
        $account->update(['status' => 'closed']);

        $this->expectException(ValidationException::class);
        $this->service->withdraw($account->fresh(), [
            'amount' => 50_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->user->id,
        ]);
    }

    public function test_product_deactivation_does_not_alter_historical_balances(): void
    {
        $account = $this->openAccount('mandatory', 0);

        $this->service->deposit($account, [
            'amount' => 300_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->user->id,
        ]);

        $balanceBefore = (float) $account->fresh()->balance;
        $transactionCount = $account->transactions()->count();

        $account->savingProduct->update(['is_active' => false]);

        $this->assertSame($balanceBefore, (float) $account->fresh()->balance);
        $this->assertSame($transactionCount, $account->fresh()->transactions()->count());
    }

    // =====================================================================
    // C. TRANSAKSI FINANSIAL
    // =====================================================================

    public function test_deposit_and_withdrawal_update_balance_correctly(): void
    {
        $account = $this->openAccount('voluntary', 0);

        $this->service->deposit($account, ['amount' => 500_000, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $this->user->id]);
        $this->service->withdraw($account->fresh(), ['amount' => 180_000, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $this->user->id]);

        $this->assertSame(320_000.0, (float) $account->fresh()->balance);
    }

    public function test_withdrawal_exceeding_balance_is_rejected(): void
    {
        $account = $this->openAccount('voluntary', 100_000);

        $this->expectException(ValidationException::class);
        $this->service->withdraw($account, ['amount' => 100_001, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $this->user->id]);
    }

    public function test_minimum_amount_is_enforced(): void
    {
        $account = $this->openAccount('voluntary', 0);

        $this->expectException(ValidationException::class);
        $this->service->deposit($account, ['amount' => 0.001, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $this->user->id]);
    }

    public function test_duplicate_idempotency_key_creates_single_effect(): void
    {
        $account = $this->openAccount('voluntary', 0);
        $key = 'ticket20-'.uniqid();

        foreach ([1, 2, 3] as $attempt) {
            $this->service->deposit($account->fresh(), [
                'amount' => 75_000,
                'transaction_date' => now()->format('Y-m-d'),
                'processed_by' => $this->user->id,
                'idempotency_key' => $key,
            ]);
        }

        $this->assertSame(1, SavingsTransaction::where('idempotency_key', $key)->count());
        $this->assertSame(75_000.0, (float) $account->fresh()->balance);
    }

    // =====================================================================
    // D. REVERSAL
    // =====================================================================

    public function test_reversal_validates_current_account_and_member_status(): void
    {
        $account = $this->openAccount('voluntary', 0);

        $original = $this->service->deposit($account, [
            'amount' => 250_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->user->id,
        ]);

        // Anggota dinonaktifkan SETELAH transaksi: reversal atas transaksi
        // lama tetap divalidasi terhadap status SAAT INI (ditolak).
        $account->member->update(['status' => 'inactive']);

        $this->expectException(ValidationException::class);
        $this->service->reverse($original->fresh(), $this->user->id, 'Uji kelayakan');
    }

    public function test_reversal_allowed_when_account_and_member_still_active(): void
    {
        $account = $this->openAccount('voluntary', 0);

        $original = $this->service->deposit($account, [
            'amount' => 250_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->user->id,
        ]);

        $reversal = $this->service->reverse($original->fresh(), $this->user->id, 'Salah input');

        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertSame(0.0, (float) $account->fresh()->balance);
    }

    public function test_original_transaction_remains_immutable_after_reversal(): void
    {
        $account = $this->openAccount('voluntary', 0);

        $original = $this->service->deposit($account, [
            'amount' => 150_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->user->id,
        ]);

        $scalarFields = ['type', 'amount', 'status', 'transaction_number', 'savings_account_id'];
        $snapshot = $original->only($scalarFields);
        $snapshotDate = $original->transaction_date->toDateString();

        $this->service->reverse($original->fresh(), $this->user->id, 'Koreksi');

        $original->refresh();
        $this->assertSame($snapshot, $original->only($scalarFields));
        $this->assertSame($snapshotDate, $original->transaction_date->toDateString());
    }

    public function test_duplicate_reversal_is_rejected(): void
    {
        $account = $this->openAccount('voluntary', 0);

        $original = $this->service->deposit($account, [
            'amount' => 90_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->user->id,
        ]);

        $this->service->reverse($original->fresh(), $this->user->id, 'Pertama');

        $this->expectException(ValidationException::class);
        $this->service->reverse($original->fresh(), $this->user->id, 'Kedua');
    }

    // =====================================================================
    // E. SALDO, HISTORI & SUMMARY
    // =====================================================================

    public function test_account_balance_matches_effective_transaction_history(): void
    {
        $account = $this->openAccount('voluntary', 0);

        $this->service->deposit($account, ['amount' => 400_000, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $this->user->id]);
        $this->service->deposit($account->fresh(), ['amount' => 100_000, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $this->user->id]);
        $this->service->withdraw($account->fresh(), ['amount' => 150_000, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $this->user->id]);

        // Reversal setoran kedua.
        $secondDeposit = SavingsTransaction::query()->orderBy('id')->skip(1)->first();
        $this->service->reverse($secondDeposit, $this->user->id, 'Uji');

        // Efektif: 400k + 100k - 150k - 100k(reversal) = 250.000.
        $this->assertSame(250_000.0, (float) $account->fresh()->balance);

        // Rekonstruksi dari ledger transaksi efektif = saldo tersimpan.
        $recalculated = round(
            (float) $account->transactions()->where('status', 'completed')->where('type', 'deposit')->sum('amount')
            - (float) $account->transactions()->where('status', 'completed')->where('type', 'withdrawal')->sum('amount'),
            2
        );
        $this->assertSame($recalculated, (float) $account->fresh()->balance);
    }

    public function test_member_level_summary_reconciles_with_accounts(): void
    {
        $member = Member::factory()->create(['status' => 'active']);

        $accountA = $this->openAccount('voluntary', 0);
        $accountA->update(['member_id' => $member->id]);
        $accountB = $this->openAccount('mandatory', 0);
        $accountB->update(['member_id' => $member->id]);

        $this->service->deposit($accountA, ['amount' => 300_000, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $this->user->id]);
        $this->service->deposit($accountB, ['amount' => 200_000, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $this->user->id]);

        // Summary anggota = jumlah rekening (sumber: saldo resmi akun).
        $memberTotal = round((float) SavingsAccount::where('member_id', $member->id)->sum('balance'), 2);
        $this->assertSame(500_000.0, $memberTotal);

        // Konsisten dengan perhitungan per rekening.
        $sumFromAccounts = SavingsAccount::where('member_id', $member->id)->get()
            ->sum(fn ($account) => round(
                (float) $account->transactions()->where('status', 'completed')->where('type', 'deposit')->sum('amount')
                - (float) $account->transactions()->where('status', 'completed')->where('type', 'withdrawal')->sum('amount'),
                2
            ));
        $this->assertSame(round((float) $sumFromAccounts, 2), $memberTotal);
    }

    public function test_category_totals_derived_from_account_balances(): void
    {
        $voluntary = $this->openAccount('voluntary', 0);
        $mandatory = $this->openAccount('mandatory', 0);

        $this->service->deposit($voluntary, ['amount' => 300_000, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $this->user->id]);
        $this->service->deposit($mandatory, ['amount' => 120_000, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $this->user->id]);

        $voluntaryTotal = round((float) SavingsAccount::query()
            ->whereHas('savingProduct', fn ($q) => $q->where('category', 'voluntary'))
            ->sum('balance'), 2);
        $mandatoryTotal = round((float) SavingsAccount::query()
            ->whereHas('savingProduct', fn ($q) => $q->where('category', 'mandatory'))
            ->sum('balance'), 2);

        $this->assertSame(300_000.0, $voluntaryTotal);
        $this->assertSame(120_000.0, $mandatoryTotal);
    }

    public function test_history_filter_by_type_and_date(): void
    {
        $account = $this->openAccount('voluntary', 0);

        $this->service->deposit($account, ['amount' => 100_000, 'transaction_date' => '2026-05-01', 'processed_by' => $this->user->id]);
        $this->service->withdraw($account->fresh(), ['amount' => 20_000, 'transaction_date' => '2026-05-15', 'processed_by' => $this->user->id]);
        $this->service->deposit($account->fresh(), ['amount' => 30_000, 'transaction_date' => '2026-06-01', 'processed_by' => $this->user->id]);

        $this->actingAs($this->user)
            ->get(route('simpanan-rekening.show', $account->id).'?type=withdrawal')
            ->assertInertia(fn ($page) => $page
                ->has('transactions.data', 1));

        $this->actingAs($this->user)
            ->get(route('simpanan-rekening.show', $account->id).'?date_from=2026-05-01&date_to=2026-05-31')
            ->assertInertia(fn ($page) => $page
                ->has('transactions.data', 2));
    }

    public function test_ineffective_transactions_are_excluded_from_balance(): void
    {
        $account = $this->openAccount('voluntary', 100_000);

        // Transaksi gagal (overdraft): tidak boleh memengaruhi saldo/ledger.
        try {
            $this->service->withdraw($account, ['amount' => 999_999, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $this->user->id]);
            $this->fail('Overdraft seharusnya ditolak.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, $account->transactions()->count());
        $this->assertSame(100_000.0, (float) $account->fresh()->balance);
    }

    // =====================================================================
    // F/G. REKONSILIASI (read-only)
    // =====================================================================

    public function test_reconciliation_detects_introduced_inconsistency(): void
    {
        $account = $this->openAccount('voluntary', 0);

        $this->service->deposit($account, ['amount' => 500_000, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $this->user->id]);

        // Inkonsistensi disengaja (simulasi kerusakan data) untuk membuktikan
        // checker mendeteksinya.
        $account->update(['balance' => 999_999]);

        $result = app(SavingsReconciliationChecker::class)
            ->check(now()->subDay()->format('Y-m-d'), now()->format('Y-m-d'));

        $mismatches = array_filter($result['findings'], fn ($f) => $f['finding_type'] === 'BALANCE_MISMATCH'
            && $f['object_id'] === $account->id);
        $this->assertNotEmpty($mismatches, 'Checker harus mendeteksi selisih saldo vs ledger.');
    }

    public function test_reconciliation_does_not_mutate_financial_records(): void
    {
        $account = $this->openAccount('voluntary', 0);

        $this->service->deposit($account, ['amount' => 200_000, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $this->user->id]);

        $account->update(['balance' => 123_456]); // inkonsistensi disengaja

        $balanceBefore = $account->fresh()->balance;
        $txBefore = $account->transactions()->get()->toJson();

        app(SavingsReconciliationChecker::class)->check(now()->subDay()->format('Y-m-d'), now()->format('Y-m-d'));

        $this->assertSame($balanceBefore, $account->fresh()->balance, 'Rekonsiliasi tidak boleh mengubah saldo.');
        $this->assertSame($txBefore, $account->transactions()->get()->toJson(), 'Rekonsiliasi tidak boleh mengubah transaksi.');
    }

    // =====================================================================
    // PRODUK: DEAKTIVASI & AUDIT
    // =====================================================================

    public function test_product_with_linked_accounts_can_be_deactivated(): void
    {
        $account = $this->openAccount('voluntary', 100_000);
        $product = $account->savingProduct;

        $this->actingAs($this->user)
            ->patch(route('simpanan-produk.deactivate', $product->id))
            ->assertRedirect();

        $this->assertFalse($product->fresh()->is_active);
        // Rekening tidak tersentuh.
        $this->assertDatabaseHas('savings_accounts', ['id' => $account->id, 'status' => 'active', 'balance' => $account->balance]);
        // Audit tercatat.
        $this->assertDatabaseHas('audit_logs', ['action' => 'savings.product.deactivated', 'object_id' => $product->id]);
    }

    public function test_product_create_and_update_are_audited(): void
    {
        $this->actingAs($this->user);

        $this->post(route('simpanan-produk.store'), [
            'code' => 'AUD-1',
            'name' => 'Produk Audit',
            'category' => 'mandatory',
        ]);

        $product = SavingProduct::where('code', 'AUD-1')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['action' => 'savings.product.created', 'object_id' => $product->id]);

        $this->put(route('simpanan-produk.update', $product->id), [
            'code' => $product->code,
            'name' => 'Produk Audit Baru',
            'category' => 'mandatory',
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'savings.product.updated', 'object_id' => $product->id]);
    }
}
