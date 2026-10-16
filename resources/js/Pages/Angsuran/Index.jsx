import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import TableSortButton from '@/Components/Admin/TableSortButton';
import TablePagination from '@/Components/Admin/TablePagination';
import useTableSort from '@/hooks/useTableSort';
import {
    Receipt,
    Search,
    Calendar,
    CheckCircle2,
    AlertCircle,
} from 'lucide-react';
import { formatRupiah, formatDate } from '@/utils/format';

export default function Index({ loans, sort, filters, flash }) {
    const [search, setSearch] = useState(filters.search || '');
    const [tab, setTab] = useState(filters.tab || 'active');
    const handleSort = useTableSort('angsuran.index');

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        router.get(route('angsuran.index'), { search, tab }, { preserveState: true, replace: true });
    };

    const handleTabChange = (newTab) => {
        setTab(newTab);
        router.get(route('angsuran.index'), { search, tab: newTab }, { preserveState: true, replace: true });
    };

    return (
        <AdminLayout title="Modul Angsuran" pageTitle="Modul Angsuran">
            <div className="space-y-6">
                {flash?.success && (
                    <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        <AlertCircle className="h-4 w-4 flex-shrink-0" />
                        {flash.error}
                    </div>
                )}

                {/* Tabs */}
                <div className="border-b border-slate-200">
                    <nav className="-mb-px flex gap-6">
                        <button
                            type="button"
                            onClick={() => handleTabChange('active')}
                            className={`pb-3 text-sm font-semibold transition-colors border-b-2 ${
                                tab === 'active'
                                    ? 'border-emerald-600 text-emerald-700'
                                    : 'border-transparent text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            Pinjaman Aktif ({loans.total})
                        </button>
                        <button
                            type="button"
                            onClick={() => handleTabChange('overdue')}
                            className={`pb-3 text-sm font-semibold transition-colors border-b-2 ${
                                tab === 'overdue'
                                    ? 'border-rose-600 text-rose-700'
                                    : 'border-transparent text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            Tunggakan / Jatuh Tempo
                        </button>
                    </nav>
                </div>

                {/* Search */}
                <form onSubmit={handleSearchSubmit} className="flex gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cari no. pinjaman atau nama anggota..."
                            className="w-full rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm text-slate-900 placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        />
                    </div>
                    <button
                        type="submit"
                        className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 active:scale-95 transition-all"
                    >
                        Cari
                    </button>
                </form>

                {loans.data.length === 0 ? (
                    <EmptyState
                        icon={Receipt}
                        title={tab === 'overdue' ? 'Tidak ada pinjaman menunggak' : 'Belum ada pinjaman aktif'}
                        description={
                            search
                                ? 'Coba kata kunci lain.'
                                : tab === 'overdue'
                                ? 'Seluruh pembayaran angsuran berjalan lancar.'
                                : 'Cairkan pinjaman untuk memulai angsuran.'
                        }
                    />
                ) : (
                    <div className="rounded-xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                        <table className="min-w-full divide-y divide-slate-200">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-4 py-3 text-left">
                                        <TableSortButton column="loan_number" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>No. Pinjaman</TableSortButton>
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Anggota</th>
                                    <th className="px-4 py-3 text-right">
                                        <TableSortButton column="amount" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>Pokok</TableSortButton>
                                    </th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                                        Sisa Kewajiban Pokok
                                    </th>
                                    <th className="hidden lg:table-cell px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Jatuh Tempo Ke-1</th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {loans.data.map((loan) => (
                                    <tr key={loan.id} className="hover:bg-slate-50 transition-colors">
                                        <td className="px-4 py-3 text-sm font-mono font-medium text-slate-900">
                                            {loan.loan_number}
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="text-sm font-medium text-slate-900">{loan.member?.full_name}</p>
                                            <p className="text-xs text-slate-400">{loan.member?.member_number}</p>
                                        </td>
                                        <td className="px-4 py-3 text-right text-sm font-semibold tabular-nums text-slate-900">
                                            {formatRupiah(loan.amount)}
                                        </td>
                                        <td className="px-4 py-3 text-right text-sm font-bold tabular-nums text-emerald-700">
                                            {formatRupiah(loan.remaining_balance)}
                                        </td>
                                        <td className="hidden lg:table-cell px-4 py-3 text-sm text-slate-600">
                                            {loan.first_due_date ? formatDate(loan.first_due_date) : '-'}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex items-center justify-end gap-2">
                                                <Link
                                                    href={route('angsuran.schedule', loan.id)}
                                                    className="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 transition-colors"
                                                >
                                                    <Calendar className="h-3.5 w-3.5" />
                                                    Jadwal
                                                </Link>
                                                <Link
                                                    href={route('angsuran.pay.create', loan.id)}
                                                    className="inline-flex items-center gap-1 rounded bg-emerald-700 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-800 active:scale-95 transition-all"
                                                >
                                                    <Receipt className="h-3.5 w-3.5" />
                                                    Bayar
                                                </Link>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        <TablePagination paginator={loans} itemLabel="pinjaman" routeName="angsuran.index" />
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
