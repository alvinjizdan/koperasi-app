<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Record Pinjaman milik nasabah SHM (Ticket 64).
 *
 * Domain finansial SHM TERPISAH dari `App\Models\Loan` koperasi: tidak ada
 * relasi ke `App\Models\Member`, tidak memakai tabel angsuran/pembayaran
 * koperasi, dan tidak membaca business rule pinjaman koperasi.
 *
 * Rate resmi SHM = f(produk, tenor) dari tier rate produk (T82 — lihat
 * ShmProductRateService); `rate_percent` DEFAULT 2 hanya nilai historis lama
 * (T79) dan bukan fallback. Periode rate dan metode angsuran TIDAK pernah
 * diisi otomatis oleh sistem: keduanya hanya terisi bila pengguna memilih
 * secara eksplisit (baseline resmi: monthly + flat, T79).
 *
 * @property int $id
 * @property string $loan_number
 * @property int $shm_customer_id
 * @property int $shm_loan_product_id
 * @property int|null $shm_loan_application_id // Ticket 69 — pengajuan asal pencairan
 * @property string $principal
 * @property int $tenor_months
 * @property string|null $first_due_date // T82 — jatuh tempo pertama dari pengajuan disetujui
 * @property string $rate_percent
 * @property string|null $rate_period
 * @property string|null $calculation_method
 * @property string|null $location
 * @property string|null $purpose
 * @property string $status
 * @property string|null $disbursed_at
 * @property string|null $note
 */
class ShmLoan extends Model
{
    use HasFactory;

