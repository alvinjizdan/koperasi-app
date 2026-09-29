import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import { Combobox, ComboboxContent, ComboboxEmpty, ComboboxInput, ComboboxItem, ComboboxList } from '@/Components/ui/combobox';
import {
    Landmark,
    Plus,
    Search,
    Eye,
    ChevronLeft,
    ChevronRight,
    CheckCircle2,
    AlertCircle,
} from 'lucide-react';
import { formatRupiah, formatDate } from '@/utils/format';

const LOAN_STATUS_OPTIONS = [
    { value: 'all', label: 'Semua Status' },
    { value: 'PENDING', label: 'Menunggu Persetujuan' },
    { value: 'APPROVED', label: 'Disetujui' },
    { value: 'ACTIVE', label: 'Aktif / Berjalan' },
    { value: 'REJECTED', label: 'Ditolak' },
    { value: 'PAID_OFF', label: 'Lunas' },
];

export default function Index({ loans, filters, flash }) {
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        router.get(route('pinjaman.index'), { search, status }, { preserveState: true, replace: true });
    };

    const handleStatusChange = (newStatus) => {
        if (newStatus == null) newStatus = 'all';
        setStatus(newStatus);
        router.get(route('pinjaman.index'), { search, status: newStatus }, { preserveState: true, replace: true });
    };

    const getStatusBadge = (st) => {
        switch (st) {
            case 'PENDING':
                return <span className="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">Menunggu Persetujuan</span>;
            case 'APPROVED':
                return <span className="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">Disetujui</span>;
            case 'REJECTED':
                return <span className="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-800">Ditolak</span>;
            case 'DISBURSED':
            case 'ACTIVE':
                return <span className="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800">Aktif / Berjalan</span>;
            case 'PAID_OFF':
                return <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-800">Lunas</span>;
            case 'CANCELLED':
                return <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">Dibatalkan</span>;
            default:
                return <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">{st}</span>;
        }
    };

    return (
        <AdminLayout title="Daftar Pinjaman" pageTitle="Daftar Pinjaman">
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

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900">Daftar Pinjaman</h2>
                        <p className="mt-0.5 text-sm text-slate-500">{loans.total} pengajuan pinjaman terdaftar</p>
                    </div>
                    <Link
                        href={route('pinjaman.create')}
                        className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition-all duration-100 hover:bg-slate-800 active:scale-95"
                    >
                        <Plus className="h-4 w-4" />
                        Pengajuan Pinjaman
                    </Link>
                </div>

                {/* Filters */}
                <div className="flex flex-col gap-2 sm:flex-row">
                    <form onSubmit={handleSearchSubmit} className="flex flex-1 gap-2">
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
                    <Combobox
                        items={LOAN_STATUS_OPTIONS.map((o) => o.value)}
                        value={status}
                        onValueChange={handleStatusChange}
                        itemToStringValue={(v) => LOAN_STATUS_OPTIONS.find((o) => o.value === v)?.label ?? v}
                    >
                        <ComboboxInput aria-label="Filter status pinjaman" />
                        <ComboboxContent>
                            <ComboboxEmpty>Tidak ada status.</ComboboxEmpty>
                            <ComboboxList>
                                {(v) => (
                                    <ComboboxItem key={v} value={v}>
                                        {LOAN_STATUS_OPTIONS.find((o) => o.value === v)?.label}
                                    </ComboboxItem>
                                )}
                            </ComboboxList>
                        </ComboboxContent>
                    </Combobox>
                </div>

                {loans.data.length === 0 ? (
                    <EmptyState
                        icon={Landmark}
                        title={search ? 'Tidak ada data pinjaman yang cocok' : 'Belum ada pengajuan pinjaman'}
                        description={
                            search
                                ? 'Coba kata kunci lain atau ubah filter.'
                                : 'Buat pengajuan pinjaman pertama untuk anggota.'
                        }
                        action={
                            !search && (
                                <Link
                                    href={route('pinjaman.create')}
                                    className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 active:scale-95 transition-all"
                                >
                                    <Plus className="h-4 w-4" />
                                    Pengajuan Pinjaman
                                </Link>
                            )
                        }
                    />
                ) : (
                    <div className="rounded-xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                        <table className="min-w-full divide-y divide-slate-200">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">No. Pinjaman</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Anggota</th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Nominal Pokok</th>
                                    <th className="hidden md:table-cell px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">Tenor</th>
                                    <th className="hidden lg:table-cell px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Tgl Pengajuan</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
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
                                        <td className="hidden md:table-cell px-4 py-3 text-center text-sm text-slate-600">
                                            {loan.tenor_months} Bulan
                                        </td>
                                        <td className="hidden lg:table-cell px-4 py-3 text-sm text-slate-600">
                                            {formatDate(loan.submission_date)}
                                        </td>
                                        <td className="px-4 py-3">
                                            {getStatusBadge(loan.status)}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Link
                                                href={route('pinjaman.show', loan.id)}
                                                className="rounded p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition-colors inline-flex"
                                                title="Lihat Detail"
                                            >
                                                <Eye className="h-4 w-4" />
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        {loans.last_page > 1 && (
                            <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3">
                                <p className="text-sm text-slate-500">
                                    Halaman {loans.current_page} dari {loans.last_page}
                                </p>
                                <div className="flex gap-1">
                                    {loans.prev_page_url && (
                                        <Link href={loans.prev_page_url} className="rounded p-1.5 text-slate-500 hover:bg-slate-100 transition-colors">
                                            <ChevronLeft className="h-4 w-4" />
                                        </Link>
                                    )}
                                    {loans.next_page_url && (
                                        <Link href={loans.next_page_url} className="rounded p-1.5 text-slate-500 hover:bg-slate-100 transition-colors">
                                            <ChevronRight className="h-4 w-4" />
                                        </Link>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
