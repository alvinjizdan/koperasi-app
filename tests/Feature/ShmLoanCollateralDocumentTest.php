<?php

namespace Tests\Feature;

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
use Tests\Concerns\InteractsWithShmDisbursement;
use Tests\TestCase;

class ShmLoanCollateralDocumentTest extends TestCase
{
    use InteractsWithShmDisbursement;
    use RefreshDatabase;

    private User $superadmin;

    private User $admin;

    private User $marketing;

    private User $otherMarketing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = $this->userWithRole('superadmin');
        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->otherMarketing = $this->userWithRole('marketing');

        $this->seedShmSavingsProducts();
        $this->openCashierSessionFor($this->admin);

        Storage::fake('local');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /**
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
                'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
                'purpose' => 'Modal usaha',
                'loan_sequence' => 1,
                'disbursement_method' => 'Tunai',
            ],
            'income' => [
                'tetap' => '15000000',
                'pasangan' => '5000000',
                'usaha' => '10000000',
            ],
            'expense' => [
                'potongan' => '1000000',
                'rumahTangga' => '5000000',
                'pendidikan' => '2000000',
                'sewa' => '0',
                'listrik' => '1000000',
                'transportasi' => '1000000',
                'lain' => '500000',
            ],
            'tambahan' => [
                'koperasi_loan_balance' => '0',
                'other_facility' => '',
                'institution_name' => '',
            ],
            'agunan' => [],
            'jaminan' => [],
            'catatan' => ['date' => now()->toDateString(), 'name' => 'Kasir 1', 'notes' => 'Catatan kasir'],
            'analisa' => [
                'analyst_code' => 'AN01',
                'analyst_office' => 'Pusat',
                'officer_code' => 'AO01',
                'officer_office' => 'Pusat',
                'date' => now()->toDateString(),
                'value' => '150000000',
                'notes' => 'Layak',
            ],
            'kolektor' => ['name' => 'Kolektor 1'],
            'persetujuan' => [
                'approved_amount' => '',
                'provision_fee' => '',
                'administration_fee' => '',
                'method' => 'flat',
                'notes' => '',
            ],
            'persyaratan' => [],
        ];
    }

    private function makeApplication(?User $user = null): ShmLoanApplication
    {
        $customer = ShmCustomer::factory()->create();
        $this->actingAs($user ?? $this->admin)
            ->post(route('shm.loans.pengajuan.store'), $this->validPayload($customer->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return ShmLoanApplication::query()->latest('id')->firstOrFail();
    }

    public function test_can_upload_pdf_document_when_creating_application(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        $pdf = UploadedFile::fake()->create('akta_tanah.pdf', 1024, 'application/pdf');

        $payload['jaminan'] = [
            [
                'tanggal' => now()->toDateString(),
                'jenis' => 'SHM',
                'nama' => 'Sertifikat Tanah No. 1234',
                'keterangan' => 'Dekat jalan raya',
                'nilai_perkiraan' => '500000000',
                'status_pengikatan' => 'APHT',
                'tanggal_pengikatan' => now()->toDateString(),
                'nilai_hak_tanggungan' => '200000000',
                'dokumen' => $pdf,
            ],
        ];

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasNoErrors();

        $application = ShmLoanApplication::query()->latest('id')->firstOrFail();
        $this->assertCount(1, $application->collaterals);

        $collateral = $application->collaterals->first();
        $this->assertNotNull($collateral->document_path);
        $this->assertSame('akta_tanah.pdf', $collateral->document_name);

        Storage::disk('local')->assertExists($collateral->document_path);
    }

    public function test_rejects_non_pdf_document(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        $invalidFile = UploadedFile::fake()->create('document.docx', 1024, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $payload['jaminan'] = [
            [
                'tanggal' => now()->toDateString(),
                'jenis' => 'SHM',
                'nama' => 'Sertifikat Tanah No. 1234',
                'nilai_perkiraan' => '500000000',
                'dokumen' => $invalidFile,
            ],
        ];

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasErrors('jaminan.0.dokumen');

        $this->assertSame(0, ShmLoanApplication::query()->count());
    }

    public function test_rejects_pdf_exceeding_max_size_5mb(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        // 6 MB = 6144 KB > 5120 KB limit
        $largePdf = UploadedFile::fake()->create('huge.pdf', 6144, 'application/pdf');

        $payload['jaminan'] = [
            [
                'tanggal' => now()->toDateString(),
                'jenis' => 'SHM',
                'nama' => 'Sertifikat Tanah No. 1234',
                'nilai_perkiraan' => '500000000',
                'dokumen' => $largePdf,
            ],
        ];

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasErrors('jaminan.0.dokumen');

        $this->assertSame(0, ShmLoanApplication::query()->count());
    }

    public function test_can_view_and_download_pdf_document_when_authorized(): void
    {
        $application = $this->makeApplication($this->marketing);

        $filePath = 'shm/collateral-documents/'.$application->id.'/test.pdf';
        Storage::disk('local')->put($filePath, '%PDF-1.4 test document content');

        $collateral = ShmLoanApplicationCollateral::create([
            'shm_loan_application_id' => $application->id,
            'shm_customer_id' => $application->shm_customer_id,
            'collateral_date' => now()->toDateString(),
            'collateral_type' => 'SHM',
            'collateral_name' => 'Sertifikat Tanah',
            'estimated_value' => 500000000,
            'document_path' => $filePath,
            'document_name' => 'original_name.pdf',
        ]);

        $url = route('shm.loans.pengajuan.jaminan.dokumen', [$application->id, $collateral->id]);

        $response = $this->actingAs($this->marketing)->get($url);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_unauthorized_user_cannot_access_collateral_document(): void
    {
        $application = $this->makeApplication($this->marketing);

        $filePath = 'shm/collateral-documents/'.$application->id.'/test.pdf';
        Storage::disk('local')->put($filePath, '%PDF-1.4 test document content');

        $collateral = ShmLoanApplicationCollateral::create([
            'shm_loan_application_id' => $application->id,
            'shm_customer_id' => $application->shm_customer_id,
            'collateral_date' => now()->toDateString(),
            'collateral_type' => 'SHM',
            'collateral_name' => 'Sertifikat Tanah',
            'estimated_value' => 500000000,
            'document_path' => $filePath,
            'document_name' => 'original_name.pdf',
        ]);

        $url = route('shm.loans.pengajuan.jaminan.dokumen', [$application->id, $collateral->id]);

        $response = $this->actingAs($this->otherMarketing)->get($url);
        $response->assertForbidden();
    }

    public function test_superadmin_can_access_any_collateral_document(): void
    {
        $application = $this->makeApplication($this->marketing);

        $filePath = 'shm/collateral-documents/'.$application->id.'/test.pdf';
        Storage::disk('local')->put($filePath, '%PDF-1.4 test document content');

        $collateral = ShmLoanApplicationCollateral::create([
            'shm_loan_application_id' => $application->id,
            'shm_customer_id' => $application->shm_customer_id,
            'collateral_date' => now()->toDateString(),
            'collateral_type' => 'SHM',
            'collateral_name' => 'Sertifikat Tanah',
            'estimated_value' => 500000000,
            'document_path' => $filePath,
            'document_name' => 'original_name.pdf',
        ]);

        $url = route('shm.loans.pengajuan.jaminan.dokumen', [$application->id, $collateral->id]);

        $response = $this->actingAs($this->superadmin)->get($url);
        $response->assertOk();
    }

    public function test_replacing_document_deletes_old_document_file(): void
    {
        $application = $this->makeApplication($this->marketing);

        $oldFilePath = 'shm/collateral-documents/'.$application->id.'/old.pdf';
        Storage::disk('local')->put($oldFilePath, '%PDF-1.4 old content');

        $collateral = ShmLoanApplicationCollateral::create([
            'shm_loan_application_id' => $application->id,
            'shm_customer_id' => $application->shm_customer_id,
            'collateral_date' => now()->toDateString(),
            'collateral_type' => 'SHM',
            'collateral_name' => 'Sertifikat Tanah',
            'estimated_value' => 500000000,
            'document_path' => $oldFilePath,
            'document_name' => 'old.pdf',
        ]);

        $payload = $this->validPayload($application->shm_customer_id);
        $newPdf = UploadedFile::fake()->create('new.pdf', 1024, 'application/pdf');

        $payload['jaminan'] = [
            [
                'id' => $collateral->id,
                'tanggal' => now()->toDateString(),
                'jenis' => 'SHM',
                'nama' => 'Sertifikat Tanah Updated',
                'nilai_perkiraan' => '500000000',
                'dokumen' => $newPdf,
            ],
        ];

        $response = $this->actingAs($this->marketing)->put(
            route('shm.loans.pengajuan.update', $application->id),
            $payload
        );
        $response->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing($oldFilePath);

        $collateral->refresh();
        $this->assertNotNull($collateral->document_path);
        $this->assertNotSame($oldFilePath, $collateral->document_path);
        $this->assertSame('new.pdf', $collateral->document_name);
        Storage::disk('local')->assertExists($collateral->document_path);
    }

    public function test_existing_collateral_without_document_remains_valid(): void
    {
        $application = $this->makeApplication($this->marketing);

        $collateral = ShmLoanApplicationCollateral::create([
            'shm_loan_application_id' => $application->id,
            'shm_customer_id' => $application->shm_customer_id,
            'collateral_date' => now()->toDateString(),
            'collateral_type' => 'SHM',
            'collateral_name' => 'Sertifikat Tanah',
            'estimated_value' => 500000000,
            'document_path' => null,
            'document_name' => null,
        ]);

        $response = $this->actingAs($this->marketing)->get(
            route('shm.loans.pengajuan.show', $application->id)
        );
        $response->assertOk();

        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Shm/Loans/PengajuanShow')
                ->has('application.jaminan.0', fn (Assert $item) => $item
                    ->where('dokumen_url', null)
                    ->where('dokumen_name', null)
                    ->etc()
                )
        );
    }
}
