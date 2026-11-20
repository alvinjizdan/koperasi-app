<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Penggunaan Dana Titipan Angsuran pada Pelunasan SHM (Ticket 41).
 *
 * Mencatat pemakaian riil dana pembayaran unallocated (`shm_loan_payments`)
 * untuk menutup sebagian atau seluruh kewajiban bruto pelunasan (`shm_loan_settlements`).
 *
 * Status:
 * - STATUS_ACTIVE: dana sedang/sudah dikonsumsi oleh settlement.
 * - STATUS_RELEASED: penggunaan dilepaskan (misal pembatalan/rejection settlement).
 * - STATUS_REVERSED: penggunaan dibatalkan karena koreksi/reversal settlement.
 *
 * @property int $id
 * @property int $shm_loan_settlement_id
 * @property int $shm_loan_payment_id
 * @property string $amount
 * @property string $status
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShmLoanSettlementUnallocatedUsage extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_RELEASED = 'released';

    public const STATUS_REVERSED = 'reversed';

    protected $table = 'shm_loan_settlement_unallocated_usages';

    protected $fillable = [
        'shm_loan_settlement_id',
        'shm_loan_payment_id',
        'amount',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'shm_loan_settlement_id' => 'integer',
            'shm_loan_payment_id' => 'integer',
            'amount' => 'decimal:2',
            'created_by' => 'integer',
        ];
    }

    /**
     * Settlement tujuan penggunaan dana titipan.
     *
     * @return BelongsTo<ShmLoanSettlement, $this>
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ShmLoanSettlement::class, 'shm_loan_settlement_id');
    }

    /**
     * Transaksi pembayaran sumber dana titipan.
     *
     * @return BelongsTo<ShmLoanPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(ShmLoanPayment::class, 'shm_loan_payment_id');
    }

    /**
     * Pengguna yang memproses konsumsi dana.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
