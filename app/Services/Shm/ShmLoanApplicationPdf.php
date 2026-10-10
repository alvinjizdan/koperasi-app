<?php

namespace App\Services\Shm;

use App\Models\ShmLoanApplication;
use Dompdf\Dompdf;
use Dompdf\Options;
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
 * Blade TIDAK melakukan query database. Rate ditampilkan dari domain object
 * yang sama (ShmLoan::OFFICIAL_RATE_PERCENT = 2%) — tidak ada nilai hardcode
 * kedua di template.
 *
 * Nama file: `pengajuan-pinjaman-{application_number}.pdf` (identifier
 * pengajuan, tanpa data pelanggan lain).
 */
class ShmLoanApplicationPdf
{
    public function download(ShmLoanApplication $application): Response
    {
        $html = view('shm.loan-application.pdf', [
            'application' => $application,
            'generatedAt' => now(),
            'methods' => ShmLoanSimulationService::supportedMethods(),
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
