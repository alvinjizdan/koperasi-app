<?php

namespace App\Services\Shm;

use App\Models\ShmLoanProduct;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ticket 64 — Ekspor PDF hasil Simulasi Pinjaman SHM.
 *
 * Memakai library PDF PHP murni (dompdf) yang ditambahkan ke project karena
 * aplikasi BELUM memiliki infrastructure PDF sama sekali (ekspor yang ada
 * adalah CSV melalui ReportExporter). Tidak ada external PDF service dan tidak
 * ada perubahan arsitektur deployment: cukup dependency composer yang berjalan
 * di proses Laravel yang sama.
 *
 * Nama file deterministic: `simulasi-pinjaman-{identifier}.pdf` di mana
 * identifier adalah hash dari seluruh input simulasi. Input yang sama selalu
 * menghasilkan nama yang sama, dan tidak ada data sensitif yang disimpan di
 * URL selain hash-nya.
 */
class ShmLoanSimulationPdf
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $result
     */
    public function download(array $payload, array $result, ?ShmLoanProduct $product = null): Response
    {
        $html = view('shm.loan-simulation.pdf', [
            'payload' => $payload,
            'result' => $result,
            'product' => $product,
            'methods' => ShmLoanSimulationService::supportedMethods(),
            'generatedAt' => now(),
        ])->render();

        // Dompdf membangun seluruh dokumen di memori. Tenor panjang (ratusan
        // baris tabel angsuran) dapat melebihi memory_limit bawaan 128M,
        // sehingga limit dinaikkan HANYA selama render dan dikembalikan lagi
        // setelahnya (best effort — lihat blok finally). Ini bukan perubahan
        // arsitektur deployment: cukup pengaturan runtime pada proses Laravel
        // yang sama.
        $previousLimit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        try {
            return $this->render($html, $payload);
        } finally {
            // Best effort: pengembalian limit gagal (dan sengaja diredam)
            // bila memori terpakai masih di atas limit lama — mis. setelah
            // render tabel angsuran yang sangat panjang. Dampaknya terbatas
            // pada satu proses worker dan tidak mengubah konfigurasi apa pun.
            if (is_string($previousLimit) && $previousLimit !== '') {
                @ini_set('memory_limit', $previousLimit);
            }
        }
    }

    /**
     * Render HTML menjadi response unduhan PDF.
     *
     * @param  array<string, mixed>  $payload
     */
    private function render(string $html, array $payload): Response
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

        $filename = 'simulasi-pinjaman-'.$this->identifier($payload).'.pdf';
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

    /**
     * Identitas file yang deterministic dan aman (hash hex, tanpa data asli).
     *
     * @param  array<string, mixed>  $payload
     */
    public function identifier(array $payload): string
    {
        ksort($payload);

        return substr(sha1((string) json_encode($payload, JSON_UNESCAPED_SLASHES)), 0, 16);
    }
}
