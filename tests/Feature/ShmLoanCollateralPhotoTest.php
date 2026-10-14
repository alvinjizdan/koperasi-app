<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanApplicationCollateral;
use App\Models\ShmLoanProduct;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Ticket 77 — persistence jaminan & foto pada Pengajuan Pinjaman SHM.
 *
 * Yang diuji:
 * - satu pengajuan dapat memiliki banyak jaminan (AC-02);
 * - jaminan tersimpan pada pengajuan yang benar (AC-07);
 * - referensi nasabah jaminan mengikuti nasabah pengajuan (AC-03);
 * - tidak ada fallback ke anggota koperasi (AC-04);
 * - nilai negatif ditolak (Part C);
 * - pengajuan tidak valid (id anggota koperasi) ditolak;
 * - unauthorized user tidak dapat membaca/mengubah jaminan lintas pemilik (AC-15);
 * - foto berhasil diunggah, path tersimpan, bertahan setelah reload (AC-08/09/10);
 * - file tidak valid ditolak (AC-11);
 * - pengajuan/jaminan lama tanpa foto tetap dapat dibuka (AC-13/14);
 * - approval & disbursement workflow tidak rusak (AC-16/17).
 *
 * Yang TIDAK diuji: master jenis/status jaminan (PENDING BUSINESS
 * CONFIRMATION), valuation/LTV/haircut (PENDING).
 */
class ShmLoanCollateralPhotoTest extends TestCase
{
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

