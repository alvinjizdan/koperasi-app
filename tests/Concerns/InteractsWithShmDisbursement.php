<?php

namespace Tests\Concerns;

use App\Models\CashierSession;
use App\Models\Member;
use App\Models\SavingProduct;
use App\Models\ShmCustomer;
use App\Models\User;
use App\Services\CashierService;

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
