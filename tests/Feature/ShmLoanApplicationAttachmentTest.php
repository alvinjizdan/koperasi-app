<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShmAgent;
use App\Models\ShmCustomer;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanApplicationAttachment;
use App\Models\ShmLoanProduct;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShmLoanApplicationAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;

    private User $otherMarketing;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->marketing = $this->userWithRole('marketing');
        $this->otherMarketing = $this->userWithRole('marketing');
        $this->admin = $this->userWithRole('admin');

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

        $product = ShmLoanProduct::factory()->create();

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
                'simpanan_khusus' => '500000',
                'status_pernikahan' => 'Belum Menikah',
                'agent_id' => $agent->id,
            ],
            'pengajuan' => [
                'product_id' => $product->id,
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
            'jaminan' => [
                [
                    'tanggal' => now()->toDateString(),
                    'jenis' => 'SHM',
                    'nama' => 'Sertifikat Tanah No. 9999',
                    'nilai_perkiraan' => '150000000',
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

    public function test_can_upload_single_pdf_attachment_under_10mb(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        $file = UploadedFile::fake()->create('lampiran_1.pdf', 2048, 'application/pdf');
        $payload['lampiran'] = [$file];

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasNoErrors();

        $application = ShmLoanApplication::firstOrFail();
        $this->assertCount(1, $application->attachments);

        $attachment = $application->attachments->first();
        $this->assertSame('lampiran_1.pdf', $attachment->file_name);
        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertGreaterThan(0, $attachment->file_size);
        Storage::disk('local')->assertExists($attachment->file_path);
    }

    public function test_can_upload_multiple_pdf_attachments(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        $file1 = UploadedFile::fake()->create('lampiran_a.pdf', 1024, 'application/pdf');
        $file2 = UploadedFile::fake()->create('lampiran_b.pdf', 3072, 'application/pdf');
        $payload['lampiran'] = [$file1, $file2];

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasNoErrors();

        $application = ShmLoanApplication::firstOrFail();
        $this->assertCount(2, $application->attachments);

        foreach ($application->attachments as $attachment) {
            Storage::disk('local')->assertExists($attachment->file_path);
        }
    }

    public function test_accepts_pdf_attachment_at_exact_10mb_limit(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        // Exactly 10240 KB = 10 MB
        $file = UploadedFile::fake()->create('lampiran_max.pdf', 10240, 'application/pdf');
        $payload['lampiran'] = [$file];

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasNoErrors();

        $this->assertSame(1, ShmLoanApplicationAttachment::query()->count());
    }

    public function test_rejects_pdf_attachment_exceeding_10mb_limit(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        // 10241 KB > 10240 KB limit
        $file = UploadedFile::fake()->create('lampiran_oversized.pdf', 10241, 'application/pdf');
        $payload['lampiran'] = [$file];

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasErrors('lampiran.0');

        $this->assertSame(0, ShmLoanApplicationAttachment::query()->count());
    }

    public function test_rejects_non_pdf_attachment(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        $file = UploadedFile::fake()->create('dokumen.docx', 500, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $payload['lampiran'] = [$file];

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasErrors('lampiran.0');

        $this->assertSame(0, ShmLoanApplicationAttachment::query()->count());
    }

    public function test_authorized_user_and_admin_can_view_and_download_attachment(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $file = UploadedFile::fake()->createWithContent('kontrak.pdf', '%PDF-1.4 test contract content');
        $payload['lampiran'] = [$file];

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $application = ShmLoanApplication::firstOrFail();
        $attachment = $application->attachments->firstOrFail();

        // 1. Owner can view/download
        $url = route('shm.loans.pengajuan.lampiran.dokumen', [
            'application' => $application->id,
            'attachmentId' => $attachment->id,
        ]);
        $ownerResp = $this->actingAs($this->marketing)->get($url);
        $ownerResp->assertOk();
        $this->assertSame('application/pdf', $ownerResp->headers->get('content-type'));

        // 2. Admin can view/download
        $adminResp = $this->actingAs($this->admin)->get(route('shm.loans.pengajuan.lampiran.dokumen', [
            'application' => $application->id,
            'attachmentId' => $attachment->id,
        ]));
        $adminResp->assertOk();

        // 3. Other non-admin marketing cannot view/download (403)
        $unauthResp = $this->actingAs($this->otherMarketing)->get(route('shm.loans.pengajuan.lampiran.dokumen', [
            'application' => $application->id,
            'attachmentId' => $attachment->id,
        ]));
        $unauthResp->assertForbidden();

        // 4. Guest is redirected to login
        auth()->logout();
        $guestResp = $this->get(route('shm.loans.pengajuan.lampiran.dokumen', [
            'application' => $application->id,
            'attachmentId' => $attachment->id,
        ]));
        $guestResp->assertRedirect(route('shm.login'));
    }

    public function test_update_application_can_add_and_delete_attachments(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $file1 = UploadedFile::fake()->create('file1.pdf', 512, 'application/pdf');
        $payload['lampiran'] = [$file1];

        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $application = ShmLoanApplication::firstOrFail();
        $firstAttachment = $application->attachments->firstOrFail();
        $firstPath = $firstAttachment->file_path;

        Storage::disk('local')->assertExists($firstPath);

        // Update: delete firstAttachment and upload second attachment
        $file2 = UploadedFile::fake()->create('file2.pdf', 1024, 'application/pdf');
        $updatePayload = array_merge($this->validPayload($customer->id), [
            'deleted_attachment_ids' => [$firstAttachment->id],
            'lampiran' => [$file2],
        ]);

        $response = $this->actingAs($this->marketing)->put(route('shm.loans.pengajuan.update', $application->id), $updatePayload);
        $response->assertSessionHasNoErrors();

        // Check DB and storage
        $application->refresh();
        $this->assertCount(1, $application->attachments);
        $newAttachment = $application->attachments->first();
        $this->assertSame('file2.pdf', $newAttachment->file_name);

        // Previous file must be removed from disk
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($newAttachment->file_path);
    }
}
