import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import {
    Users,
    UserPlus,
    Search,
    Eye,
    Edit3,
    KeyRound,
    Ban,
    CheckCircle2,
    AlertCircle,
} from 'lucide-react';
import { formatDateTime } from '@/utils/format';

export default function Index({ users, roles, filters, flash }) {
    const [search, setSearch] = useState(filters.search || '');
    const [role, setRole] = useState(filters.role || 'all');
    const [status, setStatus] = useState(filters.status || 'all');
    const [toggleTarget, setToggleTarget] = useState(null);
    const [isToggling, setIsToggling] = useState(false);

    const applyFilters = (overrides = {}) => {
        router.get(
            route('pengaturan.users.index'),
            { search, role, status, ...overrides },
            { preserveState: true, replace: true }
        );
    };

    const confirmToggle = () => {
        if (!toggleTarget) return;
        setIsToggling(true);
        router.patch(
            route('pengaturan.users.toggle-active', toggleTarget.id),
            {},
            {
                onSuccess: () => {
                    setToggleTarget(null);
                    setIsToggling(false);
                },
                onError: () => setIsToggling(false),
                onFinish: () => setIsToggling(false),
            }
        );
    };

    return (
        <AdminLayout title="Pengguna" pageTitle="Manajemen Pengguna">
            {flash?.success && (
                <div className="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs sm:text-sm text-emerald-800 shadow-xs">
                    <CheckCircle2 className="h-5 w-5 shrink-0 text-emerald-600" />
                    <span className="font-medium">{flash.success}</span>
                </div>
            )}
            {flash?.error && (
                <div className="mb-6 flex items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs sm:text-sm text-rose-800 shadow-xs">
                    <AlertCircle className="h-5 w-5 shrink-0 text-rose-600" />
                    <span className="font-medium">{flash.error}</span>
                </div>
            )}

            {/* Header */}
            <div className="mb-6 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        <Users className="h-6 w-6 text-emerald-600" />
                        <span>Akun Pengguna Sistem</span>
                    </h2>
                    <p className="mt-1 text-xs text-slate-500 sm:text-sm">
                        Kelola akun petugas koperasi, status akses, dan penetapan role.
                    </p>
                </div>
                <Link
                    href={route('pengaturan.users.create')}
                    className="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2.5 text-xs font-semibold text-white shadow-xs transition-all hover:bg-slate-800 active:scale-95 sm:text-sm"
                >
                    <UserPlus className="h-4 w-4" />
                    <span>Tambah Pengguna</span>
                </Link>
            </div>

            {/* Filter bar */}
            <div className="mb-6 rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        applyFilters();
                    }}
                    className="flex flex-col gap-3 sm:flex-row"
                >
                    <div className="relative flex-1">
                        <Search className="pointer-events-none absolute inset-y-0 left-0 flex h-full w-4 pl-3 text-slate-400" />
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cari nama atau email pengguna..."
                            className="w-full rounded-lg border border-slate-300 bg-slate-50/50 py-2 pl-9 pr-4 text-xs text-slate-900 placeholder:text-slate-400 focus:border-slate-900 focus:bg-white focus:outline-none focus:ring-1 focus:ring-slate-900 sm:text-sm"
                        />
                    </div>
                    <select
                        value={role}
                        onChange={(e) => {
                            setRole(e.target.value);
                            applyFilters({ role: e.target.value });
                        }}
                        className="rounded-lg border border-slate-300 bg-slate-50/50 px-3 py-2 text-xs font-medium text-slate-700 focus:border-slate-900 focus:bg-white focus:outline-none focus:ring-1 focus:ring-slate-900 sm:text-sm"
                    >
                        <option value="all">Semua Role</option>
                        {roles.map((r) => (
                            <option key={r.id} value={r.name}>
                                {r.display_name}
                            </option>
                        ))}
                    </select>
                    <select
                        value={status}
                        onChange={(e) => {
                            setStatus(e.target.value);
                            applyFilters({ status: e.target.value });
                        }}
                        className="rounded-lg border border-slate-300 bg-slate-50/50 px-3 py-2 text-xs font-medium text-slate-700 focus:border-slate-900 focus:bg-white focus:outline-none focus:ring-1 focus:ring-slate-900 sm:text-sm"
                    >
                        <option value="all">Semua Status</option>
                        <option value="active">Aktif</option>
                        <option value="inactive">Nonaktif</option>
                    </select>
                    <button
                        type="submit"
                        className="rounded-lg border border-slate-200 bg-slate-100 px-4 py-2 text-xs font-semibold text-slate-800 transition-colors hover:bg-slate-200 active:scale-95 sm:text-sm"
                    >
                        Cari
                    </button>
                </form>
            </div>

            {/* Table */}
            <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                {users.data.length > 0 ? (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs sm:text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th scope="col" className="px-6 py-3.5">Nama / Email</th>
                                    <th scope="col" className="px-6 py-3.5">Role</th>
                                    <th scope="col" className="px-6 py-3.5">Status</th>
                                    <th scope="col" className="px-6 py-3.5">Login Terakhir</th>
                                    <th scope="col" className="px-6 py-3.5">Dibuat</th>
                                    <th scope="col" className="px-6 py-3.5 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 text-slate-700">
                                {users.data.map((user) => (
                                    <tr key={user.id} className="transition-colors hover:bg-slate-50/80">
                                        <td className="px-6 py-4">
                                            <div className="font-semibold text-slate-900">{user.name}</div>
                                            <div className="text-xs text-slate-400">{user.email}</div>
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="flex flex-wrap gap-1">
                                                {user.roles.length === 0 && (
                                                    <span className="text-xs text-slate-400">—</span>
                                                )}
                                                {user.roles.map((r) => (
                                                    <span
                                                        key={r.id}
                                                        className="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700"
                                                    >
                                                        {r.display_name}
                                                    </span>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4">
                                            {user.is_active ? (
                                                <span className="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">
                                                    Aktif
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600">
                                                    Nonaktif
                                                </span>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-xs text-slate-500">
                                            {user.last_login_at ? formatDateTime(user.last_login_at) : 'Belum pernah'}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-xs text-slate-500">
                                            {formatDateTime(user.created_at)}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right">
                                            <div className="flex items-center justify-end gap-1.5">
                                                <Link
                                                    href={route('pengaturan.users.show', user.id)}
                                                    className="rounded-lg p-1.5 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-900"
                                                    title="Detail"
                                                >
                                                    <Eye className="h-4 w-4" />
                                                </Link>
                                                <Link
                                                    href={route('pengaturan.users.edit', user.id)}
                                                    className="rounded-lg p-1.5 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-900"
                                                    title="Edit"
                                                >
                                                    <Edit3 className="h-4 w-4" />
                                                </Link>
                                                <button
                                                    type="button"
                                                    onClick={() => setToggleTarget(user)}
                                                    className={`rounded-lg p-1.5 transition-colors ${
                                                        user.is_active
                                                            ? 'text-rose-500 hover:bg-rose-50 hover:text-rose-700'
                                                            : 'text-emerald-600 hover:bg-emerald-50 hover:text-emerald-700'
                                                    }`}
                                                    title={user.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                                                >
                                                    {user.is_active ? <Ban className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4" />}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div className="p-8">
                        <EmptyState
                            icon={Users}
                            title="Tidak Ada Akun Pengguna"
                            description="Tidak ditemukan akun yang sesuai dengan pencarian atau filter."
                        />
                    </div>
                )}

                {users.links && users.links.length > 3 && (
                    <div className="flex flex-col items-center justify-between gap-4 border-t border-slate-200 bg-slate-50/50 px-6 py-4 text-xs text-slate-500 sm:flex-row">
                        <div>
                            Menampilkan <span className="font-semibold text-slate-900">{users.from || 0}</span>–
                            <span className="font-semibold text-slate-900">{users.to || 0}</span> dari{' '}
                            <span className="font-semibold text-slate-900">{users.total}</span> pengguna
                        </div>
                        <div className="flex items-center gap-1">
                            {users.links.map((link, idx) =>
                                link.url ? (
                                    <Link
                                        key={idx}
                                        href={link.url}
                                        className={`rounded-md px-3 py-1.5 font-semibold transition-colors ${
                                            link.active
                                                ? 'bg-slate-900 text-white shadow-xs'
                                                : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-200/80'
                                        }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ) : (
                                    <span
                                        key={idx}
                                        className="rounded-md bg-slate-100/60 px-3 py-1.5 font-medium text-slate-400"
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                )
                            )}
                        </div>
                    </div>
                )}
            </div>

            <ConfirmModal
                isOpen={!!toggleTarget}
                onClose={() => setToggleTarget(null)}
                onConfirm={confirmToggle}
                title={toggleTarget?.is_active ? 'Konfirmasi Nonaktifkan Akun' : 'Konfirmasi Aktivasi Akun'}
                message={
                    toggleTarget?.is_active
                        ? `Akun ${toggleTarget?.name} (${toggleTarget?.email}) akan dinonaktifkan dan tidak dapat login. Data transaksi historis tidak berubah.`
                        : `Akun ${toggleTarget?.name} (${toggleTarget?.email}) akan diaktifkan kembali dan dapat login.`
                }
                confirmText={toggleTarget?.is_active ? 'Ya, Nonaktifkan' : 'Ya, Aktifkan'}
                isProcessing={isToggling}
            />
        </AdminLayout>
    );
}
