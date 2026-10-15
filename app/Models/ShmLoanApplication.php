<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Pengajuan Pinjaman SHM (Ticket 67).
 *
 * DOMAIN DECISION (CASE B): aggregate TERPISAH dari `shm_loans`. `shm_loans`
 * merepresentasikan pinjaman yang sudah berjalan (active|closed,
 * disbursed_at), sementara pengajuan memiliki lifecycle sendiri dan data yang
 * tidak ada di `shm_loans` (penghasilan/pengeluaran, jaminan, analisa,
 * catatan, persyaratan). Konversi application → loan dilakukan ticket
 * approval/pencairan berikutnya.
 *
 * Rate resmi SHM TIDAK disimpan sebagai kolom: selalu diresolusi dari
 * produk + tenor (officialRatePercent(), T82) sehingga tidak ada sumber
 * nilai kedua.
 * Total penghasilan/pengeluaran/sisa adalah DERIVED dari komponen (tidak ada
 * nilai ganda).
 *
 * Status memakai vocabulary existing (pending/approved/rejected). Ticket 68
 * (§6) mempertahankan nilai 'pending' pada kolom ini — status keputusan
 * disimpan HANYA di baris ShmApproval existing (pending/approved/rejected)
 * sehingga tidak ada sumber status ganda. 'ditangguhkan' tidak dibuat
 * (PENDING BUSINESS CONFIRMATION).
 *
 * @property int $id
 * @property string $application_number
 * @property int $shm_customer_id
 * @property int|null $shm_loan_product_id
 * @property int|null $marketing_id
 * @property int|null $shm_application_id
 * @property string $application_date
 * @property string $principal
 * @property int $tenor_months
 * @property Carbon|null $first_due_date // T82 — tanggal jatuh tempo pertama (dipilih Marketing)
 * @property string|null $purpose
 * @property int|null $loan_sequence
 * @property string|null $disbursement_method
 * @property string $status
 * @property string|null $unit_kerja
 * @property string|null $nip
 * @property string|null $telepon_rumah
 * @property string|null $tempat_lahir
 * @property Carbon|null $tanggal_lahir
 * @property int|null $jumlah_tanggungan
 * @property string|null $saldo_modal
 * @property string|null $saldo_non_modal
 * @property string|null $batas_hutang
 */
class ShmLoanApplication extends Model
{
    protected $fillable = [
        'application_number',
        'shm_customer_id',
        'shm_loan_product_id',
        'application_date',
        'principal',
        'tenor_months',
        // Ticket 82 — jatuh tempo pertama dipilih Marketing (bukan auto).
        'first_due_date',
        'purpose',
        'loan_sequence',
        'disbursement_method',
        'income_fixed',
        'income_spouse',
        'income_business',
        'expense_deduction',
        'expense_household',
        'expense_education',
        'expense_rent',
        'expense_utilities',
        'expense_transport',
        'expense_other',
        'koperasi_loan_balance',
        'other_facility',
        'institution_name',
        'kasir_note_date',
        'kasir_note_name',
        'kasir_note_text',
        'analyst_code',
        'analyst_office',
        'officer_code',
        'officer_office',
        'analysis_date',
        'analysis_value',
        'analysis_notes',
        'collector_name',
        'approved_amount',
        'provision_fee',
        'administration_fee',
        // Ticket 85 — biaya notaris custom (input user, non-negatif).
        'notary_fee',
        'calculation_method',
        'approval_notes',
        'status',
        // Ticket 68: pemilik & tautan approval (diisi server, bukan payload).
        'marketing_id',
        'shm_application_id',
        // Ticket 76-FIX — Data Pribadi adalah DATA PENGAJUAN (snapshot per
        // aplikasi), bukan data master nasabah. Nilai dimasukkan manual oleh
        // staf tanpa formula; ketiga kolom saldo/batas adalah input manual
        // per pengajuan (keputusan bisnis) — formula otomatis (bila ada)
        // tetap PENDING BUSINESS CONFIRMATION.
        'unit_kerja',
        'nip',
        'telepon_rumah',
        'tempat_lahir',
        'tanggal_lahir',
        'jumlah_tanggungan',
        'saldo_modal',
        'saldo_non_modal',
        'batas_hutang',
    ];

