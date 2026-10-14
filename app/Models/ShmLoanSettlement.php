<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Record Settlement / Pelunasan Pinjaman SHM (Ticket 89).
 *
 * Domain finansial SHM TERPISAH dari koperasi: tidak berelasi ke `Loan`,
 * `InstallmentSchedule`, maupun `Member`.
 *
 * Satu record mewakili SATU pelunasan pinjaman. Referensi settlement dibuat
 * oleh SERVER (bukan payload klien) dan UNIQUE di database.
 *
 * Status kontraktual (lifecycle Model B — T89B):
 * - STATUS_PENDING: settlement diajukan, menunggu approval.
 * - STATUS_APPROVED: disetujui, menunggu pembayaran pelunasan.
 * - STATUS_PAYMENT_RECORDED: pembayaran pelunasan (Payment T86) sudah
 *   ditautkan — pembayaran BELUM dianggap terverifikasi.
 * - STATUS_PAYMENT_VERIFIED: pembayaran diverifikasi dan memenuhi
 *   kewajiban settlement.
 * - STATUS_COMPLETED: settlement selesai — satu-satunya status yang diikuti
 *   penutupan pinjaman (active → closed).
 * - STATUS_REJECTED: pengajuan ditolak; pinjaman pengajuan ulang dibuat
 *   sebagai baris baru (partial unique hanya pada completed).
 *
 * YANG SENGAJA TIDAK ADA (bukan kelalaian):
 * - kolom persentase discount default — policy master data-driven (T89B),
 *   tanpa angka seed;
 * - logika approval authority — role mapping PENDING BUSINESS CONFIRMATION;
 * - kolom pembayaran baru — pembayaran tetap di `shm_loan_payments` (T86).
 *
 * YANG SENGAJA TIDAK ADA (bukan kelalaian):
 * - kolom diskon/potongan — rule discount PENDING BUSINESS CONFIRMATION;
 * - kolom kanal pembayaran settlement — mekanisme pembayaran PENDING;
 * - logika approval — approval requirement PENDING.
 *
 * @property int $id
 * @property int $shm_loan_id
 * @property string $settlement_reference
 * @property Carbon $settlement_date
 * @property string $outstanding_principal
 * @property string $outstanding_interest
 * @property string $outstanding_penalty
 * @property string $unallocated_payment_amount
 * @property string $settlement_amount
 * @property string $overpayment_amount
 * @property string $status
 * @property int|null $shm_loan_payment_id
 * @property Carbon|null $payment_recorded_at
 * @property Carbon|null $payment_verified_at
 * @property int|null $payment_verified_by
 * @property int|null $discount_policy_id
 * @property bool $discount_eligible
 * @property bool $discount_applied
 * @property int|null $remaining_months
 * @property string|null $discount_percent
 * @property string|null $remaining_interest_amount
 * @property string|null $discount_base_amount
 * @property string|null $discount_amount
 * @property string|null $interest_after_discount_amount
 * @property string $status
 * @property string|null $notes
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShmLoanSettlement extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAYMENT_RECORDED = 'payment_recorded';

    public const STATUS_PAYMENT_VERIFIED = 'payment_verified';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'shm_loan_id',
        'settlement_reference',
        'settlement_date',
        'outstanding_principal',
        'outstanding_interest',
        'outstanding_penalty',
        'unallocated_payment_amount',
        'settlement_amount',
        'overpayment_amount',
        'status',
        'notes',
        'created_by',
        // T89B — workflow Model B.
        'shm_loan_payment_id',
        'payment_recorded_at',
        'payment_verified_at',
        'payment_verified_by',
        // T89B — snapshot discount (immutable).
        'discount_policy_id',
        'discount_eligible',
        'discount_applied',
        'remaining_months',
        'discount_percent',
        'remaining_interest_amount',
        'discount_base_amount',
        'discount_amount',
        'interest_after_discount_amount',
    ];

    protected function casts(): array
    {
        return [
            'shm_loan_id' => 'integer',
            'settlement_date' => 'date',
            'outstanding_principal' => 'decimal:2',
            'outstanding_interest' => 'decimal:2',
            'outstanding_penalty' => 'decimal:2',
            'unallocated_payment_amount' => 'decimal:2',
            'settlement_amount' => 'decimal:2',
            'overpayment_amount' => 'decimal:2',
            'shm_loan_payment_id' => 'integer',
            'payment_recorded_at' => 'datetime',
            'payment_verified_at' => 'datetime',
            'payment_verified_by' => 'integer',
            'discount_policy_id' => 'integer',
            'discount_eligible' => 'boolean',
            'discount_applied' => 'boolean',
            'remaining_months' => 'integer',
            'discount_percent' => 'decimal:2',
            'remaining_interest_amount' => 'decimal:2',
            'discount_base_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'interest_after_discount_amount' => 'decimal:2',
            'created_by' => 'integer',
        ];
    }

    /**
     * Pembayaran pelunasan (Payment T86) yang ditautkan ke settlement ini.
     *
     * @return BelongsTo<ShmLoanPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(ShmLoanPayment::class, 'shm_loan_payment_id');
    }

    /**
     * Policy diskon yang di-SNAPSHOT saat settlement dibuat (immutable).
     *
     * @return BelongsTo<ShmSettlementDiscountPolicy, $this>
     */
    public function discountPolicy(): BelongsTo
    {
        return $this->belongsTo(ShmSettlementDiscountPolicy::class, 'discount_policy_id');
    }

    /**
     * Pengguna yang memverifikasi pembayaran pelunasan.
     *
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_verified_by');
    }

    /**
     * Pinjaman SHM pemilik settlement ini.
     *
     * @return BelongsTo<ShmLoan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(ShmLoan::class, 'shm_loan_id');
    }

    /**
     * Pengguna yang mengonfirmasi pelunasan (server-side, bukan payload).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
