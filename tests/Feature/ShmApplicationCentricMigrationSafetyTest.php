<?php

namespace Tests\Feature;

use App\Models\ShmCustomer;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Test Suite khusus untuk memverifikasi logika UP dan DOWN migration
 * 2026_11_09_090000_refactor_shm_documents_and_dates_to_applications.
 *
 * Menguji 8 skenario yang disyaratkan:
 * 1. Backfill kedua tanggal (start & end) secara independen.
 * 2. Backfill ketika hanya start date yang kosong pada application.
 * 3. Backfill ketika hanya end date yang kosong pada application.
 * 4. Konflik tanggal dari beberapa memorandum mendeteksi perbedaan dan melempar RuntimeException.
 * 5. Rollback dokumen yang masih terhubung ke memorandum berhasil direlink.
 * 6. Rollback dokumen baru yang belum memiliki memorandum melempar RuntimeException dan mencegah data loss.
 * 7. Rollback ketika memorandum telah dihapus (shm_memorandum_id NULL dan tidak ada memo) melempar RuntimeException.
 * 8. Multiple memorandum untuk satu application saat rollback dideteksi dan melempar RuntimeException.
 */
class ShmApplicationCentricMigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ShmCustomer $customer;

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->user = User::factory()->create();
        $this->customer = ShmCustomer::create([
            'customer_number' => 'CUST-TEST-01',
            'name' => 'Nasabah Test Migration',
            'nik' => '1234567890123456',
            'phone' => '081234567890',
            'is_active' => true,
        ]);

        $this->migration = require database_path('migrations/2026_11_09_090000_refactor_shm_documents_and_dates_to_applications.php');
    }

    private function createRawApp(string $type = 'shgb', ?string $startDate = null, ?string $endDate = null): int
    {
        $data = [
            'application_number' => 'APP-'.fake()->unique()->numerify('####'),
            'type' => $type,
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => $this->user->id,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('shm_applications', 'shgb_start_date')) {
            $data['shgb_start_date'] = $startDate;
        }
        if (Schema::hasColumn('shm_applications', 'shgb_end_date')) {
            $data['shgb_end_date'] = $endDate;
        }

        return DB::table('shm_applications')->insertGetId($data);
    }

    private function createRawMemo(int $appId, string $type = 'shm_shgb', ?string $startDate = null, ?string $endDate = null): int
    {
        return DB::table('shm_memorandums')->insertGetId([
            'shm_application_id' => $appId,
            'type' => $type,
            'status' => 'draft',
            'created_by' => $this->user->id,
            'shgb_start_date' => $startDate,
            'shgb_end_date' => $endDate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 1. Backfill kedua tanggal.
     */
    public function test_1_backfill_both_dates(): void
    {
        // Null-kan tanggal di aplikasi terlebih dahulu
        $appId = $this->createRawApp('shgb', null, null);
        DB::table('shm_applications')->where('id', $appId)->update(['shgb_start_date' => null, 'shgb_end_date' => null]);

        $this->createRawMemo($appId, 'shm_shgb', '2026-01-01', '2046-01-01');

        $this->migration->up();

        $app = DB::table('shm_applications')->where('id', $appId)->first();
        $this->assertSame('2026-01-01', $app->shgb_start_date);
        $this->assertSame('2046-01-01', $app->shgb_end_date);
    }

    /**
     * 2. Backfill ketika hanya start date yang kosong.
     */
    public function test_2_backfill_when_only_start_date_is_null(): void
    {
        $appId = $this->createRawApp('shgb', null, '2040-12-31');
        DB::table('shm_applications')->where('id', $appId)->update(['shgb_start_date' => null, 'shgb_end_date' => '2040-12-31']);

        $this->createRawMemo($appId, 'shm_shgb', '2026-02-01', '2046-02-01');

        $this->migration->up();

        $app = DB::table('shm_applications')->where('id', $appId)->first();
        // start_date terisi dari memo
        $this->assertSame('2026-02-01', $app->shgb_start_date);
        // end_date yang sudah ada di aplikasi TIDAK ditimpa
        $this->assertSame('2040-12-31', $app->shgb_end_date);
    }

    /**
     * 3. Backfill ketika hanya end date yang kosong.
     */
    public function test_3_backfill_when_only_end_date_is_null(): void
    {
        $appId = $this->createRawApp('shgb', '2025-05-01', null);
        DB::table('shm_applications')->where('id', $appId)->update(['shgb_start_date' => '2025-05-01', 'shgb_end_date' => null]);

        $this->createRawMemo($appId, 'shm_shgb', '2026-02-01', '2045-05-01');

        $this->migration->up();

        $app = DB::table('shm_applications')->where('id', $appId)->first();
        // start_date yang sudah ada di aplikasi TIDAK ditimpa
        $this->assertSame('2025-05-01', $app->shgb_start_date);
        // end_date terisi dari memo
        $this->assertSame('2045-05-01', $app->shgb_end_date);
    }

    /**
     * 4. Konflik tanggal dari beberapa memorandum.
     */
    public function test_4_detects_date_conflicts_from_multiple_memorandums(): void
    {
        $appId = $this->createRawApp('shgb', null, null);
        DB::table('shm_applications')->where('id', $appId)->update(['shgb_start_date' => null, 'shgb_end_date' => null]);

        $this->createRawMemo($appId, 'shm_shgb', '2026-01-01', '2046-01-01');
        // Memorandum kedua memiliki start date berbeda
        $this->createRawMemo($appId, 'shm_shgb', '2026-06-01', '2046-01-01');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Konflik migrasi tanggal SHGB pada pengajuan ID {$appId}");

        $this->migration->up();
    }

    /**
     * 5. Rollback dokumen yang masih terhubung ke memorandum.
     */
    public function test_5_rollback_document_linked_to_memorandum(): void
    {
        $appId = $this->createRawApp('buyback');
        $memoId = $this->createRawMemo($appId, 'buyback');

        $docId = DB::table('shm_buyback_documents')->insertGetId([
            'shm_application_id' => $appId,
            'shm_memorandum_id' => $memoId,
            'document_type' => 'shm',
            'file_name' => 'shm.pdf',
            'file_path' => 'shm/test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'uploaded_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Rollback harus berhasil karena memorandum pasangan ada
        $this->migration->down();

        $doc = DB::table('shm_buyback_documents')->where('id', $docId)->first();
        $this->assertNotNull($doc);
        $this->assertEquals($memoId, $doc->shm_memorandum_id);
        $this->assertFalse(Schema::hasColumn('shm_buyback_documents', 'shm_application_id'));
    }

    /**
     * 6. Rollback dokumen baru yang belum memiliki memorandum.
     */
    public function test_6_rollback_aborts_if_document_has_no_candidate_memorandum(): void
    {
        $appId = $this->createRawApp('buyback');
        // TIDAK ADA memorandum yang dibuat untuk aplikasi ini

        DB::table('shm_buyback_documents')->insertGetId([
            'shm_application_id' => $appId,
            'shm_memorandum_id' => null,
            'document_type' => 'shm',
            'file_name' => 'shm.pdf',
            'file_path' => 'shm/test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'uploaded_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak memiliki Memorandum Buyback pasangan dalam sistem lama');

        $this->migration->down();
    }

    /**
     * 7. Rollback ketika memorandum telah dihapus dan shm_memorandum_id menjadi NULL.
     */
    public function test_7_rollback_aborts_if_memorandum_was_deleted_leaving_null_foreign_key(): void
    {
        $appId = $this->createRawApp('buyback');
        $memoId = $this->createRawMemo($appId, 'buyback');

        $docId = DB::table('shm_buyback_documents')->insertGetId([
            'shm_application_id' => $appId,
            'shm_memorandum_id' => $memoId,
            'document_type' => 'shm',
            'file_name' => 'shm.pdf',
            'file_path' => 'shm/test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'uploaded_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Memorandum dihapus sehingga shm_memorandum_id menjadi NULL dan record memorandum hilang
        DB::table('shm_memorandums')->where('id', $memoId)->delete();
        DB::table('shm_buyback_documents')->where('id', $docId)->update(['shm_memorandum_id' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak memiliki Memorandum Buyback pasangan dalam sistem lama');

        $this->migration->down();
    }

    /**
     * 8. Multiple memorandum untuk satu application saat rollback.
     */
    public function test_8_rollback_aborts_if_multiple_candidate_memorandums_exist(): void
    {
        $appId = $this->createRawApp('buyback');
        $this->createRawMemo($appId, 'buyback');
        $this->createRawMemo($appId, 'buyback'); // Memorandum Buyback kedua

        DB::table('shm_buyback_documents')->insertGetId([
            'shm_application_id' => $appId,
            'shm_memorandum_id' => null,
            'document_type' => 'shm',
            'file_name' => 'shm.pdf',
            'file_path' => 'shm/test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'uploaded_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Ditemukan 2 Memorandum Buyback untuk Pengajuan ID {$appId}");

        $this->migration->down();
    }

    /**
     * 9. ISSUE A: Deteksi duplikasi dokumen sebelum membuat unique constraint.
     */
    public function test_9_up_aborts_if_duplicate_document_types_exist_for_same_application(): void
    {
        $appId = $this->createRawApp('buyback');
        $memo1 = $this->createRawMemo($appId, 'buyback');
        $memo2 = $this->createRawMemo($appId, 'buyback');

        // Masukkan dua dokumen dengan type yang sama ('shm') untuk pengajuan yang sama
        // Pada skema lama (sebelum up), shm_application_id masih null dan shm_memorandum_id berbeda
        DB::table('shm_buyback_documents')->insert([
            [
                'shm_application_id' => null,
                'shm_memorandum_id' => $memo1,
                'document_type' => 'shm',
                'file_name' => 'shm_1.pdf',
                'file_path' => 'shm/test1.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 1024,
                'uploaded_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'shm_application_id' => null,
                'shm_memorandum_id' => $memo2,
                'document_type' => 'shm',
                'file_name' => 'shm_2.pdf',
                'file_path' => 'shm/test2.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 2048,
                'uploaded_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ditemukan duplikasi dokumen legalitas untuk kombinasi (shm_application_id, document_type)');

        $this->migration->up();
    }

    /**
     * 10. ISSUE B: Deteksi konflik tanggal SHGB antara pengajuan dan memorandum saat rollback.
     */
    public function test_10_down_aborts_if_shgb_dates_conflict_between_application_and_memorandum(): void
    {
        $appId = $this->createRawApp('shgb', '2026-01-01', '2046-01-01');
        // Memorandum memiliki start date berbeda
        $memoId = $this->createRawMemo($appId, 'shm_shgb', '2026-05-15', '2046-01-01');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Ditemukan konflik tanggal SHGB antara Pengajuan ID {$appId} dan Memorandum ID {$memoId}");

        $this->migration->down();
    }

    /**
     * 11. Memastikan exception pada rollback tidak meninggalkan perubahan skema parsial.
     */
    public function test_11_rollback_abort_preserves_schema_and_does_not_drop_columns(): void
    {
        $appId = $this->createRawApp('shgb', '2026-01-01', '2046-01-01');
        $this->createRawMemo($appId, 'shm_shgb', '2026-05-15', '2046-01-01');

        try {
            $this->migration->down();
            $this->fail('Rollback seharusnya melempar RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Ditemukan konflik tanggal SHGB', $e->getMessage());
        }

        // Pastikan kolom dan tabel tetap utuh (tidak terhapus parsial)
        $this->assertTrue(Schema::hasColumn('shm_applications', 'shgb_start_date'));
        $this->assertTrue(Schema::hasColumn('shm_applications', 'shgb_end_date'));
        $this->assertTrue(Schema::hasColumn('shm_buyback_documents', 'shm_application_id'));
    }
}
