<?php

namespace App\Services\Shm;

use App\Models\Shm\ShmSavingProduct;
use App\Models\Shm\ShmSavingsAccount;
use App\Models\Shm\ShmSavingsTransaction;
use App\Models\ShmCustomer;
use App\Models\ShmLoanSettlement;
use Illuminate\Validation\ValidationException;

/**
 * Service penghubung domain Pinjaman SHM ke Modul Simpanan SHM.
 *
 * Mengalokasikan dana potongan pencairan (Simpanan Pokok & Wajib) serta
 * overpayment settlement / angsuran langsung ke rekening simpanan nasabah SHM (ShmCustomer).
 *
 * PRINSIP ISOLASI:
 * 1. Tidak menggunakan model, rekening, maupun transaksi Simpanan Koperasi.
 * 2. Menggunakan ShmSavingProduct, ShmSavingsAccount, dan ShmSavingsTransaction.
 * 3. Idempotent melalui deterministik idempotency_key.
 * 4. Jika rekening SHM belum ada, sistem membuka rekening secara valid di domain Simpanan SHM.
 * 5. Tidak membutuhkan status atau relasi Member koperasi.
 */
class ShmSavingsIntegrationService
{
    public function __construct(
        private readonly ShmSavingsTransactionService $savingsService,
    ) {}

    /**
     * Dapatkan atau buat rekening simpanan SHM nasabah untuk kategori produk tertentu.
     *
     * @param  'principal'|'mandatory'|'voluntary'  $category
     *
     * @throws ValidationException
     */
    public function resolveAccount(ShmCustomer $customer, string $category): ShmSavingsAccount
    {
        // 1. Cari rekening aktif existing milik nasabah SHM dengan kategori tersebut
        $account = ShmSavingsAccount::query()
            ->where('shm_customer_id', $customer->id)
            ->where('status', 'active')
            ->whereHas('savingProduct', function ($q) use ($category) {
                $q->where('category', $category)
                    ->where('is_active', true);
            })
            ->first();

        if ($account) {
            return $account;
        }

        // 2. Jika belum ada rekening, cari produk aktif untuk kategori tersebut di SHM
        $product = ShmSavingProduct::query()
            ->where('category', $category)
            ->where('is_active', true)
            ->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'savings' => [sprintf(
                    'SAVINGS_PRODUCT_CONFIGURATION_BLOCKER: Produk simpanan SHM aktif untuk kategori "%s" belum tersedia.',
                    $category
                )],
            ]);
        }

        // 3. Buka rekening baru untuk nasabah SHM tersebut
        return ShmSavingsAccount::create([
            'account_number' => ShmSavingsAccount::generateAccountNumber(),
            'shm_customer_id' => $customer->id,
            'shm_saving_product_id' => $product->id,
            'opened_at' => now()->toDateString(),
            'status' => 'active',
            'balance' => 0,
        ]);
    }

    /**
     * Posting setoran simpanan dari transaksi SHM (Pencairan / Overpayment).
     *
     * @param  'principal'|'mandatory'|'voluntary'  $category
     */
    public function deposit(
        ShmCustomer $customer,
        string $category,
        float $amount,
        string $idempotencyKey,
        string $description,
        int $actorId,
        bool $recordCashImpact = true,
    ): ShmSavingsTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Nominal setoran simpanan harus > 0.');
        }

        $account = $this->resolveAccount($customer, $category);

        return $this->savingsService->deposit($account, [
            'amount' => $amount,
            'transaction_date' => now()->toDateString(),
            'description' => $description,
            'idempotency_key' => $idempotencyKey,
            'processed_by' => $actorId,
        ]);
    }

    /**
     * Kompensasi setoran overpayment settlement yang pernah diposting ke Simpanan Pokok SHM.
     */
    public function reverseOverpayment(
        ShmLoanSettlement $settlement,
        int $actorId,
        string $reason,
    ): ?ShmSavingsTransaction {
        $idempotencyKey = sprintf('shm-settlement-overpayment:%d', $settlement->id);
        $tx = ShmSavingsTransaction::where('idempotency_key', $idempotencyKey)->first();

        if ($tx === null) {
            return null;
        }

        $existingReversal = $tx->reversals()->where('status', 'completed')->first();
        if ($existingReversal !== null) {
            return $existingReversal;
        }

        return $this->savingsService->reverse(
            $tx,
            $actorId,
            sprintf('Koreksi settlement %s: %s', $settlement->settlement_reference, $reason),
        );
    }
}
