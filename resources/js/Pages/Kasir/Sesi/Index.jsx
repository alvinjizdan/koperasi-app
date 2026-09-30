import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { ScrollArea, ScrollBar } from '@/Components/ui/scroll-area';
import EmptyState from '@/Components/Admin/EmptyState';
import CashierSessionCard from '@/Components/Admin/CashierSessionCard';
import TableSortButton from '@/Components/Admin/TableSortButton';
import TablePagination from '@/Components/Admin/TablePagination';
import useTableSort from '@/hooks/useTableSort';
import { Combobox, ComboboxContent, ComboboxEmpty, ComboboxInput, ComboboxItem, ComboboxList } from '@/Components/ui/combobox';
import {
    CheckCircle2,
    AlertCircle,
    History,
    Filter,
    PlusCircle,
} from 'lucide-react';
import { formatRupiah, formatDate, formatDateTime } from '@/utils/format';

const SESSION_STATUS_OPTIONS = [
    { value: 'all', label: 'Semua Status' },
    { value: 'OPEN', label: 'Terbuka' },
    { value: 'CLOSED', label: 'Ditutup' },
];

const OPERATOR_OPTIONS = (operators) => [
    { value: '', label: 'Semua Operator' },
    ...operators.map((o) => ({ value: String(o.id), label: o.name })),
];