    protected function casts(): array
    {
        return [
            'application_date' => 'date',
            'kasir_note_date' => 'date',
            'analysis_date' => 'date',
            'tenor_months' => 'integer',
            'first_due_date' => 'date',
            'loan_sequence' => 'integer',
            // Seluruh nilai finansial memakai desimal, bukan float.
            'principal' => 'decimal:2',
            'income_fixed' => 'decimal:2',
            'income_spouse' => 'decimal:2',
            'income_business' => 'decimal:2',
            'expense_deduction' => 'decimal:2',
            'expense_household' => 'decimal:2',
            'expense_education' => 'decimal:2',
            'expense_rent' => 'decimal:2',
            'expense_utilities' => 'decimal:2',
            'expense_transport' => 'decimal:2',
            'expense_other' => 'decimal:2',
            'koperasi_loan_balance' => 'decimal:2',
            'analysis_value' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'provision_fee' => 'decimal:2',
            'administration_fee' => 'decimal:2',
            'notary_fee' => 'decimal:2',
            // Ticket 76-FIX — Data Pribadi pengajuan.
            'tanggal_lahir' => 'date',
            'jumlah_tanggungan' => 'integer',
            'saldo_modal' => 'decimal:2',
            'saldo_non_modal' => 'decimal:2',
            'batas_hutang' => 'decimal:2',
        ];
    }

    /**
     * Nasabah SHM pemilik pengajuan (TIDAK PERNAH ke `members`).
     *
     * @return BelongsTo<ShmCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(ShmCustomer::class, 'shm_customer_id');
    }

    /**
     * Produk pinjaman (opsional).
     *
     * @return BelongsTo<ShmLoanProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(ShmLoanProduct::class, 'shm_loan_product_id');
    }

    /**
     * Section 5 — daftar jaminan.
     *
     * @return HasMany<ShmLoanApplicationCollateral, $this>
     */
    public function collaterals(): HasMany
    {
        return $this->hasMany(ShmLoanApplicationCollateral::class);
    }

    /**
     * Section 4 — daftar barang agunan kredit.
     *
     * @return HasMany<ShmLoanApplicationAsset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(ShmLoanApplicationAsset::class);
    }

    /**
     * Section 11 — daftar persyaratan tambahan.
     *
     * @return HasMany<ShmLoanApplicationRequirement, $this>
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(ShmLoanApplicationRequirement::class);
    }

    /** Total penghasilan bulanan (derived). */
    public function totalIncome(): float
    {
        return round(
            (float) $this->income_fixed
            + (float) $this->income_spouse
            + (float) $this->income_business,
            2,
        );
    }

    /** Total pengeluaran bulanan (derived). */
    public function totalExpense(): float
    {
        return round(
            (float) $this->expense_deduction
            + (float) $this->expense_household
            + (float) $this->expense_education
            + (float) $this->expense_rent
            + (float) $this->expense_utilities
            + (float) $this->expense_transport
            + (float) $this->expense_other,
            2,
        );
    }

    /**
     * Sisa penghasilan = total penghasilan − total pengeluaran.
     * Tidak ada aturan DSCR/eligibility — murni selisih angka (derived).
     */
    public function remainingIncome(): float
    {
        return round($this->totalIncome() - $this->totalExpense(), 2);
    }

    /**
     * Rate produk yang berlaku untuk pengajuan ini (T82).
     *
     * Rate TIDAK lagi universal 2%: resolusi = f(product, tenor) dari tier
     * rate produk. Tanpa produk, tanpa tenor valid, atau produk tanpa tier
     * yang menutup tenor → null (tidak ada fallback rate).
     */
    public function officialRatePercent(): ?float
    {
        $product = $this->product;

        if ($product === null || ! $product->is_active) {
            return null;
        }

        $tier = $product->rateTierFor((int) $this->tenor_months);

        return $tier === null ? null : (float) $tier->rate_percent;
    }

    /**
     * Marketing pemilik pengajuan (dari authenticated user saat create).
     *
     * @return BelongsTo<User, $this>
     */
    public function marketing(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marketing_id');
    }

    /**
     * Mirror pengajuan legacy (`shm_applications`) yang membawa baris
     * ShmApproval modul Approval SHM existing (Ticket 68). Null selama
     * pengajuan belum diajukan approval.
     *
     * @return BelongsTo<ShmApplication, $this>
     */
    public function shmApplication(): BelongsTo
    {
        return $this->belongsTo(ShmApplication::class, 'shm_application_id');
    }

    /**
     * Keputusan approval pengajuan ini (dari mirror; null = belum diajukan).
     *
     * Materikan relasi latestOfMany sekali agar aman dipanggil berulang
     * (pola ShmApprovalController).
     */
    public function approval(): ?ShmApproval
    {
        return $this->shmApplication?->latestApproval;
    }

    /**
     * Pinjaman hasil pencairan pengajuan ini (Ticket 69). Null = belum
     * dicairkan; setelah pencairan tepat satu baris (unique di database).
     *
     * @return HasOne<ShmLoan, $this>
     */
    public function loan(): HasOne
    {
        return $this->hasOne(ShmLoan::class, 'shm_loan_application_id');
    }

    /**
     * Pengajuan terkunci setelah approval dimulai (pending/decided):
     * tidak dapat diedit sampai ada kontrak resubmit business.
     */
    public function isLocked(): bool
    {
        return $this->shm_application_id !== null;
    }

    /** Apakah pengajuan masih menunggu keputusan (peta BARU)? */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
