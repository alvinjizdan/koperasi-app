<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmLoan;
use App\Models\ShmLoanProduct;
use App\Models\User;
use App\Services\Shm\ShmProductRateService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ShmLoanProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * T98-FIX — CRUD master produk Pinjaman SHM (Tambah/Edit).
 *
 * Yang diuji:
 * - CREATE/UPDATE: admin membuat & mengedit baris master pada skema
 *   existing (tanpa migrasi baru); kode UNIQUE server-side.
 * - VALIDASI: field wajib, kategori dibatasi tiga kategori resmi T82,
 *   panjang maksimum kolom, kode duplikat ditolak.
 * - AUTHZ: pemegang shm.view saja TIDAK dapat membuka form maupun menulis;
 *   role marketing (shm.update/shm.customer.* ada, tanpa
 *   shm.product.manage) juga ditolak — backend menegakkan permission
 *   terpisah (pola BUG-01).
 * - RATE SERVICE tetap source of truth: produk baru tanpa tier DITOLAK
 *   resolver (tanpa fallback 2%); produk resmi tetap resolve normal.
 * - IMMUHISTORIS: edit master tidak mengubah rate/snapshot pinjaman lama.
 */
class ShmLoanProductCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(ShmLoanProductSeeder::class);
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
            'name' => 'product-crud-tester-'.Str::uuid(),
            'display_name' => 'Product CRUD Tester',
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id')
        );

        $user->roles()->attach($role->id);

        return $user;
    }

    private function admin(): User
    {
        return $this->userWithRole('admin');
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'BSM-BARU',
            'name' => 'BSM Baru',
            'category' => ShmLoanProduct::CATEGORY_PERUMAHAN,
            'description' => 'Produk uji CRUD.',
            'eligibility_summary' => 'Syarat uji CRUD.',
            'is_active' => true,
        ], $overrides);
    }

    // =================================================================
    // CREATE
    // =================================================================

    public function test_admin_can_create_product_on_existing_schema(): void
    {
        $this->actingAs($this->admin())
            ->post(route('shm.loans.products.store'), $this->validPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('shm.loans.products.index'));

        $product = ShmLoanProduct::query()->where('code', 'BSM-BARU')->firstOrFail();

        $this->assertSame('BSM Baru', $product->name);
        $this->assertSame(ShmLoanProduct::CATEGORY_PERUMAHAN, $product->category);
        $this->assertSame('Produk uji CRUD.', $product->description);
        $this->assertSame('Syarat uji CRUD.', $product->eligibility_summary);
        $this->assertTrue($product->is_active);

        // Enam produk resmi + 1 buatan CRUD; seeder tetap 5 baris orisinal.
        $this->assertSame(6, ShmLoanProduct::query()->count());

        // Produk baru TANPA tier rate — kolom rate tidak ada di master.
        $this->assertSame(0, $product->rateTiers()->count());
        $this->assertFalse(Schema::hasColumn('shm_loan_products', 'rate_percent'));
    }

    public function test_create_page_renders_for_manager_and_is_not_swallowed_by_wildcard(): void
    {
        $this->actingAs($this->admin())
            ->get(route('shm.loans.products.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Shm/Loans/ProductForm')
                ->where('product', null)
                ->has('categories', 3)
                ->where('categories.0.id', ShmLoanProduct::CATEGORY_PERUMAHAN)
                ->where('categories.1.id', ShmLoanProduct::CATEGORY_PERKAMPUNGAN)
                ->where('categories.2.id', ShmLoanProduct::CATEGORY_RO)
                ->etc());
    }

    public function test_create_rejects_duplicate_code(): void
    {
        $this->actingAs($this->admin())
            ->post(route('shm.loans.products.store'), $this->validPayload([
                'code' => 'BSM-PRIORITAS',
            ]))
            ->assertSessionHasErrors('code');

        $this->assertSame(5, ShmLoanProduct::query()->count());
    }

    public function test_create_validates_required_fields_and_official_category(): void
    {
        $this->actingAs($this->admin())
            ->post(route('shm.loans.products.store'), [
                'code' => '',
                'name' => '',
                'category' => 'LAINNYA',
            ])
            ->assertSessionHasErrors(['code', 'name', 'category']);

        // Kategori di luar tiga kategori resmi T82 ditolak.
        $this->actingAs($this->admin())
            ->post(route('shm.loans.products.store'), $this->validPayload([
                'category' => 'KONSUMSI',
            ]))
            ->assertSessionHasErrors('category');

        // Panjang maksimum kolom (code 30, nama 120) berlaku.
        $this->actingAs($this->admin())
            ->post(route('shm.loans.products.store'), $this->validPayload([
                'code' => str_repeat('X', 31),
                'name' => str_repeat('Y', 121),
            ]))
            ->assertSessionHasErrors(['code', 'name']);

        $this->assertSame(5, ShmLoanProduct::query()->count());
    }

    public function test_create_accepts_inactive_product_and_null_optionals(): void
    {
        $this->actingAs($this->admin())
            ->post(route('shm.loans.products.store'), $this->validPayload([
                'is_active' => false,
                'description' => null,
                'eligibility_summary' => null,
            ]))
            ->assertSessionHasNoErrors();

        $product = ShmLoanProduct::query()->where('code', 'BSM-BARU')->firstOrFail();
        $this->assertFalse($product->is_active);
        $this->assertNull($product->description);
        $this->assertNull($product->eligibility_summary);
    }

    // =================================================================
    // UPDATE
    // =================================================================

    public function test_admin_can_edit_product_master_fields(): void
    {
        $product = ShmLoanProduct::query()->where('code', 'BSM-PRIORITAS')->firstOrFail();

        $this->actingAs($this->admin())
            ->put(route('shm.loans.products.update', $product), $this->validPayload([
                'code' => 'BSM-PRIORITAS-2',
                'name' => 'BSM Prioritas Baru',
                'category' => ShmLoanProduct::CATEGORY_RO,
                'description' => 'Keterangan baru.',
                'eligibility_summary' => 'Syarat baru.',
                'is_active' => false,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('shm.loans.products.index'));

        $product->refresh();
        $this->assertSame('BSM-PRIORITAS-2', $product->code);
        $this->assertSame('BSM Prioritas Baru', $product->name);
        $this->assertSame(ShmLoanProduct::CATEGORY_RO, $product->category);
        $this->assertSame('Keterangan baru.', $product->description);
        $this->assertSame('Syarat baru.', $product->eligibility_summary);
        $this->assertFalse($product->is_active);

        // Tier rate produk TIDAK tersentuh oleh edit master.
        $this->assertSame(5, $product->rateTiers()->count());
    }

    public function test_edit_page_prefills_product_and_passes_categories(): void
    {
        $product = ShmLoanProduct::query()->where('code', 'BSM-FLEXIBEL')->firstOrFail();

        $this->actingAs($this->admin())
            ->get(route('shm.loans.products.edit', $product))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Shm/Loans/ProductForm')
                ->where('product.code', 'BSM-FLEXIBEL')
                ->where('product.name', 'BSM Flexibel')
                ->where('product.category', ShmLoanProduct::CATEGORY_PERUMAHAN)
                ->where('product.is_active', true)
                ->has('categories', 3)
                ->etc());
    }

    public function test_update_rejects_code_taken_by_another_product_but_keeps_own_code(): void
    {
        $prioritas = ShmLoanProduct::query()->where('code', 'BSM-PRIORITAS')->firstOrFail();
        $flexibel = ShmLoanProduct::query()->where('code', 'BSM-FLEXIBEL')->firstOrFail();

        // Kode milik produk lain ditolak.
        $this->actingAs($this->admin())
            ->put(route('shm.loans.products.update', $flexibel), $this->validPayload([
                'code' => 'BSM-PRIORITAS',
                'name' => 'BSM Flexibel',
                'category' => ShmLoanProduct::CATEGORY_PERUMAHAN,
            ]))
            ->assertSessionHasErrors('code');

        // Kode sendiri tetap sah saat edit (ignoreCurrent).
        $this->actingAs($this->admin())
            ->put(route('shm.loans.products.update', $prioritas), $this->validPayload([
                'code' => 'BSM-PRIORITAS',
                'name' => 'BSM Prioritas Tetap',
                'category' => ShmLoanProduct::CATEGORY_PERUMAHAN,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('BSM Prioritas Tetap', $prioritas->fresh()->name);
        $this->assertSame(5, ShmLoanProduct::query()->count());
    }

    // =================================================================
    // AUTHZ (baca ≠ tulis — pola BUG-01)
    // =================================================================

    public function test_view_only_user_cannot_open_forms_or_mutate_products(): void
    {
        $viewer = $this->userWithPermissions(['shm.view']);
        $product = ShmLoanProduct::query()->where('code', 'BSM-PRIORITAS')->firstOrFail();

        $this->actingAs($viewer)->get(route('shm.loans.products.create'))->assertForbidden();
        $this->actingAs($viewer)->get(route('shm.loans.products.edit', $product))->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('shm.loans.products.store'), $this->validPayload())
            ->assertForbidden();
        $this->actingAs($viewer)
            ->put(route('shm.loans.products.update', $product), $this->validPayload())
            ->assertForbidden();

        // Halaman daftar tetap terbaca bagi pemegang shm.view.
        $this->actingAs($viewer)
            ->get(route('shm.loans.products.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canManage', false)
                ->etc());

        $this->assertSame(5, ShmLoanProduct::query()->count());
    }

    public function test_marketing_role_without_product_manage_cannot_mutate(): void
    {
        // Marketing memiliki shm.update/shm.customer.* tetapi TIDAK
        // shm.product.manage — master produk tetap tertutup baginya.
        $marketing = $this->userWithRole('marketing');
        $product = ShmLoanProduct::query()->where('code', 'BSM-PRIORITAS')->firstOrFail();

        $this->assertFalse($marketing->hasPermission('shm.product.manage'));

        $this->actingAs($marketing)->get(route('shm.loans.products.create'))->assertForbidden();
        $this->actingAs($marketing)
            ->post(route('shm.loans.products.store'), $this->validPayload())
            ->assertForbidden();
        $this->actingAs($marketing)
            ->put(route('shm.loans.products.update', $product), $this->validPayload())
            ->assertForbidden();

        // Daftar produk tetap terbaca; tombol CRUD tidak dirender.
        $this->actingAs($marketing)
            ->get(route('shm.loans.products.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canManage', false)
                ->etc());
    }

    public function test_index_passes_can_manage_true_for_manager(): void
    {
        $manager = $this->userWithPermissions(['shm.view', 'shm.product.manage']);

        $this->actingAs($manager)
            ->get(route('shm.loans.products.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canManage', true)
                ->etc());
    }

    // =================================================================
    // RATE SERVICE TETAP SOURCE OF TRUTH (tanpa fallback 2%)
    // =================================================================

    public function test_new_product_without_tiers_is_rejected_by_rate_resolver(): void
    {
        $this->actingAs($this->admin())
            ->post(route('shm.loans.products.store'), $this->validPayload())
            ->assertSessionHasNoErrors();

        $product = ShmLoanProduct::query()->where('code', 'BSM-BARU')->firstOrFail();
        $resolver = app(ShmProductRateService::class);

        try {
            $resolver->resolve($product, 12);
            $this->fail('Produk tanpa tier harus ditolak resolver — bukan fallback 2%.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('tidak tersedia', (string) $exception->getMessage());
        }

        // Produk resmi tetap resolve normal (resolver tidak berubah).
        $prioritas = ShmLoanProduct::query()->where('code', 'BSM-PRIORITAS')->firstOrFail();
        $this->assertSame(1.30, (float) $resolver->resolve($prioritas, 12)->ratePercent);
    }

    // =================================================================
    // IMMUHISTORIS (edit master tidak mengubah pinjaman lama)
    // =================================================================

    public function test_editing_master_does_not_change_existing_loan_snapshot(): void
    {
        $product = ShmLoanProduct::query()->where('code', 'BSM-PRIORITAS')->firstOrFail();

        $loan = ShmLoan::factory()->create([
            'shm_loan_product_id' => $product->id,
            'tenor_months' => 48,
            'rate_percent' => 1.60,
            'rate_period' => 'monthly',
            'calculation_method' => 'flat',
        ]);

        // Edit master: ganti nama/kode dan nonaktifkan produk.
        $this->actingAs($this->admin())
            ->put(route('shm.loans.products.update', $product), $this->validPayload([
                'code' => 'BSM-PRIORITAS-EDIT',
                'name' => 'BSM Prioritas Diubah',
                'category' => ShmLoanProduct::CATEGORY_PERUMAHAN,
                'is_active' => false,
            ]))
            ->assertSessionHasNoErrors();

        $loan->refresh();
        $this->assertSame(1.60, (float) $loan->rate_percent);
        $this->assertSame('monthly', $loan->rate_period);
        $this->assertSame('flat', $loan->calculation_method);
        $this->assertSame($product->id, $loan->shm_loan_product_id);

        // Snapshot historis terkunci; tier produk juga tidak berubah.
        $this->assertSame(5, $product->rateTiers()->count());
    }

    public function test_deactivated_product_rejects_new_resolution_but_not_history(): void
    {
        $product = ShmLoanProduct::query()->where('code', 'BSM-FLEXIBEL')->firstOrFail();

        $this->actingAs($this->admin())
            ->put(route('shm.loans.products.update', $product), $this->validPayload([
                'code' => 'BSM-FLEXIBEL',
                'name' => 'BSM Flexibel',
                'category' => ShmLoanProduct::CATEGORY_PERUMAHAN,
                'is_active' => false,
            ]))
            ->assertSessionHasNoErrors();

        $resolver = app(ShmProductRateService::class);

        try {
            $resolver->resolve($product->fresh(), 12);
            $this->fail('Produk nonaktif harus ditolak resolver untuk pengajuan baru.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('tidak aktif', (string) $exception->getMessage());
        }
    }
}