    /**
     * Payload valid — bentuk sama dengan ShmLoanApplicationTest::validPayload.
     *
     * @return array<string, mixed>
     */
    private function validPayload(int $customerId): array
    {
        return [
            'pribadi' => ['customer_id' => $customerId],
            'pengajuan' => [
                'product_id' => ShmLoanProduct::factory()->create()->id,
                'principal' => '150000000',
                'tenor_months' => 24,
                // T82 — jatuh tempo pertama wajib diisi Marketing sebelum
                // pencairan (fixture mengisinya secara eksplisit).
                'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
                'purpose' => 'Modal usaha',
                'loan_sequence' => 1,
                'disbursement_method' => 'Tunai',
            ],
            'income' => ['tetap' => '3000000', 'pasangan' => '', 'usaha' => '500000'],
            'expense' => [
                'potongan' => '', 'rumahTangga' => '1500000', 'pendidikan' => '',
                'sewa' => '', 'listrik' => '', 'transportasi' => '', 'lain' => '',
            ],
            'tambahan' => [
                'koperasi_loan_balance' => '0', 'other_facility' => '', 'institution_name' => '',
            ],
            'agunan' => [],
            'jaminan' => [
                [
                    'tanggal' => now()->format('Y-m-d'),
                    'jenis' => 'BPKB',
                    'nama' => 'Motor Honda Beat',
                    'keterangan' => 'Milik nasabah',
                    'nilai_perkiraan' => '15000000',
                    'status_pengikatan' => 'Belum diikat',
                    'tanggal_pengikatan' => '',
                    'nilai_hak_tanggungan' => '',
                ],
            ],
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

    /** @param  array<string, mixed>  $payload */
    private function storeAs(User $user, array $payload): mixed
    {
        return $this->actingAs($user)->post(route('shm.loans.pengajuan.store'), $payload);
    }

    private function makeApplication(?User $user = null): ShmLoanApplication
    {
        $customer = ShmCustomer::factory()->create();
        $this->storeAs($user ?? $this->admin, $this->validPayload($customer->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return ShmLoanApplication::query()->latest('id')->firstOrFail();
    }

    // -----------------------------------------------------------------
    // A. Contract: relasi, banyak jaminan, referensi nasabah
    // -----------------------------------------------------------------

    public function test_one_application_can_hold_multiple_collaterals(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['jaminan'][] = [
            'tanggal' => now()->format('Y-m-d'),
            'jenis' => 'SHM',
            'nama' => 'Ruko Cirebon',
            'keterangan' => '',
            'nilai_perkiraan' => '500000000',
            'status_pengikatan' => '',
            'tanggal_pengikatan' => '',
            'nilai_hak_tanggungan' => '',
        ];

        $this->storeAs($this->admin, $payload)->assertSessionHasNoErrors();

        $application = ShmLoanApplication::query()->sole();

        $this->assertSame(2, $application->collaterals()->count());
        $this->assertTrue(
            $application->collaterals()
                ->where('shm_loan_application_id', $application->id)
                ->exists(),
        );
    }

    public function test_collateral_belongs_to_application_and_follows_application_customer(): void
    {
        $customer = ShmCustomer::factory()->create();
        $application = $this->makeApplicationWithCustomer($customer);

        $collateral = $application->collaterals()->sole();

        // Relasi hanya ke pengajuan — tidak ada kolom nasabah/anggota di baris jaminan.
        $this->assertSame($application->id, $collateral->shm_loan_application_id);
        $this->assertArrayNotHasKey('shm_customer_id', $collateral->getAttributes());
        $this->assertArrayNotHasKey('member_id', $collateral->getAttributes());

        // Nomor nasabah yang ditampilkan pada detail = nasabah pengajuan.
        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.show', $application))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/PengajuanShow')
                ->where('application.jaminan.0.customer_number', $customer->customer_number)
                ->etc());
    }

    private function makeApplicationWithCustomer(ShmCustomer $customer): ShmLoanApplication
    {
        $this->storeAs($this->admin, $this->validPayload($customer->id))
            ->assertSessionHasNoErrors();

        return ShmLoanApplication::query()->latest('id')->firstOrFail();
    }

    public function test_koperasi_member_cannot_be_used_for_collateral_application(): void
    {
        $member = Member::factory()->create();
        $payload = $this->validPayload(1);
        $payload['pribadi']['customer_id'] = $member->id;

        $this->storeAs($this->admin, $payload)
            ->assertSessionHasErrors('pribadi.customer_id');

        $this->assertSame(0, ShmLoanApplication::query()->count());
        $this->assertSame(0, ShmLoanApplicationCollateral::query()->count());
    }

    public function test_negative_collateral_values_are_rejected(): void
    {
        $customer = ShmCustomer::factory()->create();

        $payload = $this->validPayload($customer->id);
        $payload['jaminan'][0]['nilai_perkiraan'] = '-1';
        $this->storeAs($this->admin, $payload)
            ->assertSessionHasErrors('jaminan.0.nilai_perkiraan');

        $payload = $this->validPayload($customer->id);
        $payload['jaminan'][0]['nilai_hak_tanggungan'] = '-1';
        $this->storeAs($this->admin, $payload)
            ->assertSessionHasErrors('jaminan.0.nilai_hak_tanggungan');

        $this->assertSame(0, ShmLoanApplicationCollateral::query()->count());
    }

    // -----------------------------------------------------------------
    // B. Foto: unggah, path, reload
    // -----------------------------------------------------------------

    public function test_collateral_photo_can_be_uploaded_and_survives_reload(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['jaminan'][0]['foto'] = UploadedFile::fake()->image('jaminan.jpg', 200, 200);

        $this->storeAs($this->admin, $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $application = ShmLoanApplication::query()->sole();
        $collateral = $application->collaterals()->sole();

        // Path tersimpan relatif pada disk privat, bukan binary/absolute path.
        $this->assertNotNull($collateral->photo_path);
        $this->assertStringNotContainsString('..', $collateral->photo_path);
        $this->assertStringStartsWith('shm/collateral-photos/', $collateral->photo_path);
        Storage::disk('local')->assertExists($collateral->photo_path);

        // Foto tetap tersedia setelah request baru (reload).
        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.show', $application))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/PengajuanShow')
                ->where('application.jaminan.0.foto_url', fn (mixed $value): bool => is_string($value) && $value !== '')
                ->etc());

        $fotoUrl = route('shm.loans.pengajuan.jaminan.foto', [$application->id, $collateral->id]);

        $this->actingAs($this->admin)
            ->get($fotoUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_invalid_photo_file_is_rejected(): void
    {
        $customer = ShmCustomer::factory()->create();

        // Bukan gambar (teks) — ditolak.
        $payload = $this->validPayload($customer->id);
        $payload['jaminan'][0]['foto'] = UploadedFile::fake()->create('malware.php', 10, 'application/x-php');
        $this->storeAs($this->admin, $payload)
            ->assertSessionHasErrors('jaminan.0.foto');

        // Melebihi 2 MB — ditolak.
        $payload = $this->validPayload($customer->id);
        $payload['jaminan'][0]['foto'] = UploadedFile::fake()->create('besar.jpg', 3000, 'image/jpeg');
        $this->storeAs($this->admin, $payload)
            ->assertSessionHasErrors('jaminan.0.foto');

        // Seluruh transaksi store di-rollback — tidak ada baris & tidak ada berkas.
        $this->assertSame(0, ShmLoanApplication::query()->count());
        $this->assertSame(0, ShmLoanApplicationCollateral::query()->count());
        Storage::disk('local')->assertDirectoryEmpty('shm/collateral-photos');
    }

    public function test_existing_application_and_collateral_without_photo_still_open(): void
    {
        $application = $this->makeApplication();
        $collateral = $application->collaterals()->sole();

        // Kolom photo_path nullable — data lama valid tanpa foto (AC-13/14).
        $this->assertNull($collateral->photo_path);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.show', $application))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/PengajuanShow')
                ->where('application.jaminan.0.foto_url', null)
                ->etc());

        // Route foto mengembalikan 404 (bukan error 500/gambar rusak).
        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.jaminan.foto', [$application->id, $collateral->id]))
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // C. Otorisasi
    // -----------------------------------------------------------------

    public function test_photo_route_requires_shm_permission_and_ownership(): void
    {
        // Pengajuan dengan foto (izin 200 membutuhkan berkas nyata).
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['jaminan'][0]['foto'] = UploadedFile::fake()->image('izin.jpg', 100, 100);
        $this->storeAs($this->admin, $payload)->assertSessionHasNoErrors();

        $application = ShmLoanApplication::query()->sole();
        $collateral = $application->collaterals()->sole();
        $url = route('shm.loans.pengajuan.jaminan.foto', [$application->id, $collateral->id]);

        // Tamu → redirect login.
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect(route('shm.login'));

        // Tanpa permission SHM → 403.
        $this->actingAs($this->kasir)->get($url)->assertForbidden();

        // Pemilik lain (marketing lain, tanpa shm.view_all) → 403.
        $this->actingAs($this->otherMarketing)->get($url)->assertForbidden();

        // Admin (shm.view_all) → 200.
        $this->actingAs($this->admin)->get($url)->assertOk();
    }

    public function test_cross_owner_cannot_update_collateral_or_upload_photo(): void
    {
        $application = $this->makeApplication();
        $collateral = $application->collaterals()->sole();

        $payload = $this->validPayload($application->shm_customer_id);
        $payload['jaminan'][0]['id'] = $collateral->id;
        $payload['jaminan'][0]['foto'] = UploadedFile::fake()->image('curian.jpg', 100, 100);

        $this->actingAs($this->otherMarketing)
            ->post(route('shm.loans.pengajuan.update', $application->id), $payload + ['_method' => 'put'])
            ->assertForbidden();

        // Data & berkas tidak berubah.
        $this->assertSame('Motor Honda Beat', $collateral->fresh()->collateral_name);
        Storage::disk('local')->assertDirectoryEmpty('shm/collateral-photos');
    }

    public function test_locked_application_rejects_photo_change(): void
    {
        $application = $this->makeApplication();
        $collateral = $application->collaterals()->sole();

        // Ajukan approval → pengajuan terkunci.
        $this->actingAs($this->admin)
            ->post(route('shm.loans.pengajuan.submit-approval', $application->id))
            ->assertSessionHasNoErrors();

        $payload = $this->validPayload($application->shm_customer_id);
        $payload['jaminan'][0]['id'] = $collateral->id;
        $payload['jaminan'][0]['foto'] = UploadedFile::fake()->image('baru.jpg', 100, 100);

        $this->actingAs($this->admin)
            ->post(route('shm.loans.pengajuan.update', $application->id), $payload + ['_method' => 'put'])
            ->assertSessionHasErrors('application');

        $this->assertNull($collateral->fresh()->photo_path);
        Storage::disk('local')->assertDirectoryEmpty('shm/collateral-photos');
    }

    // -----------------------------------------------------------------
    // D. Workflow regression: approval & disbursement tetap berjalan
    // -----------------------------------------------------------------

    public function test_approval_and_disbursement_flow_still_works_with_photo(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['persetujuan']['approved_amount'] = '150000000';
        $payload['jaminan'][0]['foto'] = UploadedFile::fake()->image('jaminan.jpg', 120, 120);

        $this->storeAs($this->marketing, $payload)->assertSessionHasNoErrors();
        $application = ShmLoanApplication::query()->sole();

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.submit-approval', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();

        // Keputusan memakai modul Approval SHM existing (shm.applications.approve
        // dengan ShmWorkflowService sebagai state machine tunggal).
        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->shm_application_id), [
                'notes' => 'Disetujui (uji T77).',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('shm.loans.pengajuan.disburse', $application->id))
            ->assertSessionHasNoErrors();

        $this->assertNotNull($application->fresh()->loan);

        // PDF tetap dirender dengan foto yang tersimpan.
        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.pdf', $application))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_replacing_photo_deletes_old_file_after_commit(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['jaminan'][0]['foto'] = UploadedFile::fake()->image('lama.jpg', 100, 100);

        $this->storeAs($this->admin, $payload)->assertSessionHasNoErrors();

        $application = ShmLoanApplication::query()->sole();
        $collateral = $application->collaterals()->sole();
        $oldPath = $collateral->photo_path;
        Storage::disk('local')->assertExists($oldPath);

        $update = $this->validPayload($customer->id);
        $update['jaminan'][0]['id'] = $collateral->id;
        $update['jaminan'][0]['foto'] = UploadedFile::fake()->image('baru.jpg', 100, 100);

        $this->actingAs($this->admin)
            ->post(route('shm.loans.pengajuan.update', $application->id), $update + ['_method' => 'put'])
            ->assertSessionHasNoErrors();

        $fresh = $collateral->fresh();
        $this->assertNotNull($fresh->photo_path);
        $this->assertNotSame($oldPath, $fresh->photo_path);
        Storage::disk('local')->assertExists($fresh->photo_path);
        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_removing_collateral_removes_its_photo_file(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['jaminan'][0]['foto'] = UploadedFile::fake()->image('hapus.jpg', 100, 100);

        $this->storeAs($this->admin, $payload)->assertSessionHasNoErrors();

        $application = ShmLoanApplication::query()->sole();
        $collateral = $application->collaterals()->sole();
        $path = $collateral->photo_path;
        Storage::disk('local')->assertExists($path);

        // Update tanpa baris jaminan → baris & berkas ikut hilang (no orphan).
        $update = $this->validPayload($customer->id);
        $update['jaminan'] = [];

        $this->actingAs($this->admin)
            ->put(route('shm.loans.pengajuan.update', $application->id), $update)
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $application->collaterals()->count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_rollback_cleans_up_stored_photo_files(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['jaminan'][0]['foto'] = UploadedFile::fake()->image('rollback.jpg', 100, 100);
        // Persyaratan tidak valid → transaksi gagal setelah foto tersimpan.
        $payload['persyaratan'] = [['text' => '']];

        $this->storeAs($this->admin, $payload)
            ->assertSessionHasErrors('persyaratan.0.text');

        $this->assertSame(0, ShmLoanApplication::query()->count());
        $this->assertSame(0, ShmLoanApplicationCollateral::query()->count());
        Storage::disk('local')->assertDirectoryEmpty('shm/collateral-photos');
    }
}
