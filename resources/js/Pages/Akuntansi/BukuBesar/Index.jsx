import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import { formatRupiah, formatDate } from '@/utils/format';
import { BookMarked } from 'lucide-react';

export default function Index({ accounts, filters, rows, totals, selectedAccount }) {
    const inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20';

    return (
        <AdminLayout title="Buku Besar" pageTitle="Akuntansi — Buku Besar">
            <div className="mb-6">
                <h2 className="text-xl font-bold text-slate-900">Buku Besar</h2>
                <p className="mt-1 text-xs sm:text-sm text-slate-500">
                    Hanya jurnal berstatus <strong>POSTED</strong> yang memengaruhi buku besar.
                    Jurnal DRAFT tidak dihitung; jurnal REVERSED tetap dapat ditelusuri dari halaman jurnal.
                </p>
            </div>

            <form method="get" action={route('akuntansi.buku-besar.index')} className="mb-6 grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:grid-cols-4">
                <div>
                    <label className="text-xs font-semibold text-slate-600" htmlFor="account_id">Akun</label>
                    <select id="account_id" name="account_id" defaultValue={filters.account_id ?? ''} className={inputClass}>
                        <option value="">— Semua akun —</option>
                        {accounts.map((account) => (
                            <option key={account.id} value={account.id}>{account.code} — {account.name}</option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="text-xs font-semibold text-slate-600" htmlFor="start_date">Dari Tanggal</label>
                    <input id="start_date" name="start_date" type="date" defaultValue={filters.start_date ?? ''} className={inputClass} />
                </div>
                <div>
                    <label className="text-xs font-semibold text-slate-600" htmlFor="end_date">Sampai Tanggal</label>
                    <input id="end_date" name="end_date" type="date" defaultValue={filters.end_date ?? ''} className={inputClass} />
                </div>
                <div className="flex items-end">
                    <button type="submit" className="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Terapkan Filter
                    </button>
                </div>
            </form>

            {selectedAccount && (
                <div className="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs sm:text-sm">
                    <span className="font-semibold text-slate-700">{selectedAccount.code} — {selectedAccount.name}</span>
                    <span className="ml-2 text-slate-500">
                        ({selectedAccount.category_label}, saldo normal {selectedAccount.normal_balance})
                    </span>
                </div>
            )}

            {rows.length === 0 ? (
                <EmptyState
                    icon={BookMarked}
                    title="Belum Ada Mutasi Buku Besar"
                    description="Belum ada jurnal POSTED pada filter yang dipilih. Posting jurnal terlebih dahulu dari halaman Jurnal Umum."
                    action={
                        <Link href={route('akuntansi.jurnal.index')} className="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                            Buka Jurnal Umum
                        </Link>
                    }
                />
            ) : (
                <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-xs">
                    <table className="min-w-full divide-y divide-slate-200 text-xs sm:text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Tanggal</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Nomor Jurnal</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Akun</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Deskripsi</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-500">Debit</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-500">Kredit</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-500">Saldo</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Sumber</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.map((row, index) => (
                                <tr key={`${row.journal_entry_id}-${row.account_id}-${index}`} className="hover:bg-slate-50">
                                    <td className="px-4 py-3 text-slate-600">{formatDate(row.transaction_date)}</td>
                                    <td className="px-4 py-3 font-mono text-slate-900">
                                        <Link href={route('akuntansi.jurnal.show', row.journal_entry_id)} className="hover:text-emerald-700 hover:underline">
                                            {row.journal_number}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 font-mono text-slate-700">{row.account_code} — {row.account_name}</td>
                                    <td className="px-4 py-3 text-slate-600">{row.description ?? '-'}</td>
                                    <td className="px-4 py-3 text-right text-slate-900">{row.debit > 0 ? formatRupiah(row.debit) : '-'}</td>
                                    <td className="px-4 py-3 text-right text-slate-900">{row.credit > 0 ? formatRupiah(row.credit) : '-'}</td>
                                    <td className="px-4 py-3 text-right font-semibold text-slate-900">{formatRupiah(row.balance)}</td>
                                    <td className="px-4 py-3 text-slate-500">
                                        {row.source_type ? `${row.source_type.split('\\').pop()}#${row.source_id}` : 'manual'}
                                    </td>
                                </tr>
                            ))}
                            <tr className="bg-slate-50 font-semibold">
                                <td colSpan={4} className="px-4 py-3 text-right text-slate-700">Total</td>
                                <td className="px-4 py-3 text-right text-slate-900">{formatRupiah(totals.debit)}</td>
                                <td className="px-4 py-3 text-right text-slate-900">{formatRupiah(totals.credit)}</td>
                                <td className="px-4 py-3 text-right text-slate-900">{formatRupiah(totals.balance)}</td>
                                <td />
                            </tr>
                        </tbody>
                    </table>
                </div>
            )}
        </AdminLayout>
    );
}
