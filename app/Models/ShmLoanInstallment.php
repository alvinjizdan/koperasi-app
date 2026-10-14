<?php

namespace App\Models;

use Database\Factories\ShmLoanInstallmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Record Angsuran / Jadwal Pembayaran Pinjaman SHM (Ticket 74).
 *
 * Domain finansial SHM TERPISAH dari `App\Models\InstallmentSchedule` koperasi:
 * tidak berelasi ke `Loan` atau `Member`, dan tidak membaca aturan pinjaman koperasi.
 *
 * Status kontraktual:
 * - STATUS_SCHEDULED: Angsuran terdaftar dalam jadwal dan menunggu jatuh tempo/pembayaran.
 * - STATUS_PAID: Angsuran telah lunas.
 * Status lanjutan (partially_paid, overdue, cancelled) berstatus PENDING BUSINESS CONFIRMATION.
 *
 * Immutabilitas jadwal:
 * - Komponen jadwal (`installment_number`, `due_date`, `principal_amount`, `interest_amount`, `total_amount`)
 *   ditetapkan saat pembentukan jadwal (snapshot) dan tidak boleh diubah oleh proses pembayaran biasa.
 *
 * @property int $id
 * @property int $shm_loan_id
 * @property int $installment_number
 * @property string $due_date
 * @property string $principal_amount
 * @property string $interest_amount
 * @property string $total_amount
 * @property string $paid_amount
 * @property string $paid_interest_amount // Ticket 87 — split paid bunga
 * @property string $paid_principal_amount // Ticket 87 — split paid pokok
 * @property string $remaining_amount
 * @property string $status
 * @property string|null $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShmLoanInstallment extends Model
{
    /** @use HasFactory<ShmLoanInstallmentFactory> */
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'shm_loan_id',
        'installment_number',
        'due_date',
        'principal_amount',
        'interest_amount',
        'total_amount',
        'paid_amount',
        // Ticket 87 — split paid per komponen (alokasi bunga → pokok).
        'paid_interest_amount',
        'paid_principal_amount',
        'remaining_amount',
        'status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'shm_loan_id' => 'integer',
            'installment_number' => 'integer',
            'due_date' => 'date',
            'principal_amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'paid_interest_amount' => 'decimal:2',
            'paid_principal_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Pinjaman SHM pemilik angsuran.
     *
     * @return BelongsTo<ShmLoan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(ShmLoan::class, 'shm_loan_id');
    }

    /**
     * Baris alokasi pembayaran ke angsuran ini (Ticket 87).
     *
     * @return HasMany<ShmLoanPaymentAllocation, $this>
     */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(ShmLoanPaymentAllocation::class, 'shm_loan_installment_id');
    }

    /**
     * Sisa bunga yang belum dibayar (interest_amount − paid_interest_amount).
     */
    public function outstandingInterest(): string
    {
        return number_format(
            max(0.0, (float) $this->interest_amount - (float) $this->paid_interest_amount),
            2,
            '.',
            '',
        );
    }

    /**
     * Sisa pokok yang belum dibayar (principal_amount − paid_principal_amount).
     */
    public function outstandingPrincipal(): string
    {
        return number_format(
            max(0.0, (float) $this->principal_amount - (float) $this->paid_principal_amount),
            2,
            '.',
            '',
        );
    }
}
