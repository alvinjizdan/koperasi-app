<?php

namespace App\Services\Accounting;

use App\Models\AccountingImportBatch;
use App\Models\AccountingImportedReport;
use App\Services\AuditLogger;
use App\Support\AccountingContext;
use App\Support\DbTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ShmReportImportService
{
    public function __construct(
        private readonly SimpleXlsxService $xlsx,
    ) {}

    /**
     * Preview report file.
     *
     * @return array{
     *     filename: string,
     *     checksum: string,
     *     report_type: string,
     *     report_date: string,
     *     period_start: ?string,
     *     period_end: ?string,
     *     total_rows: int,
     *     rows: array<int, array<string, mixed>>,
     *     summary: array<string, mixed>,
     *     errors: array<int, array{row: int, message: string}>
     * }
     */
    public function preview(UploadedFile $file, string $reportType): array
    {
        $this->validateFile($file);

        $filePath = $file->getRealPath();
        $checksum = hash_file('sha256', $filePath);
        $rawRows = $this->xlsx->readRows($filePath);

        return $this->parseReportRows($rawRows, $reportType, $file->getClientOriginalName(), $checksum);
    }

    /**
     * Import report data into accounting_imported_reports and batch metadata.
     *
     * @throws ValidationException
     */
    public function import(UploadedFile $file, string $reportType, int $userId): AccountingImportBatch
    {
        $preview = $this->preview($file, $reportType);

        if (! empty($preview['errors'])) {
            throw ValidationException::withMessages([
                'file' => [sprintf('Terdapat %d kesalahan pada berkas laporan. Periksa kembali struktur berkas.', count($preview['errors']))],
            ]);
        }

        if (empty($preview['rows'])) {
            throw ValidationException::withMessages([
                'file' => ['Berkas laporan tidak memiliki data yang dapat diimpor.'],
            ]);
        }

        // Check duplicate by checksum
        $existing = AccountingImportBatch::query()
            ->where('checksum_hash', $preview['checksum'])
            ->where('accounting_context', AccountingContext::SHM)
            ->where('import_type', AccountingImportBatch::TYPE_REPORT)
            ->where('status', 'COMPLETED')
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'file' => [sprintf('Laporan ini sudah pernah diimpor pada %s (Batch #%d).', $existing->created_at->format('d/m/Y H:i'), $existing->id)],
            ]);
        }

        // Secondary duplicate check: Check if same report type for exact same date / period is already imported
        $targetDate = $preview['period_end'] ?? $preview['report_date'];
        $existingReportForPeriod = AccountingImportBatch::query()
            ->where('accounting_context', AccountingContext::SHM)
            ->where('import_type', AccountingImportBatch::TYPE_REPORT)
            ->where('report_type', $reportType)
            ->whereDate('period_end', $targetDate)
            ->where('status', 'COMPLETED')
            ->first();

        if ($existingReportForPeriod !== null) {
            throw ValidationException::withMessages([
                'file' => [sprintf('Laporan %s untuk tanggal/periode %s sudah pernah diimpor pada Batch #%d. Hapus atau arsipkan batch sebelumnya jika ingin mengganti data periode tersebut.', $reportType, $targetDate, $existingReportForPeriod->id)],
            ]);
        }

        return DbTransaction::run(function () use ($preview, $reportType, $userId): AccountingImportBatch {
            $batch = AccountingImportBatch::create([
                'accounting_context' => AccountingContext::SHM,
                'import_type' => AccountingImportBatch::TYPE_REPORT,
                'report_type' => $reportType,
                'filename' => $preview['filename'],
                'checksum_hash' => $preview['checksum'],
                'imported_by' => $userId,
                'row_count' => count($preview['rows']),
                'success_count' => count($preview['rows']),
                'failed_count' => 0,
                'status' => 'COMPLETED',
                'period_start' => $preview['period_start'],
                'period_end' => $preview['period_end'] ?? $preview['report_date'],
                'summary_meta' => $preview['summary'],
            ]);

            foreach ($preview['rows'] as $idx => $r) {
                AccountingImportedReport::create([
                    'batch_id' => $batch->id,
                    'accounting_context' => AccountingContext::SHM,
                    'report_type' => $reportType,
                    'report_date' => $preview['report_date'],
                    'period_start' => $preview['period_start'],
                    'period_end' => $preview['period_end'],
                    'section' => $r['section'] ?? null,
                    'account_code' => $r['account_code'] ?? null,
                    'account_name' => $r['account_name'] ?? null,
                    'debit' => $r['debit'] ?? 0,
                    'credit' => $r['credit'] ?? 0,
                    'amount' => $r['amount'] ?? 0,
                    'description' => $r['description'] ?? null,
                    'extra_attributes' => $r['extra_attributes'] ?? null,
                    'row_index' => $idx + 1,
                ]);
            }

            AuditLogger::log(
                'accounting.shm.report_imported',
                AccountingImportBatch::class,
                $batch->id,
                $preview['filename'],
                sprintf('Import laporan SHM (%s) tanggal %s berhasil disimpan.', $reportType, $preview['report_date']),
                [
                    'batch_id' => $batch->id,
                    'report_type' => $reportType,
                    'report_date' => $preview['report_date'],
                    'row_count' => count($preview['rows']),
                ]
            );

            return $batch;
        });
    }

    private function validateFile(UploadedFile $file): void
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext !== 'xlsx') {
            throw ValidationException::withMessages([
                'file' => ['Berkas harus berformat .xlsx (Excel).'],
            ]);
        }

        if ($file->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'file' => ['Ukuran berkas maksimal adalah 10 MB.'],
            ]);
        }
    }

    private function parseReportRows(array $rows, string $reportType, string $filename, string $checksum): array
    {
        $errors = [];
        $parsedRows = [];
        $reportDate = now()->toDateString();
        $periodStart = null;
        $periodEnd = null;
        $summary = [];

        if (empty($rows)) {
            $errors[] = ['row' => 1, 'message' => 'Berkas laporan kosong.'];

            return [
                'filename' => $filename,
                'checksum' => $checksum,
                'report_type' => $reportType,
                'report_date' => $reportDate,
                'period_start' => null,
                'period_end' => null,
                'total_rows' => 0,
                'rows' => [],
                'summary' => [],
                'errors' => $errors,
            ];
        }

        // Try extracting metadata from first few rows
        foreach (array_slice($rows, 0, 8) as $row) {
            $lineText = implode(' ', array_map('trim', $row));
            if (preg_match('/(\d{4}-\d{2}-\d{2})/', $lineText, $m)) {
                $reportDate = $m[1];
            }
        }

        $currentSection = 'General';

        foreach ($rows as $index => $row) {
            $rowNum = $index + 1;
            $firstCell = trim($row[0] ?? '');
            $secondCell = trim($row[1] ?? '');
            $thirdCell = trim($row[2] ?? '');

            if ($firstCell === '' && $secondCell === '' && $thirdCell === '') {
                continue;
            }

            // Detect section headers
            if ($secondCell === '' && $thirdCell === '' && ! is_numeric(str_replace(['.', ','], '', $firstCell))) {
                if (str_contains(strtoupper($firstCell), 'ASET') || str_contains(strtoupper($firstCell), 'ASSET')) {
                    $currentSection = 'Aset';

                    continue;
                }
                if (str_contains(strtoupper($firstCell), 'LIABILITAS') || str_contains(strtoupper($firstCell), 'HUTANG')) {
                    $currentSection = 'Liabilitas';

                    continue;
                }
                if (str_contains(strtoupper($firstCell), 'EKUITAS') || str_contains(strtoupper($firstCell), 'MODAL')) {
                    $currentSection = 'Ekuitas';

                    continue;
                }
                if (str_contains(strtoupper($firstCell), 'PENDAPATAN') || str_contains(strtoupper($firstCell), 'REVENUE')) {
                    $currentSection = 'Pendapatan';

                    continue;
                }
                if (str_contains(strtoupper($firstCell), 'BEBAN') || str_contains(strtoupper($firstCell), 'BIAYA') || str_contains(strtoupper($firstCell), 'EXPENSE')) {
                    $currentSection = 'Beban';

                    continue;
                }
            }

            // Ignore header row names
            $strUpper = strtoupper($firstCell);
            if (in_array($strUpper, ['KODE AKUN', 'KODE PERK.', 'KODE', 'NO', 'TANGGAL'], true)) {
                continue;
            }

            // Summary row detection
            if (str_starts_with($strUpper, 'TOTAL ') || str_starts_with($strUpper, 'RINGKASAN ')) {
                $amountRaw = str_replace([',', ' '], '', $thirdCell !== '' ? $thirdCell : $secondCell);
                $summary[$strUpper] = is_numeric($amountRaw) ? (float) $amountRaw : $amountRaw;

                continue;
            }

            // Account line item
            $accountCode = $firstCell;
            $accountName = $secondCell;
            $amountVal = 0.0;
            $debitVal = 0.0;
            $creditVal = 0.0;

            if ($reportType === AccountingImportBatch::REPORT_DAILY_JOURNAL) {
                // Daily journal structure: No, Tanggal, Kode Perk, Perkiraan, ..., Debet, Kredit
                $dateCol = trim($row[1] ?? '');
                $codeCol = trim($row[2] ?? '');
                $nameCol = trim($row[3] ?? '');
                $descCol = trim($row[6] ?? '');
                $debCol = str_replace([',', ' '], '', trim($row[7] ?? '0'));
                $credCol = str_replace([',', ' '], '', trim($row[8] ?? '0'));

                if ($codeCol !== '' && (is_numeric($debCol) || is_numeric($credCol))) {
                    $parsedRows[] = [
                        'section' => $currentSection,
                        'account_code' => $codeCol,
                        'account_name' => $nameCol,
                        'debit' => is_numeric($debCol) ? (float) $debCol : 0,
                        'credit' => is_numeric($credCol) ? (float) $credCol : 0,
                        'amount' => is_numeric($debCol) ? (float) $debCol : (float) $credCol,
                        'description' => $descCol,
                        'extra_attributes' => ['date' => $dateCol],
                    ];
                }

                continue;
            }

            // Balance sheet, Trial Balance, Profit Loss
            $amountClean = str_replace([',', ' '], '', $thirdCell !== '' ? $thirdCell : $secondCell);
            if (is_numeric($amountClean)) {
                $amountVal = (float) $amountClean;
            }

            if ($accountCode !== '' && $accountName !== '') {
                $parsedRows[] = [
                    'section' => $currentSection,
                    'account_code' => $accountCode,
                    'account_name' => $accountName,
                    'debit' => $amountVal >= 0 ? $amountVal : 0,
                    'credit' => $amountVal < 0 ? abs($amountVal) : 0,
                    'amount' => $amountVal,
                    'description' => "Baris laporan {$currentSection} - {$accountCode}",
                    'extra_attributes' => null,
                ];
            }
        }

        if (empty($parsedRows)) {
            $errors[] = ['row' => 1, 'message' => 'Tidak ditemukan baris data laporan yang dapat diparsing.'];
        }

        return [
            'filename' => $filename,
            'checksum' => $checksum,
            'report_type' => $reportType,
            'report_date' => $reportDate,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'total_rows' => count($parsedRows),
            'rows' => $parsedRows,
            'summary' => $summary,
            'errors' => $errors,
        ];
    }
}
