import React from 'react';
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react';

/**
 * Tombol header tabel yang dapat diklik untuk mengurutkan kolom.
 *
 * Status aktif mengikuti pasangan `sort` dan `direction` dari server, sehingga
 * ikon selalu konsisten dengan urutan data yang sedang ditampilkan (bukan
 * state lokal yang bisa selisih setelah navigasi).
 */
export default function TableSortButton({ column, sort, direction, onSort, children }) {
    const isActive = sort === column && direction !== null;
    const Icon = !isActive ? ChevronsUpDown : direction === 'desc' ? ArrowDown : ArrowUp;

    return (
        <button
            type="button"
            onClick={() => onSort(column)}
            aria-label={`Urutkan berdasarkan ${typeof children === 'string' ? children : column}`}
            className={`group inline-flex items-center gap-1 whitespace-nowrap text-xs font-semibold uppercase tracking-wider transition-colors focus:outline-none focus-visible:ring-1 focus-visible:ring-slate-400 ${
                isActive ? 'text-slate-900' : 'text-slate-500 hover:text-slate-800'
            }`}
        >
            {children}
            <Icon
                className={`h-3 w-3 shrink-0 transition-opacity ${
                    isActive ? 'opacity-100' : 'opacity-50 group-hover:opacity-100'
                }`}
            />
        </button>
    );
}
