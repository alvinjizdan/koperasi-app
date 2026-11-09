<?php

namespace App\Services\Shm;

use App\Models\ShmLoanApplication;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Cleanup file privat milik satu pengajuan pinjaman SHM.
 *
 * Dipanggil dari lifecycle model ShmLoanApplication setelah baris database
 * benar-benar terhapus. Path dikumpulkan SEBELUM delete agar record child
 * yang ikut ter-cascade masih dapat dibaca.
 *
 * Kegagalan filesystem tidak dilempar kembali: record database sudah tidak ada,
 * sehingga path tidak dapat dipulihkan. Kegagalan dicatat ke log agar dapat
 * ditindaklanjuti manual tanpa membuat request gagal setelah commit.
 */
class ShmLoanApplicationFileCleanup
{
    private const DISK = 'local';

    /**
     * Kumpulkan seluruh path file yang menempel pada pengajuan.
     *
     * @return array<int, string>
     */
    public function pathsFor(ShmLoanApplication $application): array
    {
        $application->load(['collaterals', 'attachments']);

        $paths = [
            $application->ktp_photo_path,
            $application->kk_photo_path,
        ];

        foreach ($application->collaterals as $collateral) {
            $paths[] = $collateral->photo_path;
            $paths[] = $collateral->document_path;
        }

        foreach ($application->attachments as $attachment) {
            $paths[] = $attachment->file_path;
        }

        return collect($paths)
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Hapus file yang sudah tidak memiliki record database.
     *
     * @param  array<int, string>  $paths
     */
    public function delete(array $paths, int $applicationId): void
    {
        $disk = Storage::disk(self::DISK);

        foreach ($paths as $path) {
            try {
                if (! $disk->exists($path)) {
                    continue;
                }

                if (! $disk->delete($path)) {
                    Log::warning('SHM loan application file cleanup failed.', [
                        'application_id' => $applicationId,
                        'path' => $path,
                    ]);
                }
            } catch (Throwable $exception) {
                Log::error('SHM loan application file cleanup threw an exception.', [
                    'application_id' => $applicationId,
                    'path' => $path,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
