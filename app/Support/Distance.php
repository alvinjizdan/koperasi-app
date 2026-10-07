<?php

namespace App\Support;

/**
 * Perhitungan jarak antar titik koordinat (formula Haversine).
 *
 * CATATAN PENTING: kelas ini hanya menyediakan kemampuan menghitung jarak.
 * TIDAK ada batas jarak (radius) yang ditetapkan di sini, dan jarak TIDAK
 * pernah dijadikan syarat check-in. Nilai batas radius merupakan keputusan KSP
 * yang belum diambil (PENDING BUSINESS DECISION), sehingga sistem sengaja hanya
 * menampilkan jarak sebagai informasi.
 */
final class Distance
{
    /** Rata-rata jari-jari bumi dalam kilometer. */
    private const EARTH_RADIUS_KM = 6371.0088;

    /**
     * Jarak dalam kilometer antara dua koordinat.
     *
     * Mengembalikan null bila salah satu titik tidak memiliki koordinat lengkap,
     * sehingga tidak pernah menghasilkan angka dari data yang tidak ada.
     */
    public static function betweenInKilometers(
        ?float $latitudeFrom,
        ?float $longitudeFrom,
        ?float $latitudeTo,
        ?float $longitudeTo,
    ): ?float {
        if ($latitudeFrom === null || $longitudeFrom === null
        || $latitudeTo === null || $longitudeTo === null) {
            return null;
        }

        $deltaLatitude = deg2rad($latitudeTo - $latitudeFrom);
        $deltaLongitude = deg2rad($longitudeTo - $longitudeFrom);

        $a = sin($deltaLatitude / 2) ** 2
        + cos(deg2rad($latitudeFrom)) * cos(deg2rad($latitudeTo)) * sin($deltaLongitude / 2) ** 2;

        $centralAngle = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round(self::EARTH_RADIUS_KM * $centralAngle, 3);
    }

    /**
     * Jarak dalam meter, atau null bila koordinat belum lengkap.
     */
    public static function betweenInMeters(
        ?float $latitudeFrom,
        ?float $longitudeFrom,
        ?float $latitudeTo,
        ?float $longitudeTo,
    ): ?float {
        $kilometers = self::betweenInKilometers($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo);

        return $kilometers === null ? null : round($kilometers * 1000, 1);
    }
}
