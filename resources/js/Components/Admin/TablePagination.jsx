import React from 'react';
import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

const PER_PAGE_OPTIONS = [10, 25, 50, 100];
const DEFAULT_PER_PAGE = 25;

const formatNumber = (value) => (typeof value === 'number' ? value.toLocaleString('id-ID') : (value ?? 0));

/**
 * Susun daftar tombol halaman dengan ellipsis.
 *
 * Contoh output: [1, 2, 3, '…', 12] atau [1, '…', 5, 6, 7, '…', 12].
 * Nilai berupa string '…' dirender sebagai ellipsis non-klik.
 */
function buildPageItems(current, last) {
    if (last <= 7) {
        return Array.from({ length: last }, (_, i) => i + 1);
    }

    if (current <= 4) {
        return [1, 2, 3, 4, 5, '…', last];
    }

    if (current >= last - 3) {
        return [1, '…', last - 4, last - 3, last - 2, last - 1, last];
    }

    return [1, '…', current - 1, current, current + 1, '…', last];
}

/**
 * Pagination server-side bersama untuk tabel manajemen.
 *
 * Fitur:
 * - Ringkasan "Menampilkan X–Y dari Z data" selalu tampil (termasuk saat kosong).
 * - Page size selector (10/25/50/100) yang mereset halaman ke 1 saat diganti.
 * - Navigasi Previous/Next, nomor halaman, dan ellipsis untuk halaman banyak.
 *
 * `routeName` (dan `routeParams`) diperlukan agar selector page size bisa
 * membangun URL baru; parameter filter/sort lain di URL dipertahankan.
 */
export default function TablePagination({
    paginator,
    itemLabel = 'baris',
    routeName = null,
    routeParams = [],
    pageParam = 'page',
}) {
    if (!paginator) {
        return null;
    }

    const currentPage = paginator.current_page ?? 1;
    const lastPage = paginator.last_page ?? 1;
    const total = paginator.total ?? 0;
    const perPage = paginator.per_page ?? DEFAULT_PER_PAGE;
    const from = paginator.from ?? 0;
    const to = paginator.to ?? 0;
    const hasMultiplePages = lastPage > 1;

    /**
     * Bangun href untuk satu halaman dengan mempertahankan seluruh parameter
     * aktif (filter, search, sort) di query string.
     */
    const hrefForPage = (page) => {
        if (paginator.path) {
            const url = new URL(paginator.path, window.location.origin);
            const params = new URLSearchParams(window.location.search);
            params.set(pageParam, page);
            url.search = params.toString();

            return `${url.pathname}${url.search}`;
        }

        return paginator.first_page_url ?? '#';
    };

    const handlePerPageChange = (event) => {
        const nextPerPage = Number(event.target.value);

        if (!routeName || nextPerPage === perPage) {
            return;
        }

        const params = new URLSearchParams(window.location.search);
        params.set('per_page', nextPerPage);
        params.delete(pageParam);

        router.get(route(routeName, ...routeParams), Object.fromEntries(params), {
            preserveState: true,
            replace: true,
        });
    };

    const linkClass =
        'inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-1.5 text-xs font-medium transition-colors';
    const idleClass = 'border-slate-200 bg-white text-slate-500 hover:bg-slate-100 hover:text-slate-800';
    const disabledClass = 'border-slate-200 bg-white text-slate-300 cursor-not-allowed';
    const activeClass = 'border-slate-900 bg-slate-900 text-white shadow-xs';

    return (
        <div className="flex flex-col gap-3 border-t border-slate-200 bg-slate-50/50 px-4 py-3 text-xs text-slate-500 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <p>
                    Menampilkan{' '}
                    <span className="font-semibold text-slate-900">{formatNumber(from)}</span>–
                    <span className="font-semibold text-slate-900">{formatNumber(to)}</span> dari{' '}
                    <span className="font-semibold text-slate-900">{formatNumber(total)}</span> {itemLabel}
                </p>

                {routeName && (
                    <label className="flex items-center gap-2">
                        <span className="hidden sm:inline">Baris per halaman</span>
                        <select
                            value={perPage}
                            onChange={handlePerPageChange}
                            className="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-medium text-slate-700 transition-colors focus:border-emerald-500 focus:outline-none"
                        >
                            {(PER_PAGE_OPTIONS.includes(perPage)
                                ? PER_PAGE_OPTIONS
                                : [...PER_PAGE_OPTIONS, perPage].sort((a, b) => a - b)
                            ).map((option) => (
                                <option key={option} value={option}>
                                    {option}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
            </div>

            {hasMultiplePages && (
                <nav className="flex items-center gap-1" aria-label="Navigasi halaman">
                    {paginator.prev_page_url ? (
                        <Link
                            href={paginator.prev_page_url}
                            className={`${linkClass} ${idleClass}`}
                            aria-label="Halaman sebelumnya"
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </Link>
                    ) : (
                        <span className={`${linkClass} ${disabledClass}`} aria-hidden="true">
                            <ChevronLeft className="h-4 w-4" />
                        </span>
                    )}

                    {buildPageItems(currentPage, lastPage).map((item, index) =>
                        item === '…' ? (
                            <span key={`ellipsis-${index}`} className="px-1 text-slate-400">
                                …
                            </span>
                        ) : item === currentPage ? (
                            <span key={`page-${item}`} className={`${linkClass} ${activeClass}`} aria-current="page">
                                {item}
                            </span>
                        ) : (
                            <Link key={`page-${item}`} href={hrefForPage(item)} className={`${linkClass} ${idleClass}`}>
                                {item}
                            </Link>
                        ),
                    )}

                    {paginator.next_page_url ? (
                        <Link
                            href={paginator.next_page_url}
                            className={`${linkClass} ${idleClass}`}
                            aria-label="Halaman berikutnya"
                        >
                            <ChevronRight className="h-4 w-4" />
                        </Link>
                    ) : (
                        <span className={`${linkClass} ${disabledClass}`} aria-hidden="true">
                            <ChevronRight className="h-4 w-4" />
                        </span>
                    )}
                </nav>
            )}
        </div>
    );
}
