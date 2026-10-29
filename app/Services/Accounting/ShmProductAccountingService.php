<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\ShmLoanProduct;
use App\Models\ShmProductAccounting;
use App\Services\AuditLogger;
use App\Support\AccountingContext;
use App\Support\DbTransaction;
use Illuminate\Validation\ValidationException;

class ShmProductAccountingService
{
    /**
     * Get SHM product accountings list query with relationships.
     */
    public function query()
    {
        return ShmProductAccounting::query()
            ->shm()
            ->with([
                'product:id,code,name,category,is_active',
                'cashAccount:id,code,name,category,normal_balance',
                'receivableAccount:id,code,name,category,normal_balance',
                'interestRevenueAccount:id,code,name,category,normal_balance',
                'penaltyRevenueAccount:id,code,name,category,normal_balance',
                'settlementDiscountAccount:id,code,name,category,normal_balance',
                'updater:id,name',
            ]);
    }

    /**
     * Get selectable SHM CoA accounts grouped or categorized.
     *
     * @return array<string, mixed>
     */
    public function getSelectableAccounts(): array
    {
        $accounts = ChartOfAccount::query()
            ->shm()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'category', 'normal_balance']);

        return [
            'assets' => $accounts->where('category', ChartOfAccount::CATEGORY_ASSET)->values()->all(),
            'revenues' => $accounts->where('category', ChartOfAccount::CATEGORY_REVENUE)->values()->all(),
            'all' => $accounts->values()->all(),
        ];
    }

    /**
     * Create or update configuration for a product.
     *
     * @param  array{shm_loan_product_id: int, cash_account_id?: ?int, receivable_account_id?: ?int, interest_revenue_account_id?: ?int, penalty_revenue_account_id?: ?int, settlement_discount_account_id?: ?int, is_active?: ?bool, notes?: ?string}  $data
     */
    public function saveConfiguration(array $data, ?int $userId = null): ShmProductAccounting
    {
        return DbTransaction::run(function () use ($data, $userId) {
            $productId = (int) ($data['shm_loan_product_id'] ?? 0);
            $product = ShmLoanProduct::find($productId);

            if (! $product) {
                throw ValidationException::withMessages([
                    'shm_loan_product_id' => ['Produk pinjaman SHM tidak valid atau tidak ditemukan.'],
                ]);
            }

            // Validate accounts
            $this->validateAccountReference($data['cash_account_id'] ?? null, 'cash_account_id', 'Kas / Bank', [ChartOfAccount::CATEGORY_ASSET]);
            $this->validateAccountReference($data['receivable_account_id'] ?? null, 'receivable_account_id', 'Piutang Pokok Pinjaman', [ChartOfAccount::CATEGORY_ASSET]);
            $this->validateAccountReference($data['interest_revenue_account_id'] ?? null, 'interest_revenue_account_id', 'Pendapatan Bunga Pinjaman', [ChartOfAccount::CATEGORY_REVENUE]);
            $this->validateAccountReference($data['penalty_revenue_account_id'] ?? null, 'penalty_revenue_account_id', 'Pendapatan Denda', [ChartOfAccount::CATEGORY_REVENUE]);
            // Settlement discount category is pending business confirmation (contra-revenue vs expense), so allow asset/revenue/expense
            $this->validateAccountReference($data['settlement_discount_account_id'] ?? null, 'settlement_discount_account_id', 'Diskon Pelunasan', null);

            $existing = ShmProductAccounting::query()
                ->where('shm_loan_product_id', $productId)
                ->where('accounting_context', AccountingContext::SHM)
                ->first();

            $isNew = $existing === null;
            $config = $existing ?? new ShmProductAccounting;

            $config->fill([
                'shm_loan_product_id' => $productId,
                'accounting_context' => AccountingContext::SHM,
                'cash_account_id' => $data['cash_account_id'] ?? null,
                'receivable_account_id' => $data['receivable_account_id'] ?? null,
                'interest_revenue_account_id' => $data['interest_revenue_account_id'] ?? null,
                'penalty_revenue_account_id' => $data['penalty_revenue_account_id'] ?? null,
                'settlement_discount_account_id' => $data['settlement_discount_account_id'] ?? null,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($isNew) {
                $config->created_by = $userId;
            }
            $config->updated_by = $userId;
            $config->save();

            AuditLogger::log(
                $isNew ? 'accounting.shm_product_accounting.created' : 'accounting.shm_product_accounting.updated',
                ShmProductAccounting::class,
                $config->id,
                $product->code.' - '.$product->name,
                $isNew ? "Konfigurasi akuntansi produk SHM [{$product->code}] dibuat." : "Konfigurasi akuntansi produk SHM [{$product->code}] diperbarui.",
                [
                    'product_code' => $product->code,
                    'cash_account_id' => $config->cash_account_id,
                    'receivable_account_id' => $config->receivable_account_id,
                    'interest_revenue_account_id' => $config->interest_revenue_account_id,
                    'penalty_revenue_account_id' => $config->penalty_revenue_account_id,
                    'settlement_discount_account_id' => $config->settlement_discount_account_id,
                    'is_active' => $config->is_active,
                ]
            );

            return $config->fresh([
                'product',
                'cashAccount',
                'receivableAccount',
                'interestRevenueAccount',
                'penaltyRevenueAccount',
                'settlementDiscountAccount',
            ]);
        });
    }

    /**
     * Toggle active status of a configuration safely.
     */
    public function toggleActive(ShmProductAccounting $config, ?int $userId = null): ShmProductAccounting
    {
        if ($config->accounting_context !== AccountingContext::SHM) {
            throw ValidationException::withMessages([
                'accounting_context' => ['Konfigurasi bukan milik entitas akuntansi SHM.'],
            ]);
        }

        $config->is_active = ! $config->is_active;
        $config->updated_by = $userId;
        $config->save();

        $action = $config->is_active ? 'diaktifkan' : 'dinonaktifkan';
        AuditLogger::log(
            $config->is_active ? 'accounting.shm_product_accounting.activated' : 'accounting.shm_product_accounting.deactivated',
            ShmProductAccounting::class,
            $config->id,
            $config->product?->code ?? 'Product',
            "Konfigurasi akuntansi produk SHM {$action}.",
            ['is_active' => $config->is_active, 'user_id' => $userId]
        );

        return $config;
    }

    private function validateAccountReference(?int $accountId, string $field, string $label, ?array $allowedCategories = null): void
    {
        if ($accountId === null || $accountId === 0) {
            return;
        }

        $account = ChartOfAccount::find($accountId);

        if (! $account) {
            throw ValidationException::withMessages([
                $field => ["Akun {$label} tidak ditemukan di sistem."],
            ]);
        }

        if ($account->accounting_context !== AccountingContext::SHM) {
            throw ValidationException::withMessages([
                $field => ["Akun [{$account->code} - {$account->name}] bukan milik entitas Akuntansi SHM (tercatat di {$account->accounting_context})."],
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                $field => ["Akun [{$account->code} - {$account->name}] berstatus nonaktif dan tidak dapat digunakan untuk konfigurasi."],
            ]);
        }

        if ($allowedCategories !== null && ! in_array($account->category, $allowedCategories, true)) {
            $catLabel = implode('/', $allowedCategories);
            throw ValidationException::withMessages([
                $field => ["Akun {$label} harus memiliki kategori [{$catLabel}]. Akun [{$account->code}] saat ini berkategori [{$account->category}]."],
            ]);
        }
    }
}
