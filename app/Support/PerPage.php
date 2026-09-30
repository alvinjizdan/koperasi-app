<?php

namespace App\Support;

/**
 * Resolver jumlah baris per halaman untuk pagination server-side.
 *
 * Seluruh halaman listing memakai whitelist yang sama (10, 25, 50, 100) dengan
 * bawaan 25. Nilai di luar whitelist (mis. ?per_page=999999, ?per_page=1, atau
 * input non-numerik) jatuh kembali ke nilai bawaan sehingga pengguna tidak
 * dapat meminta ribuan baris sekaligus.
 */
final class PerPage
{
    /**
     * Pilihan page size yang boleh dipilih pengguna.
     *
     * @var array<int, int>
     */
    public const ALLOWED = [10, 25, 50, 100];

    /**
     * Jumlah baris per halaman bawaan.
     */
    public const DEFAULT = 25;

    /**
     * Kunci parameter query string yang digunakan di seluruh aplikasi.
     */
    public const PARAM = 'per_page';

    /**
     * Normalisasi input page size ke whitelist.
     *
     * @param  mixed  $value  Nilai dari query string (bisa apa saja).
     */
    public static function resolve(mixed $value): int
    {
        $candidate = (int) $value;

        return in_array($candidate, self::ALLOWED, true) ? $candidate : self::DEFAULT;
    }
}
