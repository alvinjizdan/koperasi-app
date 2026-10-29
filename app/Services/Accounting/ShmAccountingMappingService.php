<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\ShmAccountingMapping;
use App\Models\ShmLoanProduct;
use App\Models\ShmProductAccounting;
use App\Services\AuditLogger;
use App\Support\AccountingContext;
use App\Support\DbTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Service untuk SHM Accounting Mapping & Account Resolution (T006D).
 */
class ShmAccountingMappingService
{
    /**
     * Query mappings for SHM context.
     *
     * @return Builder
     */
    public function query()
    {
        return ShmAccountingMapping::query()
            ->shm()
            ->with([
                'product:id,code,name,category',
                'debitAccount:id,code,name,category,normal_balance',
                'creditAccount:id,code,name,category,normal_balance',
                'updater:id,name',
            ]);
    }

    /**
     * Simpan atau perbarui mapping.
     *
     * @param  array{business_event: string, shm_loan_product_id?: ?int, debit_account_role?: ?string, debit_account_id?: ?int, credit_account_role?: ?string, credit_account_id?: ?int, is_active?: ?bool, description?: ?string}  $data
     */
    public function saveMapping(array $data, ?int $userId = null, ?int $mappingId = null): ShmAccountingMapping
    {
        return DbTransaction::run(function () use ($data, $userId, $mappingId) {
            $event = (string) ($data['business_event'] ?? '');
            if (! array_key_exists($event, ShmAccountingMapping::supportedEvents())) {
                throw ValidationException::withMessages([
                    'business_event' => ['Business event tidak valid.'],
                ]);
            }

            $productId = ! empty($data['shm_loan_product_id']) ? (int) $data['shm_loan_product_id'] : null;
            if ($productId !== null) {
                $product = ShmLoanProduct::find($productId);
                if (! $product) {
                    throw ValidationException::withMessages([
                        'shm_loan_product_id' => ['Produk pinjaman SHM tidak ditemukan.'],
                    ]);
                }
            }

            // Validasi Debit
            $debitRole = ! empty($data['debit_account_role']) ? (string) $data['debit_account_role'] : null;
            $debitAccountId = ! empty($data['debit_account_id']) ? (int) $data['debit_account_id'] : null;
            if ($debitRole === null && $debitAccountId === null) {
                throw ValidationException::withMessages([
                    'debit_account' => ['Debit harus ditentukan via peran akun (role) atau akun spesifik.'],
                ]);
            }
            if ($debitAccountId !== null) {
                $this->validateAccount($debitAccountId, 'debit_account_id', 'Debit');
            }

            // Validasi Credit
            $creditRole = ! empty($data['credit_account_role']) ? (string) $data['credit_account_role'] : null;
            $creditAccountId = ! empty($data['credit_account_id']) ? (int) $data['credit_account_id'] : null;
            if ($creditRole === null && $creditAccountId === null) {
                throw ValidationException::withMessages([
                    'credit_account' => ['Kredit harus ditentukan via peran akun (role) atau akun spesifik.'],
                ]);
            }
            if ($creditAccountId !== null) {
                $this->validateAccount($creditAccountId, 'credit_account_id', 'Kredit');
            }

            // Check duplicate unique mapping
            $duplicateQuery = ShmAccountingMapping::query()
                ->where('accounting_context', AccountingContext::SHM)
                ->where('business_event', $event)
                ->where('shm_loan_product_id', $productId);

            if ($mappingId !== null) {
                $duplicateQuery->where('id', '!=', $mappingId);
            }

            if ($duplicateQuery->exists()) {
                throw ValidationException::withMessages([
                    'business_event' => ['Mapping untuk event dan produk ini sudah ada dalam sistem.'],
                ]);
            }

            $mapping = $mappingId ? ShmAccountingMapping::findOrFail($mappingId) : new ShmAccountingMapping;
            $isNew = ! $mapping->exists;

            $mapping->fill([
                'accounting_context' => AccountingContext::SHM,
                'business_event' => $event,
                'shm_loan_product_id' => $productId,
                'debit_account_role' => $debitRole,
                'debit_account_id' => $debitAccountId,
                'credit_account_role' => $creditRole,
                'credit_account_id' => $creditAccountId,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
                'description' => $data['description'] ?? null,
            ]);

            if ($isNew) {
                $mapping->created_by = $userId;
            }
            $mapping->updated_by = $userId;
            $mapping->save();

            AuditLogger::log(
                $isNew ? 'accounting.shm_mapping.created' : 'accounting.shm_mapping.updated',
                ShmAccountingMapping::class,
                $mapping->id,
                $mapping->business_event,
                $isNew ? "Mapping akuntansi SHM untuk [{$mapping->business_event}] dibuat." : "Mapping akuntansi SHM untuk [{$mapping->business_event}] diperbarui.",
                [
                    'business_event' => $mapping->business_event,
                    'product_id' => $mapping->shm_loan_product_id,
                    'debit_role' => $mapping->debit_account_role,
                    'credit_role' => $mapping->credit_account_role,
                    'is_active' => $mapping->is_active,
                ]
            );

            return $mapping->fresh(['product', 'debitAccount', 'creditAccount']);
        });
    }

