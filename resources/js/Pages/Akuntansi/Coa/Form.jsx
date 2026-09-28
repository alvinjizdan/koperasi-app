import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { ArrowLeft, Save } from 'lucide-react';

export default function Form({ categories, parents, account }) {
    const isEdit = account !== null;

    const [values, setValues] = useState({
        code: account?.code ?? '',
        name: account?.name ?? '',
        category: account?.category ?? 'asset',
        parent_id: account?.parent_id ?? '',
        description: account?.description ?? '',
    });
    const [processing, setProcessing] = useState(false);

    const submit = (event) => {
        event.preventDefault();
        setProcessing(true);

        if (isEdit) {
            router.put(route('akuntansi.coa.update', account.id), values, { onFinish: () => setProcessing(false) });
        } else {
            router.post(route('akuntansi.coa.store'), values, { onFinish: () => setProcessing(false) });
        }
    };

    const categoryDefinition = categories.find((category) => category.value === values.category);

    const inputClass = 'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20';

    return (
        <AdminLayout title={isEdit ? 'Ubah Akun COA' : 'Tambah Akun COA'} pageTitle="Chart of Accounts">
            <div className="mb-6">
                <Link
                    href={route('akuntansi.coa.index')}
                    className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 hover:text-slate-900"
                >
                    <ArrowLeft className="h-4 w-4" /> Kembali ke Daftar Perkiraan
                </Link>
            </div>

            <form onSubmit={submit} className="max-w-2xl rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label className="text-xs font-semibold text-slate-600" htmlFor="code">Kode Akun *</label>
                        <input
                            id="code"
                            type="text"
                            value={values.code}
                            onChange={(event) => setValues({ ...values, code: event.target.value })}
                            className={inputClass}
                            maxLength={20}
                            required
                        />
                    </div>

                    <div>
                        <label className="text-xs font-semibold text-slate-600" htmlFor="name">Nama Akun *</label>
                        <input
                            id="name"
                            type="text"
                            value={values.name}
                            onChange={(event) => setValues({ ...values, name: event.target.value })}
                            className={inputClass}
                            maxLength={150}
                            required
                        />
                    </div>

                    <div>
                        <label className="text-xs font-semibold text-slate-600" htmlFor="category">Kategori *</label>
                        <select
                            id="category"
                            value={values.category}
                            onChange={(event) => setValues({ ...values, category: event.target.value })}
                            className={inputClass}
                            disabled={isEdit}
                        >
                            {categories.map((category) => (
                                <option key={category.value} value={category.value}>{category.label}</option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label className="text-xs font-semibold text-slate-600">Saldo Normal</label>
                        <p className="mt-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold uppercase text-slate-700">
                            {categoryDefinition?.normal_balance ?? '-'} (otomatis dari kategori)
                        </p>
                    </div>

                    <div>
                        <label className="text-xs font-semibold text-slate-600" htmlFor="parent_id">Akun Induk (opsional)</label>
                        <select
                            id="parent_id"
                            value={values.parent_id}
                            onChange={(event) => setValues({ ...values, parent_id: event.target.value })}
                            className={inputClass}
                        >
                            <option value="">— Tanpa induk —</option>
                            {parents
                                .filter((parent) => !isEdit || parent.id !== account.id)
                                .map((parent) => (
                                    <option key={parent.id} value={parent.id}>
                                        {parent.code} — {parent.name}
                                    </option>
                                ))}
                        </select>
                    </div>

                    <div className="sm:col-span-2">
                        <label className="text-xs font-semibold text-slate-600" htmlFor="description">Deskripsi (opsional)</label>
                        <textarea
                            id="description"
                            value={values.description}
                            onChange={(event) => setValues({ ...values, description: event.target.value })}
                            className={inputClass}
                            rows={3}
                            maxLength={1000}
                        />
                    </div>
                </div>

                <div className="mt-6 flex items-center gap-3">
                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                    >
                        <Save className="h-4 w-4" />
                        {isEdit ? 'Simpan Perubahan' : 'Simpan Akun'}
                    </button>
                    <Link
                        href={route('akuntansi.coa.index')}
                        className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    >
                        Batal
                    </Link>
                </div>
            </form>
        </AdminLayout>
    );
}
