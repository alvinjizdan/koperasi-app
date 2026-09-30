<?php

namespace App\Http\Controllers\Concerns;

use App\Support\PerPage;
use App\Support\QuerySorter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

/**
 * Sorting server-side dengan whitelist kolom untuk controller.
 *
 * Membaca parameter `sort` dan `direction` dari request lalu menerapkannya
 * lewat App\Support\QuerySorter agar hanya kolom pada whitelist yang bisa
 * diurutkan (tidak ada kolom arbitrer dari input pengguna).
 *
 * @see QuerySorter
 */
trait InteractsWithSorting
{
    /**
     * Terapkan sorting request (sort + direction) pada query.
     *
     * Ketika parameter sort tidak valid atau kosong, urutan fallback dipakai
     * dan status yang dikembalikan bernilai null (frontend menampilkan header
     * dalam keadaan tidak aktif).
     *
     * @param  Builder|Relation  $query
     * @param  array<string, string>  $allowed  Map kunci publik => ekspresi kolom
     * @param  array<int, array{0: string, 1: string}>  $fallback  Urutan bawaan, mis. [['created_at', 'desc']]
     * @return array{key: string|null, direction: string|null} Status sorting untuk props frontend
     */
    protected function applySorting($query, Request $request, array $allowed, array $fallback = []): array
    {
        return (new QuerySorter)->apply(
            $query,
            $request->input('sort'),
            $request->input('direction'),
            $allowed,
            $fallback,
        );
    }

    /**
     * Jumlah baris per halaman dari request (?per_page=).
     *
     * Hanya menerima whitelist 10/25/50/100; nilai lain jatuh ke bawaan 25.
     *
     * @see PerPage
     */
    protected function resolvePerPage(Request $request): int
    {
        return PerPage::resolve($request->input(PerPage::PARAM));
    }
}
