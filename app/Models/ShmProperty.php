<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Objek tanah/bangunan yang menyertai pengajuan SHM/SHGB.
 *
 * certificate_type hanya membedakan jenis sertifikat SHM dan SHGB. Itu
 * pembeda teknis, bukan pernyataan kelayakan. Aturan legal SHM versus SHGB
 * masih PENDING BUSINESS DECISION.
 *
 * Koordinat bersifat nullable dan hanya informatif untuk membantu Marketing
 * menemukan objek. Koordinat bukan bukti kepemilikan dan tidak dipakai
 * sebagai syarat apa pun.
 *
 * @property int $id
 * @property int $shm_application_id
 * @property string $certificate_type
 * @property string|null $certificate_number
 * @property string|null $owner_name
 * @property string $address
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $notes
 */
class ShmProperty extends Model
{
    use HasFactory;

    public const TYPE_SHM = 'SHM';

    public const TYPE_SHGB = 'SHGB';

    protected $fillable = [
        // Diisi server dari route model binding, bukan dari payload klien.
        'shm_application_id',
        'certificate_type',
        'certificate_number',
        'owner_name',
        'address',
        'latitude',
        'longitude',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            // Desimal agar koordinat tidak bergeser saat disimpan.
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ShmApplication::class, 'shm_application_id');
    }

    /**
     * Lokasi objek, atau null bila koordinat belum diisi.
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

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * @return array<int, string>
     */
    public static function certificateTypes(): array
    {
        return [self::TYPE_SHM, self::TYPE_SHGB];
    }
}
