import React, { useState } from 'react';
import { router, Link } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { ScrollArea, ScrollBar } from '@/Components/ui/scroll-area';
import EmptyState from '@/Components/Admin/EmptyState';
import { formatRupiah, formatDate } from '@/utils/format';
import ShmReportActions from '@/Components/Shm/ShmReportActions';
import { Search, Filter, BookOpen, CheckCircle, AlertTriangle } from 'lucide-react';

export default function DailyJournal({ entries, totals, filters, accounts }) {
    const inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none';
    const [search, setSearch] = useState(filters.search ?? '');

    const handleFilterSubmit = (e) => {
        e.preventDefault();
        const formData = new FormData(e.currentTarget);
        router.get(route('shm.daily-journal.index'), {
            start_date: formData.get('start_date') || '',
            end_date: formData.get('end_date') || '',
            account_id: formData.get('account_id') || '',
            search: formData.get('search') || '',
        }, { preserveState: true, replace: true });
    };

    const handleReset = () => {
        setSearch('');
        router.get(route('shm.daily-journal.index'), {}, { preserveState: false, replace: true });
    };

    const items = entries.data ?? [];
    const hasData = items.length > 0;

    return (
        <ShmLayout title="Jurnal Harian BSM Makro" pageTitle="Akuntansi BSM Makro — Jurnal Harian">
            {/* Header */}
            <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 className="text-xl font-semibold text-gray-800">Jurnal Harian</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Daftar transaksi jurnal accounting BSM Makro berdasarkan periode.
                    </p>
                </div>
                <div>
                    <ShmReportActions
                        exportRoute="shm.daily-journal.export"
                        exportParams={{
                            start_date: filters.start_date,
                            end_date: filters.end_date,
                            account_id: filters.account_id,
                            search: filters.search,
                        }}
                    />
                </div>
            </div>

            {/* Filter Form */}
            <form onSubmit={handleFilterSubmit} className="mb-6 rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label className="text-xs font-semibold text-slate-600 block mb-1" htmlFor="start_date">
                            Tanggal Mulai
                        </label>
                        <input
                            id="start_date"
                            name="start_date"
                            type="date"
                            defaultValue={filters.start_date ?? ''}
                            className={inputClass}
                        />
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-600 block mb-1" htmlFor="end_date">
                            Tanggal Akhir
                        </label>
                        <input
                            id="end_date"
                            name="end_date"
                            type="date"
                            defaultValue={filters.end_date ?? ''}
                            className={inputClass}
                        />
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-600 block mb-1" htmlFor="account_id">
                            Perkiraan
                        </label>
                        <select
                            id="account_id"
                            name="account_id"
                            defaultValue={filters.account_id ?? ''}
                            className={inputClass}
                        >
                            <option value="">Semua Perkiraan</option>
                            {accounts.map((acc) => (
                                <option key={acc.id} value={acc.id}>
                                    {acc.code} - {acc.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-600 block mb-1" htmlFor="search">
                            Pencarian
                        </label>
                        <div className="relative">
                            <input
                                id="search"
                                name="search"
                                type="text"
                                placeholder="Voucher, slip, keterangan, nama..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className={inputClass}
                            />
                            <Search className="absolute right-3 top-2.5 h-4 w-4 text-slate-400" />
                        </div>
                    </div>
                </div>

                <div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-3">
                    <div className="text-xs text-slate-500">
                        Kantor: <span className="font-medium text-slate-700">Semua Kantor</span>
                    </div>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={handleReset}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                        >
                            Reset
                        </button>
                        <button
                            type="submit"
                            className="rounded-lg bg-emerald-600 px-4 py-1.5 text-xs font-medium text-white hover:bg-emerald-700"
                        >
                            Terapkan Filter
                        </button>
                    </div>
                </div>
            </form>

            {/* Accounting Invariant / Totals Card */}
            <div className="mb-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                    <p className="text-xs font-medium text-slate-500">Total Debet Periode</p>
                    <p className="mt-1 text-lg font-bold text-slate-900">{formatRupiah(totals.total_debit)}</p>
                </div>
                <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                    <p className="text-xs font-medium text-slate-500">Total Kredit Periode</p>
                    <p className="mt-1 text-lg font-bold text-slate-900">{formatRupiah(totals.total_credit)}</p>
                </div>
                <div className={`rounded-xl border p-4 shadow-xs ${totals.is_balanced ? 'border-emerald-200 bg-emerald-50/50' : 'border-rose-200 bg-rose-50/50'}`}>
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-xs font-medium text-slate-500">Status Keseimbangan</p>
                            <p className={`mt-1 text-sm font-bold flex items-center gap-1.5 ${totals.is_balanced ? 'text-emerald-700' : 'text-rose-700'}`}>
                                {totals.is_balanced ? (
                                    <>
                                        <CheckCircle className="h-4 w-4" /> SEIMBANG (BALANCED)
                                    </>
                                ) : (
                                    <>
                                        <AlertTriangle className="h-4 w-4" /> TIDAK SEIMBANG
                                    </>
                                )}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {/* Table or Empty State */}
            {!hasData ? (
                <EmptyState
                    icon={BookOpen}
                    title="Tidak Ada Data Jurnal"
                    description="Tidak ditemukan transaksi jurnal POSTED pada rentang filter ini."
                />
            ) : (
                <div className="rounded-xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                    <ScrollArea className="w-full">
                        <table className="w-full text-left text-xs">
                            <thead className="bg-slate-50 border-b border-slate-200 text-slate-700 font-semibold uppercase tracking-wider text-[11px]">
                                <tr>
                                    <th className="px-3 py-3 w-12 text-center">No</th>
                                    <th className="px-3 py-3 whitespace-nowrap">Tanggal</th>
                                    <th className="px-3 py-3 whitespace-nowrap">Kode Perk.</th>
                                    <th className="px-3 py-3 whitespace-nowrap">Perkiraan</th>
                                    <th className="px-3 py-3 whitespace-nowrap">Voucher</th>
                                    <th className="px-3 py-3 whitespace-nowrap">No Slip</th>
                                    <th className="px-3 py-3 min-w-[180px]">Keterangan</th>
                                    <th className="px-3 py-3 text-right whitespace-nowrap">Debet</th>
                                    <th className="px-3 py-3 text-right whitespace-nowrap">Kredit</th>
                                    <th className="px-3 py-3 whitespace-nowrap">Nama</th>
                                    <th className="px-3 py-3 whitespace-nowrap">No. Agt</th>
                                    <th className="px-3 py-3 whitespace-nowrap">Rek. Agt</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 text-slate-800">
                                {items.map((entry, idx) => {
                                    const groupNumber = (entries.current_page - 1) * entries.per_page + (idx + 1);
                                    return (
                                        <React.Fragment key={entry.id}>
                                            {entry.lines.map((line, lineIdx) => {
                                                const isFirstLine = lineIdx === 0;
                                                return (
                                                    <tr
                                                        key={`${entry.id}-${line.id}`}
                                                        className={`hover:bg-slate-50/80 transition-colors ${isFirstLine && idx > 0 ? 'border-t-2 border-slate-200' : ''}`}
                                                    >
                                                        {isFirstLine ? (
                                                            <>
                                                                <td className="px-3 py-2 text-center font-medium text-slate-500 align-top bg-slate-50/30">
                                                                    {groupNumber}
                                                                </td>
                                                                <td className="px-3 py-2 whitespace-nowrap align-top text-slate-700">
                                                                    {entry.formatted_date}
                                                                </td>
                                                            </>
                                                        ) : (
                                                            <>
                                                                <td className="px-3 py-2 bg-slate-50/30"></td>
                                                                <td className="px-3 py-2"></td>
                                                            </>
                                                        )}

                                                        <td className="px-3 py-2 font-mono whitespace-nowrap text-slate-600 align-top">
                                                            {line.account_code}
                                                        </td>
                                                        <td className="px-3 py-2 font-medium text-slate-800 align-top">
                                                            {line.account_name}
                                                        </td>

                                                        {isFirstLine ? (
                                                            <>
                                                                <td className="px-3 py-2 font-mono text-[11px] whitespace-nowrap text-slate-600 align-top">
                                                                    {entry.voucher}
                                                                </td>
                                                                <td className="px-3 py-2 font-mono text-[11px] whitespace-nowrap text-slate-600 align-top">
                                                                    {entry.slip_number}
                                                                </td>
                                                            </>
                                                        ) : (
                                                            <>
                                                                <td className="px-3 py-2"></td>
                                                                <td className="px-3 py-2"></td>
                                                            </>
                                                        )}

                                                        <td className="px-3 py-2 text-slate-600 align-top">
                                                            {line.description}
                                                        </td>

                                                        <td className="px-3 py-2 text-right font-mono whitespace-nowrap align-top">
                                                            {line.debit !== null ? formatRupiah(line.debit) : '-'}
                                                        </td>
                                                        <td className="px-3 py-2 text-right font-mono whitespace-nowrap align-top">
                                                            {line.credit !== null ? formatRupiah(line.credit) : '-'}
                                                        </td>

                                                        {isFirstLine ? (
                                                            <>
                                                                <td className="px-3 py-2 whitespace-nowrap font-medium text-slate-700 align-top">
                                                                    {entry.customer_name}
                                                                </td>
                                                                <td className="px-3 py-2 whitespace-nowrap font-mono text-[11px] text-slate-600 align-top">
                                                                    {entry.member_number}
                                                                </td>
                                                                <td className="px-3 py-2 whitespace-nowrap font-mono text-[11px] text-slate-600 align-top">
                                                                    {entry.account_number}
                                                                </td>
                                                            </>
                                                        ) : (
                                                            <>
                                                                <td className="px-3 py-2"></td>
                                                                <td className="px-3 py-2"></td>
                                                                <td className="px-3 py-2"></td>
                                                            </>
                                                        )}
                                                    </tr>
                                                );
                                            })}
                                        </React.Fragment>
                                    );
                                })}
                            </tbody>
                        </table>
                        <ScrollBar orientation="horizontal" />
                    </ScrollArea>

                    {/* Pagination */}
                    {entries.links && entries.links.length > 3 && (
                        <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 sm:px-6">
                            <div className="text-xs text-slate-500">
                                Menampilkan <span className="font-medium text-slate-700">{entries.from ?? 0}</span> sampai{' '}
                                <span className="font-medium text-slate-700">{entries.to ?? 0}</span> dari{' '}
                                <span className="font-medium text-slate-700">{entries.total ?? 0}</span> jurnal
                            </div>
                            <div className="flex space-x-1">
                                {entries.links.map((link, i) => {
                                    if (!link.url) {
                                        return (
                                            <span
                                                key={i}
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                                className="px-2.5 py-1 text-xs text-slate-400 border border-slate-200 rounded"
                                            />
                                        );
                                    }
                                    return (
                                        <Link
                                            key={i}
                                            href={link.url}
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                            className={`px-2.5 py-1 text-xs rounded border transition-colors ${
                                                link.active
                                                    ? 'bg-emerald-600 text-white border-emerald-600 font-semibold'
                                                    : 'border-slate-200 text-slate-700 hover:bg-slate-50'
                                            }`}
                                        />
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </div>
            )}
        </ShmLayout>
    );
}

