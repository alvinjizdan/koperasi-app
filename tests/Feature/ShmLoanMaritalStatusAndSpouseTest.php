<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShmAgent;
use App\Models\ShmCustomer;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Models\User;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShmLoanMaritalStatusAndSpouseTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;

    private User $marketingOther;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(MakroPhysicalRoleSeeder::class);

        $this->marketing = $this->userWithRole('marketing_makro');
        $this->marketingOther = $this->userWithRole('marketing_makro');
        $this->admin = $this->userWithRole('admin_makro');
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
                'marital_status' => 'belum_menikah',
                'marital_separation_type' => '',
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

    /** 1. Janda + Cerai Hidup requires Akta Cerai */
    public function test_janda_cerai_hidup_requires_akta_cerai(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'janda';
        $payload['pribadi']['marital_separation_type'] = 'cerai_hidup';

        // Tanpa akta cerai -> error
        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasErrors(['pribadi.akta_cerai']);

        // Dengan akta cerai PDF -> sukses
        $payload['pribadi']['akta_cerai'] = UploadedFile::fake()->create('akta_cerai.pdf', 300, 'application/pdf');
        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasNoErrors();

        $app = ShmLoanApplication::query()->sole();
        $this->assertSame('janda', $app->marital_status);
        $this->assertSame('cerai_hidup', $app->marital_separation_type);
        $this->assertNotNull($app->divorce_certificate_path);
        $this->assertNull($app->death_certificate_path);
        Storage::disk('local')->assertExists($app->divorce_certificate_path);
    }

    /** 2. Janda + Cerai Mati requires Akta Kematian */
    public function test_janda_cerai_mati_requires_akta_kematian(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'janda';
        $payload['pribadi']['marital_separation_type'] = 'cerai_mati';

        // Tanpa akta kematian -> error
        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasErrors(['pribadi.akta_kematian']);

        // Dengan akta kematian PDF -> sukses
        $payload['pribadi']['akta_kematian'] = UploadedFile::fake()->create('akta_kematian.pdf', 300, 'application/pdf');
        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasNoErrors();

        $app = ShmLoanApplication::query()->sole();
        $this->assertSame('janda', $app->marital_status);
        $this->assertSame('cerai_mati', $app->marital_separation_type);
        $this->assertNotNull($app->death_certificate_path);
        $this->assertNull($app->divorce_certificate_path);
        Storage::disk('local')->assertExists($app->death_certificate_path);
    }

    /** 3. Duda + Cerai Hidup requires Akta Cerai */
    public function test_duda_cerai_hidup_requires_akta_cerai(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'duda';
        $payload['pribadi']['marital_separation_type'] = 'cerai_hidup';

        // Tanpa akta cerai -> error
        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasErrors(['pribadi.akta_cerai']);

        // Dengan akta cerai PDF -> sukses
        $payload['pribadi']['akta_cerai'] = UploadedFile::fake()->create('akta_cerai_duda.pdf', 300, 'application/pdf');
        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasNoErrors();

        $app = ShmLoanApplication::query()->sole();
        $this->assertSame('duda', $app->marital_status);
        $this->assertSame('cerai_hidup', $app->marital_separation_type);
        $this->assertNotNull($app->divorce_certificate_path);
        Storage::disk('local')->assertExists($app->divorce_certificate_path);
    }

    /** 4. Duda + Cerai Mati requires Akta Kematian */
    public function test_duda_cerai_mati_requires_akta_kematian(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'duda';
        $payload['pribadi']['marital_separation_type'] = 'cerai_mati';

        // Tanpa akta kematian -> error
        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasErrors(['pribadi.akta_kematian']);

        // Dengan akta kematian PDF -> sukses
        $payload['pribadi']['akta_kematian'] = UploadedFile::fake()->create('akta_kematian_duda.pdf', 300, 'application/pdf');
        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasNoErrors();

        $app = ShmLoanApplication::query()->sole();
        $this->assertSame('duda', $app->marital_status);
        $this->assertSame('cerai_mati', $app->marital_separation_type);
        $this->assertNotNull($app->death_certificate_path);
        Storage::disk('local')->assertExists($app->death_certificate_path);
    }

    /** 5. Missing separation type is rejected for Janda/Duda */
    public function test_missing_separation_type_is_rejected_for_janda_and_duda(): void
    {
        $customer = ShmCustomer::factory()->create();

        // Janda tanpa separation type
        $payloadJanda = $this->validPayload($customer->id);
        $payloadJanda['pribadi']['marital_status'] = 'janda';
        $payloadJanda['pribadi']['marital_separation_type'] = '';
        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payloadJanda)
            ->assertSessionHasErrors(['pribadi.marital_separation_type']);

        // Duda tanpa separation type
        $payloadDuda = $this->validPayload($customer->id);
        $payloadDuda['pribadi']['marital_status'] = 'duda';
        $payloadDuda['pribadi']['marital_separation_type'] = '';
        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payloadDuda)
            ->assertSessionHasErrors(['pribadi.marital_separation_type']);
    }

    /** 6. Invalid separation type is rejected */
    public function test_invalid_separation_type_is_rejected(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'janda';
        $payload['pribadi']['marital_separation_type'] = 'pisah_ranjang'; // invalid

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasErrors(['pribadi.marital_separation_type']);
    }

    /** 7. Menikah still requires Buku Nikah */
    public function test_menikah_still_requires_buku_nikah(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'menikah';
        $payload['pribadi']['spouse_name'] = 'Siti Aminah';
        $payload['pribadi']['spouse_nik'] = '3201015505920001';
        $payload['pribadi']['spouse_phone'] = '081234567890';

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasErrors(['pribadi.buku_nikah']);

        $payload['pribadi']['buku_nikah'] = UploadedFile::fake()->create('buku_nikah.pdf', 300, 'application/pdf');
        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasNoErrors();

        $app = ShmLoanApplication::query()->sole();
        $this->assertSame('menikah', $app->marital_status);
        $this->assertNull($app->marital_separation_type);
        $this->assertNotNull($app->marriage_certificate_path);
    }

    /** 8. Belum Menikah requires no marital document */
    public function test_belum_menikah_requires_no_marital_document(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'belum_menikah';
        $payload['pribadi']['marital_separation_type'] = '';

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasNoErrors();

        $app = ShmLoanApplication::query()->sole();
        $this->assertSame('belum_menikah', $app->marital_status);
        $this->assertNull($app->marital_separation_type);
        $this->assertNull($app->marriage_certificate_path);
        $this->assertNull($app->divorce_certificate_path);
        $this->assertNull($app->death_certificate_path);
    }

    /** 9. Wrong document type for selected separation type is rejected */
    public function test_wrong_document_type_for_selected_separation_type_is_rejected(): void
    {
        $customer = ShmCustomer::factory()->create();

        // Cerai Mati tetapi hanya upload Akta Cerai (kurang akta kematian)
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'janda';
        $payload['pribadi']['marital_separation_type'] = 'cerai_mati';
        $payload['pribadi']['akta_cerai'] = UploadedFile::fake()->create('akta_cerai.pdf', 300, 'application/pdf');

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasErrors(['pribadi.akta_kematian']);

        // Cerai Hidup tetapi hanya upload Akta Kematian (kurang akta cerai)
        $payload2 = $this->validPayload($customer->id);
        $payload2['pribadi']['marital_status'] = 'duda';
        $payload2['pribadi']['marital_separation_type'] = 'cerai_hidup';
        $payload2['pribadi']['akta_kematian'] = UploadedFile::fake()->create('akta_kematian.pdf', 300, 'application/pdf');

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload2)
            ->assertSessionHasErrors(['pribadi.akta_cerai']);
    }

    /** 10. Non-PDF uploads are rejected */
    public function test_non_pdf_uploads_are_rejected(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'janda';
        $payload['pribadi']['marital_separation_type'] = 'cerai_mati';
        $payload['pribadi']['akta_kematian'] = UploadedFile::fake()->create('akta_kematian.jpg', 300, 'image/jpeg');

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasErrors(['pribadi.akta_kematian']);
    }

    /** 11. Documents exceeding 10 MB are rejected */
    public function test_documents_exceeding_10mb_are_rejected(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'duda';
        $payload['pribadi']['marital_separation_type'] = 'cerai_mati';
        $payload['pribadi']['akta_kematian'] = UploadedFile::fake()->create('akta_kematian_large.pdf', 11000, 'application/pdf');

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasErrors(['pribadi.akta_kematian']);
    }

    /** 12. Existing documents are preserved when no replacement is submitted */
    public function test_existing_documents_are_preserved_when_no_replacement_submitted(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'janda';
        $payload['pribadi']['marital_separation_type'] = 'cerai_mati';
        $payload['pribadi']['akta_kematian'] = UploadedFile::fake()->create('akta_kematian.pdf', 300, 'application/pdf');

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $app = ShmLoanApplication::query()->sole();
        $origPath = $app->death_certificate_path;

        // Update tanpa mengirim akta kematian baru
        $updatePayload = $this->validPayload($customer->id);
        $updatePayload['pribadi']['marital_status'] = 'janda';
        $updatePayload['pribadi']['marital_separation_type'] = 'cerai_mati';
        $updatePayload['pribadi']['unit_kerja'] = 'Pemasaran Baru';

        $response = $this->actingAs($this->marketing)
            ->put(route('shm.loans.pengajuan.update', $app->id), $updatePayload);
        $response->assertSessionHasNoErrors();

        $app->refresh();
        $this->assertSame('Pemasaran Baru', $app->unit_kerja);
        $this->assertSame($origPath, $app->death_certificate_path);
        Storage::disk('local')->assertExists($origPath);
    }

    /** 13. Replacing documents cleans up old files safely */
    public function test_replacing_documents_cleans_up_old_files_safely(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'janda';
        $payload['pribadi']['marital_separation_type'] = 'cerai_mati';
        $payload['pribadi']['akta_kematian'] = UploadedFile::fake()->create('akta_kematian_v1.pdf', 300, 'application/pdf');

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $app = ShmLoanApplication::query()->sole();
        $oldPath = $app->death_certificate_path;

        // Replace dengan akta kematian baru
        $updatePayload = $this->validPayload($customer->id);
        $updatePayload['pribadi']['marital_status'] = 'janda';
        $updatePayload['pribadi']['marital_separation_type'] = 'cerai_mati';
        $updatePayload['pribadi']['akta_kematian'] = UploadedFile::fake()->create('akta_kematian_v2.pdf', 350, 'application/pdf');

        $response = $this->actingAs($this->marketing)
            ->put(route('shm.loans.pengajuan.update', $app->id), $updatePayload);
        $response->assertSessionHasNoErrors();

        $app->refresh();
        $this->assertNotSame($oldPath, $app->death_certificate_path);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($app->death_certificate_path);
    }

    /** 14. Switching from Cerai Hidup to Cerai Mati preserves data integrity */
    public function test_switching_from_cerai_hidup_to_cerai_mati_preserves_integrity(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'janda';
        $payload['pribadi']['marital_separation_type'] = 'cerai_hidup';
        $payload['pribadi']['akta_cerai'] = UploadedFile::fake()->create('akta_cerai.pdf', 300, 'application/pdf');

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $app = ShmLoanApplication::query()->sole();
        $ceraiPath = $app->divorce_certificate_path;

        // Switch ke cerai mati dengan upload akta kematian
        $updatePayload = $this->validPayload($customer->id);
        $updatePayload['pribadi']['marital_status'] = 'janda';
        $updatePayload['pribadi']['marital_separation_type'] = 'cerai_mati';
        $updatePayload['pribadi']['akta_kematian'] = UploadedFile::fake()->create('akta_kematian.pdf', 300, 'application/pdf');

        $this->actingAs($this->marketing)->put(route('shm.loans.pengajuan.update', $app->id), $updatePayload)
            ->assertSessionHasNoErrors();

        $app->refresh();
        $this->assertSame('cerai_mati', $app->marital_separation_type);
        $this->assertNotNull($app->death_certificate_path);
        Storage::disk('local')->assertExists($app->death_certificate_path);
        Storage::disk('local')->assertExists($ceraiPath); // Previously stored document is preserved safely
    }

    /** 15. Switching from Cerai Mati to Cerai Hidup preserves data integrity */
    public function test_switching_from_cerai_mati_to_cerai_hidup_preserves_integrity(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'duda';
        $payload['pribadi']['marital_separation_type'] = 'cerai_mati';
        $payload['pribadi']['akta_kematian'] = UploadedFile::fake()->create('akta_kematian.pdf', 300, 'application/pdf');

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $app = ShmLoanApplication::query()->sole();
        $deathPath = $app->death_certificate_path;

        // Switch ke cerai hidup dengan upload akta cerai
        $updatePayload = $this->validPayload($customer->id);
        $updatePayload['pribadi']['marital_status'] = 'duda';
        $updatePayload['pribadi']['marital_separation_type'] = 'cerai_hidup';
        $updatePayload['pribadi']['akta_cerai'] = UploadedFile::fake()->create('akta_cerai.pdf', 300, 'application/pdf');

        $this->actingAs($this->marketing)->put(route('shm.loans.pengajuan.update', $app->id), $updatePayload)
            ->assertSessionHasNoErrors();

        $app->refresh();
        $this->assertSame('cerai_hidup', $app->marital_separation_type);
        $this->assertNotNull($app->divorce_certificate_path);
        Storage::disk('local')->assertExists($app->divorce_certificate_path);
        Storage::disk('local')->assertExists($deathPath);
    }

    /** 16. Authorized users can view the correct document */
    public function test_authorized_users_can_view_the_correct_document(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'janda';
        $payload['pribadi']['marital_separation_type'] = 'cerai_mati';
        $payload['pribadi']['akta_kematian'] = UploadedFile::fake()->create('akta_kematian.pdf', 300, 'application/pdf');

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $app = ShmLoanApplication::query()->sole();

        $response = $this->actingAs($this->marketing)->get(
            route('shm.loans.pengajuan.dokumen-pernikahan', [$app->id, 'akta_kematian'])
        );

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');

        // Admin juga dapat melihat
        $this->actingAs($this->admin)->get(
            route('shm.loans.pengajuan.dokumen-pernikahan', [$app->id, 'akta_kematian'])
        )->assertOk();
    }

    /** 17. Unauthorized users cannot access another applicant's document */
    public function test_unauthorized_users_cannot_access_another_applicants_document(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'janda';
        $payload['pribadi']['marital_separation_type'] = 'cerai_mati';
        $payload['pribadi']['akta_kematian'] = UploadedFile::fake()->create('akta_kematian.pdf', 300, 'application/pdf');

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $app = ShmLoanApplication::query()->sole();

        $this->actingAs($this->marketingOther)->get(
            route('shm.loans.pengajuan.dokumen-pernikahan', [$app->id, 'akta_kematian'])
        )->assertForbidden();
    }

    /** 18. Deleting an application cleans up all marital documents */
    public function test_deleting_application_cleans_up_all_marital_documents(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'janda';
        $payload['pribadi']['marital_separation_type'] = 'cerai_mati';
        $payload['pribadi']['akta_kematian'] = UploadedFile::fake()->create('akta_kematian.pdf', 300, 'application/pdf');

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $app = ShmLoanApplication::query()->sole();
        $path = $app->death_certificate_path;

        Storage::disk('local')->assertExists($path);
        $app->delete();
        Storage::disk('local')->assertMissing($path);
    }

    /** 19. Legacy single, married, and null records remain readable and editable */
    public function test_legacy_single_married_and_null_records_remain_readable_and_editable(): void
    {
        $customer = ShmCustomer::factory()->create();

        // Null marital status
        $payloadNull = $this->validPayload($customer->id);
        unset($payloadNull['pribadi']['marital_status']);
        unset($payloadNull['pribadi']['marital_separation_type']);
        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payloadNull)->assertSessionHasNoErrors();

        $appNull = ShmLoanApplication::query()->sole();
        $this->assertNull($appNull->marital_status);

        $this->actingAs($this->marketing)->get(route('shm.loans.pengajuan.edit', $appNull->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Pengajuan')
                ->where('formData.pribadi.marital_status', '')
                ->where('formData.pribadi.marital_separation_type', '')
                ->etc());

        // Single status
        $payloadSingle = $this->validPayload($customer->id);
        $payloadSingle['pribadi']['marital_status'] = 'single';
        $this->actingAs($this->marketing)->put(route('shm.loans.pengajuan.update', $appNull->id), $payloadSingle)->assertSessionHasNoErrors();
        $appNull->refresh();
        $this->assertSame('single', $appNull->marital_status);

        // Detail page Inertia verification
        $this->actingAs($this->admin)->get(route('shm.loans.pengajuan.show', $appNull))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/PengajuanShow')
                ->where('application.pribadi.marital_status', 'single')
                ->etc());
    }

    /** 20. Existing spouse-data validation remains correct */
    public function test_existing_spouse_data_validation_remains_correct(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['marital_status'] = 'menikah';
        $payload['pribadi']['buku_nikah'] = UploadedFile::fake()->create('buku_nikah.pdf', 300, 'application/pdf');

        // Missing spouse_name
        $payload['pribadi']['spouse_name'] = '';
        $payload['pribadi']['spouse_nik'] = '3201015505920001';
        $payload['pribadi']['spouse_phone'] = '081234567890';
        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasErrors(['pribadi.spouse_name']);

        // Invalid NIK
        $payload['pribadi']['spouse_name'] = 'Siti Aminah';
        $payload['pribadi']['spouse_nik'] = '12345';
        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasErrors(['pribadi.spouse_nik']);

        // Complete & valid
        $payload['pribadi']['spouse_nik'] = '3201015505920001';
        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasNoErrors();

        $app = ShmLoanApplication::query()->sole();
        $this->assertSame('Siti Aminah', $app->spouse_name);
        $this->assertSame('3201015505920001', $app->spouse_nik);
        $this->assertSame('081234567890', $app->spouse_phone);
    }
}
