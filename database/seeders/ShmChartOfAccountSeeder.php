<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\ShmLoanProduct;
use App\Models\ShmProductAccounting;
use App\Support\AccountingContext;
use Illuminate\Database\Seeder;

/**
 * Ticket 38 — Master Chart of Accounts resmi untuk SHM (BSM Makro).
 *
 * Mendaftarkan akun-akun resmi SHM yang diisolasi dari konteks Koperasi,
 * termasuk akun Diskon Pelunasan SHM (4-1901) sebagai kontra-pendapatan (DEC-37-01).
 *
 * Seeder ini bersifat DETERMINISTIK dan IDEMPOTEN menggunakan updateOrCreate
 * dengan acuan kunci ['accounting_context' => AccountingContext::SHM, 'code' => ...].
 */
class ShmChartOfAccountSeeder extends Seeder
{
    /**
     * Daftar akun resmi SHM yang didaftarkan.
     *
     * @var array<int, array{code: string, name: string, category: string, normal_balance: string, parent: string|null}>
     */
    private const OFFICIAL_SHM_ACCOUNTS = [
        // ASET (Kelompok 1)
        ['code' => '1-0000', 'name' => 'ASET SHM (Kelompok Induk)', 'category' => ChartOfAccount::CATEGORY_ASSET, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT, 'parent' => null],
        ['code' => '1-1100', 'name' => 'Kas Operasional SHM', 'category' => ChartOfAccount::CATEGORY_ASSET, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT, 'parent' => '1-0000'],
        ['code' => '1-1200', 'name' => 'Bank Operasional SHM', 'category' => ChartOfAccount::CATEGORY_ASSET, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT, 'parent' => '1-0000'],
        ['code' => '1-1300', 'name' => 'Piutang Pembiayaan SHM', 'category' => ChartOfAccount::CATEGORY_ASSET, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT, 'parent' => '1-0000'],

        // LIABILITAS (Kelompok 2)
        ['code' => '2-0000', 'name' => 'LIABILITAS SHM (Kelompok Induk)', 'category' => ChartOfAccount::CATEGORY_LIABILITY, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT, 'parent' => null],
        ['code' => '2-1100', 'name' => 'Hutang Usaha SHM', 'category' => ChartOfAccount::CATEGORY_LIABILITY, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT, 'parent' => '2-0000'],
        // DEC-37-02 (Ticket 39): Titipan Pembayaran Angsuran SHM (Unallocated Payment Liability)
        ['code' => '2-1301', 'name' => 'Titipan Pembayaran Angsuran SHM', 'category' => ChartOfAccount::CATEGORY_LIABILITY, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT, 'parent' => '2-0000'],
        // DEC-37-03 (Ticket 40): Kelebihan Pembayaran Pelunasan SHM (Settlement Overpayment Liability)
        ['code' => '2-1302', 'name' => 'Kelebihan Pembayaran Pelunasan SHM', 'category' => ChartOfAccount::CATEGORY_LIABILITY, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT, 'parent' => '2-0000'],

        // EKUITAS (Kelompok 3)
        ['code' => '3-0000', 'name' => 'EKUITAS SHM (Kelompok Induk)', 'category' => ChartOfAccount::CATEGORY_EQUITY, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT, 'parent' => null],
        ['code' => '3-1100', 'name' => 'Modal Disetor SHM', 'category' => ChartOfAccount::CATEGORY_EQUITY, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT, 'parent' => '3-0000'],
        ['code' => '3-1200', 'name' => 'Laba Ditahan SHM', 'category' => ChartOfAccount::CATEGORY_EQUITY, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT, 'parent' => '3-0000'],

        // PENDAPATAN (Kelompok 4)
        ['code' => '4-0000', 'name' => 'PENDAPATAN SHM (Kelompok Induk)', 'category' => ChartOfAccount::CATEGORY_REVENUE, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT, 'parent' => null],
        ['code' => '4-1101', 'name' => 'Pendapatan Bunga SHM', 'category' => ChartOfAccount::CATEGORY_REVENUE, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT, 'parent' => '4-0000'],
        ['code' => '4-1103', 'name' => 'Pendapatan Denda / Penalti SHM', 'category' => ChartOfAccount::CATEGORY_REVENUE, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT, 'parent' => '4-0000'],
        // DEC-37-01: Diskon Pelunasan SHM (Gross Revenue / Kontra-Pendapatan)
        ['code' => '4-1901', 'name' => 'Diskon Pelunasan SHM', 'category' => ChartOfAccount::CATEGORY_REVENUE, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT, 'parent' => '4-0000'],

        // BEBAN (Kelompok 5)
        ['code' => '5-0000', 'name' => 'BEBAN SHM (Kelompok Induk)', 'category' => ChartOfAccount::CATEGORY_EXPENSE, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT, 'parent' => null],
        ['code' => '5-1100', 'name' => 'Beban Operasional SHM', 'category' => ChartOfAccount::CATEGORY_EXPENSE, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT, 'parent' => '5-0000'],
    ];

    public function run(): void
    {
        $idsByCode = [];

        foreach (self::OFFICIAL_SHM_ACCOUNTS as $account) {
            $parentId = $account['parent'] !== null ? ($idsByCode[$account['parent']] ?? null) : null;

            $model = ChartOfAccount::query()->updateOrCreate(
                [
                    'accounting_context' => AccountingContext::SHM,
                    'code' => $account['code'],
                ],
                [
                    'name' => $account['name'],
                    'category' => $account['category'],
                    'normal_balance' => $account['normal_balance'],
                    'parent_id' => $parentId,
                    'is_active' => true,
                    'is_template' => false,
                ]
            );

            $idsByCode[$account['code']] = $model->id;
        }

        // Tautkan settlement_discount_account_id pada seluruh ShmProductAccounting yang ada
        $discountAccount = $idsByCode['4-1901'] ?? null;
        if ($discountAccount !== null) {
            $products = ShmLoanProduct::all();
            foreach ($products as $prod) {
                ShmProductAccounting::query()->updateOrCreate(
                    [
                        'shm_loan_product_id' => $prod->id,
                        'accounting_context' => AccountingContext::SHM,
                    ],
                    [
                        'settlement_discount_account_id' => $discountAccount,
                    ]
                );
            }
        }
    }
}
