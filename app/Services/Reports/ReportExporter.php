<?php

namespace App\Services\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor CSV untuk laporan dasar.
 *
 * Baris dikirim langsung ke output (streaming) agar tidak memuat seluruh data
 * ke memori. Nilai teks yang diawali karakter berbahaya bagi spreadsheet diberi
 * awalan kutip tunggal untuk mencegah CSV formula injection, sedangkan nilai
 * numerik dibiarkan apa adanya agar tetap terbaca sebagai angka.
 */
class ReportExporter
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            // BOM UTF-8 agar karakter Indonesia terbaca benar pada spreadsheet.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, array_map($this->sanitize(...), $row));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * Cegah formula injection pada nilai teks tanpa merusak nilai numerik.
     */
    private function sanitize(mixed $value): mixed
    {
        if ($value === null || is_int($value) || is_float($value)) {
            return $value;
        }

        $string = (string) $value;

        if ($string === '') {
            return $string;
        }

        if (in_array($string[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$string;
        }

        return $string;
    }
}
