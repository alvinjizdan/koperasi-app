import React from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { ScrollArea, ScrollBar } from '@/Components/ui/scroll-area';
import EmptyState from '@/Components/Admin/EmptyState';
import { BookOpen, PlusCircle, Pencil, Ban } from 'lucide-react';

export default function Index({ accounts, categories }) {
    return (
        <AdminLayout title="Chart of Accounts" pageTitle="Daftar Perkiraan (COA)">
            <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 className="text-xl font-bold text-slate-900">Daftar Perkiraan</h2>
                    <p className="mt-1 text-xs sm:text-sm text-slate-500">
                        Data awal berstatus <strong>template</strong> — COA resmi koperasi belum ditetapkan
                        dan dapat disesuaikan dari halaman ini.
                    </p>
                </div>
                <Link
                    href={route('akuntansi.coa.create')}
                    className="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3.5 py-2 text-xs sm:text-sm font-semibold text-white hover:bg-slate-800 transition-colors active:scale-95 shadow-xs"
                >
                    <PlusCircle className="h-4 w-4" />
                    <span>Tambah Akun</span>
                </Link>
            </div>

            {accounts.length === 0 ? (
                <EmptyState
                    icon={BookOpen}
                    title="Belum Ada Akun"
                    description="Daftar perkiraan masih kosong. Tambahkan akun pertama atau jalankan ChartOfAccountSeeder."
                />
            ) : (
                <ScrollArea className="rounded-xl border border-slate-200 bg-white shadow-xs">
<ScrollBar orientation="horizontal" />
                    <table className="min-w-full divide-y divide-slate-200 text-xs sm:text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Kode</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Nama Akun</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Kategori</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Saldo Normal</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Induk</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Dipakai</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Status</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-500">Aksi</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {accounts.map((account) => (
                                <tr key={account.id} className="hover:bg-slate-50">
                                    <td className="px-4 py-3 font-mono font-semibold text-slate-900">{account.code}</td>
                                    <td className="px-4 py-3 text-slate-900">
                                        {account.name}
                                        {account.is_template && (
                                            <span className="ml-2 rounded bg-amber-50 border border-amber-200 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">
                                                Template
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-slate-600">{account.category_label}</td>
                                    <td className="px-4 py-3 uppercase text-slate-600">{account.normal_balance}</td>
                                    <td className="px-4 py-3 font-mono text-slate-500">{account.parent_code ?? '-'}</td>
                                    <td className="px-4 py-3 text-slate-600">
                                        {account.journal_usage_count > 0
                                            ? `${account.journal_usage_count}x di jurnal`
                                            : 'Belum dipakai'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className={`rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${account.is_active ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-500 border-slate-200'}`}>
                                            {account.is_active ? 'Aktif' : 'Nonaktif'}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-right whitespace-nowrap">
                                        <Link
                                            href={route('akuntansi.coa.edit', account.id)}
                                            className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1.5 text-[11px] font-semibold text-slate-700 hover:bg-slate-50"
                                        >
                                            <Pencil className="h-3.5 w-3.5" /> Ubah
                                        </Link>
                                        {account.is_active && (
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    if (confirm(account.can_be_deleted
                                                        ? `Hapus akun ${account.code}? Akun belum pernah dipakai di jurnal.`
                                                        : `Nonaktifkan akun ${account.code}? Akun yang sudah dipakai di jurnal tidak dapat dihapus.`)) {
                                                        router.patch(route('akuntansi.coa.deactivate', account.id));
                                                    }
                                                }}
                                                className="ml-2 inline-flex items-center gap-1 rounded-lg border border-rose-300 px-2.5 py-1.5 text-[11px] font-semibold text-rose-700 hover:bg-rose-50"
                                            >
                                                <Ban className="h-3.5 w-3.5" />
                                                {account.can_be_deleted ? 'Hapus' : 'Nonaktifkan'}
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </ScrollArea>
            )}
        </AdminLayout>
    );
}
