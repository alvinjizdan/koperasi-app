import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import StatCard from '@/Components/Admin/StatCard';
import EmptyState from '@/Components/Admin/EmptyState';
import {
    ArrowDownCircle,
    ArrowUpCircle,
    Banknote,
    ChevronLeft,
    ChevronRight,
    CheckCircle2,
    AlertCircle,
    Filter,
    LineChart,
    Scale,
} from 'lucide-react';
import { formatRupiah, formatDate } from '@/utils/format';

export default function Ringkasan({ rows, summary, filters, operators, flash }) {
    const [filterState, setFilterState] = useState({
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
        operator_id: filters.operator_id || '',
        status: filters.status || 'all',
    });

    const applyFilters = (next) => {
        setFilterState(next);
        router.get(route('kasir.ringkasan.index'), next, { preserveState: true, replace: true });
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        applyFilters(filterState);
    };

    return (
        <AdminLayout title="Ringkasan Kas Harian" pageTitle="Ringkasan Kas Harian">
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

                <form
                    onSubmit={handleSubmit}
                    className="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs sm:grid-cols-2 lg:grid-cols-5"
                >
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Tanggal Mulai</label>
                        <input
                            type="date"
                            value={filterState.date_from}
                            onChange={(e) => setFilterState({ ...filterState, date_from: e.target.value })}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Tanggal Akhir</label>
                        <input
                            type="date"
                            value={filterState.date_to}
                            onChange={(e) => setFilterState({ ...filterState, date_to: e.target.value })}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Operator</label>
                        <select
                            value={filterState.operator_id}
                            onChange={(e) => setFilterState({ ...filterState, operator_id: e.target.value })}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        >
                            <option value="">Semua Operator</option>
                            {operators.map((operator) => (
                                <option key={operator.id} value={operator.id}>
                                    {operator.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Status Sesi</label>
                        <select
                            value={filterState.status}
                            onChange={(e) => setFilterState({ ...filterState, status: e.target.value })}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        >
                            <option value="all">Semua Status</option>
                            <option value="OPEN">Terbuka</option>
                            <option value="CLOSED">Ditutup</option>
                        </select>
                    </div>
                    <div className="flex items-end">
                        <button
                            type="submit"
                            className="inline-flex items-center gap-1.5 rounded-lg bg-slate-800 px-3 py-2 text-xs font-semibold text-white transition-all hover:bg-slate-900 active:scale-95"
                        >
                            <Filter className="h-3.5 w-3.5" />
                            Terapkan
                        </button>
                    </div>
                </form>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        title="Saldo Awal"
                        value={formatRupiah(summary.opening_balance)}
                        icon={Banknote}
                        statusNote={`${summary.sessions_count} sesi`}
                    />
                    <StatCard
                        title="Penerimaan"
                        value={formatRupiah(summary.income_total)}
                        icon={ArrowDownCircle}
                        statusNote="Transaksi sah"
                    />
                    <StatCard
                        title="Pengeluaran"
                        value={formatRupiah(summary.expense_total)}
                        icon={ArrowUpCircle}
                        statusNote={`Setoran: ${formatRupiah(summary.deposit_total)}`}
                    />
                    <StatCard
                        title="Saldo Akhir"
                        value={formatRupiah(summary.system_balance)}
                        icon={LineChart}
                        statusNote={
                            summary.open_sessions_count > 0
                                ? `${summary.open_sessions_count} sesi masih terbuka`
                                : 'Semua sesi tertutup'
                        }
                    />
                </div>

                <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p className="text-xs text-slate-500">Total Selisih Kas (Sesi Ditutup)</p>
                            <p
                                className={`text-lg font-bold tabular-nums ${
                                    summary.difference_total === 0 ? 'text-slate-900' : 'text-rose-700'
                                }`}
                            >
                                {formatRupiah(summary.difference_total)}
                            </p>
                        </div>
                        <p className="inline-flex items-center gap-1.5 text-xs text-slate-500">
                            <Scale className="h-3.5 w-3.5" />
                            Periode {formatDate(summary.date_from)} — {formatDate(summary.date_to)}
                        </p>
                    </div>
                </div>

                {rows.data.length === 0 ? (
                    <EmptyState
                        icon={Banknote}
                        title="Tidak ada sesi kasir pada periode ini"
                        description="Ubah filter tanggal atau operator untuk melihat ringkasan lainnya."
                    />
                ) : (
                    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Tanggal
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Operator
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            No. Sesi
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Saldo Awal
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Penerimaan
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Pengeluaran
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Setoran
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Saldo Akhir
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Selisih
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {rows.data.map((row) => (
                                        <tr key={row.id} className="transition-colors hover:bg-slate-50">
                                            <td className="px-4 py-3 text-sm text-slate-700">
                                                {formatDate(row.operational_date)}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-slate-700">
                                                {row.operator?.name || '-'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Link
                                                    href={route('kasir.sesi.show', row.id)}
                                                    className="font-mono text-xs text-emerald-700 hover:text-emerald-900"
                                                >
                                                    {row.session_number}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-3 text-right text-sm tabular-nums text-slate-900">
                                                {formatRupiah(row.opening_balance)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-sm tabular-nums text-emerald-700">
                                                {formatRupiah(row.income_total)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-sm tabular-nums text-rose-700">
                                                {formatRupiah(row.expense_total)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-sm tabular-nums text-blue-700">
                                                {formatRupiah(row.deposit_total)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-sm font-semibold tabular-nums text-slate-900">
                                                {formatRupiah(row.system_balance)}
                                            </td>
                                            <td
                                                className={`px-4 py-3 text-right text-sm tabular-nums ${
                                                    row.difference === null
                                                        ? 'text-slate-400'
                                                        : row.difference === 0
                                                          ? 'text-slate-600'
                                                          : 'font-semibold text-rose-700'
                                                }`}
                                            >
                                                {row.difference === null ? '—' : formatRupiah(row.difference)}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span
                                                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                                        row.status === 'OPEN'
                                                            ? 'bg-emerald-100 text-emerald-800'
                                                            : 'bg-slate-100 text-slate-600'
                                                    }`}
                                                >
                                                    {row.status === 'OPEN' ? 'TERBUKA' : 'DITUTUP'}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {rows.last_page > 1 && (
                            <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3">
                                <p className="text-sm text-slate-500">
                                    Menampilkan {rows.from}–{rows.to} dari {rows.total} sesi
                                </p>
                                <div className="flex gap-1">
                                    {rows.prev_page_url && (
                                        <Link
                                            href={rows.prev_page_url}
                                            className="rounded p-1.5 text-slate-500 transition-colors hover:bg-slate-100"
                                        >
                                            <ChevronLeft className="h-4 w-4" />
                                        </Link>
                                    )}
                                    {rows.next_page_url && (
                                        <Link
                                            href={rows.next_page_url}
                                            className="rounded p-1.5 text-slate-500 transition-colors hover:bg-slate-100"
                                        >
                                            <ChevronRight className="h-4 w-4" />
                                        </Link>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                )}

                <p className="text-[11px] leading-relaxed text-slate-400">
                    Angka ringkasan dihitung dari transaksi kas yang berstatus berlaku (POSTED). Setoran kasir dicatat
                    sebagai perpindahan kas internal dan mengurangi saldo fisik kasir. Tambahan modal kasir tidak
                    tersedia karena aturannya belum dikonfirmasi.
                </p>
            </div>
        </AdminLayout>
    );
}
