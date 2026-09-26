import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Edit3, ArrowLeft, Save } from 'lucide-react';

export default function Edit({ user }) {
    const { data, setData, put, processing, errors } = useForm({
        name: user.name || '',
        email: user.email || '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('pengaturan.users.update', user.id));
    };

    const inputClass = (field) =>
        `mt-1.5 w-full rounded-lg border px-3.5 py-2 text-xs text-slate-900 focus:outline-none focus:ring-1 sm:text-sm ${
            errors[field]
                ? 'border-rose-400 bg-rose-50/30 focus:border-rose-600 focus:ring-rose-600'
                : 'border-slate-300 bg-white focus:border-slate-900 focus:ring-slate-900'
        }`;

    return (
        <AdminLayout title={`Edit ${user.name}`} pageTitle="Edit Akun Pengguna">
            <div className="mb-6 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        <Edit3 className="h-6 w-6 text-emerald-600" />
                        <span>Edit Data Akun</span>
                    </h2>
                    <p className="mt-1 text-xs text-slate-500 sm:text-sm">
                        Email adalah identitas login dan harus tetap unik.
                    </p>
                </div>
                <Link
                    href={route('pengaturan.users.show', user.id)}
                    className="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 active:scale-95 sm:text-sm"
                >
                    <ArrowLeft className="h-4 w-4" />
                    <span>Kembali ke Detail</span>
                </Link>
            </div>

            <div className="mx-auto max-w-3xl rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:p-8">
                <form onSubmit={handleSubmit} className="space-y-6">
                    <div>
                        <label htmlFor="name" className="block text-xs font-semibold text-slate-900 sm:text-sm">
                            Nama Lengkap <span className="text-rose-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            className={inputClass('name')}
                        />
                        {errors.name && <p className="mt-1 text-xs font-medium text-rose-600">{errors.name}</p>}
                    </div>

                    <div>
                        <label htmlFor="email" className="block text-xs font-semibold text-slate-900 sm:text-sm">
                            Email <span className="text-rose-500">*</span>
                        </label>
                        <input
                            type="email"
                            id="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className={inputClass('email')}
                        />
                        {errors.email && <p className="mt-1 text-xs font-medium text-rose-600">{errors.email}</p>}
                        <p className="mt-1 text-[11px] text-slate-400">
                            Password dikelola melalui fitur reset terpisah pada halaman detail pengguna.
                        </p>
                    </div>

                    <div className="flex items-center justify-end gap-3 border-t border-slate-200 pt-6">
                        <Link
                            href={route('pengaturan.users.show', user.id)}
                            className="rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 active:scale-95 sm:text-sm"
                        >
                            Batal
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-6 py-2.5 text-xs font-semibold text-white shadow-xs transition-all hover:bg-slate-800 disabled:opacity-50 active:scale-95 sm:text-sm"
                        >
                            <Save className="h-4 w-4" />
                            <span>{processing ? 'Memperbarui...' : 'Simpan Perubahan'}</span>
                        </button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
