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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithShmDisbursement;
use Tests\TestCase;

class ShmLoanIdentityDocumentPhotoTest extends TestCase
{
    use InteractsWithShmDisbursement;
    use RefreshDatabase;

    private User $superadmin;

    private User $admin;

    private User $marketing;

    private User $otherMarketing;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = $this->userWithRole('superadmin');
        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->otherMarketing = $this->userWithRole('marketing');
        $this->kasir = $this->userWithRole('kasir');

        Storage::fake('local');
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
            ['code' => 'AGEN-FIXTURE'],
            ['name' => 'Agen Fixture', 'nik' => '3201000000009999', 'is_active' => true],
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

    /** 1. Upload KTP dan KK format JPG */
    public function test_upload_ktp_and_kk_with_jpg_format(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        $payload['pribadi']['ktp_photo'] = UploadedFile::fake()->image('ktp.jpg', 300, 200);
        $payload['pribadi']['kk_photo'] = UploadedFile::fake()->image('kk.jpg', 300, 200);

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $app = ShmLoanApplication::query()->sole();
        $this->assertNotNull($app->ktp_photo_path);
        $this->assertNotNull($app->kk_photo_path);

        $this->assertStringStartsWith('shm/identity-photos/'.$app->id.'/ktp_', $app->ktp_photo_path);
        $this->assertStringStartsWith('shm/identity-photos/'.$app->id.'/kk_', $app->kk_photo_path);

        Storage::disk('local')->assertExists($app->ktp_photo_path);
        Storage::disk('local')->assertExists($app->kk_photo_path);
    }

    /** 2. Upload KTP dan KK format PNG */
    public function test_upload_ktp_and_kk_with_png_format(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        $payload['pribadi']['ktp_photo'] = UploadedFile::fake()->image('ktp.png', 300, 200);
        $payload['pribadi']['kk_photo'] = UploadedFile::fake()->image('kk.png', 300, 200);

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasNoErrors();

        $app = ShmLoanApplication::query()->sole();
        $this->assertStringEndsWith('.png', $app->ktp_photo_path);
        $this->assertStringEndsWith('.png', $app->kk_photo_path);

        Storage::disk('local')->assertExists($app->ktp_photo_path);
        Storage::disk('local')->assertExists($app->kk_photo_path);
    }

    /** 3. Upload file tepat pada atau di bawah batas 2 MB (2048 KB) */
    public function test_upload_files_at_exact_max_size_allowed(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        $payload['pribadi']['ktp_photo'] = UploadedFile::fake()->create('ktp_2mb.jpg', 2048, 'image/jpeg');
        $payload['pribadi']['kk_photo'] = UploadedFile::fake()->create('kk_2mb.png', 2048, 'image/png');

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasNoErrors();

        $app = ShmLoanApplication::query()->sole();
        Storage::disk('local')->assertExists($app->ktp_photo_path);
        Storage::disk('local')->assertExists($app->kk_photo_path);
    }

    /** 4. Upload file lebih dari 2 MB ditolak */
    public function test_upload_files_exceeding_2mb_are_rejected(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        $payload['pribadi']['ktp_photo'] = UploadedFile::fake()->create('ktp_large.jpg', 2049, 'image/jpeg');
        $payload['pribadi']['kk_photo'] = UploadedFile::fake()->create('kk_large.png', 3000, 'image/png');

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasErrors(['pribadi.ktp_photo', 'pribadi.kk_photo']);

        $this->assertSame(0, ShmLoanApplication::query()->count());
        Storage::disk('local')->assertDirectoryEmpty('shm/identity-photos');
    }

    /** 5. Format file tidak didukung ditolak */
    public function test_unsupported_file_formats_are_rejected(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        $payload['pribadi']['ktp_photo'] = UploadedFile::fake()->create('doc.pdf', 500, 'application/pdf');
        $payload['pribadi']['kk_photo'] = UploadedFile::fake()->create('script.php', 10, 'application/x-php');

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasErrors(['pribadi.ktp_photo', 'pribadi.kk_photo']);

        $this->assertSame(0, ShmLoanApplication::query()->count());
    }

    /** 6. Submit tanpa file (opsional / backward-compatible) */
    public function test_submit_without_files_succeeds(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasNoErrors();

        $app = ShmLoanApplication::query()->sole();
        $this->assertNull($app->ktp_photo_path);
        $this->assertNull($app->kk_photo_path);
    }

