import React, { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { ScrollArea, ScrollBar } from '@/Components/ui/scroll-area';
import EmptyState from '@/Components/Admin/EmptyState';
import { BookOpen, PlusCircle, Pencil, Ban, Download, Upload, FileSpreadsheet, AlertTriangle, CheckCircle } from 'lucide-react';

export default function Index({ accounts, categories, filters, importResult }) {
    const [search, setSearch] = useState(filters.search || '');
    const [category, setCategory] = useState(filters.category || '');
    const [status, setStatus] = useState(filters.status || '');
    const [showImportModal, setShowImportModal] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        file: null,
    });

    const handleFilter = (e) => {
        e.preventDefault();
        router.get(route('shm.coa.index'), { search, category, status }, { preserveState: true, replace: true });
    };

    const handleImportSubmit = (e) => {
        e.preventDefault();
        post(route('shm.coa.import'), {
            onSuccess: () => {
                reset();
                setShowImportModal(false);
            },
        });
    };

    return (
        <ShmLayout title="BSM Makro Chart of Accounts" pageTitle="Daftar Perkiraan (COA) BSM Makro">
            {/* Header & Actions */}
            <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 className="text-xl font-semibold text-gray-800">Daftar Perkiraan BSM Makro</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Chart of Accounts independen untuk entitas <strong>Akuntansi BSM Makro</strong>. Terisolasi penuh dari BSM Mikro.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <a
                        href={route('shm.coa.template')}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs"
                    >
                        <Download className="h-4 w-4" />
                        <span>Template XLSX</span>
                    </a>
                    <a
                        href={route('shm.coa.export')}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs"
                    >
                        <FileSpreadsheet className="h-4 w-4" />
                        <span>Export XLSX</span>
                    </a>
                    <button
                        type="button"
                        onClick={() => setShowImportModal(true)}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs"
                    >
                        <Upload className="h-4 w-4" />
                        <span>Import XLSX</span>
                    </button>
                    <Link
                        href={route('shm.coa.create')}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-bsm-primary px-3.5 py-2 text-xs sm:text-sm font-semibold text-white hover:bg-bsm-primary-hover transition-colors shadow-xs"
                    >
                        <PlusCircle className="h-4 w-4" />
                        <span>Tambah Akun</span>
                    </Link>
                </div>
            </div>

            {/* Import Feedback Section */}
            {importResult && (
                <div className={`mb-6 p-4 rounded-xl border ${importResult.failed_rows > 0 ? 'bg-rose-50 border-rose-200' : 'bg-emerald-50 border-emerald-200'}`}>
                    <div className="flex items-start gap-3">
                        {importResult.failed_rows > 0 ? (
                            <AlertTriangle className="h-5 w-5 text-rose-600 mt-0.5 shrink-0" />
                        ) : (
                            <CheckCircle className="h-5 w-5 text-emerald-600 mt-0.5 shrink-0" />
                        )}
                        <div className="flex-1">
                            <h4 className={`text-sm font-semibold ${importResult.failed_rows > 0 ? 'text-rose-900' : 'text-emerald-900'}`}>
                                Hasil Impor XLSX: {importResult.successful_rows} berhasil, {importResult.failed_rows} gagal dari total {importResult.total_rows} baris
                            </h4>
                            {importResult.failed_rows > 0 && (
                                <div className="mt-3 max-h-48 overflow-y-auto rounded-lg border border-rose-200 bg-white p-3 text-xs">
                                    <table className="w-full text-left">
                                        <thead>
                                            <tr className="border-b border-rose-100 text-rose-800 font-semibold">
                                                <th className="py-1 px-2">Baris</th>
                                                <th className="py-1 px-2">Kolom</th>
                                                <th className="py-1 px-2">Nilai</th>
                                                <th className="py-1 px-2">Penyebab Kesalahan</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-rose-50 text-slate-700">
                                            {importResult.errors.map((err, idx) => (
                                                <tr key={idx}>
                                                    <td className="py-1 px-2 font-mono font-semibold text-rose-700">{err.row}</td>
                                                    <td className="py-1 px-2 font-semibold">{err.column}</td>
                                                    <td className="py-1 px-2 font-mono">{err.value || '-'}</td>
                                                    <td className="py-1 px-2 text-rose-600">{err.reason}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* Filter Section */}
            <form onSubmit={handleFilter} className="mb-6 grid grid-cols-1 sm:grid-cols-4 gap-3 bg-white p-4 rounded-xl border border-slate-200 shadow-xs">
                <div>
                    <label className="block text-xs font-semibold text-slate-600 mb-1">Cari Kode / Nama</label>
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Contoh: 1-1100 atau Kas"
                        className="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                    />
                </div>
                <div>
                    <label className="block text-xs font-semibold text-slate-600 mb-1">Kategori</label>
                    <select
                        value={category}
                        onChange={(e) => setCategory(e.target.value)}
                        className="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                    >
                        <option value="">Semua Kategori</option>
                        {categories.map((c) => (
                            <option key={c.value} value={c.value}>
                                {c.label} ({c.normal_balance.toUpperCase()})
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="block text-xs font-semibold text-slate-600 mb-1">Status</label>
                    <select
                        value={status}
                        onChange={(e) => setStatus(e.target.value)}
                        className="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                    >
                        <option value="">Semua Status</option>
                        <option value="active">Aktif</option>
                        <option value="inactive">Nonaktif</option>
                    </select>
                </div>
                <div className="flex items-end gap-2">
                    <button
                        type="submit"
                        className="w-full rounded-lg bg-slate-900 py-1.5 text-xs font-semibold text-white hover:bg-slate-800"
                    >
                        Saring
                    </button>
                    {(search || category || status) && (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setCategory('');
                                setStatus('');
                                router.get(route('shm.coa.index'));
                            }}
                            className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                        >
                            Reset
                        </button>
                    )}
                </div>
            </form>

            {/* Account Table */}
            {accounts.length === 0 ? (
                <EmptyState
                    icon={BookOpen}
                    title="Belum Ada Akun BSM Makro"
                    description="Daftar perkiraan Akuntansi BSM Makro masih kosong. Silakan tambah akun manual atau gunakan fitur Impor XLSX."
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
                                        {account.description && (
                                            <div className="text-[11px] text-slate-400">{account.description}</div>
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
                                            href={route('shm.coa.edit', account.id)}
                                            className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1.5 text-[11px] font-semibold text-slate-700 hover:bg-slate-50"
                                        >
                                            <Pencil className="h-3.5 w-3.5" /> Ubah
                                        </Link>
                                        {account.is_active && (
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    if (confirm(account.can_be_deleted
                                                        ? `Hapus akun ${account.code}? Akun belum pernah dipakai di transaksi.`
                                                        : `Nonaktifkan akun ${account.code}? Akun yang sudah dipakai di transaksi tidak dapat dihapus.`)) {
                                                        router.patch(route('shm.coa.deactivate', account.id));
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

            {/* Import XLSX Modal */}
            {showImportModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-xs">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl border border-slate-200">
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-base font-bold text-slate-900">Impor SHM CoA (.xlsx)</h3>
                            <button
                                type="button"
                                onClick={() => setShowImportModal(false)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                ✕
                            </button>
                        </div>
                        <p className="text-xs text-slate-500 mb-4">
                            Pilih berkas Excel (.xlsx) sesuai template resmi. Mode impor adalah <strong>CREATE ONLY</strong>. Duplikat akun akan digagalkan secara atomik.
                        </p>
                        <form onSubmit={handleImportSubmit}>
                            <div className="mb-4">
                                <label className="block text-xs font-semibold text-slate-700 mb-1">Berkas XLSX</label>
                                <input
                                    type="file"
                                    accept=".xlsx"
                                    onChange={(e) => setData('file', e.target.files[0])}
                                    className="block w-full text-xs text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
                                />
                                {errors.file && (
                                    <p className="mt-1 text-xs text-rose-600">{errors.file}</p>
                                )}
                            </div>
                            <div className="flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => setShowImportModal(false)}
                                    className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing || !data.file}
                                    className="rounded-lg bg-bsm-primary px-4 py-2 text-xs font-semibold text-white hover:bg-bsm-primary-hover disabled:opacity-50"
                                >
                                    {processing ? 'Memproses...' : 'Unggah & Impor'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </ShmLayout>
    );
}

