import React, { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import PasswordInput from '@/Components/PasswordInput';
import {
    ArrowLeft,
    Ban,
    CheckCircle2,
    Edit3,
    KeyRound,
    ShieldCheck,
    UserCog,
} from 'lucide-react';
import { formatDateTime } from '@/utils/format';

export default function Show({ user, allRoles, flash }) {
    const [confirmToggle, setConfirmToggle] = useState(false);
    const [isToggling, setIsToggling] = useState(false);

    const roleForm = useForm({
        roles: user.roles.map((r) => r.id),
    });
    const resetForm = useForm({
        password: '',
        password_confirmation: '',
    });

    const selectedRoleIds = roleForm.data.roles;

    const toggleRole = (roleId) => {
        const next = selectedRoleIds.includes(roleId)
            ? selectedRoleIds.filter((id) => id !== roleId)
            : [...selectedRoleIds, roleId];
        roleForm.setData('roles', next);
    };

    const submitRoles = (e) => {
        e.preventDefault();
        roleForm.patch(route('pengaturan.users.update-roles', user.id), {
            onSuccess: () => roleForm.setData('roles', selectedRoleIds),
        });
    };

    const submitReset = (e) => {
        e.preventDefault();
        resetForm.patch(route('pengaturan.users.reset-password', user.id), {
            onSuccess: () => resetForm.reset(),
        });
    };

    const performToggle = () => {
        setIsToggling(true);
        router.patch(
            route('pengaturan.users.toggle-active', user.id),
            {},
            {
                onSuccess: () => {
                    setConfirmToggle(false);
                    setIsToggling(false);
                },
                onError: () => setIsToggling(false),
                onFinish: () => setIsToggling(false),
            }
        );
    };

    const isSelf = user.email === (flash?.auth_email ?? null);
    const moduleEntries = Object.entries(user.permissions || {});

    const inputClass = (field) =>
        `mt-1.5 w-full rounded-lg border px-3.5 py-2 text-xs text-slate-900 focus:outline-none focus:ring-1 sm:text-sm ${
            resetForm.errors[field]
                ? 'border-rose-400 bg-rose-50/30 focus:border-rose-600 focus:ring-rose-600'
                : 'border-slate-300 bg-white focus:border-slate-900 focus:ring-slate-900'
        }`;

    return (
        <AdminLayout title={`Pengguna: ${user.name}`} pageTitle="Detail Akun Pengguna">
            {flash?.success && (
                <div className="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs text-emerald-800 shadow-xs sm:text-sm">
                    <CheckCircle2 className="h-5 w-5 shrink-0 text-emerald-600" />
                    <span className="font-medium">{flash.success}</span>
                </div>
            )}
            {flash?.error && (
                <div className="mb-6 flex items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs text-rose-800 shadow-xs sm:text-sm">
                    <Ban className="h-5 w-5 shrink-0 text-rose-600" />
                    <span className="font-medium">{flash.error}</span>
                </div>
            )}

            <div className="mb-6 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-4">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-slate-900 text-lg font-bold text-white">
                        {user.name.charAt(0).toUpperCase()}
                    </div>
                    <div>
                        <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">{user.name}</h2>
                        <p className="text-xs text-slate-500 sm:text-sm">{user.email}</p>
                        <div className="mt-1.5 flex flex-wrap items-center gap-2">
                            {user.is_active ? (
                                <span className="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-800">
                                    Aktif
                                </span>
                            ) : (
                                <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600">
                                    Nonaktif
                                </span>
                            )}
                            {user.is_superadmin && (
                                <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-semibold text-amber-800">
                                    <ShieldCheck className="h-3 w-3" />
                                    Superadmin
                                </span>
                            )}
                        </div>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Link
                        href={route('pengaturan.users.index')}
                        className="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 active:scale-95 sm:text-sm"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        <span>Daftar</span>
                    </Link>
                    <Link
                        href={route('pengaturan.users.edit', user.id)}
                        className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-xs transition-all hover:bg-slate-800 active:scale-95 sm:text-sm"
                    >
                        <Edit3 className="h-4 w-4" />
                        <span>Edit Data</span>
                    </Link>
                    <button
                        type="button"
                        onClick={() => setConfirmToggle(true)}
                        disabled={user.is_last_active_superadmin}
                        className={`inline-flex items-center gap-2 rounded-lg px-4 py-2 text-xs font-semibold transition-all active:scale-95 sm:text-sm ${
                            user.is_active
                                ? 'bg-rose-600 text-white shadow-xs hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50'
                                : 'bg-emerald-600 text-white shadow-xs hover:bg-emerald-700'
                        }`}
                    >
                        {user.is_active ? <Ban className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4" />}
                        <span>{user.is_active ? 'Nonaktifkan' : 'Aktifkan'}</span>
                    </button>
                </div>
            </div>

            {user.is_last_active_superadmin && (
                <div className="mb-6 flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-800 sm:text-sm">
                    <ShieldCheck className="h-5 w-5 shrink-0 text-amber-600" />
                    <span className="font-medium">
                        Akun ini adalah satu-satunya Superadmin aktif — status dan role Superadmin tidak dapat dilepas.
                    </span>
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {/* Kolom kiri: info & penetapan role */}
                <div className="space-y-6 lg:col-span-2">
                    <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                        <div className="border-b border-slate-100 px-6 py-4">
                            <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                <UserCog className="h-4 w-4 text-slate-500" />
                                Informasi Akun
                            </h3>
                        </div>
                        <dl className="grid grid-cols-1 gap-4 px-6 py-4 text-xs sm:grid-cols-2 sm:text-sm">
                            <div>
                                <dt className="text-slate-500">Status Akun</dt>
                                <dd className="font-semibold text-slate-900">{user.is_active ? 'Aktif' : 'Nonaktif'}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Email Terverifikasi</dt>
                                <dd className="font-semibold text-slate-900">{user.email_verified ? 'Ya' : 'Belum'}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Login Terakhir</dt>
                                <dd className="font-semibold text-slate-900">
                                    {user.last_login_at ? formatDateTime(user.last_login_at) : 'Belum pernah'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Akun Dibuat</dt>
                                <dd className="font-semibold text-slate-900">{formatDateTime(user.created_at)}</dd>
                            </div>
                        </dl>
                    </div>

                    {/* Penetapan role */}
                    <form
                        onSubmit={submitRoles}
                        className="rounded-xl border border-slate-200 bg-white shadow-xs"
                    >
                        <div className="border-b border-slate-100 px-6 py-4">
                            <h3 className="text-sm font-semibold text-slate-900">Penetapan Role</h3>
                            <p className="mt-0.5 text-[11px] text-slate-500">
                                Perubahan role tercatat pada audit trail beserta pelaku dan waktunya.
                            </p>
                        </div>
                        <div className="grid grid-cols-1 gap-2 px-6 py-4 sm:grid-cols-2">
                            {allRoles.map((role) => (
                                <label
                                    key={role.id}
                                    className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors ${
                                        selectedRoleIds.includes(role.id)
                                            ? 'border-emerald-400 bg-emerald-50/60'
                                            : 'border-slate-200 bg-white hover:bg-slate-50'
                                    }`}
                                >
                                    <input
                                        type="checkbox"
                                        checked={selectedRoleIds.includes(role.id)}
                                        onChange={() => toggleRole(role.id)}
                                        className="mt-0.5 h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-900"
                                    />
                                    <div>
                                        <p className="text-xs font-semibold text-slate-900 sm:text-sm">{role.display_name}</p>
                                        {role.description && <p className="text-[11px] text-slate-500">{role.description}</p>}
                                    </div>
                                </label>
                            ))}
                        </div>
                        {roleForm.errors.roles && (
                            <p className="px-6 text-xs font-medium text-rose-600">{roleForm.errors.roles}</p>
                        )}
                        <div className="flex justify-end border-t border-slate-100 px-6 py-4">
                            <button
                                type="submit"
                                disabled={roleForm.processing}
                                className="rounded-lg bg-slate-900 px-5 py-2 text-xs font-semibold text-white shadow-xs transition-all hover:bg-slate-800 disabled:opacity-50 active:scale-95 sm:text-sm"
                            >
                                {roleForm.processing ? 'Menyimpan...' : 'Simpan Role'}
                            </button>
                        </div>
                    </form>

                    {/* Permission efektif */}
                    <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                        <div className="border-b border-slate-100 px-6 py-4">
                            <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                <ShieldCheck className="h-4 w-4 text-slate-500" />
                                Permission Efektif
                            </h3>
                            <p className="mt-0.5 text-[11px] text-slate-500">
                                Gabungan permission dari seluruh role yang dipegang akun ini.
                                {user.is_superadmin && ' Superadmin memiliki seluruh permission.'}
                            </p>
                        </div>
                        <div className="px-6 py-4">
                            {moduleEntries.length === 0 ? (
                                <p className="text-xs text-slate-500">Akun belum memiliki permission apa pun.</p>
                            ) : (
                                <div className="space-y-4">
                                    {moduleEntries.map(([module, perms]) => (
                                        <div key={module}>
                                            <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                                {module}
                                            </p>
                                            <div className="mt-1.5 flex flex-wrap gap-1.5">
                                                {perms.map((p) => (
                                                    <span
                                                        key={p.name}
                                                        title={p.display_name}
                                                        className="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-medium text-slate-700"
                                                    >
                                                        {p.name}
                                                    </span>
                                                ))}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* Kolom kanan: reset password */}
                <div className="space-y-6">
                    <form onSubmit={submitReset} className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                        <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                            <KeyRound className="h-4 w-4 text-slate-500" />
                            Reset Password
                        </h3>
                        <p className="mt-1 text-[11px] text-slate-500">
                            Tetapkan password baru untuk akun ini. Password tidak disimpan dalam bentuk teks biasa dan
                            tidak dicatat pada audit trail.
                        </p>
                        <div className="mt-4 space-y-4">
                            <div>
                                <label htmlFor="password" className="block text-xs font-semibold text-slate-900 sm:text-sm">
                                    Password Baru <span className="text-rose-500">*</span>
                                </label>
                                <PasswordInput
                                    id="password"
                                    value={resetForm.data.password}
                                    onChange={(e) => resetForm.setData('password', e.target.value)}
                                    className={inputClass('password')}
                                />
                                {resetForm.errors.password && (
                                    <p className="mt-1 text-xs font-medium text-rose-600">{resetForm.errors.password}</p>
                                )}
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
                                    value={resetForm.data.password_confirmation}
                                    onChange={(e) => resetForm.setData('password_confirmation', e.target.value)}
                                    className={inputClass('password_confirmation')}
                                />
                            </div>
                            <button
                                type="submit"
                                disabled={resetForm.processing}
                                className="w-full rounded-lg bg-rose-600 px-4 py-2 text-xs font-semibold text-white shadow-xs transition-all hover:bg-rose-700 disabled:opacity-50 active:scale-95 sm:text-sm"
                            >
                                {resetForm.processing ? 'Memproses...' : 'Reset Password Akun'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <ConfirmModal
                isOpen={confirmToggle}
                onClose={() => setConfirmToggle(false)}
                onConfirm={performToggle}
                title={user.is_active ? 'Konfirmasi Nonaktifkan Akun' : 'Konfirmasi Aktivasi Akun'}
                message={
                    user.is_active
                        ? `Akun ${user.name} akan dinonaktifkan dan tidak dapat login lagi. Riwayat transaksi tetap tersimpan.`
                        : `Akun ${user.name} akan diaktifkan kembali dan dapat login.`
                }
                confirmText={user.is_active ? 'Ya, Nonaktifkan' : 'Ya, Aktifkan'}
                isProcessing={isToggling}
            />
        </AdminLayout>
    );
}
