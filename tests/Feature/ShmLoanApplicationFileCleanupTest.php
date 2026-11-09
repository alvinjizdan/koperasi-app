<?php

namespace Tests\Feature;

use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanApplicationAttachment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Lifecycle cleanup file pengajuan SHM.
 *
 * Test ini sengaja TIDAK memakai RefreshDatabase agar penghapusan berjalan
 * sebagai transaksi root: callback after-commit dapat dieksekusi dan diuji
 * seperti di production. Database tetap SQLite in-memory yang baru per test.
 */
class ShmLoanApplicationFileCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->artisan('migrate')->assertSuccessful();
    }

    private function makeApplication(): ShmLoanApplication
    {
        $customer = ShmCustomer::factory()->create();

        return ShmLoanApplication::create([
            'application_number' => 'APP-'.Str::upper(Str::random(12)),
            'shm_customer_id' => $customer->id,
            'application_date' => now()->toDateString(),
            'principal' => 1_000_000,
            'tenor_months' => 12,
            'status' => 'pending',
        ]);
    }

    /**
     * @return array{0: ShmLoanApplicationAttachment, 1: string}
     */
    private function attachFile(ShmLoanApplication $application, string $name = 'lampiran.pdf'): array
    {
        $path = sprintf('shm/loan-attachments/%d/%s', $application->id, Str::random(12).'.pdf');
        Storage::disk('local')->put($path, '%PDF-1.4 test');

        $attachment = $application->attachments()->create([
            'file_name' => $name,
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 13,
        ]);

        return [$attachment, $path];
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function writeFiles(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk('local')->put($path, 'test-content');
        }
    }

    public function test_deleting_application_removes_all_file_types_after_commit(): void
    {
        $application = $this->makeApplication();

        $ktpPath = 'shm/identity-photos/'.$application->id.'/ktp.jpg';
        $kkPath = 'shm/identity-photos/'.$application->id.'/kk.jpg';
        $photoPath = 'shm/collateral-photos/'.$application->id.'/photo.jpg';
        $documentPath = 'shm/collateral-documents/'.$application->id.'/document.pdf';

        $application->forceFill([
            'ktp_photo_path' => $ktpPath,
            'kk_photo_path' => $kkPath,
        ])->save();

        $application->collaterals()->create([
            'collateral_type' => 'SHM',
            'collateral_name' => 'Tanah',
            'photo_path' => $photoPath,
            'document_path' => $documentPath,
            'document_name' => 'document.pdf',
        ]);

        [, $attachmentPathA] = $this->attachFile($application, 'a.pdf');
        [, $attachmentPathB] = $this->attachFile($application, 'b.pdf');

        $paths = [$ktpPath, $kkPath, $photoPath, $documentPath, $attachmentPathA, $attachmentPathB];
        $this->writeFiles($paths);

        $otherApplication = $this->makeApplication();
        [, $otherPath] = $this->attachFile($otherApplication, 'other.pdf');

        foreach ($paths as $path) {
            Storage::disk('local')->assertExists($path);
        }

        $application->delete();

        $this->assertDatabaseMissing('shm_loan_applications', ['id' => $application->id]);
        $this->assertSame(
            0,
            ShmLoanApplicationAttachment::query()
                ->where('shm_loan_application_id', $application->id)
                ->count(),
        );

        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }

        Storage::disk('local')->assertExists($otherPath);
        $this->assertDatabaseHas('shm_loan_applications', ['id' => $otherApplication->id]);
    }

    public function test_rollback_keeps_database_rows_and_files(): void
    {
        $application = $this->makeApplication();
        [, $attachmentPath] = $this->attachFile($application);

        try {
            DB::transaction(function () use ($application): void {
                $application->delete();

                throw new RuntimeException('forced rollback');
            });

            $this->fail('Transaksi seharusnya rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced rollback', $exception->getMessage());
        }

        $this->assertDatabaseHas('shm_loan_applications', ['id' => $application->id]);
        $this->assertDatabaseHas('shm_loan_application_attachments', [
            'shm_loan_application_id' => $application->id,
        ]);
        Storage::disk('local')->assertExists($attachmentPath);
    }

    public function test_fk_restrict_prevents_deletion_and_keeps_files(): void
    {
        $application = $this->makeApplication();
        [, $attachmentPath] = $this->attachFile($application);

        ShmLoan::factory()->create([
            'shm_loan_application_id' => $application->id,
        ]);

        try {
            $application->delete();
            $this->fail('Penghapusan seharusnya dibatasi FK shm_loans.');
        } catch (QueryException) {
            // FK RESTRICT menolak delete; event `deleted` tidak pernah berjalan.
        }

        $this->assertDatabaseHas('shm_loan_applications', ['id' => $application->id]);
        $this->assertDatabaseHas('shm_loan_application_attachments', [
            'shm_loan_application_id' => $application->id,
        ]);
        Storage::disk('local')->assertExists($attachmentPath);
    }

    public function test_collateral_document_label_displays_10_mb(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Shm/Loans/Pengajuan.jsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString(
            'Format: PDF — maksimal 10 MB.',
            $source,
        );
        $this->assertStringNotContainsString(
            'Format: PDF — maksimal 5 MB.',
            $source,
        );
    }
}
