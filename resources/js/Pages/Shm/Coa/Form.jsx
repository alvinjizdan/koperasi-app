import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { ArrowLeft, Save } from 'lucide-react';

export default function Form({ categories, parents, account }) {
    const isEdit = account !== null;

    const { data, setData, post, put, processing, errors } = useForm({
        code: account?.code || '',
        name: account?.name || '',
        category: account?.category || 'asset',
        parent_id: account?.parent_id ? String(account.parent_id) : '',
        description: account?.description || '',
        is_active: account?.is_active ?? true,
    });

    const currentCategory = categories.find((c) => c.value === data.category);

    const handleSubmit = (e) => {
        e.preventDefault();
        if (isEdit) {
            put(route('shm.coa.update', account.id));
        } else {
            post(route('shm.coa.store'));
        }
    };

    return (
        <ShmLayout
            title={isEdit ? 'Ubah Akun SHM' : 'Tambah Akun SHM'}
            pageTitle={isEdit ? 'Ubah Akun SHM' : 'Tambah Akun SHM'}
        >
            <div className="mb-6">
                <Link
                    href={route('shm.coa.index')}
                    className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-slate-800"
                >
                    <ArrowLeft className="h-4 w-4" />
                    <span>Kembali ke Daftar Perkiraan SHM</span>
                </Link>
                <h2 className="mt-2 text-xl font-bold text-slate-900">
                    {isEdit ? `Ubah Akun: ${account.code}` : 'Tambah Akun Baru (SHM)'}
                </h2>
                <p className="mt-1 text-xs text-slate-500">
                    Akun ini akan dialokasikan khusus pada entitas <strong>Akuntansi SHM</strong>.
                </p>
            </div>

            <div className="max-w-2xl rounded-2xl border border-slate-200 bg-white p-6 shadow-xs">
                <form onSubmit={handleSubmit} className="space-y-5">
                    {/* Kode Akun */}
                    <div>
                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Kode Akun <span className="text-rose-500">*</span>
                        </label>
                        <input
                            type="text"
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value)}
                            placeholder="Contoh: 1-1100 atau 111.01"
                            className="w-full rounded-lg border border-slate-300 px-3.5 py-2 text-xs sm:text-sm text-slate-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        />
                        {errors.code && <p className="mt-1 text-xs text-rose-600">{errors.code}</p>}
                    </div>

                    {/* Nama Akun */}
                    <div>
                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Nama Akun <span className="text-rose-500">*</span>
                        </label>
                        <input
                            type="text"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Contoh: Kas Operasional SHM"
                            className="w-full rounded-lg border border-slate-300 px-3.5 py-2 text-xs sm:text-sm text-slate-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        />
                        {errors.name && <p className="mt-1 text-xs text-rose-600">{errors.name}</p>}
                    </div>

                    {/* Kategori Akun */}
                    <div>
                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Kategori Akun <span className="text-rose-500">*</span>
                        </label>
                        <select
                            value={data.category}
                            onChange={(e) => setData('category', e.target.value)}
                            className="w-full rounded-lg border border-slate-300 px-3.5 py-2 text-xs sm:text-sm text-slate-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        >
                            {categories.map((c) => (
                                <option key={c.value} value={c.value}>
                                    {c.label} ({c.value})
                                </option>
                            ))}
                        </select>
                        {errors.category && <p className="mt-1 text-xs text-rose-600">{errors.category}</p>}
                    </div>

                    {/* Saldo Normal (Informatif, auto-set by category) */}
                    <div>
                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">
                            Saldo Normal (Ditentukan Otomatis)
                        </label>
                        <input
                            type="text"
                            disabled
                            value={currentCategory ? currentCategory.normal_balance.toUpperCase() : 'DEBIT'}
                            className="w-full rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-2 text-xs sm:text-sm font-semibold text-slate-600 uppercase"
                        />
                    </div>

                    {/* Akun Induk (Hierarchy) */}
                    <div>
                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Akun Induk (Opsional)
                        </label>
                        <select
                            value={data.parent_id}
                            onChange={(e) => setData('parent_id', e.target.value)}
                            className="w-full rounded-lg border border-slate-300 px-3.5 py-2 text-xs sm:text-sm text-slate-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        >
                            <option value="">-- Tanpa Induk (Akun Utama) --</option>
                            {parents
                                .filter((p) => p.category === data.category)
                                .map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.code} - {p.name}
                                    </option>
                                ))}
                        </select>
                        {errors.parent_id && <p className="mt-1 text-xs text-rose-600">{errors.parent_id}</p>}
                    </div>

                    {/* Keterangan */}
                    <div>
                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Keterangan
                        </label>
                        <textarea
                            rows={3}
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder="Catatan tambahan mengenai penggunaan akun ini"
                            className="w-full rounded-lg border border-slate-300 px-3.5 py-2 text-xs sm:text-sm text-slate-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        />
                        {errors.description && <p className="mt-1 text-xs text-rose-600">{errors.description}</p>}
                    </div>

                    {/* Status Aktif */}
                    <div className="flex items-center gap-2 pt-1">
                        <input
                            type="checkbox"
                            id="is_active"
                            checked={data.is_active}
                            onChange={(e) => setData('is_active', e.target.checked)}
                            className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        <label htmlFor="is_active" className="text-xs font-semibold text-slate-700">
                            Akun Aktif (Dapat digunakan dalam jurnal dan transaksi)
                        </label>
                    </div>

                    {/* Tombol Simpan */}
                    <div className="flex justify-end gap-3 pt-4 border-t border-slate-100">
                        <Link
                            href={route('shm.coa.index')}
                            className="rounded-lg border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                        >
                            Batal
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-5 py-2 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 shadow-xs"
                        >
                            <Save className="h-4 w-4" />
                            <span>{processing ? 'Menyimpan...' : 'Simpan Akun'}</span>
                        </button>
                    </div>
                </form>
            </div>
        </ShmLayout>
    );
}

