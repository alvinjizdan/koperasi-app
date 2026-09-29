import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PasswordInput from '@/Components/PasswordInput';
import { UserPlus, ArrowLeft, Save, KeyRound } from 'lucide-react';

export default function Create({ roles }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        roles: [],
        is_active: true,
    });

    const toggleRole = (roleId) => {
        const next = data.roles.includes(roleId)
            ? data.roles.filter((id) => id !== roleId)
            : [...data.roles, roleId];
        setData('roles', next);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('pengaturan.users.store'));
    };

    const inputClass = (field) =>
        `mt-1.5 w-full rounded-lg border px-3.5 py-2 text-xs text-slate-900 focus:outline-none focus:ring-1 sm:text-sm ${
            errors[field]
                ? 'border-rose-400 bg-rose-50/30 focus:border-rose-600 focus:ring-rose-600'
                : 'border-slate-300 bg-white focus:border-slate-900 focus:ring-slate-900'
        }`;

    return (
        <AdminLayout title="Tambah Pengguna" pageTitle="Form Akun Pengguna Baru">
            <div className="mb-6 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        <UserPlus className="h-6 w-6 text-emerald-600" />
                        <span>Akun Pengguna Baru</span>
                    </h2>
                    <p className="mt-1 text-xs text-slate-500 sm:text-sm">
                        Password disimpan dalam bentuk hash dan tidak dapat dilihat kembali.
                    </p>
                </div>
                <Link
                    href={route('pengaturan.users.index')}
                    className="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 active:scale-95 sm:text-sm"
                >
                    <ArrowLeft className="h-4 w-4" />
                    <span>Kembali ke Daftar</span>
                </Link>
            </div>

            <div className="mx-auto max-w-4xl rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:p-8">
                <form onSubmit={handleSubmit} className="space-y-6">
                    <div>
                        <h3 className="border-b border-slate-100 pb-2 text-sm font-semibold uppercase tracking-wider text-slate-500">
                            Identitas Akun
                        </h3>
                        <div className="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <div>
                                <label htmlFor="name" className="block text-xs font-semibold text-slate-900 sm:text-sm">
                                    Nama Lengkap <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="Nama petugas"
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
                                    placeholder="petugas@koperasi.id"
                                    className={inputClass('email')}
                                />
                                {errors.email ? (
                                    <p className="mt-1 text-xs font-medium text-rose-600">{errors.email}</p>
                                ) : (
                                    <p className="mt-1 text-[11px] text-slate-400">Email dipakai untuk login dan harus unik.</p>
                                )}
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 className="border-b border-slate-100 pb-2 text-sm font-semibold uppercase tracking-wider text-slate-500">
                            Password Awal
                        </h3>
                        <div className="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <div>
                                <label htmlFor="password" className="block text-xs font-semibold text-slate-900 sm:text-sm">
                                    Password <span className="text-rose-500">*</span>
                                </label>
                                <PasswordInput
                                    id="password"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    className={inputClass('password')}
                                />
                                {errors.password && <p className="mt-1 text-xs font-medium text-rose-600">{errors.password}</p>}
                            </div>
                            <div>
                                <label
                                    htmlFor="password_confirmation"
                                    className="block text-xs font-semibold text-slate-900 sm:text-sm"
                                >
                                    Ulangi Password <span className="text-rose-500">*</span>
                                </label>
                                <PasswordInput
                                    id="password_confirmation"
                                    value={data.password_confirmation}
                                    onChange={(e) => setData('password_confirmation', e.target.value)}
                                    className={inputClass('password_confirmation')}
                                />
                            </div>
                        </div>
                        <p className="mt-2 flex items-center gap-1.5 text-[11px] text-slate-400">
                            <KeyRound className="h-3.5 w-3.5" />
                            Password disimpan menggunakan hashing bawaan Laravel (bcrypt) dan tidak pernah tampil kembali.
                        </p>
                    </div>

                    <div>
                        <h3 className="border-b border-slate-100 pb-2 text-sm font-semibold uppercase tracking-wider text-slate-500">
                            Role &amp; Status Akun
                        </h3>
                        <div className="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            {roles.map((role) => (
                                <label
                                    key={role.id}
                                    className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors ${
                                        data.roles.includes(role.id)
                                            ? 'border-emerald-400 bg-emerald-50/60'
                                            : 'border-slate-200 bg-white hover:bg-slate-50'
                                    }`}
                                >
                                    <input
                                        type="checkbox"
                                        checked={data.roles.includes(role.id)}
                                        onChange={() => toggleRole(role.id)}
                                        className="mt-0.5 h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-900"
                                    />
                                    <div>
                                        <p className="text-xs font-semibold text-slate-900 sm:text-sm">{role.display_name}</p>
                                        {role.description && (
                                            <p className="text-[11px] text-slate-500">{role.description}</p>
                                        )}
                                    </div>
                                </label>
                            ))}
                        </div>
                        {errors.roles && <p className="mt-1 text-xs font-medium text-rose-600">{errors.roles}</p>}

                        <label className="mt-4 flex cursor-pointer items-center gap-2 text-xs font-medium text-slate-700 sm:text-sm">
                            <input
                                type="checkbox"
                                checked={data.is_active}
                                onChange={(e) => setData('is_active', e.target.checked)}
                                className="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-900"
                            />
                            <span>Akun aktif dan dapat login</span>
                        </label>
                    </div>

                    <div className="flex items-center justify-end gap-3 border-t border-slate-200 pt-6">
                        <Link
                            href={route('pengaturan.users.index')}
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
                            <span>{processing ? 'Menyimpan...' : 'Simpan Akun'}</span>
                        </button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