    /**
     * LEGACY (T73/T79): angka tunggal 2% pernah menjadi rate resmi SHM.
     *
     * SEJAK T82 rate pinjaman = f(product, tenor) dari tier rate produk
     * (ShmProductRateService) — konstanta ini TIDAK dipakai lagi sebagai
     * rate/fallback pinjaman, pencairan, maupun pembangkitan jadwal; hanya
     * modul simulasi legacy yang masih menyebutnya.
     */
    public const OFFICIAL_RATE_PERCENT = 2.0;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'loan_number',
        'shm_customer_id',
        'shm_loan_product_id',
        // Ticket 69: diisi server saat pencairan (bukan payload klien).
        'shm_loan_application_id',
        'principal',
        // Ticket 85: snapshot biaya pencairan (immutable historical).
        'admin_fee',
        'provision_fee',
        'insurance_fee',
        'notary_fee',
        'principal_savings',
        'mandatory_savings',
        'total_deduction',
        'net_disbursement',
        'tenor_months',
        // Ticket 82: dibawa dari pengajuan yang disetujui (bukan hitung auto).
        'first_due_date',
        'rate_percent',
        'rate_period',
        'calculation_method',
        'location',
        'purpose',
        'status',
        'disbursed_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'decimal:2',
            'admin_fee' => 'decimal:2',
            'provision_fee' => 'decimal:2',
            'insurance_fee' => 'decimal:2',
            'notary_fee' => 'decimal:2',
            'principal_savings' => 'decimal:2',
            'mandatory_savings' => 'decimal:2',
            'total_deduction' => 'decimal:2',
            'net_disbursement' => 'decimal:2',
            'tenor_months' => 'integer',
            'first_due_date' => 'date',
            'rate_percent' => 'decimal:4',
            'disbursed_at' => 'date',
        ];
    }

    /**
     * Nasabah SHM pemilik pinjaman.
     *
     * @return BelongsTo<ShmCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(ShmCustomer::class, 'shm_customer_id');
    }

    /**
     * Produk pinjaman SHM.
     *
     * @return BelongsTo<ShmLoanProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(ShmLoanProduct::class, 'shm_loan_product_id');
    }

    /**
     * Pengajuan asal pencairan (Ticket 69). Null bila pinjaman dibuat di
     * luar alur pencairan pengajuan. Unique di database: satu pengajuan
     * hanya pernah menghasilkan satu pinjaman.
     *
     * @return BelongsTo<ShmLoanApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(ShmLoanApplication::class, 'shm_loan_application_id');
    }

    /**
     * Jadwal / daftar angsuran pinjaman SHM (Ticket 74).
     *
     * @return HasMany<ShmLoanInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(ShmLoanInstallment::class, 'shm_loan_id')->orderBy('installment_number');
    }

    /**
     * Resolve pinjaman berdasarkan nilai parameter route yang diterima.
     *
     * Digunakan oleh Laravel Route Model Binding (RMB).
     * Dipanggil dua bentuk:
     * - resolveRouteBinding($value, $field) ketika framework memberitahu
     *   kolom pengenal yang digunakan (default $field = 'id').
     * - resolveRouteBinding($value) ketika framework tidak dapat
     *   menentukan kolom pengenal (mis. nilai tidak dapat di-parse
     *   sebagai int).
     *
     * Strategi:
     * - Jika $field adalah 'id' (default), prioritaskan pencarian
     *   berdasarkan id (jika $value adalah numerik), lalu fallback
     *   ke loan_number.
     * - Jika $field bukan 'id', kembalikan null (tidak dikenal).
     */
    public function resolveRouteBinding($value, $field = null): ?ShmLoan
    {
        // Jika $field adalah 'id' (default atau eksplisit),
        // prioritaskan pencarian berdasarkan id jika $value adalah numerik,
        // lalu fallback ke loan_number.
        if ($field === null || $field === 'id') {
            if (is_numeric($value)) {
                return $this->resolveRouteBindingUsingId((int) $value);
            }

            // Jika nilai bukan numerik dan $field='id', coba
            // pencarian berdasarkan loan_number sebagai fallback.
            return ShmLoan::where('loan_number', (string) $value)->first();
        }

        // Jika $field bukan 'id', tidak diketahui; kembalikan null.
        return null;
    }

    /**
     * Resolve pinjaman berdasarkan id.
     */
    protected function resolveRouteBindingUsingId(int $id): ?ShmLoan
    {
        return ShmLoan::find($id);
    }

    /**
     * Record settlement / pelunasan pinjaman SHM (Ticket 89).
     *
     * @return HasOne<ShmLoanSettlement, $this>
     */
    public function settlement(): HasOne
    {
        return $this->hasOne(ShmLoanSettlement::class, 'shm_loan_id')->latestOfMany();
    }

    /**
     * Seluruh record settlement / pelunasan pinjaman SHM.
     *
     * @return HasMany<ShmLoanSettlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(ShmLoanSettlement::class, 'shm_loan_id')->orderByDesc('id');
    }

    /**
     * Riwayat koreksi settlement pada pinjaman ini.
     *
     * @return HasMany<ShmSettlementCorrection, $this>
     */
    public function settlementCorrections(): HasMany
    {
        return $this->hasMany(ShmSettlementCorrection::class, 'shm_loan_id')->orderByDesc('id');
    }

    /**
     * Transaksi pembayaran pinjaman SHM (Ticket 86).
     *
     * Murni transaksi pembayaran — T86 tidak pernah mengubah angsuran maupun
     * saldo pokok dari sini. Terbaru di atas (pembayaran terakhir dulu).
     *
     * @return HasMany<ShmLoanPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ShmLoanPayment::class, 'shm_loan_id')
            ->orderByDesc('payment_date')
            ->orderByDesc('id');
    }

    /**
     * Saldo pokok (outstanding) pinjaman.
     *
     * Modul pembayaran/angsuran pinjaman SHM BELUM ada (koleksi & pelunasan
     * ditangani ticket terpisah), sehingga tidak ada pembayaran yang dapat
     * dikurangkan: selama pinjaman berjalan saldo pokok = plafon, dan pinjaman
     * berstatus closed ditampilkan sebagai 0. Nilai ini BUKAN hasil formula
     * pelunasan apa pun.
     */
    public function outstanding(): float
    {
        if ($this->status === self::STATUS_CLOSED) {
            return 0.0;
        }

        return (float) $this->principal;
    }

    /**
     * Total kewajiban berjalan yang belum dibayar (pokok + bunga).
     *
     * Berdasarkan jadwal angsuran aktif (T107). Nilai 0 jika pinjaman closed.
     */
    public function totalOwed(): float
    {
        if ($this->status === self::STATUS_CLOSED) {
            return 0.0;
        }

        return (float) $this->installments()
            ->where('status', ShmLoanInstallment::STATUS_SCHEDULED)
            ->sum('remaining_amount');
    }

    /**
     * Angsuran belum lunas pertama sesuai urutan kronologis (T151/T003).
     */
    public function currentPayableInstallment(): ?ShmLoanInstallment
    {
        return $this->installments()
            ->where('status', ShmLoanInstallment::STATUS_SCHEDULED)
            ->where('remaining_amount', '>', 0)
            ->orderBy('installment_number')
            ->first();
    }

    /**
     * Settlement aktif yang mengunci operasi pembayaran angsuran reguler (T108/T003).
     */
    public function activeSettlement(): ?ShmLoanSettlement
    {
        return ShmLoanSettlement::query()
            ->where('shm_loan_id', $this->id)
            ->whereIn('status', [
                ShmLoanSettlement::STATUS_PENDING,
                ShmLoanSettlement::STATUS_APPROVED,
                ShmLoanSettlement::STATUS_PAYMENT_RECORDED,
                ShmLoanSettlement::STATUS_PAYMENT_VERIFIED,
            ])
            ->latest('id')
            ->first();
    }

    /**
     * Apakah record ini menyimpan pilihan eksplisit metode & periode rate?
     *
     * Bila tidak, tabel angsuran tidak dapat dibangun: pembangkitan jadwal
     * membutuhkan kontrak perhitungan eksplisit (baseline resmi flat +
     * monthly, T79) tanpa menebak default.
     */
    public function hasExplicitCalculation(): bool
    {
        return $this->calculation_method !== null && $this->rate_period !== null;
    }

    /**
     * Scope filter status pinjaman ('active' | 'closed' | semua).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if (empty($status) || $status === 'all') {
            return $query;
        }

        return $query->where('status', $status);
    }

    /**
     * Scope pencarian nomor pinjaman / nasabah (nama & nomor) / produk / tujuan.
     *
     * Memakai `like` agar konsisten dengan scope pencarian master data lain
     * dan tetap berjalan pada SQLite yang dipakai test suite.
     *
     * Pencarian nasabah hanya menyentuh `shm_customers` — tidak pernah
     * `members` — sehingga nomor/kode nasabah koperasi tidak pernah cocok
     * dengan pinjaman SHM.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($search)).'%';

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('loan_number', 'like', $term)
                ->orWhere('purpose', 'like', $term)
                ->orWhere('location', 'like', $term)
                ->orWhereHas('customer', fn (Builder $customer) => $customer
                    ->where('name', 'like', $term)
                    ->orWhere('customer_number', 'like', $term))
                ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', $term));
        });
    }

    /**
     * Ticket 70 — scope kepemilikan pinjaman untuk halaman daftar.
     *
     * Kepemilikan mengikuti PENGAJUAN asal pencairan
     * (`shm_loan_applications.marketing_id`) karena `shm_loans` tidak
     * memiliki kolom `marketing_id` sendiri. Pinjaman tanpa pengajuan asal
     * (`shm_loan_application_id` NULL) tidak berada dalam lingkup kepemilikan
     * siapa pun sehingga hanya pemegang `shm.view_all` yang melihatnya.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOwnedBy(Builder $query, int $marketingId): Builder
    {
        return $query->whereHas(
            'application',
            fn (Builder $application) => $application->where('marketing_id', $marketingId),
        );
    }
}
