<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu aktivitas/kunjungan Marketing kepada seorang anggota.
 *
 * Baris ini dibuat HANYA ketika Marketing melakukan check-in. Tidak ada
 * pencatatan lokasi berkala atau pelacakan perjalanan.
 *
 * checked_in_at selalu berasal dari waktu sistem, bukan dari input pengguna.
 * marketing_id juga selalu berasal dari pengguna yang sedang login.
 *
 * @property int $id
 * @property int $marketing_id
 * @property int $member_id
 * @property Carbon $checked_in_at
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $result
 * @property string|null $notes
 * @property string|null $idempotency_key
 */
class MarketingActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'marketing_id',
        'member_id',
        'checked_in_at',
        'latitude',
        'longitude',
        'result',
        'notes',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            // Desimal agar koordinat tidak bergeser saat disimpan.
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /**
     * Marketing yang melakukan aktivitas.
     *
     * @return BelongsTo<User, $this>
     */
    public function marketing(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marketing_id');
    }

    /**
     * Anggota yang menjadi tujuan kunjungan.
     *
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Batasi query pada marketing tertentu.
     *
     * Dipakai untuk memastikan Marketing hanya melihat aktivitasnya sendiri.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForMarketing(Builder $query, int $marketingId): Builder
    {
        return $query->where('marketing_id', $marketingId);
    }

    /**
     * Locasi pada aktivitas ini, atau null bila koordinat belum tersedia.
     *
     * @return array{latitude: float|null, longitude: float|null}
     */
    public function location(): array
    {
        return [
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
        ];
    }
}