    /** 7. Pastikan dokumen KTP dan KK tidak tertukar dan path tersimpan di pengajuan yang benar */
    public function test_ktp_and_kk_paths_do_not_swap_and_belong_to_correct_record(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        $payload['pribadi']['ktp_photo'] = UploadedFile::fake()->image('my_ktp.jpg', 100, 100);
        $payload['pribadi']['kk_photo'] = UploadedFile::fake()->image('my_kk.png', 100, 100);

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasNoErrors();

        $app = ShmLoanApplication::query()->sole();
        $this->assertStringContainsString('ktp_'.$app->id, $app->ktp_photo_path);
        $this->assertStringContainsString('kk_'.$app->id, $app->kk_photo_path);

        // Verifikasi detail Inertia props
        $this->actingAs($this->admin)->get(route('shm.loans.pengajuan.show', $app))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/PengajuanShow')
                ->where('application.pribadi.ktp_photo_url', fn ($val) => str_contains($val, '/identitas/ktp/foto'))
                ->where('application.pribadi.kk_photo_url', fn ($val) => str_contains($val, '/identitas/kk/foto'))
                ->etc());
    }

    /** 8. Dua pengajuan berbeda tidak saling menimpa dokumen */
    public function test_multiple_applications_isolate_their_documents(): void
    {
        $customerA = ShmCustomer::factory()->create();
        $payloadA = $this->validPayload($customerA->id);
        $payloadA['pribadi']['ktp_photo'] = UploadedFile::fake()->image('ktp_a.jpg', 100, 100);
        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payloadA)
            ->assertSessionHasNoErrors();

        $customerB = ShmCustomer::factory()->create();
        $payloadB = $this->validPayload($customerB->id);
        $payloadB['pribadi']['ktp_photo'] = UploadedFile::fake()->image('ktp_b.jpg', 100, 100);
        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payloadB)
            ->assertSessionHasNoErrors();

        $appA = ShmLoanApplication::query()->first();
        $appB = ShmLoanApplication::query()->latest('id')->first();

        $this->assertNotSame($appA->id, $appB->id);
        $this->assertNotSame($appA->ktp_photo_path, $appB->ktp_photo_path);

        Storage::disk('local')->assertExists($appA->ktp_photo_path);
        Storage::disk('local')->assertExists($appB->ktp_photo_path);
    }

    /** 9. Update / pergantian dokumen membersihkan file lama (failure-safe, no orphan) */
    public function test_update_identity_photo_replaces_old_file(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['ktp_photo'] = UploadedFile::fake()->image('ktp_initial.jpg', 100, 100);

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasNoErrors();

        $app = ShmLoanApplication::query()->sole();
        $oldPath = $app->ktp_photo_path;
        Storage::disk('local')->assertExists($oldPath);

        // Edit pengajuan
        $updatePayload = $this->validPayload($customer->id);
        $updatePayload['pribadi']['ktp_photo'] = UploadedFile::fake()->image('ktp_replaced.png', 100, 100);

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.update', $app->id), $updatePayload + ['_method' => 'put'])
            ->assertSessionHasNoErrors();

        $app->refresh();
        $this->assertNotSame($oldPath, $app->ktp_photo_path);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($app->ktp_photo_path);
    }

    /** 10. Otorisasi akses dokumen identitas */
    public function test_identity_document_photo_authorization(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['ktp_photo'] = UploadedFile::fake()->image('ktp.jpg', 100, 100);

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasNoErrors();

        $app = ShmLoanApplication::query()->sole();
        $url = route('shm.loans.pengajuan.identitas.foto', [$app->id, 'ktp']);

        // Tamu (unauthenticated) -> redirect login
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect(route('shm.login'));

        // Kasir (tanpa shm.view) -> 403
        $this->actingAs($this->kasir)->get($url)->assertForbidden();

        // Marketing lain (tanpa shm.view_all) -> 403
        $this->actingAs($this->otherMarketing)->get($url)->assertForbidden();

        // Pemilik pengajuan (marketing) -> 200
        $this->actingAs($this->marketing)->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // Admin (shm.view_all) -> 200
        $this->actingAs($this->admin)->get($url)->assertOk();

        // Tipe tidak valid -> 404
        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.identitas.foto', [$app->id, 'sim']))
            ->assertNotFound();
    }
}
