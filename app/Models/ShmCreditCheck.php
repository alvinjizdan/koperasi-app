<?php

namespace App\Models;

use App\Enums\ShmCreditCheckOutcome;
use App\Enums\ShmCreditCheckStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Proses credit checking pada satu pengajuan SHM.
 *
 * Satu pengajuan memiliki SATU baris credit checking (dibuat eksplisit saat
 * pengerjaan dimulai, bukan otomatis). Kolom `shm_application_id` bersifat
 * unik secara efektif karena seluruh proses memakai updateOrCreate berdasarkan
 * pengajuan; riwayat perubahan ditelusuri lewat audit log, bukan baris ganda.
 *
 * TIDAK ada integrasi BI Checking, SLIK, atau API eksternal, dan TIDAK ada
 * skor, ambang angka, blacklist, atau penolakan otomatis. `outcome` hanya hasil
 * pencatatan manual petugas.
 *
 * Pemetaan istilah: filled_by = checked_by, checked_at = completed_at,
 * result = result_summary, notes = checking_notes.
 *
 * @property int $id
 * @property int $shm_application_id
 * @property ShmCreditCheckStatus $status
 * @property ShmCreditCheckOutcome|null $outcome
 * @property int|null $filled_by
 * @property Carbon|null $started_at
 * @property Carbon|null $checked_at
 * @property string|null $result
 * @property string|null $notes
 */
class ShmCreditCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        // shm_application_id diisi server dari route model binding, bukan dari
        // payload klien. Wajib fillable karena create() melalui fill.
        'shm_application_id',
        'status',
        'outcome',
        'filled_by',
        'started_at',
        'checked_at',
        'result',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShmCreditCheckStatus::class,
            'outcome' => ShmCreditCheckOutcome::class,
            'started_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * Pengajuan SHM yang sedang diperiksa.
     *
     * @return BelongsTo<ShmApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(ShmApplication::class, 'shm_application_id');
    }

    /**
     * Petugas yang memulai/menyelesaikan pengecekan.
     *
     * Kolomnya bernama `filled_by` (warisan Ticket 44) dan dipetakan ke istilah
     * `checked_by` pada ticket workflow.
     *
     * @return BelongsTo<User, $this>
     */
    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filled_by');
    }

    public function isPending(): bool
    {
        return $this->status === ShmCreditCheckStatus::Pending;
    }

    public function isInProgress(): bool
    {
        return $this->status === ShmCreditCheckStatus::InProgress;
    }

    public function isCompleted(): bool
    {
        return $this->status === ShmCreditCheckStatus::Completed;
    }

    /**
     * Label status untuk UI.
     */
    public function statusLabel(): string
    {
        return $this->status->label();
    }

    /**
     * Label hasil untuk UI, atau '-' bila hasil belum dicatat.
     */
    public function outcomeLabel(): string
    {
        return $this->outcome?->label() ?? '-';
    }

    /**
     * Hanya credit checking yang sudah selesai.
     *
     * Dipakai halaman Hasil Marketing supaya data yang masih berjalan tidak
     * pernah muncul sebagai hasil final.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', ShmCreditCheckStatus::Completed->value);
    }

    /**
     * Batasi pada credit checking milik pengajuan seorang Marketing.
     *
     * Marketing tanpa shm.view_all hanya boleh melihat pengajuan miliknya
     * sendiri, sehingga daftar credit checking pun mengikuti batas yang sama.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForMarketing(Builder $query, int $marketingId): Builder
    {
        return $query->whereHas(
            'application',
            static fn (Builder $application): Builder => $application->where('marketing_id', $marketingId),
        );
    }

    /**
     * Scope pencarian berdasarkan nomor pengajuan, nomor nasabah, atau nama
     * nasabah. Memakai `like` (bukan `ilike`) agar konsisten dengan scope
     * pencarian master data lain dan tetap berjalan pada driver SQLite yang
     * dipakai test suite.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if ($search === null || trim($search) === '') {
            return $query;
        }

        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($search)).'%';

        return $query->whereHas('application', function (Builder $application) use ($term): void {
            $application
                ->where('application_number', 'like', $term)
                ->orWhereHas('customer', function (Builder $customer) use ($term): void {
                    $customer->where('name', 'like', $term)
                        ->orWhere('customer_number', 'like', $term)
                        ->orWhere('nik', 'like', $term);
                });
        });
    }
}
