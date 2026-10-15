<?php

namespace App\Services\Shm;

use App\Models\ShmLoanApplication;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ticket 67 — Export PDF Pengajuan Pinjaman SHM yang SUDAH TERSIMPAN.
 *
 * Memakai infrastructure PDF yang sama dengan simulasi (dompdf, dependency
 * T64) — library baru tidak ditambahkan dan PDF simulasi tidak diubah.
 * Generator terpisah karena kontennya berbeda:11 section pengajuan tersimpan,
 * bukan tabel angsuran.
 *
 * Seluruh data diambil dari relasi yang sudah di-eager-load controller;
 * Blade TIDAK melakukan query database. Rate ditampilkan dari resolusi
 * tier produk pengajuan (officialRatePercent(), T82) — tidak ada nilai
 * hardcode di template.
 *
 * Nama file: `pengajuan-pinjaman-{application_number}.pdf` (identifier
 * pengajuan, tanpa data pelanggan lain).
 *
 * Ticket 77 — foto jaminan disematkan sebagai data URI (base64) dari disk
 * privat. dompdf dibuat dengan `isRemoteEnabled = false`, jadi satu-satunya
 * gambar yang bisa muncul adalah yang sudah berada di memori; path
 * filesystem TIDAK pernah menyentuh template. Foto yang berkasnya hilang
 * dilewati dengan tenang — dokumen tetap valid tanpa foto.
 */
class ShmLoanApplicationPdf
{
    /** Batas ukuran satu foto yang disematkan (byte) — pengaman memori. */
    private const MAX_EMBEDDED_PHOTO_BYTES = 2_000_000;

    public function download(ShmLoanApplication $application): Response
    {
        $html = view('shm.loan-application.pdf', [
            'application' => $application,
            'generatedAt' => now(),
            'methods' => ShmLoanSimulationService::supportedMethods(),
            'collateralPhotos' => $this->collateralPhotos($application),
        ])->render();

        // Pola yang sama dengan PDF simulasi: naikkan memory_limit hanya
        // selama render (dokumen dibangun penuh di memori) dan kembalikan
        // setelahnya secara best effort.
        $previousLimit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        try {
            return $this->render($html, $application);
        } finally {
            if (is_string($previousLimit) && $previousLimit !== '') {
                @ini_set('memory_limit', $previousLimit);
            }
        }
    }

    /**
     * Ticket 77 — data URI foto jaminan (id jaminan => `data:image/...;base64,...`).
     *
     * Hanya berkas yang benar-benar ada di disk dan berada di bawah batas
     * ukuran yang disertakan; selebihnya dilewati tanpa error sehingga
     * jaminan tanpa foto (data lama) tetap dirender normal.
     *
     * @return array<int, string>
     */
    private function collateralPhotos(ShmLoanApplication $application): array
    {
        $photos = [];
        $disk = Storage::disk('local');

        foreach ($application->collaterals as $collateral) {
            if ($collateral->photo_path === null) {
                continue;
            }

            if (! $disk->exists($collateral->photo_path)) {
                continue;
            }

            $contents = $disk->get($collateral->photo_path);

            if ($contents === null || $contents === false || $contents === '') {
                continue;
            }

            if (strlen($contents) > self::MAX_EMBEDDED_PHOTO_BYTES) {
                continue;
            }

            $mime = match (strtolower(pathinfo($collateral->photo_path, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            };

            $photos[$collateral->id] = 'data:'.$mime.';base64,'.base64_encode($contents);
        }

        return $photos;
    }

    private function render(string $html, ShmLoanApplication $application): Response
    {
        $options = new Options;
        // Dokumen dibangun dari HTML lokal: gambar/berkas eksternal tidak
        // pernah dimuat, sehingga PDF tidak bergantung pada jaringan.
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'pengajuan-pinjaman-'.$application->application_number.'.pdf';
        // Kompresi content stream dimatikan agar keluaran deterministic dan
        // isi dokumen tetap dapat diverifikasi.
        $pdf = $dompdf->output(['compress' => 0]) ?? '';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($pdf),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
