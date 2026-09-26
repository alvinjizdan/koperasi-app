import React from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { ShieldCheck, KeyRound, Clock, CheckCircle2, AlertCircle } from 'lucide-react';
import { formatDateTime } from '@/utils/format';

export default function Security({ account, flash }) {
    return (
        <AdminLayout title="Keamanan Akun" pageTitle="Keamanan Akun Saya">
            {flash?.success && (
                <div className="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs text-emerald-800 shadow-xs sm:text-sm">
                    <CheckCircle2 className="h-5 w-5 shrink-0 text-emerald-600" />
                    <span className="font-medium">{flash.success}</span>
                </div>
            )}
            {flash?.error && (
                <div className="mb-6 flex items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs text-rose-800 shadow-xs sm:text-sm">
                    <AlertCircle className="h-5 w-5 shrink-0 text-rose-600" />
                    <span className="font-medium">{flash.error}</span>
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {/* Status akun */}
                <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:p-8">
                    <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                        <ShieldCheck className="h-4 w-4 text-slate-500" />
                        Status Akun
                    </h3>
                    <dl className="mt-4 space-y-4 text-xs sm:text-sm">
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-slate-500">Nama</dt>
                            <dd className="font-semibold text-slate-900">{account.name}</dd>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-slate-500">Email</dt>
                            <dd className="font-semibold text-slate-900">{account.email}</dd>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-slate-500">Role</dt>
                            <dd className="flex flex-wrap justify-end gap-1">
                                {account.roles.length === 0 ? (
                                    <span className="text-slate-400">—</span>
                                ) : (
                                    account.roles.map((name) => (
                                        <span
                                            key={name}
                                            className="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-700"
                                        >
                                            {name}
                                        </span>
                                    ))
                                )}
                            </dd>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-slate-500">Status Akun</dt>
                            <dd>
                                {account.is_active ? (
                                    <span className="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-800">
                                        Aktif
                                    </span>
                                ) : (
                                    <span className="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-[11px] font-semibold text-rose-800">
                                        Nonaktif
                                    </span>
                                )}
                            </dd>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-slate-500">Email Terverifikasi</dt>
                            <dd className="font-semibold text-slate-900">{account.email_verified ? 'Ya' : 'Belum'}</dd>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-slate-500">Akun Dibuat</dt>
                            <dd className="font-semibold text-slate-900">{formatDateTime(account.created_at)}</dd>
                        </div>
                    </dl>
                </div>

                {/* Aktivitas login & ubah password */}
                <div className="space-y-6">
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:p-8">
                        <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                            <Clock className="h-4 w-4 text-slate-500" />
                            Aktivitas Login
                        </h3>
                        <p className="mt-3 text-xs text-slate-600">
                            Login terakhir:{' '}
                            <span className="font-semibold text-slate-900">
                                {account.last_login_at ? formatDateTime(account.last_login_at) : 'Belum pernah'}
                            </span>
                        </p>
                        <p className="mt-2 text-[11px] text-slate-400">
                            Pengelolaan sesi aktif dan MFA belum tersedia pada versi ini dan menjadi pekerjaan lanjutan.
                        </p>
                    </div>

                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:p-8">
                        <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                            <KeyRound className="h-4 w-4 text-slate-500" />
                            Ubah Password
                        </h3>
                        <p className="mt-2 text-[11px] text-slate-500">
                            Ubah password dilakukan dari halaman profil dengan verifikasi password saat ini.
                        </p>
                        <a
                            href={route('profile.edit')}
                            className="mt-4 inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-xs transition-all hover:bg-slate-800 active:scale-95 sm:text-sm"
                        >
                            <KeyRound className="h-4 w-4" />
                            <span>Buka Form Ubah Password</span>
                        </a>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
