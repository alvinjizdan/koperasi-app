<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Alokasi satu pembayaran SHM ke satu angsuran (Ticket 87).
 *
 * Satu baris = sebagian pembayaran (`shm_loan_payment_id`) yang dialokasikan
 * ke satu angsuran (`shm_loan_installment_id`), dipecah per komponen:
 * penalty → interest → principal. Unique (payment, installment) menjamin
 * tidak ada alokasi ganda — baik idempotensi maupun proteksi race.
 *
 * `penalty_amount` selalu 0 pada T87: formula penalty masih PENDING
 * BUSINESS CONFIRMATION sehingga tidak ada nilai penalty yang dikarang.
 *
 * Nilai moneter memakai decimal(18,2) — konvensi finansial SHM (bukan FLOAT).
 *
 * @property int $id
 * @property int $shm_loan_payment_id
 * @property int $shm_loan_installment_id
 * @property string $penalty_amount
 * @property string $interest_amount
 * @property string $principal_amount
 * @property string $total_amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShmLoanPaymentAllocation extends Model
{
    protected $fillable = [
        'shm_loan_payment_id',
        'shm_loan_installment_id',
        'penalty_amount',
        'interest_amount',
        'principal_amount',
        'total_amount',
    ];

    protected function casts(): array
    {
        return [
            'shm_loan_payment_id' => 'integer',
            'shm_loan_installment_id' => 'integer',
            'penalty_amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'principal_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    /**
     * Pembayaran pemilik alokasi ini.
     *
     * @return BelongsTo<ShmLoanPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(ShmLoanPayment::class, 'shm_loan_payment_id');
    }

    /**
     * Angsuran tujuan alokasi ini.
     *
     * @return BelongsTo<ShmLoanInstallment, $this>
     */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(ShmLoanInstallment::class, 'shm_loan_installment_id');
    }
}
