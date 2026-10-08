<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Keputusan approval pengajuan SHM, single stage untuk MVP.
 *
 * Multi-level approval adalah PENDING BUSINESS DECISION dan tidak
 * diimplementasikan. Batas kewenangan, siapa yang berhak menyetujui, dan
 * syarat dokumen sebelum approve juga belum diputuskan KSP.
 *
 * decided_by diisi dari pengguna yang benar-benar memutuskan, bukan dari
 * payload klien.
 *
 * @property int $id
 * @property int $shm_application_id
 * @property int|null $decided_by
 * @property string $status
 * @property Carbon|null $decided_at
 * @property string|null $notes
 */
class ShmApproval extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        // decided_by berasal dari pengguna yang memutuskan, bukan dari payload.
        'shm_application_id',
        'decided_by',
        'decided_at',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ShmApplication::class, 'shm_application_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isDecided(): bool
    {
        return $this->status !== self::STATUS_PENDING;
    }

    /**
     * Ticket 57 — jumlah approval yang menunggu keputusan untuk cakupan
     * pengguna.
     *
     * Pending HANYA didefinisikan oleh kolom status tabel ini
     * (STATUS_PENDING) — bukan oleh status pengajuan, timestamp, memorandum,
     * atau dokumen. Dihitung sebagai satu query COUNT dengan scope
     * kepemilikan pada pengajuan terkait; baris approval tidak dimuat.
     */
    public static function pendingCountForUser(bool $canViewAll, int $marketingId): int
    {
        return (int) self::query()
            ->where('status', self::STATUS_PENDING)
            ->whereHas('application', function (Builder $application) use ($canViewAll, $marketingId): void {
                if (! $canViewAll) {
                    $application->where('marketing_id', $marketingId);
                }
            })
            ->count();
    }

    /**
     * Ticket 58 — ringkasan antrean approval per marketing.
     *
     * Satu grouped aggregate query di database:
     *   SELECT shm_applications.marketing_id, COUNT(*)
     *   FROM shm_approvals
     *   JOIN shm_applications ON ...
     *   WHERE shm_approvals.status = STATUS_PENDING
     *   GROUP BY shm_applications.marketing_id
     *
     * Tanpa `shm.view_all`, hasil hanya berisi marketing_id pengguna sendiri,
     * jadi:
     *   - user tanpa pending menerima array kosong (bukan daftar marketing
     *     lain dengan angka 0),
     *   - user dengan pending menerima tepat satu baris.
     *
     * `shm.view_all` menerima seluruh marketing yang memiliki pending approval
     * dalam cakupan SHM — marketing dengan 0 pending tidak dimuat, sehingga
     * tidak ada query tambahan per marketing dan tidak ada pembocoran volume
     * orang lain.
     *
     * Nama marketing diambil dari tabel users (kolom `name`); relasi
     * `ShmApplication.marketing` sudah ada dan merujuk ke users.id.
     *
     * @return array<int, array{marketing_id: int, name: string, pending_count: int}>
     */
    public static function pendingCountByMarketing(bool $canViewAll, int $ownMarketingId): array
    {
        $builder = self::query()
            ->selectRaw('shm_applications.marketing_id, COUNT(*) as pending_count')
            ->where('shm_approvals.status', self::STATUS_PENDING)
            ->join('shm_applications', 'shm_approvals.shm_application_id', '=', 'shm_applications.id')
            ->groupBy('shm_applications.marketing_id');

        // Tanpa shm.view_all, GROUP BY hanya mengandung satu
        // marketing_id — sehingga hasil selalu tepat satu baris.
        // Ini juga mencegah SQL melebarkan hasil karena kolom ambiguous.
        if (! $canViewAll) {
            $builder->where('shm_applications.marketing_id', $ownMarketingId);
        }

        $rows = (clone $builder)
            ->pluck('pending_count', 'marketing_id');

        if ($rows->isEmpty()) {
            return [];
        }

        // Nama marketing dimuat terpisah supaya hanya satu query hubungan
        // yang dieksekusi, dibatasi pada marketing_id yang benar-benar
        // muncul di hasil aggregate.
        $marketingIds = $rows->keys()->all();

        $names = User::query()
            ->whereIn('id', $marketingIds)
            ->pluck('name', 'id');

        $list = collect($rows)
            ->map(fn (int $pendingCount, int $marketingId): array => [
                'marketing_id' => $marketingId,
                'name' => $names[$marketingId] ?? '-',
                'pending_count' => $pendingCount,
            ])
            ->sort(fn (array $a, array $b): int => match (true) {
                $b['pending_count'] > $a['pending_count'] => 1,
                $b['pending_count'] < $a['pending_count'] => -1,
                default => $a['name'] <=> $b['name'],
            })
            ->values()
            ->all();

        return $list;
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];
    }
}
