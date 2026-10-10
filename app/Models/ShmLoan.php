<?php

namespace App\Models;

use Database\Factories\ShmLoanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Record Pinjaman milik nasabah SHM (Ticket 64).
 *
 * Domain finansial SHM TERPISAH dari `App\Models\Loan` koperasi: tidak ada
 * relasi ke `App\Models\Member`, tidak memakai tabel angsuran/pembayaran
 * koperasi, dan tidak membaca business rule pinjaman koperasi.
 *
 * Rate resmi SHM = 2% (`rate_percent` DEFAULT 2). Periode rate dan metode
 * angsuran TIDAK pernah diisi otomatis oleh sistem: keduanya hanya terisi
 * bila pengguna memilih secara eksplisit, karena periodisasi dan formula
 * masih PENDING BUSINESS CONFIRMATION.
 *
 * @property int $id
 * @property string $loan_number
 * @property int $shm_customer_id
 * @property int $shm_loan_product_id
 * @property int|null $shm_loan_application_id // Ticket 69 — pengajuan asal pencairan
 * @property string $principal
 * @property int $tenor_months
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
    /** @use HasFactory<ShmLoanFactory> */
    use HasFactory;

    /** Rate bunga/jasa resmi SHM (persen) sesuai requirement terbaru. */
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
        'tenor_months',
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
            'tenor_months' => 'integer',
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
     * Apakah record ini menyimpan pilihan eksplisit metode & periode rate?
     *
     * Bila tidak, tabel angsuran tidak dapat dibangun karena formula SHM
     * masih PENDING BUSINESS CONFIRMATION.
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
