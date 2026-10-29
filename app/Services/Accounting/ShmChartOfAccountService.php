<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\JournalLine;
use App\Services\AuditLogger;
use App\Support\AccountingContext;
use App\Support\DbTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ShmChartOfAccountService
{
    public function __construct(
        private SimpleXlsxService $xlsx
    ) {}

    /**
     * Get SHM CoA query.
     */
    public function query()
    {
        return ChartOfAccount::query()->shm();
    }

    /**
     * Check if an account is referenced by transactional records.
     */
    public function isReferenced(ChartOfAccount $account): bool
    {
        return JournalLine::where('account_id', $account->id)->exists();
    }

    /**
     * Create an SHM account with server-side context enforcement.
     *
     * @param  array{code: string, name: string, category: string, normal_balance?: ?string, parent_id?: ?int, description?: ?string, is_active?: ?bool}  $data
     */
    public function createAccount(array $data, ?int $userId = null): ChartOfAccount
    {
        $this->validateAccountData($data);

        $category = $data['category'];
        $normalBalance = $data['normal_balance'] ?? ChartOfAccount::CATEGORIES[$category]['normal_balance'];

        $account = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => trim($data['code']),
            'name' => trim($data['name']),
            'category' => $category,
            'normal_balance' => $normalBalance,
            'parent_id' => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
            'is_template' => false,
        ]);

        AuditLogger::log(
            'accounting.shm_coa.created',
            ChartOfAccount::class,
            $account->id,
            $account->code.' - '.$account->name,
            'Akun SHM COA baru dibuat.',
            ['category' => $account->category, 'normal_balance' => $account->normal_balance]
        );

        return $account;
    }

    /**
     * Update an SHM account with server-side context enforcement.
     *
     * @param  array{code: string, name: string, category: string, normal_balance?: ?string, parent_id?: ?int, description?: ?string, is_active?: ?bool}  $data
     */
    public function updateAccount(ChartOfAccount $account, array $data, ?int $userId = null): ChartOfAccount
    {
        if ($account->accounting_context !== AccountingContext::SHM) {
            throw ValidationException::withMessages([
                'account' => ['Akun bukan milik entitas akuntansi SHM.'],
            ]);
        }

        $this->validateAccountData($data, $account->id);

        $category = $data['category'];
        $normalBalance = $data['normal_balance'] ?? ChartOfAccount::CATEGORIES[$category]['normal_balance'];

        $account->update([
            'code' => trim($data['code']),
            'name' => trim($data['name']),
            'category' => $category,
            'normal_balance' => $normalBalance,
            'parent_id' => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : $account->is_active,
        ]);

        AuditLogger::logChanges(
            $account,
            'accounting.shm_coa.updated',
            ['code', 'name', 'category', 'normal_balance', 'is_active', 'parent_id', 'description'],
            'Akun SHM COA diperbarui.'
        );

        return $account;
    }

    /**
     * Deactivate or delete an SHM account depending on usage.
     */
    public function deactivateOrDelete(ChartOfAccount $account, ?int $userId = null): array
    {
        if ($account->accounting_context !== AccountingContext::SHM) {
            throw ValidationException::withMessages([
                'account' => ['Akun bukan milik entitas akuntansi SHM.'],
            ]);
        }

        if ($this->isReferenced($account)) {
            $account->update(['is_active' => false]);

            AuditLogger::log(
                'accounting.shm_coa.deactivated',
                ChartOfAccount::class,
                $account->id,
                $account->code.' - '.$account->name,
                'Akun SHM yang sudah digunakan di transaksi/jurnal dinonaktifkan (tidak dihapus) agar integritas historis utuh.'
            );

            return [
                'action' => 'deactivated',
                'message' => 'Akun '.$account->code.' dinonaktifkan karena telah memiliki mutasi transaksi.',
            ];
        }

        // Check if has children
        if ($account->children()->exists()) {
            throw ValidationException::withMessages([
                'account' => ['Akun induk tidak dapat dihapus selama masih memiliki sub-akun.'],
            ]);
        }

        $account->delete();

        AuditLogger::log(
            'accounting.shm_coa.deleted',
            ChartOfAccount::class,
            $account->id,
            $account->code.' - '.$account->name,
            'Akun SHM COA yang belum pernah digunakan dihapus permanen.'
        );

        return [
            'action' => 'deleted',
            'message' => 'Akun '.$account->code.' berhasil dihapus.',
        ];
    }

    /**
     * Export all SHM accounts to an XLSX file.
     */
    public function exportToXlsx(): string
    {
        $accounts = ChartOfAccount::query()
            ->shm()
            ->with('parent:id,code')
            ->orderBy('code')
            ->get();

        $rows = [];
        // Header
        $rows[] = [
            'Kode Akun',
            'Nama Akun',
            'Kategori',
            'Saldo Normal',
            'Kode Induk',
            'Status',
            'Keterangan',
        ];

        foreach ($accounts as $acc) {
            $rows[] = [
                $acc->code,
                $acc->name,
                $acc->category,
                $acc->normal_balance,
                $acc->parent?->code ?? '',
                $acc->is_active ? 'ACTIVE' : 'INACTIVE',
                $acc->description ?? '',
            ];
        }

        $filePath = sys_get_temp_dir().'/shm_coa_export_'.date('Ymd_His').'_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'SHM_COA');

        return $filePath;
    }

    /**
     * Generate an empty XLSX template for import.
     */
    public function generateTemplateXlsx(): string
    {
        $rows = [
            [
                'Kode Akun',
                'Nama Akun',
                'Kategori',
                'Saldo Normal',
                'Kode Induk',
                'Status',
                'Keterangan',
            ],
            [
                '1-1100',
                'Kas Operasional SHM',
                'asset',
                'debit',
                '',
                'ACTIVE',
                'Contoh Kas Operasional',
            ],
            [
                '4-1100',
                'Pendapatan Bunga Pinjaman SHM',
                'revenue',
                'credit',
                '',
                'ACTIVE',
                'Contoh Pendapatan Bunga',
            ],
        ];

        $filePath = sys_get_temp_dir().'/shm_coa_template_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Template_SHM_COA');

        return $filePath;
    }

    /**
     * Import SHM accounts from an uploaded XLSX file.
     * Mode: CREATE ONLY.
     *
     * @return array{total_rows: int, successful_rows: int, failed_rows: int, errors: array<int, array{row: int, column: string, value: string, reason: string}>}
     */
    public function importFromXlsx(UploadedFile $file, int $userId): array
    {
        // 1. Validate file extension
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext !== 'xlsx') {
            throw ValidationException::withMessages([
                'file' => ['Format berkas tidak didukung. Hanya format .xlsx yang diperbolehkan (format .xls dan .csv ditolak).'],
            ]);
        }

        // 2. Parse rows
        try {
            $rows = $this->xlsx->readRows($file->getRealPath());
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'file' => ['Berkas .xlsx tidak dapat dibaca atau rusak: '.$e->getMessage()],
            ]);
        }

        if (empty($rows)) {
            throw ValidationException::withMessages([
                'file' => ['Berkas .xlsx kosong atau tidak memiliki data.'],
            ]);
        }

        // 3. Validate Header
        $header = array_map('trim', $rows[0]);
        $expectedCols = ['Kode Akun', 'Nama Akun', 'Kategori', 'Saldo Normal'];
        foreach ($expectedCols as $col) {
            if (! in_array($col, $header, true)) {
                throw ValidationException::withMessages([
                    'file' => ["Header berkas tidak lengkap. Kolom wajib [{$col}] tidak ditemukan."],
                ]);
            }
        }

        $codeColIdx = array_search('Kode Akun', $header);
        $nameColIdx = array_search('Nama Akun', $header);
        $catColIdx = array_search('Kategori', $header);
        $normColIdx = array_search('Saldo Normal', $header);
        $parentColIdx = array_search('Kode Induk', $header);
        $statusColIdx = array_search('Status', $header);
        $descColIdx = array_search('Keterangan', $header);

        $errors = [];
        $dataRows = [];
        $seenFileCodes = [];

        // 4. Validate all rows (1-based index in messages, header is row 1)
        for ($i = 1; $i < count($rows); $i++) {
            $rowNum = $i + 1;
            $row = $rows[$i];

            // If completely empty row, skip
            if (empty(array_filter($row, fn ($v) => $v !== ''))) {
                continue;
            }

            $code = trim($row[$codeColIdx] ?? '');
            $name = trim($row[$nameColIdx] ?? '');
            $cat = strtolower(trim($row[$catColIdx] ?? ''));
            $norm = strtolower(trim($row[$normColIdx] ?? ''));
            $parentCode = $parentColIdx !== false ? trim($row[$parentColIdx] ?? '') : '';
            $statusStr = $statusColIdx !== false ? strtoupper(trim($row[$statusColIdx] ?? 'ACTIVE')) : 'ACTIVE';
            $desc = $descColIdx !== false ? trim($row[$descColIdx] ?? '') : '';

            // Check code
            if ($code === '') {
                $errors[] = [
                    'row' => $rowNum,
                    'column' => 'Kode Akun',
                    'value' => '',
                    'reason' => 'Kode Akun wajib diisi.',
                ];
            } elseif (strlen($code) > 20) {
                $errors[] = [
                    'row' => $rowNum,
                    'column' => 'Kode Akun',
                    'value' => $code,
                    'reason' => 'Kode Akun melebihi batas maksimal 20 karakter.',
                ];
            } elseif (in_array($code, $seenFileCodes, true)) {
                $errors[] = [
                    'row' => $rowNum,
                    'column' => 'Kode Akun',
                    'value' => $code,
                    'reason' => 'Kode Akun duplikat di dalam berkas impor yang sama.',
                ];
            } else {
                $seenFileCodes[] = $code;
            }

            // Check name
            if ($name === '') {
                $errors[] = [
                    'row' => $rowNum,
                    'column' => 'Nama Akun',
                    'value' => '',
                    'reason' => 'Nama Akun wajib diisi.',
                ];
            } elseif (strlen($name) > 150) {
                $errors[] = [
                    'row' => $rowNum,
                    'column' => 'Nama Akun',
                    'value' => $name,
                    'reason' => 'Nama Akun melebihi batas maksimal 150 karakter.',
                ];
            }

            // Check category
            if (! array_key_exists($cat, ChartOfAccount::CATEGORIES)) {
                $errors[] = [
                    'row' => $rowNum,
                    'column' => 'Kategori',
                    'value' => $cat,
                    'reason' => 'Kategori tidak valid. Kategori yang didukung: asset, liability, equity, revenue, expense.',
                ];
            } else {
                // Check normal balance
                $expectedNorm = ChartOfAccount::CATEGORIES[$cat]['normal_balance'];
                if ($norm === '') {
                    $norm = $expectedNorm;
                } elseif (! in_array($norm, ['debit', 'credit'], true)) {
                    $errors[] = [
                        'row' => $rowNum,
                        'column' => 'Saldo Normal',
                        'value' => $norm,
                        'reason' => 'Saldo Normal tidak valid. Nilai yang diperbolehkan: debit, credit.',
                    ];
                } elseif ($norm !== $expectedNorm) {
                    $errors[] = [
                        'row' => $rowNum,
                        'column' => 'Saldo Normal',
                        'value' => $norm,
                        'reason' => "Saldo Normal [{$norm}] tidak konsisten dengan kategori [{$cat}] (seharusnya {$expectedNorm}).",
                    ];
                }
            }

            // Check status
            $isActive = true;
            if ($statusStr !== '') {
                if (in_array($statusStr, ['ACTIVE', 'AKTIF', '1', 'TRUE'], true)) {
                    $isActive = true;
                } elseif (in_array($statusStr, ['INACTIVE', 'NONAKTIF', '0', 'FALSE'], true)) {
                    $isActive = false;
                } else {
                    $errors[] = [
                        'row' => $rowNum,
                        'column' => 'Status',
                        'value' => $statusStr,
                        'reason' => 'Status tidak valid. Nilai yang diperbolehkan: ACTIVE atau INACTIVE.',
                    ];
                }
            }

            $dataRows[] = [
                'row_num' => $rowNum,
                'code' => $code,
                'name' => $name,
                'category' => $cat,
                'normal_balance' => $norm,
                'parent_code' => $parentCode,
                'is_active' => $isActive,
                'description' => $desc,
            ];
        }

        // 5. Check against existing SHM records (Conflict detection: CREATE ONLY)
        $existingShmCodes = ChartOfAccount::query()
            ->shm()
            ->whereIn('code', $seenFileCodes)
            ->pluck('code')
            ->all();

        foreach ($dataRows as $item) {
            if (in_array($item['code'], $existingShmCodes, true)) {
                $errors[] = [
                    'row' => $item['row_num'],
                    'column' => 'Kode Akun',
                    'value' => $item['code'],
                    'reason' => "Akun dengan kode [{$item['code']}] sudah terdaftar di SHM CoA (Mode impor: CREATE ONLY).",
                ];
            }
        }

        // 6. Fail-closed: If ANY errors, return without saving
        if (! empty($errors)) {
            return [
                'total_rows' => count($dataRows),
                'successful_rows' => 0,
                'failed_rows' => count($errors),
                'errors' => $errors,
            ];
        }

        // 7. Atomic Persistence inside DB Transaction
        DbTransaction::run(function () use ($dataRows, $userId) {
            $insertedMap = [];

            // Pass 1: Insert all accounts without parent_id
            foreach ($dataRows as $item) {
                $account = ChartOfAccount::create([
                    'accounting_context' => AccountingContext::SHM,
                    'code' => $item['code'],
                    'name' => $item['name'],
                    'category' => $item['category'],
                    'normal_balance' => $item['normal_balance'],
                    'is_active' => $item['is_active'],
                    'description' => $item['description'],
                    'is_template' => false,
                ]);

                $insertedMap[$item['code']] = $account;
            }

            // Pass 2: Connect parent_id if parent_code was provided
            foreach ($dataRows as $item) {
                $pCode = $item['parent_code'];
                if ($pCode !== '') {
                    $parentAcc = $insertedMap[$pCode] ?? ChartOfAccount::query()->shm()->where('code', $pCode)->first();
                    if ($parentAcc) {
                        $insertedMap[$item['code']]->update([
                            'parent_id' => $parentAcc->id,
                        ]);
                    }
                }
            }

            AuditLogger::log(
                'accounting.shm_coa.imported',
                ChartOfAccount::class,
                null,
                'SHM CoA XLSX Import',
                'Impor massal '.count($dataRows).' akun SHM CoA berhasil.',
                ['count' => count($dataRows), 'user_id' => $userId]
            );
        });

        return [
            'total_rows' => count($dataRows),
            'successful_rows' => count($dataRows),
            'failed_rows' => 0,
            'errors' => [],
        ];
    }

    private function validateAccountData(array $data, ?int $ignoreId = null): void
    {
        $code = trim($data['code'] ?? '');
        $name = trim($data['name'] ?? '');
        $category = $data['category'] ?? '';

        if ($code === '') {
            throw ValidationException::withMessages(['code' => ['Kode akun wajib diisi.']]);
        }
        if (strlen($code) > 20) {
            throw ValidationException::withMessages(['code' => ['Kode akun maksimal 20 karakter.']]);
        }

        // Scoped uniqueness to SHM
        $conflictQuery = ChartOfAccount::query()
            ->shm()
            ->where('code', $code);

        if ($ignoreId !== null) {
            $conflictQuery->where('id', '!=', $ignoreId);
        }

        if ($conflictQuery->exists()) {
            throw ValidationException::withMessages(['code' => ["Kode akun [{$code}] sudah digunakan di Akuntansi SHM."]]);
        }

        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Nama akun wajib diisi.']]);
        }
        if (strlen($name) > 150) {
            throw ValidationException::withMessages(['name' => ['Nama akun maksimal 150 karakter.']]);
        }

        if (! array_key_exists($category, ChartOfAccount::CATEGORIES)) {
            throw ValidationException::withMessages(['category' => ['Kategori akun tidak valid.']]);
        }

        if (isset($data['parent_id']) && $data['parent_id'] !== null) {
            $parentId = (int) $data['parent_id'];
            if ($ignoreId !== null && $parentId === $ignoreId) {
                throw ValidationException::withMessages(['parent_id' => ['Akun induk tidak boleh merujuk akun itu sendiri.']]);
            }

            $parent = ChartOfAccount::query()->shm()->find($parentId);
            if (! $parent) {
                throw ValidationException::withMessages(['parent_id' => ['Akun induk tidak ditemukan di Akuntansi SHM.']]);
            }
            if ($parent->category !== $category) {
                throw ValidationException::withMessages(['parent_id' => ["Akun induk harus berada dalam kategori yang sama ({$parent->categoryLabel()})."]]);
            }
        }
    }
}
