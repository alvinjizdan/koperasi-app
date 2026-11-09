<?php

namespace App\Services\Accounting;

use App\Models\AccountingImportBatch;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Services\AuditLogger;
use App\Support\AccountingContext;
use App\Support\DbTransaction;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ShmJournalImportService
{
    public function __construct(
        private readonly SimpleXlsxService $xlsx,
        private readonly JournalService $journalService,
    ) {}

    /**
     * Parse and preview XLSX journal data before storing.
     *
     * @return array{
     *     filename: string,
     *     checksum: string,
     *     total_journals: int,
     *     total_lines: int,
     *     total_debit: float,
     *     total_credit: float,
     *     is_balanced: bool,
     *     journals: array<int, array<string, mixed>>,
     *     errors: array<int, array{row: int, journal_ref: string, message: string}>
     * }
     */
    public function preview(UploadedFile $file): array
    {
        $this->validateFile($file);

        $filePath = $file->getRealPath();
        $checksum = hash_file('sha256', $filePath);
        $rows = $this->xlsx->readRows($filePath);

        return $this->parseRows($rows, $file->getClientOriginalName(), $checksum);
    }

    /**
     * Store parsed journals as DRAFT entries with batch tracking.
     *
     * @throws ValidationException
     */
    public function import(UploadedFile $file, int $userId): AccountingImportBatch
    {
        $preview = $this->preview($file);

        if (! empty($preview['errors'])) {
            throw ValidationException::withMessages([
                'file' => [sprintf('Terdapat %d kesalahan pada berkas XLSX. Periksa kembali format data.', count($preview['errors']))],
            ]);
        }

        if (empty($preview['journals'])) {
            throw ValidationException::withMessages([
                'file' => ['Berkas XLSX tidak berisi baris jurnal yang valid.'],
            ]);
        }

        // Check duplicate import by checksum
        $existingBatch = AccountingImportBatch::query()
            ->where('checksum_hash', $preview['checksum'])
            ->where('accounting_context', AccountingContext::SHM)
            ->where('status', 'COMPLETED')
            ->first();

        if ($existingBatch !== null) {
            throw ValidationException::withMessages([
                'file' => [sprintf('Berkas ini sudah pernah diimpor pada %s (Batch #%d).', $existingBatch->created_at->format('d/m/Y H:i'), $existingBatch->id)],
            ]);
        }

        // Secondary duplicate check: Check if journal references in this import already exist in SHM journal entries
        $incomingRefs = array_filter(array_map(fn ($j) => $j['raw_ref'] ?? null, $preview['journals']));
        if (! empty($incomingRefs)) {
            $existingRefs = JournalEntry::query()
                ->where('accounting_context', AccountingContext::SHM)
                ->whereIn('description', array_map(fn ($r) => "Jurnal Impor {$r}", $incomingRefs))
                ->pluck('description')
                ->all();

            if (! empty($existingRefs)) {
                throw ValidationException::withMessages([
                    'file' => [sprintf('Ditemukan %d referensi jurnal yang sudah pernah tersimpan di sistem (contoh: %s). Untuk mencegah duplikasi, periksa kembali nomor bukti jurnal Anda.', count($existingRefs), $existingRefs[0])],
                ]);
            }
        }

        return DbTransaction::run(function () use ($preview, $userId): AccountingImportBatch {
            $dates = array_filter(array_map(fn ($j) => $j['date'], $preview['journals']));
            sort($dates);
            $periodStart = ! empty($dates) ? reset($dates) : null;
            $periodEnd = ! empty($dates) ? end($dates) : null;

            // 1. Create batch record
            $batch = AccountingImportBatch::create([
                'accounting_context' => AccountingContext::SHM,
                'import_type' => AccountingImportBatch::TYPE_JOURNAL,
                'report_type' => null,
                'filename' => $preview['filename'],
                'checksum_hash' => $preview['checksum'],
                'imported_by' => $userId,
                'row_count' => $preview['total_lines'],
                'success_count' => $preview['total_journals'],
                'failed_count' => 0,
                'status' => 'COMPLETED',
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'summary_meta' => [
                    'total_journals' => $preview['total_journals'],
                    'total_lines' => $preview['total_lines'],
                    'total_debit' => $preview['total_debit'],
                    'total_credit' => $preview['total_credit'],
                ],
            ]);

            // 2. Create DRAFT journal entries
            foreach ($preview['journals'] as $jData) {
                $lines = array_map(function ($line) {
                    return [
                        'account_id' => $line['account_id'],
                        'description' => $line['description'],
                        'debit' => $line['debit'],
                        'credit' => $line['credit'],
                    ];
                }, $jData['lines']);

                $entry = $this->journalService->createDraft([
                    'accounting_context' => AccountingContext::SHM,
                    'transaction_date' => $jData['date'],
                    'description' => $jData['description'],
                    'posting_type' => 'import_xlsx',
                ], $lines, $userId);

                // Associate batch
                $entry->import_batch_id = $batch->id;
                $entry->save();
            }

            AuditLogger::log(
                'accounting.shm.journal_imported',
                AccountingImportBatch::class,
                $batch->id,
                $preview['filename'],
                sprintf('Import %d jurnal SHM berhasil disimpan dalam status DRAFT.', $preview['total_journals']),
                [
                    'batch_id' => $batch->id,
                    'total_journals' => $preview['total_journals'],
                    'total_lines' => $preview['total_lines'],
                    'checksum' => $preview['checksum'],
                ]
            );

            return $batch;
        });
    }

    /**
     * Download XLSX template for journal import.
     */
    public function generateTemplateXlsx(): string
    {
        $rows = [
            [
                'Tanggal',
                'No Bukti / Referensi',
                'Kode Akun',
                'Keterangan',
                'Debit',
                'Kredit',
            ],
            [
                now()->toDateString(),
                'JU-2026-0001',
                '1-1100',
                'Penerimaan Kas Operasional',
                '1000000',
                '0',
            ],
            [
                now()->toDateString(),
                'JU-2026-0001',
                '4-1100',
                'Pendapatan Bunga Pinjaman SHM',
                '0',
                '1000000',
            ],
        ];

        $filePath = sys_get_temp_dir().'/template_jurnal_shm_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Template_Jurnal_SHM');

        return $filePath;
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

    /**
     * Parse raw rows from XLSX.
     *
     * Expected format:
     * Header: Tanggal | No Bukti/Referensi | Kode Akun | Keterangan | Debit | Kredit
     */
    private function parseRows(array $rows, string $filename, string $checksum): array
    {
        $errors = [];
        $journalsGrouped = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $totalLines = 0;

        if (empty($rows)) {
            $errors[] = ['row' => 1, 'journal_ref' => '-', 'message' => 'File XLSX kosong atau tidak dapat dibaca.'];

            return [
                'filename' => $filename,
                'checksum' => $checksum,
                'total_journals' => 0,
                'total_lines' => 0,
                'total_debit' => 0.0,
                'total_credit' => 0.0,
                'is_balanced' => true,
                'journals' => [],
                'errors' => $errors,
            ];
        }

        // Header check
        $headerRow = $rows[0] ?? [];
        $normalizedHeader = array_map(fn ($h) => strtolower(trim((string) $h)), $headerRow);

        // Find column indices
        $colDate = $this->findColIndex($normalizedHeader, ['tanggal', 'date']);
        $colRef = $this->findColIndex($normalizedHeader, ['no bukti', 'referensi', 'ref', 'voucher', 'no_bukti']);
        $colCode = $this->findColIndex($normalizedHeader, ['kode akun', 'kode perk.', 'kode_akun', 'account_code', 'kode']);
        $colDesc = $this->findColIndex($normalizedHeader, ['keterangan', 'deskripsi', 'description']);
        $colDebit = $this->findColIndex($normalizedHeader, ['debit', 'debet']);
        $colCredit = $this->findColIndex($normalizedHeader, ['kredit', 'credit']);

        if ($colDate === null || $colCode === null || $colDebit === null || $colCredit === null) {
            $errors[] = [
                'row' => 1,
                'journal_ref' => '-',
                'message' => 'Format kolom tidak sesuai. Minimal harus memiliki kolom: Tanggal, Kode Akun, Keterangan, Debit, Kredit.',
            ];

            return [
                'filename' => $filename,
                'checksum' => $checksum,
                'total_journals' => 0,
                'total_lines' => 0,
                'total_debit' => 0.0,
                'total_credit' => 0.0,
                'is_balanced' => true,
                'journals' => [],
                'errors' => $errors,
            ];
        }

        // Load all active SHM accounts in one query
        $shmAccounts = ChartOfAccount::query()
            ->shm()
            ->where('is_active', true)
            ->get()
            ->keyBy('code');

        // Check each row starting from row 2 (index 1)
        for ($i = 1; $i < count($rows); $i++) {
            $rowNum = $i + 1;
            $row = $rows[$i];

            // Skip empty rows
            $hasContent = false;
            foreach ($row as $cell) {
                if (trim((string) $cell) !== '') {
                    $hasContent = true;
                    break;
                }
            }
            if (! $hasContent) {
                continue;
            }

            $dateRaw = trim($row[$colDate] ?? '');
            $refRaw = $colRef !== null ? trim($row[$colRef] ?? '') : '';
            $codeRaw = trim($row[$colCode] ?? '');
            $descRaw = $colDesc !== null ? trim($row[$colDesc] ?? '') : '';
            $debitRaw = trim($row[$colDebit] ?? '0');
            $creditRaw = trim($row[$colCredit] ?? '0');

            // Grouping key: ref + date if ref provided, to prevent mixing different dates under same ref
            // If ref is provided and multiple dates are seen under same ref, flag warning/error or key by both
            $journalKey = $refRaw !== '' ? "REF-{$refRaw}" : ($dateRaw !== '' ? "DATE-{$dateRaw}-{$rowNum}" : "ROW-{$rowNum}");

            // 1. Date validation
            $date = $this->parseDate($dateRaw);
            if ($date === null) {
                $errors[] = ['row' => $rowNum, 'journal_ref' => $journalKey, 'message' => "Format tanggal tidak valid: '{$dateRaw}'. Gunakan format YYYY-MM-DD atau DD/MM/YYYY."];
            }

            // Check if same ref has conflicting dates
            if (isset($journalsGrouped[$journalKey]) && $date !== null && $journalsGrouped[$journalKey]['date'] !== null) {
                if ($journalsGrouped[$journalKey]['date'] !== $date) {
                    $errors[] = [
                        'row' => $rowNum,
                        'journal_ref' => $journalKey,
                        'message' => sprintf(
                            "Nomor bukti '%s' memiliki tanggal berbeda pada baris %d (%s) dibanding baris sebelumnya (%s). Satu nomor bukti jurnal harus memiliki tanggal yang sama.",
                            $refRaw,
                            $rowNum,
                            $date,
                            $journalsGrouped[$journalKey]['date']
                        ),
                    ];
                }
            }

            // 2. Account code validation (must belong to SHM CoA)
            $account = $shmAccounts->get($codeRaw);
            if (! $account) {
                // Check if account exists in KOPERASI context
                $isKoperasi = ChartOfAccount::query()->where('accounting_context', AccountingContext::KOPERASI)->where('code', $codeRaw)->exists();
                if ($isKoperasi) {
                    $errors[] = ['row' => $rowNum, 'journal_ref' => $journalKey, 'message' => "Kode Akun '{$codeRaw}' adalah akun Koperasi, bukan akun SHM."];
                } else {
                    $errors[] = ['row' => $rowNum, 'journal_ref' => $journalKey, 'message' => "Kode Akun '{$codeRaw}' tidak ditemukan pada daftar perkiraan aktif SHM."];
                }
            }

            // 3. Debit / Credit validation
            $debitClean = str_replace([',', ' '], '', $debitRaw);
            $creditClean = str_replace([',', ' '], '', $creditRaw);

            if (! is_numeric($debitClean) || (float) $debitClean < 0) {
                $errors[] = ['row' => $rowNum, 'journal_ref' => $journalKey, 'message' => "Nominal debit '{$debitRaw}' tidak valid."];
            }
            if (! is_numeric($creditClean) || (float) $creditClean < 0) {
                $errors[] = ['row' => $rowNum, 'journal_ref' => $journalKey, 'message' => "Nominal kredit '{$creditRaw}' tidak valid."];
            }

            $debit = round((float) ($debitClean ?: 0), 2);
            $credit = round((float) ($creditClean ?: 0), 2);

            if ($debit == 0 && $credit == 0) {
                $errors[] = ['row' => $rowNum, 'journal_ref' => $journalKey, 'message' => 'Salah satu dari debit atau kredit harus lebih besar dari 0.'];
            }
            if ($debit > 0 && $credit > 0) {
                $errors[] = ['row' => $rowNum, 'journal_ref' => $journalKey, 'message' => 'Satu baris tidak boleh memiliki debit dan kredit sekaligus.'];
            }

            $totalDebit += $debit;
            $totalCredit += $credit;
            $totalLines++;

            if (! isset($journalsGrouped[$journalKey])) {
                $journalsGrouped[$journalKey] = [
                    'ref' => $journalKey,
                    'raw_ref' => $refRaw !== '' ? $refRaw : null,
                    'date' => $date,
                    'description' => $refRaw !== '' ? "Jurnal Impor {$refRaw}" : ($descRaw ?: "Jurnal Impor {$journalKey}"),
                    'total_debit' => 0.0,
                    'total_credit' => 0.0,
                    'lines' => [],
                ];
            }

            $journalsGrouped[$journalKey]['total_debit'] += $debit;
            $journalsGrouped[$journalKey]['total_credit'] += $credit;
            $journalsGrouped[$journalKey]['lines'][] = [
                'row' => $rowNum,
                'account_id' => $account?->id,
                'account_code' => $codeRaw,
                'account_name' => $account?->name ?? '-',
                'description' => $descRaw,
                'debit' => $debit,
                'credit' => $credit,
            ];
        }

        // Validate balance per journal entry
        foreach ($journalsGrouped as $key => &$j) {
            $diff = abs(round($j['total_debit'] - $j['total_credit'], 2));
            if ($diff > 0.005) {
                $errors[] = [
                    'row' => $j['lines'][0]['row'] ?? 0,
                    'journal_ref' => $key,
                    'message' => sprintf(
                        'Jurnal [%s] tidak seimbang (Unbalanced). Total Debit: %s, Total Kredit: %s (Selisih: %s).',
                        $key,
                        number_format($j['total_debit'], 2, ',', '.'),
                        number_format($j['total_credit'], 2, ',', '.'),
                        number_format($diff, 2, ',', '.')
                    ),
                ];
            }
        }
        unset($j);

        $isBalanced = abs(round($totalDebit - $totalCredit, 2)) <= 0.005;

        return [
            'filename' => $filename,
            'checksum' => $checksum,
            'total_journals' => count($journalsGrouped),
            'total_lines' => $totalLines,
            'total_debit' => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'is_balanced' => $isBalanced,
            'journals' => array_values($journalsGrouped),
            'errors' => $errors,
        ];
    }

    private function findColIndex(array $headers, array $candidates): ?int
    {
        foreach ($headers as $idx => $h) {
            foreach ($candidates as $cand) {
                if (str_contains($h, $cand)) {
                    return $idx;
                }
            }
        }

        return null;
    }

    private function parseDate(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        // Excel numeric serial date check
        if (is_numeric($raw) && (int) $raw > 20000 && (int) $raw < 60000) {
            // Excel base date: Dec 30, 1899
            try {
                return Carbon::create(1899, 12, 30)->addDays((int) $raw)->toDateString();
            } catch (\Throwable) {
                // pass
            }
        }

        // Standard string dates
        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd.m.Y'];
        foreach ($formats as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $raw);
                if ($d !== false && $d->year > 1990 && $d->year < 2100) {
                    return $d->toDateString();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
