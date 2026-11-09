<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShmAgent;
use App\Models\ShmCustomer;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShmLoanMaritalStatusAndSpouseTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->marketing = $this->userWithRole('marketing');
        $this->admin = $this->userWithRole('admin');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    private function validPayload(int $customerId): array
    {
        $agent = ShmAgent::firstOrCreate(
            ['code' => 'AGEN-TEST'],
            ['name' => 'Agen Test', 'nik' => '3201000000001234', 'is_active' => true],
        );

        return [
            'pribadi' => [
                'customer_id' => $customerId,
                'unit_kerja' => 'Pemasaran',
                'nip' => '12345',
                'telepon_rumah' => '021123456',
                'tempat_lahir' => 'Jakarta',
                'tanggal_lahir' => '1990-01-01',
                'jumlah_tanggungan' => 2,
                'saldo_modal' => '1000000',
                'saldo_non_modal' => '500000',
                'batas_hutang' => '5000000',
                'marital_status' => 'single',
                'spouse_name' => '',
                'spouse_nik' => '',
                'spouse_phone' => '',
            ],
            'pengajuan' => [
                'agent_id' => $agent->id,
                'product_id' => ShmLoanProduct::factory()->create()->id,
                'principal' => '50000000',
                'tenor_months' => 12,
                'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
                'purpose' => 'Modal Usaha',
                'loan_sequence' => 1,
                'disbursement_method' => 'Tunai',
            ],
            'income' => ['tetap' => '5000000', 'pasangan' => '0', 'usaha' => '0'],
            'expense' => [
                'potongan' => '0', 'rumahTangga' => '2000000', 'pendidikan' => '0',
                'sewa' => '0', 'listrik' => '0', 'transportasi' => '0', 'lain' => '0',
            ],
            'tambahan' => [
                'koperasi_loan_balance' => '0', 'other_facility' => '', 'institution_name' => '',
            ],
            'agunan' => [],
            'jaminan' => [],
            'catatan' => ['date' => now()->format('Y-m-d'), 'name' => '', 'notes' => ''],
            'analisa' => [
                'analyst_code' => '', 'analyst_office' => '', 'officer_code' => '',
                'officer_office' => '', 'date' => '', 'value' => '', 'notes' => '',
            ],
            'kolektor' => ['name' => ''],
            'persetujuan' => [
                'approved_amount' => '', 'provision_fee' => '', 'administration_fee' => '',
                'method' => 'flat', 'notes' => '',
            ],
            'persyaratan' => [],
        ];
    }

    /** 1 & 2 & 3. Buat pengajuan dengan status Belum Menikah: data pasangan null/tidak disimpan */
    public function test_submit_with_single_status_does_not_store_spouse_data(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'single';
        $payload['pribadi']['spouse_name'] = 'Should Be Cleared';
        $payload['pribadi']['spouse_nik'] = '3201000000009999';
        $payload['pribadi']['spouse_phone'] = '081234567890';

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $app = ShmLoanApplication::query()->sole();
        $this->assertSame('single', $app->marital_status);
        $this->assertNull($app->spouse_name);
        $this->assertNull($app->spouse_nik);
        $this->assertNull($app->spouse_phone);

        // Detail view hanya menampilkan status tanpa info pasangan
        $this->actingAs($this->admin)->get(route('shm.loans.pengajuan.show', $app))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/PengajuanShow')
                ->where('application.pribadi.marital_status', 'single')
                ->where('application.pribadi.spouse_name', null)
                ->etc());
    }

    /** 4 & 5. Buat pengajuan dengan status Sudah Menikah & data pasangan lengkap */
    public function test_submit_with_married_status_stores_spouse_data_correctly(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'married';
        $payload['pribadi']['spouse_name'] = 'Siti Aminah';
        $payload['pribadi']['spouse_nik'] = '3201015505920001';
        $payload['pribadi']['spouse_phone'] = '081234567890';

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $app = ShmLoanApplication::query()->sole();
        $this->assertSame('married', $app->marital_status);
        $this->assertSame('Siti Aminah', $app->spouse_name);
        $this->assertSame('3201015505920001', $app->spouse_nik);
        $this->assertSame('081234567890', $app->spouse_phone);

        // Detail view menampilkan data pasangan
        $this->actingAs($this->admin)->get(route('shm.loans.pengajuan.show', $app))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/PengajuanShow')
                ->where('application.pribadi.marital_status', 'married')
                ->where('application.pribadi.spouse_name', 'Siti Aminah')
                ->where('application.pribadi.spouse_nik', '3201015505920001')
                ->where('application.pribadi.spouse_phone', '081234567890')
                ->etc());
    }

    /** 6. Submit status Sudah Menikah tanpa nama pasangan ditolak */
    public function test_submit_married_without_spouse_name_fails(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'married';
        $payload['pribadi']['spouse_name'] = '';
        $payload['pribadi']['spouse_nik'] = '3201015505920001';
        $payload['pribadi']['spouse_phone'] = '081234567890';

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasErrors(['pribadi.spouse_name']);
        $this->assertSame(0, ShmLoanApplication::query()->count());
    }

    /** 7. Submit status Sudah Menikah tanpa NIK pasangan ditolak */
    public function test_submit_married_without_spouse_nik_fails(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'married';
        $payload['pribadi']['spouse_name'] = 'Siti Aminah';
        $payload['pribadi']['spouse_nik'] = '';
        $payload['pribadi']['spouse_phone'] = '081234567890';

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasErrors(['pribadi.spouse_nik']);
        $this->assertSame(0, ShmLoanApplication::query()->count());
    }

    /** 8. Submit status Sudah Menikah tanpa nomor telepon pasangan ditolak */
    public function test_submit_married_without_spouse_phone_fails(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'married';
        $payload['pribadi']['spouse_name'] = 'Siti Aminah';
        $payload['pribadi']['spouse_nik'] = '3201015505920001';
        $payload['pribadi']['spouse_phone'] = '';

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasErrors(['pribadi.spouse_phone']);
        $this->assertSame(0, ShmLoanApplication::query()->count());
    }

    /** 9. Submit NIK pasangan dengan format tidak valid (bukan 16 digit angka) */
    public function test_submit_spouse_nik_with_invalid_format_fails(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'married';
        $payload['pribadi']['spouse_name'] = 'Siti Aminah';
        $payload['pribadi']['spouse_nik'] = '12345'; // kurang dari 16 digit
        $payload['pribadi']['spouse_phone'] = '081234567890';

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasErrors(['pribadi.spouse_nik']);

        $payload['pribadi']['spouse_nik'] = '320101550592000A'; // ada huruf
        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasErrors(['pribadi.spouse_nik']);

        $this->assertSame(0, ShmLoanApplication::query()->count());
    }

    /** 10. Edit pengajuan menikah memastikan data sebelumnya terisi */
    public function test_edit_married_application_prefills_spouse_data(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'married';
        $payload['pribadi']['spouse_name'] = 'Siti Aminah';
        $payload['pribadi']['spouse_nik'] = '3201015505920001';
        $payload['pribadi']['spouse_phone'] = '081234567890';

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $app = ShmLoanApplication::query()->sole();

        $this->actingAs($this->marketing)->get(route('shm.loans.pengajuan.edit', $app->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Pengajuan')
                ->where('formData.pribadi.marital_status', 'married')
                ->where('formData.pribadi.spouse_name', 'Siti Aminah')
                ->where('formData.pribadi.spouse_nik', '3201015505920001')
                ->where('formData.pribadi.spouse_phone', '081234567890')
                ->etc());
    }

    /** 11 & 12. Ubah status dari Sudah Menikah menjadi Belum Menikah menghapus data pasangan */
    public function test_updating_status_from_married_to_single_clears_spouse_data(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'married';
        $payload['pribadi']['spouse_name'] = 'Siti Aminah';
        $payload['pribadi']['spouse_nik'] = '3201015505920001';
        $payload['pribadi']['spouse_phone'] = '081234567890';

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $app = ShmLoanApplication::query()->sole();

        // Update jadi single
        $updatePayload = $this->validPayload($customer->id);
        $updatePayload['pribadi']['marital_status'] = 'single';
        $updatePayload['pribadi']['spouse_name'] = '';
        $updatePayload['pribadi']['spouse_nik'] = '';
        $updatePayload['pribadi']['spouse_phone'] = '';

        $response = $this->actingAs($this->marketing)
            ->put(route('shm.loans.pengajuan.update', $app->id), $updatePayload);
        $response->assertSessionHasNoErrors();

        $app->refresh();
        $this->assertSame('single', $app->marital_status);
        $this->assertNull($app->spouse_name);
        $this->assertNull($app->spouse_nik);
        $this->assertNull($app->spouse_phone);
    }

    /** 13. Data lama tanpa status pernikahan tetap dapat dibuka */
    public function test_legacy_application_without_marital_status_still_opens_seamlessly(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        unset($payload['pribadi']['marital_status']);
        unset($payload['pribadi']['spouse_name']);
        unset($payload['pribadi']['spouse_nik']);
        unset($payload['pribadi']['spouse_phone']);

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasNoErrors();

        $app = ShmLoanApplication::query()->sole();
        $this->assertNull($app->marital_status);

        // Dapat dibuka di show
        $this->actingAs($this->admin)->get(route('shm.loans.pengajuan.show', $app))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/PengajuanShow')
                ->where('application.pribadi.marital_status', null)
                ->etc());

        // Dapat dibuka di edit
        $this->actingAs($this->marketing)->get(route('shm.loans.pengajuan.edit', $app->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Pengajuan')
                ->where('formData.pribadi.marital_status', '')
                ->etc());
    }
}
