<?php

namespace Tests\Concerns;

use App\Models\CashierSession;
use App\Models\ChartOfAccount;
use App\Models\Member;
use App\Models\SavingProduct;
use App\Models\ShmAccountingMapping;
use App\Models\ShmCustomer;
use App\Models\ShmLoanProduct;
use App\Models\ShmProductAccounting;
use App\Models\User;
use App\Services\CashierService;
use App\Support\AccountingContext;

/**
 * T002C — Prasyarat fixture pencairan SHM.
 *
 * Pencairan fail-closed membutuhkan tiga prasyarat nyata sesuai kontrak
 * T002/T150:
 *
 * 1. Nasabah SHM dengan relasi anggota sumber AKTIF (T89C/T150).
 * 2. Produk simpanan Pokok & Wajib aktif agar rekening anggota dapat
 *    di-resolve/dibuka oleh ShmSavingsIntegrationService.
 * 3. Sesi kasir AKTIF milik pelaku pencairan dengan saldo kas cukup untuk
 *    kas keluar net (T002C F-01/F-02: tanpa sesi aktif pencairan ditolak,
 *    dan kas keluar gagal → rollback penuh).
 *
 * Helper ini hanya dipakai oleh fixture uji; tidak ada logika bisnis yang
 * tinggal di sini.
 */
trait InteractsWithShmDisbursement
{
    /**
     * Saldo kas default sesi uji — cukup besar untuk kas keluar net pada
     * fixture pencairan (mis. plafon Rp450.000.000).
     */
    private float $shmTestCashBalance = 5_000_000_000;

    /**
     * Pastikan produk simpanan Pokok & Wajib aktif tersedia (idempoten
     * dalam satu test).
     */
    protected function seedShmSavingsProducts(): void
    {
        SavingProduct::firstOrCreate(
            ['code' => 'SP-T002C'],
            [
                'name' => 'Simpanan Pokok',
                'category' => SavingProduct::CATEGORY_PRINCIPAL,
                'description' => 'Simpanan Pokok (fixture uji T002C)',
                'is_active' => true,
            ],
        );

        SavingProduct::firstOrCreate(
            ['code' => 'SW-T002C'],
            [
                'name' => 'Simpanan Wajib',
                'category' => SavingProduct::CATEGORY_MANDATORY,
                'description' => 'Simpanan Wajib (fixture uji T002C)',
                'is_active' => true,
            ],
        );

        // T006E — Pastikan akun SHM & mapping generic untuk pencairan tersedia
        $cash = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1101'],
            [
                'name' => 'Kas Operasional SHM',
                'category' => ChartOfAccount::CATEGORY_ASSET,
                'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
                'is_active' => true,
            ]
        );

        $receivable = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1301'],
            [
                'name' => 'Piutang Pokok SHM',
                'category' => ChartOfAccount::CATEGORY_ASSET,
                'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
                'is_active' => true,
            ]
        );

        ShmAccountingMapping::firstOrCreate(
            [
                'accounting_context' => AccountingContext::SHM,
                'business_event' => ShmAccountingMapping::EVENT_DISBURSEMENT,
                'shm_loan_product_id' => null,
            ],
            [
                'debit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
                'credit_account_role' => ShmAccountingMapping::ROLE_CASH,
                'is_active' => true,
            ]
        );

        foreach (ShmLoanProduct::all() as $product) {
            ShmProductAccounting::firstOrCreate(
                [
                    'shm_loan_product_id' => $product->id,
                    'accounting_context' => AccountingContext::SHM,
                ],
                [
                    'cash_account_id' => $cash->id,
                    'receivable_account_id' => $receivable->id,
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * Nasabah SHM yang memiliki anggota koperasi sumber aktif — prasyarat
     * pencairan (T89C/T150: tanpa relasi anggota sumber, pencairan ditolak).
     */
    protected function createShmCustomerWithMember(): ShmCustomer
    {
        $member = Member::factory()->create(['status' => 'active']);

        return ShmCustomer::factory()->create(['member_id' => $member->id]);
    }

    /**
     * Buka (atau kembalikan) sesi kasir aktif milik pelaku dengan saldo kas
     * yang cukup untuk kas keluar net.
     */
    protected function openCashierSessionFor(User $actor, ?float $balance = null): CashierSession
    {
        $cashierService = app(CashierService::class);

        $existing = $cashierService->activeSessionFor($actor->id);

        if ($existing !== null) {
            return $existing;
        }

        return $cashierService->openSession([
            'operational_date' => now()->toDateString(),
            'opening_balance' => $balance ?? $this->shmTestCashBalance,
            'opening_notes' => 'Sesi uji T002C — prasyarat pencairan SHM.',
        ], $actor->id);
    }
}
