<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\SavingProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Superadmin untuk melewati permission middleware pada route simpanan.
        $this->user = User::factory()->superadmin()->create();
    }

    /** 1. Membuat produk simpanan berhasil */
    public function test_can_create_saving_product(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('simpanan-produk.store'), [
                'code' => 'SIM-001',
                'name' => 'Simpanan Sukarela',
                'description' => 'Simpanan biasa tanpa jangka waktu',
                'is_active' => true,
            ]);

        $response->assertRedirect(route('simpanan-produk.index'));
        $this->assertDatabaseHas('saving_products', [
            'code' => 'SIM-001',
            'name' => 'Simpanan Sukarela',
            'is_active' => true,
        ]);
    }

    /** 2. Membuka rekening anggota valid berhasil */
    public function test_can_open_savings_account_for_valid_member(): void
    {
        $member = Member::factory()->create(['status' => 'active']);
        $product = SavingProduct::factory()->create(['is_active' => true]);

        $response = $this->actingAs($this->user)
            ->post(route('simpanan-rekening.store'), [
                'member_id' => $member->id,
                'saving_product_id' => $product->id,
                'opened_at' => now()->format('Y-m-d'),
            ]);

        $account = SavingsAccount::where('member_id', $member->id)->first();

        $response->assertRedirect(route('simpanan-rekening.show', $account->id));
        $this->assertNotNull($account);
        $this->assertEquals(0, (float) $account->balance);
        $this->assertEquals('active', $account->status);
    }

    /** 3. Menolak pembukaan rekening anggota/produk tidak valid */
    public function test_rejects_opening_account_with_invalid_member_or_inactive_product(): void
    {
        $inactiveProduct = SavingProduct::factory()->inactive()->create();
        $member = Member::factory()->create(['status' => 'active']);

        // Menggunakan produk nonaktif
        $response = $this->actingAs($this->user)
            ->post(route('simpanan-rekening.store'), [
                'member_id' => $member->id,
                'saving_product_id' => $inactiveProduct->id,
                'opened_at' => now()->format('Y-m-d'),
            ]);

        $response->assertSessionHasErrors(['saving_product_id']);

        // Menggunakan member id tidak valid
        $activeProduct = SavingProduct::factory()->create(['is_active' => true]);
        $response2 = $this->actingAs($this->user)
            ->post(route('simpanan-rekening.store'), [
                'member_id' => 99999,
                'saving_product_id' => $activeProduct->id,
                'opened_at' => now()->format('Y-m-d'),
            ]);

        $response2->assertSessionHasErrors(['member_id']);
    }

    /** 4. Setoran berhasil + saldo bertambah */
    public function test_deposit_increases_account_balance(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 100000]);

        $response = $this->actingAs($this->user)
            ->post(route('simpanan-transaksi.deposit.store', $account->id), [
                'amount' => 50000,
                'transaction_date' => now()->format('Y-m-d'),
                'description' => 'Setoran awal',
            ]);

        $response->assertRedirect(route('simpanan-rekening.show', $account->id));
        $this->assertDatabaseHas('savings_accounts', [
            'id' => $account->id,
            'balance' => 150000.00,
        ]);
        $this->assertDatabaseHas('savings_transactions', [
            'savings_account_id' => $account->id,
            'type' => 'deposit',
            'amount' => 50000.00,
            'processed_by' => $this->user->id,
        ]);
    }

    /** 5. Penarikan berhasil + saldo berkurang */
    public function test_withdrawal_decreases_account_balance(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 200000]);

        $response = $this->actingAs($this->user)
            ->post(route('simpanan-transaksi.withdraw.store', $account->id), [
                'amount' => 50000,
                'transaction_date' => now()->format('Y-m-d'),
                'description' => 'Penarikan tunai',
            ]);

        $response->assertRedirect(route('simpanan-rekening.show', $account->id));
        $this->assertDatabaseHas('savings_accounts', [
            'id' => $account->id,
            'balance' => 150000.00,
        ]);
        $this->assertDatabaseHas('savings_transactions', [
            'savings_account_id' => $account->id,
            'type' => 'withdrawal',
            'amount' => 50000.00,
            'processed_by' => $this->user->id,
        ]);
    }

    /** 6. Penarikan melebihi saldo ditolak */
    public function test_withdrawal_exceeding_balance_is_rejected(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 50000]);

        $response = $this->actingAs($this->user)
            ->post(route('simpanan-transaksi.withdraw.store', $account->id), [
                'amount' => 100000,
                'transaction_date' => now()->format('Y-m-d'),
            ]);

        $response->assertSessionHasErrors(['amount']);
        $this->assertDatabaseHas('savings_accounts', [
            'id' => $account->id,
            'balance' => 50000.00,
        ]);
    }

    /** 7. Nominal nol atau negatif ditolak */
    public function test_zero_or_negative_transaction_amount_is_rejected(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 100000]);

        // Zero deposit
        $response1 = $this->actingAs($this->user)
            ->post(route('simpanan-transaksi.deposit.store', $account->id), [
                'amount' => 0,
                'transaction_date' => now()->format('Y-m-d'),
            ]);
        $response1->assertSessionHasErrors(['amount']);

        // Negative withdrawal
        $response2 = $this->actingAs($this->user)
            ->post(route('simpanan-transaksi.withdraw.store', $account->id), [
                'amount' => -50000,
                'transaction_date' => now()->format('Y-m-d'),
            ]);
        $response2->assertSessionHasErrors(['amount']);
    }

    /** 8. Request transaksi berulang tidak mencatat duplikat (idempotensi) */
    public function test_duplicate_transaction_request_with_same_idempotency_key_is_ignored(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 100000]);
        $key = 'idempotency-key-test-12345';

        // Request 1
        $this->actingAs($this->user)
            ->post(route('simpanan-transaksi.deposit.store', $account->id), [
                'amount' => 50000,
                'transaction_date' => now()->format('Y-m-d'),
                'idempotency_key' => $key,
            ]);

        // Request 2 (identical key)
        $this->actingAs($this->user)
            ->post(route('simpanan-transaksi.deposit.store', $account->id), [
                'amount' => 50000,
                'transaction_date' => now()->format('Y-m-d'),
                'idempotency_key' => $key,
            ]);

        // Hanya 1 transaksi yang tercatat dan saldo bertambah sekali saja (+50.000, bukan +100.000)
        $this->assertEquals(1, SavingsTransaction::where('idempotency_key', $key)->count());
        $this->assertDatabaseHas('savings_accounts', [
            'id' => $account->id,
            'balance' => 150000.00,
        ]);
    }

    /** 9. Transaksi gagal tidak mengubah saldo */
    public function test_failed_transaction_does_not_mutate_balance(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 50000]);

        // Penarikan gagal karena saldo tidak mencukupi
        $this->actingAs($this->user)
            ->post(route('simpanan-transaksi.withdraw.store', $account->id), [
                'amount' => 90000,
                'transaction_date' => now()->format('Y-m-d'),
            ]);

        $this->assertEquals(50000.00, (float) $account->fresh()->balance);
    }

    /** 10. Pengguna tanpa izin (unauthenticated) tidak bisa akses/proses transaksi */
    public function test_unauthenticated_user_cannot_access_or_process_savings(): void
    {
        $account = SavingsAccount::factory()->create();

        $response = $this->get(route('simpanan-rekening.index'));
        $response->assertRedirect(route('login'));

        $postResponse = $this->post(route('simpanan-transaksi.deposit.store', $account->id), [
            'amount' => 50000,
            'transaction_date' => now()->format('Y-m-d'),
        ]);
        $postResponse->assertRedirect(route('login'));
    }

    /** 11. Riwayat transaksi dan saldo konsisten */
    public function test_transaction_history_and_balance_are_consistent(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 0]);

        // Setoran 100.000
        $this->actingAs($this->user)->post(route('simpanan-transaksi.deposit.store', $account->id), [
            'amount' => 100000,
            'transaction_date' => now()->format('Y-m-d'),
        ]);

        // Setoran 50.000
        $this->actingAs($this->user)->post(route('simpanan-transaksi.deposit.store', $account->id), [
            'amount' => 50000,
            'transaction_date' => now()->format('Y-m-d'),
        ]);

        // Penarikan 30.000
        $this->actingAs($this->user)->post(route('simpanan-transaksi.withdraw.store', $account->id), [
            'amount' => 30000,
            'transaction_date' => now()->format('Y-m-d'),
        ]);

        $freshAccount = $account->fresh();

        // 100k + 50k - 30k = 120k
        $this->assertEquals(120000.00, (float) $freshAccount->balance);

        // Hitung ulang dari ledger transaksi
        $totalDeposits = $freshAccount->transactions()->where('type', 'deposit')->sum('amount');
        $totalWithdrawals = $freshAccount->transactions()->where('type', 'withdrawal')->sum('amount');
        $calculatedBalance = $totalDeposits - $totalWithdrawals;

        $this->assertEquals((float) $freshAccount->balance, (float) $calculatedBalance);
    }
}
