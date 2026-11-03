<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shm\ShmSavingProduct;
use App\Models\Shm\ShmSavingsAccount;
use App\Models\ShmCustomer;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ShmSavingProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShmSavingsCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $userWithoutPermission;

    private ShmCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(ShmSavingProductSeeder::class);

        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->value('id'));

        $this->userWithoutPermission = User::factory()->create(['email_verified_at' => now()]);

        $this->customer = ShmCustomer::factory()->create([
            'is_active' => true,
            'member_id' => null, // nasabah murni tanpa keanggotaan koperasi
        ]);
    }

    // =========================================================================
    // 1. SHM SAVING PRODUCT CRUD
    // =========================================================================

    public function test_can_list_shm_saving_products(): void
    {
        $this->actingAs($this->admin)
            ->get(route('shm.savings.products.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Savings/Products/Index')
                ->has('products.data', 3)
            );
    }

    public function test_can_create_shm_saving_product(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('shm.savings.products.store'), [
                'code' => 'SHM-PROD-TEST',
                'name' => 'Simpanan Uji Coba',
                'category' => ShmSavingProduct::CATEGORY_VOLUNTARY,
                'description' => 'Keterangan uji coba',
                'is_active' => true,
            ]);

        $response->assertRedirect(route('shm.savings.products.index'));
        $this->assertDatabaseHas('shm_saving_products', [
            'code' => 'SHM-PROD-TEST',
            'name' => 'Simpanan Uji Coba',
            'category' => 'voluntary',
        ]);
        // Pastikan tidak ada data yang masuk ke tabel produk simpanan koperasi
        $this->assertDatabaseMissing('saving_products', [
            'code' => 'SHM-PROD-TEST',
        ]);
    }

    public function test_duplicate_code_validation_on_product_create(): void
    {
        $this->actingAs($this->admin)
            ->post(route('shm.savings.products.store'), [
                'code' => 'SHM-SIMP-POKOK', // sudah ada dari seeder
                'name' => 'Simpanan Duplikat',
                'category' => ShmSavingProduct::CATEGORY_PRINCIPAL,
            ])
            ->assertSessionHasErrors(['code']);
    }

    public function test_can_update_shm_saving_product(): void
    {
        $product = ShmSavingProduct::where('code', 'SHM-SIMP-SUKARELA')->firstOrFail();

        $response = $this->actingAs($this->admin)
            ->put(route('shm.savings.products.update', $product->id), [
                'code' => 'SHM-SIMP-SUKARELA-NEW',
                'name' => 'Simpanan Sukarela Makro Edit',
                'category' => ShmSavingProduct::CATEGORY_VOLUNTARY,
                'description' => 'Update deskripsi',
                'is_active' => true,
            ]);

        $response->assertRedirect(route('shm.savings.products.index'));
        $this->assertDatabaseHas('shm_saving_products', [
            'id' => $product->id,
            'code' => 'SHM-SIMP-SUKARELA-NEW',
            'name' => 'Simpanan Sukarela Makro Edit',
        ]);
    }

    public function test_can_deactivate_shm_saving_product(): void
    {
        $product = ShmSavingProduct::where('code', 'SHM-SIMP-SUKARELA')->firstOrFail();

        $response = $this->actingAs($this->admin)
            ->patch(route('shm.savings.products.deactivate', $product->id));

        $response->assertRedirect();
        $this->assertFalse((bool) $product->fresh()->is_active);
    }

    // =========================================================================
    // 2. SHM SAVINGS ACCOUNT CRUD
    // =========================================================================

    public function test_can_list_shm_savings_accounts(): void
    {
        $this->actingAs($this->admin)
            ->get(route('shm.savings.accounts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Shm/Savings/Accounts/Index'));
    }

    public function test_can_open_shm_savings_account_for_shm_customer(): void
    {
        $product = ShmSavingProduct::where('category', ShmSavingProduct::CATEGORY_VOLUNTARY)->firstOrFail();

        $response = $this->actingAs($this->admin)
            ->post(route('shm.savings.accounts.store'), [
                'shm_customer_id' => $this->customer->id,
                'shm_saving_product_id' => $product->id,
                'opened_at' => now()->toDateString(),
            ]);

        $account = ShmSavingsAccount::where('shm_customer_id', $this->customer->id)->firstOrFail();
        $response->assertRedirect(route('shm.savings.accounts.show', $account->id));

        $this->assertDatabaseHas('shm_savings_accounts', [
            'shm_customer_id' => $this->customer->id,
            'shm_saving_product_id' => $product->id,
            'balance' => 0,
            'status' => 'active',
        ]);
        // Verifikasi isolasi: tabel Koperasi tidak bertambah
        $this->assertDatabaseCount('savings_accounts', 0);
    }

    public function test_inactive_product_cannot_be_used_for_new_account(): void
    {
        $product = ShmSavingProduct::where('category', ShmSavingProduct::CATEGORY_VOLUNTARY)->firstOrFail();
        $product->update(['is_active' => false]);

        $this->actingAs($this->admin)
            ->post(route('shm.savings.accounts.store'), [
                'shm_customer_id' => $this->customer->id,
                'shm_saving_product_id' => $product->id,
                'opened_at' => now()->toDateString(),
            ])
            ->assertSessionHasErrors(['shm_saving_product_id']);
    }

    // =========================================================================
    // 3. SHM SAVINGS TRANSACTIONS (DEPOSIT & WITHDRAWAL)
    // =========================================================================

    public function test_can_deposit_and_withdraw_manual_shm_savings(): void
    {
        $product = ShmSavingProduct::where('category', ShmSavingProduct::CATEGORY_VOLUNTARY)->firstOrFail();

        $account = ShmSavingsAccount::create([
            'account_number' => ShmSavingsAccount::generateAccountNumber(),
            'shm_customer_id' => $this->customer->id,
            'shm_saving_product_id' => $product->id,
            'opened_at' => now()->toDateString(),
            'status' => 'active',
            'balance' => 0,
        ]);

        // 1. Setoran 500.000
        $depositResponse = $this->actingAs($this->admin)
            ->post(route('shm.savings.transactions.deposit.store', $account->id), [
                'type' => 'deposit',
                'amount' => 500_000,
                'transaction_date' => now()->toDateString(),
                'description' => 'Setoran manual via UI',
            ]);

        $depositResponse->assertRedirect(route('shm.savings.accounts.show', $account->id));
        $this->assertSame(500_000.0, (float) $account->fresh()->balance);

        $this->assertDatabaseHas('shm_savings_transactions', [
            'shm_savings_account_id' => $account->id,
            'type' => 'deposit',
            'amount' => 500_000,
        ]);

        // 2. Penarikan 200.000
        $withdrawResponse = $this->actingAs($this->admin)
            ->post(route('shm.savings.transactions.withdraw.store', $account->id), [
                'type' => 'withdrawal',
                'amount' => 200_000,
                'transaction_date' => now()->toDateString(),
                'description' => 'Penarikan manual via UI',
            ]);

        $withdrawResponse->assertRedirect(route('shm.savings.accounts.show', $account->id));
        $this->assertSame(300_000.0, (float) $account->fresh()->balance);

        $this->assertDatabaseHas('shm_savings_transactions', [
            'shm_savings_account_id' => $account->id,
            'type' => 'withdrawal',
            'amount' => 200_000,
        ]);

        // Verifikasi isolasi mutlak: Koperasi transactions tetap 0
        $this->assertDatabaseCount('savings_transactions', 0);
    }

    public function test_withdrawal_fails_if_balance_insufficient(): void
    {
        $product = ShmSavingProduct::where('category', ShmSavingProduct::CATEGORY_VOLUNTARY)->firstOrFail();

        $account = ShmSavingsAccount::create([
            'account_number' => ShmSavingsAccount::generateAccountNumber(),
            'shm_customer_id' => $this->customer->id,
            'shm_saving_product_id' => $product->id,
            'opened_at' => now()->toDateString(),
            'status' => 'active',
            'balance' => 50_000,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('shm.savings.transactions.withdraw.store', $account->id), [
                'type' => 'withdrawal',
                'amount' => 100_000, // lebih besar dari saldo
                'transaction_date' => now()->toDateString(),
            ]);

        $response->assertSessionHasErrors(['amount']);
        $this->assertSame(50_000.0, (float) $account->fresh()->balance);
    }

    public function test_transaction_fails_on_closed_account(): void
    {
        $product = ShmSavingProduct::where('category', ShmSavingProduct::CATEGORY_VOLUNTARY)->firstOrFail();

        $account = ShmSavingsAccount::create([
            'account_number' => ShmSavingsAccount::generateAccountNumber(),
            'shm_customer_id' => $this->customer->id,
            'shm_saving_product_id' => $product->id,
            'opened_at' => now()->toDateString(),
            'status' => 'closed',
            'balance' => 0,
        ]);

        $this->actingAs($this->admin)
            ->post(route('shm.savings.transactions.deposit.store', $account->id), [
                'type' => 'deposit',
                'amount' => 100_000,
                'transaction_date' => now()->toDateString(),
            ])
            ->assertSessionHas('error');
    }

    // =========================================================================
    // 4. AUTHORIZATION
    // =========================================================================

    public function test_unauthorized_user_cannot_access_shm_savings(): void
    {
        $this->actingAs($this->userWithoutPermission)
            ->get(route('shm.savings.products.index'))
            ->assertForbidden();

        $this->actingAs($this->userWithoutPermission)
            ->get(route('shm.savings.accounts.index'))
            ->assertForbidden();
    }
}
