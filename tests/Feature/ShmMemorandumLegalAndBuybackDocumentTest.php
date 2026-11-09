<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmBuybackDocument;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\ShmProperty;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature Test untuk Ticket 7 — Memorandum Legal Data & Buyback Document Management.
 *
 * Menguji:
 * 1. Bagian A: Masa Berlaku SHGB (SHM/SHGB):
 *    - Tanggal mulai dan tanggal akhir dapat disimpan saat Create dan Edit.
 *    - Validasi: tanggal akhir tidak boleh lebih awal dari tanggal mulai (after_or_equal).
 *    - Field tanggal hanya berlaku untuk SHM/SHGB (tidak tersimpan di buyback).
 *    - Kompatibilitas data lama tanpa tanggal.
 *
 * 2. Bagian B: Dokumen Legalitas Buyback:
 *    - 5 jenis dokumen terpisah (shm, akta_buyback, kuasa_awal, ppjb, pengosongan).
 *    - Upload dokumen format PDF (maksimal 10 MB).
 *    - Dokumen non-PDF atau melebihi batas ukuran ditolak.
 *    - Upload bertahap selama draft (0/5 hingga 5/5).
 *    - Penggantian dokumen aman (replace) tanpa menghapus jenis dokumen lain.
 *    - Download melalui route terproteksi dengan otorisasi & private storage.
 *    - Penghapusan dokumen aman (hanya berkas terkait yang dihapus).
 *    - Validasi Submitted: Wajib lengkap 5/5 dokumen sebelum dapat diubah ke status Submitted.
 *    - Proteksi Submitted: Dokumen tidak boleh dihapus jika menyebabkan dokumen tidak lengkap saat Submitted.
 *    - Penghapusan memorandum Buyback membersihkan berkas fisik dari storage tanpa menghapus pengajuan sumber.
 */
class ShmMemorandumLegalAndBuybackDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketing;

    private User $otherMarketing;

    private ShmCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id);
        }

        $this->marketing = User::factory()->create();
        $marketingRole = Role::where('name', 'marketing')->first();
        if ($marketingRole) {
            $this->marketing->roles()->attach($marketingRole->id);
        }

        $this->otherMarketing = User::factory()->create();
        if ($marketingRole) {
            $this->otherMarketing->roles()->attach($marketingRole->id);
        }

        $this->customer = ShmCustomer::create([
            'customer_number' => 'CUST-T7-001',
            'name' => 'Nasabah Ticket 7',
            'nik' => '3201000000007777',
            'phone' => '081234567877',
            'is_active' => true,
        ]);
    }

    private function createCompletedApp(string $type, ?User $marketingUser = null): ShmApplication
    {
        $app = ShmApplication::create([
            'application_number' => 'APP-'.strtoupper($type).'-'.uniqid(),
            'type' => $type,
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => ($marketingUser ?? $this->marketing)->id,
            'status' => ShmApplicationStatus::Completed,
            'completed_at' => now(),
            'created_by' => ($marketingUser ?? $this->marketing)->id,
        ]);

        ShmProperty::create([
            'shm_application_id' => $app->id,
            'certificate_type' => 'SHM',
            'certificate_number' => 'CERT-'.uniqid(),
            'owner_name' => $this->customer->name,
            'address' => 'Jl. Legalitas No. 7',
        ]);

        return $app;
    }

    // -------------------------------------------------------------
    // BAGIAN A: Masa Berlaku SHGB (SHM/SHGB)
    // -------------------------------------------------------------

    public function test_can_create_shm_shgb_memorandum_with_valid_dates(): void
    {
        $app = $this->createCompletedApp(ShmApplication::TYPE_SHGB);

        $response = $this->actingAs($this->admin)->post(route('shm.memorandums.shm-shgb.store'), [
            'shm_application_id' => $app->id,
            'status' => 'draft',
            'notes' => 'Catatan masa berlaku SHGB',
            'shgb_start_date' => '2026-01-01',
            'shgb_end_date' => '2046-01-01',
        ]);

        $response->assertRedirect(route('shm.memorandums.shm-shgb.index'));

        $this->assertDatabaseHas('shm_memorandums', [
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);

        $created = ShmMemorandum::where('shm_application_id', $app->id)->first();
        $this->assertNotNull($created);
        $this->assertSame('2026-01-01', $created->shgb_start_date->toDateString());
        $this->assertSame('2046-01-01', $created->shgb_end_date->toDateString());
    }

    public function test_shm_shgb_rejects_end_date_before_start_date(): void
    {
        $app = $this->createCompletedApp(ShmApplication::TYPE_SHGB);

        $response = $this->actingAs($this->admin)->post(route('shm.memorandums.shm-shgb.store'), [
            'shm_application_id' => $app->id,
            'status' => 'draft',
            'shgb_start_date' => '2026-05-10',
            'shgb_end_date' => '2026-05-01', // Invalid: sebelum start_date
        ]);

        $response->assertSessionHasErrors(['shgb_end_date']);
        $this->assertDatabaseMissing('shm_memorandums', [
            'shm_application_id' => $app->id,
        ]);
    }

    public function test_can_update_shm_shgb_dates(): void
    {
        $app = $this->createCompletedApp(ShmApplication::TYPE_SHGB);
        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => 'draft',
            'created_by' => $this->admin->id,
            'shgb_start_date' => '2025-01-01',
            'shgb_end_date' => '2045-01-01',
        ]);

        $response = $this->actingAs($this->admin)->put(route('shm.memorandums.shm-shgb.update', $memo->id), [
            'status' => 'draft',
            'notes' => 'Diperbarui',
            'shgb_start_date' => '2026-01-01',
            'shgb_end_date' => '2050-12-31',
        ]);

        $response->assertRedirect(route('shm.memorandums.shm-shgb.show', $memo->id));

        $memo->refresh();
        $this->assertSame('2026-01-01', $memo->shgb_start_date->toDateString());
        $this->assertSame('2050-12-31', $memo->shgb_end_date->toDateString());
    }

    public function test_historical_shm_shgb_without_dates_loads_safely(): void
    {
        $app = $this->createCompletedApp(ShmApplication::TYPE_SHGB);
        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => 'draft',
            'created_by' => $this->admin->id,
            'shgb_start_date' => null,
            'shgb_end_date' => null,
        ]);

        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.shm-shgb.show', $memo->id));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Memorandum/ShmShgbShow')
            ->where('memorandum.shgb_start_date', null)
            ->where('memorandum.shgb_end_date', null)
        );
    }

    // -------------------------------------------------------------
    // BAGIAN B: Dokumen Legalitas Buyback
    // -------------------------------------------------------------

    public function test_can_upload_all_five_buyback_documents_independently(): void
    {
        $app = $this->createCompletedApp(ShmApplication::TYPE_BUYBACK);
        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $documentTypes = [
            ShmBuybackDocument::TYPE_SHM,
            ShmBuybackDocument::TYPE_AKTA_BUYBACK,
            ShmBuybackDocument::TYPE_KUASA_AWAL,
            ShmBuybackDocument::TYPE_PPJB,
            ShmBuybackDocument::TYPE_PENGOSONGAN,
        ];

        foreach ($documentTypes as $type) {
            $pdf = UploadedFile::fake()->create("{$type}.pdf", 1000, 'application/pdf');

            $response = $this->actingAs($this->admin)->post(
                route('shm.memorandums.buyback.documents.upload', $memo->id),
                [
                    'document_type' => $type,
                    'file' => $pdf,
                ]
            );

            $response->assertSessionHasNoErrors();
            $response->assertRedirect();
        }

        $this->assertCount(5, $memo->buybackDocuments);
        $this->assertTrue($memo->isBuybackDocumentsComplete());
        $this->assertSame('5/5', $memo->buybackDocumentsRatio());
    }

    public function test_rejects_non_pdf_and_oversized_files(): void
    {
        $app = $this->createCompletedApp(ShmApplication::TYPE_BUYBACK);
        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        // 1. Non-PDF file (misal png/exe)
        $png = UploadedFile::fake()->create('doc.png', 500, 'image/png');
        $respNonPdf = $this->actingAs($this->admin)->post(
            route('shm.memorandums.buyback.documents.upload', $memo->id),
            ['document_type' => 'shm', 'file' => $png]
        );
        $respNonPdf->assertSessionHasErrors(['file']);

        // 2. File lebih besar dari 10 MB (10240 KB)
        $oversized = UploadedFile::fake()->create('huge.pdf', 11000, 'application/pdf');
        $respHuge = $this->actingAs($this->admin)->post(
            route('shm.memorandums.buyback.documents.upload', $memo->id),
            ['document_type' => 'shm', 'file' => $oversized]
        );
        $respHuge->assertSessionHasErrors(['file']);

        // 3. Tipe dokumen di luar 5 tipe resmi
        $validPdf = UploadedFile::fake()->create('test.pdf', 500, 'application/pdf');
        $respInvalidType = $this->actingAs($this->admin)->post(
            route('shm.memorandums.buyback.documents.upload', $memo->id),
            ['document_type' => 'invalid_doc_type', 'file' => $validPdf]
        );
        $respInvalidType->assertSessionHasErrors(['document_type']);
    }

    public function test_replacing_document_cleans_up_old_file_and_keeps_other_documents(): void
    {
        $app = $this->createCompletedApp(ShmApplication::TYPE_BUYBACK);
        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        // Upload SHM pertama
        $shmPdf1 = UploadedFile::fake()->create('shm_lama.pdf', 500, 'application/pdf');
        $this->actingAs($this->admin)->post(
            route('shm.memorandums.buyback.documents.upload', $memo->id),
            ['document_type' => 'shm', 'file' => $shmPdf1]
        );

        // Upload PPJB
        $ppjbPdf = UploadedFile::fake()->create('ppjb.pdf', 700, 'application/pdf');
        $this->actingAs($this->admin)->post(
            route('shm.memorandums.buyback.documents.upload', $memo->id),
            ['document_type' => 'ppjb', 'file' => $ppjbPdf]
        );

        $oldDoc = $memo->buybackDocuments()->where('document_type', 'shm')->first();
        $oldPath = $oldDoc->file_path;
        Storage::disk('local')->assertExists($oldPath);

        // Replace SHM kedua
        $shmPdf2 = UploadedFile::fake()->create('shm_baru.pdf', 800, 'application/pdf');
        $this->actingAs($this->admin)->post(
            route('shm.memorandums.buyback.documents.upload', $memo->id),
            ['document_type' => 'shm', 'file' => $shmPdf2]
        );

        // File lama terhapus, file baru ada, PPJB tetap utuh
        Storage::disk('local')->assertMissing($oldPath);
        $newDoc = $memo->buybackDocuments()->where('document_type', 'shm')->first();
        $this->assertSame('shm_baru.pdf', $newDoc->file_name);
        Storage::disk('local')->assertExists($newDoc->file_path);

        $this->assertDatabaseHas('shm_buyback_documents', [
            'shm_memorandum_id' => $memo->id,
            'document_type' => 'ppjb',
            'file_name' => 'ppjb.pdf',
        ]);
    }

    public function test_submitting_buyback_memorandum_without_complete_documents_is_rejected(): void
    {
        $app = $this->createCompletedApp(ShmApplication::TYPE_BUYBACK);
        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        // Coba submit tanpa dokumen (0/5)
        $resp0 = $this->actingAs($this->admin)->put(route('shm.memorandums.buyback.update', $memo->id), [
            'status' => 'submitted',
        ]);
        $resp0->assertSessionHas('error');
        $memo->refresh();
        $this->assertSame('draft', $memo->status);

        // Upload 4 dokumen (kurang 1)
        foreach (['shm', 'akta_buyback', 'kuasa_awal', 'ppjb'] as $type) {
            $this->actingAs($this->admin)->post(
                route('shm.memorandums.buyback.documents.upload', $memo->id),
                ['document_type' => $type, 'file' => UploadedFile::fake()->create("{$type}.pdf", 100, 'application/pdf')]
            );
        }

        // Coba submit dengan 4/5 dokumen
        $resp4 = $this->actingAs($this->admin)->put(route('shm.memorandums.buyback.update', $memo->id), [
            'status' => 'submitted',
        ]);
        $resp4->assertSessionHas('error');
        $memo->refresh();
        $this->assertSame('draft', $memo->status);

        // Upload dokumen ke-5 (pengosongan)
        $this->actingAs($this->admin)->post(
            route('shm.memorandums.buyback.documents.upload', $memo->id),
            ['document_type' => 'pengosongan', 'file' => UploadedFile::fake()->create('pengosongan.pdf', 100, 'application/pdf')]
        );

        // Submit dengan 5/5 dokumen lengkap -> BERHASIL
        $resp5 = $this->actingAs($this->admin)->put(route('shm.memorandums.buyback.update', $memo->id), [
            'status' => 'submitted',
        ]);
        $resp5->assertSessionHas('success');
        $memo->refresh();
        $this->assertSame('submitted', $memo->status);
        $this->assertNotNull($memo->submitted_at);
    }

    public function test_cannot_delete_document_when_memorandum_is_submitted(): void
    {
        $app = $this->createCompletedApp(ShmApplication::TYPE_BUYBACK);
        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        foreach (ShmBuybackDocument::REQUIRED_TYPES as $type) {
            $this->actingAs($this->admin)->post(
                route('shm.memorandums.buyback.documents.upload', $memo->id),
                ['document_type' => $type, 'file' => UploadedFile::fake()->create("{$type}.pdf", 100, 'application/pdf')]
            );
        }

        // Ubah status ke submitted
        $this->actingAs($this->admin)->put(route('shm.memorandums.buyback.update', $memo->id), [
            'status' => 'submitted',
        ]);

        $doc = $memo->buybackDocuments()->first();

        // Hapus dokumen saat submitted DITOLAK
        $delResp = $this->actingAs($this->admin)->delete(
            route('shm.memorandums.buyback.documents.destroy', [$memo->id, $doc->id])
        );

        $delResp->assertSessionHas('error');
        $this->assertDatabaseHas('shm_buyback_documents', ['id' => $doc->id]);
    }

    public function test_document_download_is_protected_by_ownership(): void
    {
        // Memorandum milik other marketing
        $otherApp = $this->createCompletedApp(ShmApplication::TYPE_BUYBACK, $this->otherMarketing);
        $memo = ShmMemorandum::create([
            'shm_application_id' => $otherApp->id,
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'status' => 'draft',
            'created_by' => $this->otherMarketing->id,
        ]);

        $this->actingAs($this->admin)->post(
            route('shm.memorandums.buyback.documents.upload', $memo->id),
            ['document_type' => 'shm', 'file' => UploadedFile::fake()->createWithContent('shm.pdf', '%PDF-1.4 Sample PDF content')]
        );

        $doc = $memo->buybackDocuments()->first();

        // Marketing tanpa view_all mencoba download dokumen milik otherMarketing -> 403 Forbidden
        $response = $this->actingAs($this->marketing)->get(
            route('shm.memorandums.buyback.documents.download', [$memo->id, $doc->id])
        );
        $response->assertStatus(403);

        // Admin berhasil download
        $adminResp = $this->actingAs($this->admin)->get(
            route('shm.memorandums.buyback.documents.download', ['memorandum' => $memo->id, 'document' => $doc->id])
        );
        $adminResp->assertStatus(200);
        $this->assertSame('application/pdf', $adminResp->headers->get('Content-Type'));
        $this->assertSame('application/pdf', $adminResp->headers->get('Content-Type'));
    }

    public function test_destroy_buyback_memorandum_cleans_files_and_preserves_application(): void
    {
        $app = $this->createCompletedApp(ShmApplication::TYPE_BUYBACK);
        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->post(
            route('shm.memorandums.buyback.documents.upload', $memo->id),
            ['document_type' => 'shm', 'file' => UploadedFile::fake()->create('shm.pdf', 100, 'application/pdf')]
        );

        $doc = $memo->buybackDocuments()->first();
        $filePath = $doc->file_path;
        Storage::disk('local')->assertExists($filePath);

        // Destroy memorandum
        $delResp = $this->actingAs($this->admin)->delete(route('shm.memorandums.buyback.destroy', $memo->id));
        $delResp->assertRedirect(route('shm.memorandums.buyback.index'));

        // Memorandum terhapus
        $this->assertDatabaseMissing('shm_memorandums', ['id' => $memo->id]);
        // Pengajuan sumber TETAP UTUH
        $this->assertDatabaseHas('shm_applications', ['id' => $app->id]);
    }
}