    /**
     * Toggle status aktif mapping.
     */
    public function toggleActive(ShmAccountingMapping $mapping, ?int $userId = null): ShmAccountingMapping
    {
        if ($mapping->accounting_context !== AccountingContext::SHM) {
            throw ValidationException::withMessages([
                'accounting_context' => ['Mapping bukan milik entitas Akuntansi SHM.'],
            ]);
        }

        $mapping->is_active = ! $mapping->is_active;
        $mapping->updated_by = $userId;
        $mapping->save();

        $action = $mapping->is_active ? 'diaktifkan' : 'dinonaktifkan';
        AuditLogger::log(
            $mapping->is_active ? 'accounting.shm_mapping.activated' : 'accounting.shm_mapping.deactivated',
            ShmAccountingMapping::class,
            $mapping->id,
            $mapping->business_event,
            "Mapping akuntansi SHM [{$mapping->business_event}] {$action}.",
            ['is_active' => $mapping->is_active, 'user_id' => $userId]
        );

        return $mapping;
    }

    /**
     * Resolusi sepasang akun (Debit & Credit) untuk business event & product tertentu.
     * Mengintegrasikan konfigurasi ShmProductAccounting (T006C).
     *
     * @return array{debit_account: ChartOfAccount, credit_account: ChartOfAccount, mapping: ShmAccountingMapping}
     */
    public function resolveAccountsForEvent(string $event, ?int $productId = null): array
    {
        // 1. Cari mapping aktif: spesifik produk terlebih dahulu, lalu fallback ke generic (product_id null)
        $mapping = ShmAccountingMapping::query()
            ->shm()
            ->active()
            ->where('business_event', $event)
            ->where(function ($q) use ($productId) {
                if ($productId !== null) {
                    $q->where('shm_loan_product_id', $productId)
                        ->orWhereNull('shm_loan_product_id');
                } else {
                    $q->whereNull('shm_loan_product_id');
                }
            })
            ->orderByRaw('shm_loan_product_id IS NULL ASC') // spesifik produk diprioritaskan
            ->first();

        if (! $mapping) {
            throw ValidationException::withMessages([
                'mapping' => ["Tidak ditemukan mapping aktif untuk business event [{$event}]."],
            ]);
        }

        // Ambil konfigurasi product accounting jika ada role yang perlu di-resolve
        $productConfig = null;
        if ($productId !== null) {
            $productConfig = ShmProductAccounting::query()
                ->shm()
                ->active()
                ->where('shm_loan_product_id', $productId)
                ->first();
        }

        $debitAccount = $this->resolveSingleAccount($mapping->debit_account_role, $mapping->debit_account_id, $productConfig, 'Debit');
        $creditAccount = $this->resolveSingleAccount($mapping->credit_account_role, $mapping->credit_account_id, $productConfig, 'Kredit');

        return [
            'debit_account' => $debitAccount,
            'credit_account' => $creditAccount,
            'mapping' => $mapping,
        ];
    }

    private function resolveSingleAccount(?string $role, ?int $accountId, ?ShmProductAccounting $productConfig, string $side): ChartOfAccount
    {
        if ($accountId !== null) {
            $account = ChartOfAccount::find($accountId);
            $this->assertAccountUsable($account, $side);

            return $account;
        }

        if ($role !== null) {
            if (! $productConfig) {
                throw ValidationException::withMessages([
                    'product_accounting' => ["Akun sisi {$side} memerlukan peran [{$role}], namun konfigurasi akuntansi produk pinjaman aktif tidak ditemukan."],
                ]);
            }

            $resolvedId = match ($role) {
                ShmAccountingMapping::ROLE_CASH => $productConfig->cash_account_id,
                ShmAccountingMapping::ROLE_RECEIVABLE => $productConfig->receivable_account_id,
                ShmAccountingMapping::ROLE_INTEREST_REVENUE => $productConfig->interest_revenue_account_id,
                ShmAccountingMapping::ROLE_PENALTY_REVENUE => $productConfig->penalty_revenue_account_id,
                ShmAccountingMapping::ROLE_SETTLEMENT_DISCOUNT => $productConfig->settlement_discount_account_id,
                default => null,
            };

            if (! $resolvedId) {
                throw ValidationException::withMessages([
                    'product_accounting' => ["Peran akun [{$role}] untuk sisi {$side} belum dikonfigurasi pada produk pinjaman."],
                ]);
            }

            $account = ChartOfAccount::find($resolvedId);
            $this->assertAccountUsable($account, $side);

            return $account;
        }

        throw ValidationException::withMessages([
            'account' => ["Akun untuk sisi {$side} tidak dapat diresolusi."],
        ]);
    }

    private function validateAccount(int $accountId, string $field, string $label): void
    {
        $account = ChartOfAccount::find($accountId);
        if (! $account) {
            throw ValidationException::withMessages([
                $field => ["Akun {$label} tidak ditemukan."],
            ]);
        }

        if ($account->accounting_context !== AccountingContext::SHM) {
            throw ValidationException::withMessages([
                $field => ["Akun {$label} [{$account->code}] bukan milik entitas Akuntansi SHM (tercatat di {$account->accounting_context})."],
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                $field => ["Akun {$label} [{$account->code}] berstatus nonaktif dan tidak dapat digunakan."],
            ]);
        }
    }

    private function assertAccountUsable(?ChartOfAccount $account, string $side): void
    {
        if (! $account) {
            throw ValidationException::withMessages([
                'account' => ["Akun sisi {$side} tidak ditemukan."],
            ]);
        }

        if ($account->accounting_context !== AccountingContext::SHM) {
            throw ValidationException::withMessages([
                'account' => ["Akun sisi {$side} [{$account->code}] bukan milik Akuntansi SHM."],
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'account' => ["Akun sisi {$side} [{$account->code}] berstatus nonaktif."],
            ]);
        }
    }
}
