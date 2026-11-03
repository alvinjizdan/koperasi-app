<?php

namespace App\Services\Shm;

use App\Models\ShmLoan;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service generator PDF Laporan Per Pinjaman BSM Makro.
 *
 * Mengikuti pola yang sama dengan ShmLoanApplicationPdf dan ShmLoanSimulationPdf.
 * Bersifat READ ONLY, tidak pernah memodifikasi data pinjaman, jaminan, maupun angsuran.
 */
class ShmLoanReportPdf
{
    public function download(ShmLoan $loan, array $reportData): Response
    {
        $html = view('shm.loan-report.pdf', [
            'loan' => $loan,
            'report' => $reportData,
            'generatedAt' => now(),
        ])->render();

        $previousLimit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        try {
            return $this->render($html, $loan);
        } finally {
            if (is_string($previousLimit) && $previousLimit !== '') {
                @ini_set('memory_limit', $previousLimit);
            }
        }
    }

    private function render(string $html, ShmLoan $loan): Response
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $cleanLoanNumber = str_replace(['/', '\\', ' '], '-', $loan->loan_number);
        $filename = 'laporan-pinjaman-'.$cleanLoanNumber.'.pdf';

        $pdf = $dompdf->output(['compress' => 0]) ?? '';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($pdf),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
