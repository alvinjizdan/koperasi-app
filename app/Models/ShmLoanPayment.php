<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Transaksi Pembayaran Pinjaman SHM (Ticket 86).
 *
 * Satu baris = SATU transaksi pembayaran yang sudah tersimpan terhadap satu
 * pinjaman SHM. Domain terpisah dari `App\Models\InstallmentPayment`
 * koperasi: tidak berelasi ke `Loan`, `InstallmentSchedule`, maupun `Member`.
 *
 * Status kontraktual:
 * - STATUS_POSTED: pembayaran tersimpan (transaksi finansial nyata).
 * - STATUS_REVERSED: dicadangkan untuk reversal (Ticket 88) — T86 TIDAK
 *   pernah menulis nilai ini.
 *
 * Yang SENGAJA tidak ada pada model ini (bukan kelalaian):
 * - kolom alokasi principal/bunga/denda — alokasi adalah Ticket 87;
 * - logika penutupan pinjaman / pelunasan — Ticket 89;
 * - pembatasan nominal ke angsuran — overpayment ditangani kontrak T87,
 *   sehingga `amount` selalu menyimpan nilai yang dikirim persis.
 *
 * @property int $id
 * @property int $shm_loan_id
 * @property string $payment_reference
 * @property Carbon $payment_date
 * @property string $amount
 * @property string|null $payment_channel
 * @property string $status
 * @property string|null $notes
 * @property int $created_by
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShmLoanPayment extends Model
{
    /** Transaksi pembayaran tersimpan (nilai default & satu-satunya penulisan T86). */
    public const STATUS_POSTED = 'posted';

    /** Dicadangkan untuk reversal (Ticket 88); tidak pernah ditulis T86. */
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'shm_loan_id',
        'payment_reference',
        'payment_date',
        'amount',
        'payment_channel',
        'status',
        'notes',
        'created_by',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'shm_loan_id' => 'integer',
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'created_by' => 'integer',
            'settlement_released_at' => 'datetime',
        ];
    }

    /**
     * Apakah pembayaran ini telah dilepas dari domain settlement ke domain reguler (T89B replacement)?
     */
    public function isReleasedToRegularDomain(): bool
    {
        return $this->settlement_released_at !== null;
    }

    /**
     * Pinjaman SHM pemilik transaksi pembayaran ini.
     *
     * @return BelongsTo<ShmLoan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(ShmLoan::class, 'shm_loan_id');
    }

    /**
     * Pengguna yang mencatat pembayaran (server-side, bukan payload).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Baris alokasi pembayaran ini ke angsuran (Ticket 87).
     *
     * @return HasMany<ShmLoanPaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(ShmLoanPaymentAllocation::class, 'shm_loan_payment_id');
    }

    /**
     * Settlement pelunasan yang menautkan pembayaran ini (T89B), bila ada.
     *
     * T108 — discriminator batas transaksi: pembayaran dengan tautan
     * settlement adalah pembayaran PELUNASAN dan DILARANG dialokasikan ke
     * angsuran; pembayaran tanpa tautan adalah pembayaran reguler.
     */
    public function settlement(): HasOne
    {
        return $this->hasOne(ShmLoanSettlement::class, 'shm_loan_payment_id');
    }

    /**
     * Total nominal yang sudah teralokasi ke angsuran (Σ total_amount).
     */
    public function allocatedAmount(): string
    {
        return number_format(
            (float) $this->allocations()->sum('total_amount'),
            2,
            '.',
            '',
        );
    }

    /**
     * Nominal yang belum teralokasi (amount − allocated). Negatif tidak
     * mungkin: alokasi tidak pernah melebihi amount pembayaran.
     */
    public function unallocatedAmount(): string
    {
        return number_format(
            (float) $this->amount - (float) $this->allocatedAmount(),
            2,
            '.',
            '',
        );
    }

    /**
     * Apakah pembayaran sudah teralokasi penuh (sisa = 0)?
     */
    public function isFullyAllocated(): bool
    {
        return (float) $this->unallocatedAmount() <= 0.0;
    }
}
