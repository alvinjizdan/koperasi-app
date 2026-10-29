<?php

namespace App\Services\Shm;

use App\Models\Member;
use App\Models\SavingProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\ShmLoanSettlement;
use App\Services\SavingsTransactionService;
use Illuminate\Validation\ValidationException;

/**
 * T89C — Service penghubung domain SHM ke Modul Simpanan Koperasi Existing.
 *
 * Mengalokasikan dana potongan pencairan (Simpanan Pokok & Wajib) serta
 * overpayment settlement ke rekening simpanan anggota koperasi yang menjadi
 * sumber data nasabah SHM.
 *
 * PRINSIP INTEGRASI:
 * 1. Tidak membuat modul/tabel SHM Savings baru.
 * 2. Menggunakan SavingsTransactionService::deposit() existing.
 * 3. Menggunakan SavingProduct::CATEGORY_PRINCIPAL dan CATEGORY_MANDATORY existing.
 * 4. Idempotent melalui deterministik idempotency_key.
 * 5. Jika rekening belum ada, dan produk simpanan aktif tersedia, sistem membuka
 *    rekening secara valid di domain Simpanan Koperasi (reuse SavingsAccount).
 * 6. Jika anggota tidak valid / tidak aktif / produk tidak tersedia, throw explicit exception.
 */
class ShmSavingsIntegrationService
{
    public function __construct(
        private readonly SavingsTransactionService $savingsService,
    ) {}

    /**
     * Dapatkan atau buat rekening simpanan anggota untuk kategori produk tertentu.
     *
     * @param  'principal'|'mandatory'|'voluntary'  $category
     *
     * @throws ValidationException
     */
    public function resolveAccount(Member $member, string $category): SavingsAccount
    {
        // 1. Cari rekening aktif existing milik anggota dengan kategori tersebut
        $account = SavingsAccount::query()
            ->where('member_id', $member->id)
            ->where('status', 'active')
            ->whereHas('savingProduct', function ($q) use ($category) {
                $q->where('category', $category)
                    ->where('is_active', true);
            })
            ->first();

        if ($account) {
            return $account;
        }

        // 2. Jika belum ada rekening, cari produk aktif untuk kategori tersebut
        $product = SavingProduct::query()
            ->where('category', $category)
            ->where('is_active', true)
            ->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'savings' => [sprintf(
                    'SAVINGS_PRODUCT_CONFIGURATION_BLOCKER: Produk simpanan aktif untuk kategori "%s" belum tersedia di Koperasi.',
                    $category
                )],
            ]);
        }

        // 3. Buka rekening baru untuk anggota tersebut secara resmi
        return SavingsAccount::create([
            'account_number' => SavingsAccount::generateAccountNumber(),
            'member_id' => $member->id,
            'saving_product_id' => $product->id,
            'opened_at' => now()->toDateString(),
            'status' => 'active',
            'balance' => 0,
        ]);
    }

    /**
     * Posting setoran simpanan dari transaksi SHM (Pencairan / Overpayment).
     *
     * @param  'principal'|'mandatory'  $category
     */
    public function deposit(
        Member $member,
        string $category,
        float $amount,
        string $idempotencyKey,
        string $description,
        int $actorId,
        bool $recordCashImpact = true,
    ): SavingsTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Nominal setoran simpanan harus > 0.');
        }

        $account = $this->resolveAccount($member, $category);

        if (! $recordCashImpact) {
            return $this->savingsService->depositWithoutCashImpact($account, [
                'amount' => $amount,
                'transaction_date' => now()->toDateString(),
                'description' => $description,
                'idempotency_key' => $idempotencyKey,
                'processed_by' => $actorId,
            ]);
        }

        return $this->savingsService->deposit($account, [
            'amount' => $amount,
            'transaction_date' => now()->toDateString(),
            'description' => $description,
            'idempotency_key' => $idempotencyKey,
            'processed_by' => $actorId,
        ]);
    }

    /**
     * Kompensasi setoran overpayment settlement yang pernah diposting ke Simpanan Pokok.
     * Tidak berdampak pada kas (non-cash impact) karena deposit overpayment juga tidak
     * memiliki dampak kas fisik di kasir.
     */
    public function reverseOverpayment(
        ShmLoanSettlement $settlement,
        int $actorId,
        string $reason,
    ): ?SavingsTransaction {
        $idempotencyKey = sprintf('shm-settlement-overpayment:%d', $settlement->id);
        $tx = SavingsTransaction::where('idempotency_key', $idempotencyKey)->first();

        if ($tx === null) {
            return null;
        }

        $existingReversal = $tx->reversals()->where('status', 'completed')->first();
        if ($existingReversal !== null) {
            return $existingReversal;
        }

        return $this->savingsService->reverseWithoutCashImpact(
            $tx,
            $actorId,
            sprintf('Koreksi settlement %s: %s', $settlement->settlement_reference, $reason),
        );
    }
}