function StatusBadge({ status }) {
    const isOpen = status === 'OPEN';

    return (
        <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                isOpen ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'
            }`}
        >
            {isOpen ? 'TERBUKA' : 'DITUTUP'}
        </span>
    );
}

export default function Index({ sessions, sort, operators, filters, active_session, flash }) {
    const [filterState, setFilterState] = useState({
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
        operator_id: filters.operator_id || '',
        status: filters.status || 'all',
    });
    const handleSort = useTableSort('kasir.sesi.index');

    const applyFilters = (next) => {
        setFilterState(next);
        router.get(route('kasir.sesi.index'), next, { preserveState: true, replace: true });
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        applyFilters(filterState);
    };

    const handleReset = () => {
        applyFilters({ date_from: '', date_to: '', operator_id: '', status: 'all' });
    };

    return (
        <AdminLayout title="Riwayat Sesi Kasir" pageTitle="Riwayat Sesi Kasir">
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

                <CashierSessionCard session={active_session} showActions />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-sm font-semibold text-slate-900">Riwayat Sesi Kasir</h1>
                    <Link
                        href={route('kasir.sesi.create')}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-2 text-xs font-semibold text-white transition-all hover:bg-emerald-800 active:scale-95"
                    >
                        <PlusCircle className="h-3.5 w-3.5" />
                        Buka Sesi Baru
                    </Link>
                </div>

                {/* Filter */}
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
                        <Combobox
                            items={OPERATOR_OPTIONS(operators)}
                            value={filterState.operator_id}
                            onValueChange={(v) => setFilterState({ ...filterState, operator_id: v ?? '' })}
                            itemToStringValue={(v) => operators.find((o) => String(o.id) === String(v))?.name ?? 'Semua Operator'}
                        >
                            <ComboboxInput aria-label="Filter operator" />
                            <ComboboxContent>
                                <ComboboxEmpty>Operator tidak ditemukan.</ComboboxEmpty>
                                <ComboboxList>
                                    {(option) => (
                                        <ComboboxItem key={option.value} value={option.value}>
                                            {option.label}
                                        </ComboboxItem>
                                    )}
                                </ComboboxList>
                            </ComboboxContent>
                        </Combobox>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Status</label>
                        <Combobox
                            items={SESSION_STATUS_OPTIONS.map((o) => o.value)}
                            value={filterState.status}
                            onValueChange={(v) => setFilterState({ ...filterState, status: v ?? 'all' })}
                            itemToStringValue={(v) => SESSION_STATUS_OPTIONS.find((o) => o.value === v)?.label ?? v}
                        >
                            <ComboboxInput aria-label="Filter status sesi" />
                            <ComboboxContent>
                                <ComboboxEmpty>Tidak ada status.</ComboboxEmpty>
                                <ComboboxList>
                                    {(v) => (
                                        <ComboboxItem key={v} value={v}>
                                            {SESSION_STATUS_OPTIONS.find((o) => o.value === v)?.label}
                                        </ComboboxItem>
                                    )}
                                </ComboboxList>
                            </ComboboxContent>
                        </Combobox>
                    </div>
                    <div className="flex items-end gap-2">
                        <button
                            type="submit"
                            className="inline-flex items-center gap-1.5 rounded-lg bg-slate-800 px-3 py-2 text-xs font-semibold text-white transition-all hover:bg-slate-900 active:scale-95"
                        >
                            <Filter className="h-3.5 w-3.5" />
                            Terapkan
                        </button>
                        <button
                            type="button"
                            onClick={handleReset}
                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                        >
                            Reset
                        </button>
                    </div>
                </form>

                {sessions.data.length === 0 ? (
                    <EmptyState
                        icon={History}
                        title="Belum ada sesi kasir"
                        description="Sesi kasir yang dibuka akan tercatat di sini."
                    />
                ) : (
                    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                        <ScrollArea>
<ScrollBar orientation="horizontal" />
                            <table className="min-w-full divide-y divide-slate-200">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left">
                                            <TableSortButton column="session_number" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>
                                                No. Sesi
                                            </TableSortButton>
                                        </th>
                                        <th className="px-4 py-3 text-left">
                                            <TableSortButton column="operational_date" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>
                                                Tanggal
                                            </TableSortButton>
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Operator
                                        </th>
                                        <th className="px-4 py-3 text-right">
                                            <TableSortButton column="opening_balance" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>
                                                Saldo Awal
                                            </TableSortButton>
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Masuk
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Keluar
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
                                        <th className="px-4 py-3 text-left">
                                            <TableSortButton column="status" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>
                                                Status
                                            </TableSortButton>
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Aksi
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {sessions.data.map((session) => (
                                        <tr key={session.id} className="transition-colors hover:bg-slate-50">
                                            <td className="px-4 py-3 font-mono text-xs font-medium text-slate-900">
                                                {session.session_number}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-slate-600">
                                                <p>{formatDate(session.operational_date)}</p>
                                                <p className="text-[11px] text-slate-400">
                                                    {formatDateTime(session.closed_at || session.opened_at)}
                                                </p>
                                            </td>
                                            <td className="px-4 py-3 text-sm text-slate-700">
                                                {session.operator?.name || '-'}
                                            </td>
                                            <td className="px-4 py-3 text-right text-sm tabular-nums text-slate-900">
                                                {formatRupiah(session.opening_balance)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-sm tabular-nums text-emerald-700">
                                                {formatRupiah(session.income_total)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-sm tabular-nums text-rose-700">
                                                {formatRupiah(session.expense_total)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-sm tabular-nums text-blue-700">
                                                {formatRupiah(session.deposit_total)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-sm font-semibold tabular-nums text-slate-900">
                                                {formatRupiah(session.system_balance)}
                                            </td>
                                            <td
                                                className={`px-4 py-3 text-right text-sm tabular-nums ${
                                                    session.difference === null
                                                        ? 'text-slate-400'
                                                        : session.difference === 0
                                                          ? 'text-slate-600'
                                                          : 'font-semibold text-rose-700'
                                                }`}
                                            >
                                                {session.difference === null ? '—' : formatRupiah(session.difference)}
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge status={session.status} />
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <Link
                                                        href={route('kasir.sesi.show', session.id)}
                                                        className="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                                                    >
                                                        Detail
                                                    </Link>
                                                    {session.status === 'OPEN' && (
                                                        <Link
                                                            href={route('kasir.sesi.close.create', session.id)}
                                                            className="rounded bg-slate-800 px-2.5 py-1 text-xs font-medium text-white transition-all hover:bg-slate-900 active:scale-95"
                                                        >
                                                            Tutup
                                                        </Link>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </ScrollArea>

                        <TablePagination paginator={sessions} itemLabel="sesi" routeName="kasir.sesi.index" />
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
