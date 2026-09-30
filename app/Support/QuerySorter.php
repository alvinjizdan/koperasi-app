<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Penerap urutan query dengan whitelist kolom.
 *
 * Semua daftar yang mendukung parameter `sort` dan `direction` wajib melewati
 * helper ini agar hanya kolom yang terdaftar pada whitelist yang bisa
 * diurutkan (tidak ada kolom arbitrer dari input pengguna). Kolom relasi
 * ditulis lengkap dengan nama tabelnya supaya tidak ambigu saat query
 * memakai join atau withCount.
 */
class QuerySorter
{
    /**
     * Status sorting terakhir yang diterapkan oleh instance ini, apakah lewat
     * whitelist atau fallback. Dipakai controller/service untuk mengirim
     * status yang akurat ke props frontend.
     *
     * @var array{key: string|null, direction: string|null}|null
     */
    private ?array $lastStatus = null;

    /**
     * Terapkan sort key + arah pada query.
     *
     * Ketika sort tidak valid atau kosong, urutan fallback dipakai dan status
     * yang dikembalikan bernilai null (frontend menampilkan header tabel dalam
     * keadaan tidak aktif).
     *
     * @param  Builder|Relation  $query
     * @param  array<string, string>  $allowed  Map kunci publik => ekspresi kolom
     * @param  array<int, array{0: string, 1: string}>  $fallback  Urutan bawaan, mis. [['created_at', 'desc']]
     * @return array{key: string|null, direction: string|null} Status sorting untuk props frontend
     */
    public function apply($query, ?string $sort, ?string $direction, array $allowed, array $fallback = []): array
    {
        $direction = strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';

        if ($sort !== null && $sort !== '' && isset($allowed[$sort])) {
            $query->orderBy($allowed[$sort], $direction);
            // Kunci deterministik agar urutan stabil antarhalaman dengan nilai kolom yang sama.
            $query->orderBy($query->getModel()->qualifyColumn('id'), $direction);

            $this->lastStatus = ['key' => $sort, 'direction' => $direction];

            return $this->lastStatus;
        }

        foreach ($fallback as [$column, $fallbackDirection]) {
            $query->orderBy($column, $fallbackDirection);
        }

        $this->lastStatus = ['key' => null, 'direction' => null];

        return $this->lastStatus;
    }

    /**
     * Status sorting yang paling baru diterapkan oleh instance ini.
     *
     * @return array{key: string|null, direction: string|null}
     */
    public function status(): array
    {
        return $this->lastStatus ?? ['key' => null, 'direction' => null];
    }
}
