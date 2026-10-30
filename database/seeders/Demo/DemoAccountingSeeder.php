<?php

namespace Database\Seeders\Demo;

use App\Models\ChartOfAccount;
use App\Models\ShmAccountingMapping;
use App\Models\ShmLoanProduct;
use App\Models\ShmProductAccounting;
use App\Models\User;
use App\Support\AccountingContext;
use Illuminate\Database\Seeder;

/**
 * T011 — Demo SHM Accounting Master Seeder.
 *
 * Menyiapkan master Chart of Accounts resmi SHM, Product Accounting untuk seluruh produk SHM,
 * dan Accounting Mappings untuk seluruh event finansial utama:
 * - SHM_LOAN_DISBURSEMENT
 * - SHM_PAYMENT
 * - SHM_SETTLEMENT
 * - SHM_SETTLEMENT_CORRECTION
 */
class DemoAccountingSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'admin.demo@ksp.local')->first()
            ?? User::query()->where('email', 'superadmin@ksp.local')->firstOrFail();

        // 1. Chart of Accounts SHM
        $accounts = [
            // Aset
            ['code' => '1-1101', 'name' => 'Kas Operasional SHM', 'category' => ChartOfAccount::CATEGORY_ASSET, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT],
            ['code' => '1-1201', 'name' => 'Bank Operasional SHM', 'category' => ChartOfAccount::CATEGORY_ASSET, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT],
            ['code' => '1-1301', 'name' => 'Piutang Pokok SHM', 'category' => ChartOfAccount::CATEGORY_ASSET, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT],
            ['code' => '1-1302', 'name' => 'Piutang Bunga SHM', 'category' => ChartOfAccount::CATEGORY_ASSET, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT],

            // Liabilitas
            ['code' => '2-1101', 'name' => 'Hutang Titipan Angsuran SHM', 'category' => ChartOfAccount::CATEGORY_LIABILITY, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT],

            // Ekuitas
            ['code' => '3-1101', 'name' => 'Modal Disetor SHM', 'category' => ChartOfAccount::CATEGORY_EQUITY, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT],
            ['code' => '3-1201', 'name' => 'Laba Ditahan SHM', 'category' => ChartOfAccount::CATEGORY_EQUITY, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT],

            // Pendapatan
            ['code' => '4-1101', 'name' => 'Pendapatan Bunga SHM', 'category' => ChartOfAccount::CATEGORY_REVENUE, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT],
            ['code' => '4-1201', 'name' => 'Pendapatan Denda SHM', 'category' => ChartOfAccount::CATEGORY_REVENUE, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT],
            ['code' => '4-1901', 'name' => 'Diskon Pelunasan SHM', 'category' => ChartOfAccount::CATEGORY_REVENUE, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT],

            // Beban
            ['code' => '5-1101', 'name' => 'Beban Operasional SHM', 'category' => ChartOfAccount::CATEGORY_EXPENSE, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT],
        ];

        $accountModels = [];
        foreach ($accounts as $acc) {
            $accountModels[$acc['code']] = ChartOfAccount::query()->updateOrCreate(
                [
                    'accounting_context' => AccountingContext::SHM,
                    'code' => $acc['code'],
                ],
                [
                    'name' => $acc['name'],
                    'category' => $acc['category'],
                    'normal_balance' => $acc['normal_balance'],
                    'is_active' => true,
                ]
            );
        }

        // COA Koperasi (untuk pembuktian Context Isolation)
        ChartOfAccount::query()->updateOrCreate(
            [
                'accounting_context' => AccountingContext::KOPERASI,
                'code' => '1-1100',
            ],
            [
                'name' => 'Kas Koperasi',
                'category' => ChartOfAccount::CATEGORY_ASSET,
                'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
                'is_active' => true,
            ]
        );
        ChartOfAccount::query()->updateOrCreate(
            [
                'accounting_context' => AccountingContext::KOPERASI,
                'code' => '3-1100',
            ],
            [
                'name' => 'Modal Koperasi',
                'category' => ChartOfAccount::CATEGORY_EQUITY,
                'normal_balance' => ChartOfAccount::NORMAL_CREDIT,
                'is_active' => true,
            ]
        );

        // 2. Product Accounting untuk seluruh produk SHM
        $products = ShmLoanProduct::all();
        foreach ($products as $prod) {
            ShmProductAccounting::query()->updateOrCreate(
                [
                    'shm_loan_product_id' => $prod->id,
                    'accounting_context' => AccountingContext::SHM,
                ],
                [
                    'cash_account_id' => $accountModels['1-1101']->id,
                    'receivable_account_id' => $accountModels['1-1301']->id,
                    'interest_revenue_account_id' => $accountModels['4-1101']->id,
                    'penalty_revenue_account_id' => $accountModels['4-1201']->id,
                    'settlement_discount_account_id' => $accountModels['4-1901']->id,
                    'is_active' => true,
                ]
            );
        }

        // 3. Accounting Mappings (Idempoten via updateOrCreate)
        $mappings = [
            [
                'business_event' => ShmAccountingMapping::EVENT_DISBURSEMENT,
                'shm_loan_product_id' => null,
                'debit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
                'credit_account_role' => ShmAccountingMapping::ROLE_CASH,
            ],
            [
                'business_event' => ShmAccountingMapping::EVENT_REGULAR_PAYMENT,
                'shm_loan_product_id' => null,
                'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
                'credit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            ],
            [
                'business_event' => ShmAccountingMapping::EVENT_SETTLEMENT,
                'shm_loan_product_id' => null,
                'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
                'credit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            ],
            [
                'business_event' => ShmAccountingMapping::EVENT_SETTLEMENT_CORRECTION,
                'shm_loan_product_id' => null,
                'debit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
                'credit_account_role' => ShmAccountingMapping::ROLE_CASH,
            ],
        ];

        foreach ($mappings as $map) {
            ShmAccountingMapping::query()->updateOrCreate(
                [
                    'accounting_context' => AccountingContext::SHM,
                    'business_event' => $map['business_event'],
                    'shm_loan_product_id' => $map['shm_loan_product_id'],
                ],
                [
                    'debit_account_role' => $map['debit_account_role'],
                    'credit_account_role' => $map['credit_account_role'],
                    'is_active' => true,
                    'created_by' => $admin->id,
                ]
            );
        }
    }
}
