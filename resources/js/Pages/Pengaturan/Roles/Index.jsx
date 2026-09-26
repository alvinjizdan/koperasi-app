import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import {
    ShieldCheck,
    Plus,
    Edit3,
    Trash2,
    Users,
    Lock,
    CheckCircle2,
    AlertCircle,
} from 'lucide-react';

export default function Index({ roles, flash }) {
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const confirmDelete = () => {
        if (!deleteTarget) return;
        setIsDeleting(true);
        router.delete(route('pengaturan.roles.destroy', deleteTarget.id), {
            onSuccess: () => {
                setDeleteTarget(null);
                setIsDeleting(false);
            },
            onError: () => setIsDeleting(false),
            onFinish: () => setIsDeleting(false),
        });
    };

    return (
        <AdminLayout title="Role & Permission" pageTitle="Manajemen Role">
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

            <div className="mb-6 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        <ShieldCheck className="h-6 w-6 text-emerald-600" />
                        <span>Role Aplikasi</span>
                    </h2>
                    <p className="mt-1 text-xs text-slate-500 sm:text-sm">
                        Role mengelompokkan permission. Role sistem dan role yang masih dipakai pengguna tidak dapat dihapus.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link
                        href={route('pengaturan.roles.permissions')}
                        className="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 active:scale-95 sm:text-sm"
                    >
                        <Lock className="h-4 w-4" />
                        <span>Matriks Permission</span>
                    </Link>
                    <Link
                        href={route('pengaturan.roles.create')}
                        className="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2.5 text-xs font-semibold text-white shadow-xs transition-all hover:bg-slate-800 active:scale-95 sm:text-sm"
                    >
                        <Plus className="h-4 w-4" />
                        <span>Tambah Role</span>
                    </Link>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                {roles.map((role) => (
                    <div key={role.id} className="flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                        <div className="flex items-start justify-between gap-2">
                            <div>
                                <h3 className="flex items-center gap-2 text-sm font-bold text-slate-900">
                                    {role.display_name}
                                    {role.is_superadmin && (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">
                                            <ShieldCheck className="h-3 w-3" />
                                            Sistem
                                        </span>
                                    )}
                                </h3>
                                <p className="mt-0.5 font-mono text-[11px] text-slate-400">{role.name}</p>
                            </div>
                        </div>

                        <p className="mt-2 flex-1 text-xs text-slate-500">{role.description || '—'}</p>

                        <div className="mt-3 flex items-center gap-4 text-[11px] text-slate-500">
                            <span className="inline-flex items-center gap-1">
                                <Lock className="h-3.5 w-3.5" />
                                {role.is_superadmin ? 'Semua permission' : `${role.permissions_count} permission`}
                            </span>
                            <span className="inline-flex items-center gap-1">
                                <Users className="h-3.5 w-3.5" />
                                {role.users_count} pengguna
                            </span>
                        </div>

                        <div className="mt-4 flex items-center gap-2 border-t border-slate-100 pt-3">
                            <Link
                                href={route('pengaturan.roles.users', role.id)}
                                className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-1.5 text-[11px] font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                            >
                                <Users className="h-3.5 w-3.5" />
                                Pengguna
                            </Link>
                            <Link
                                href={route('pengaturan.roles.edit', role.id)}
                                className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-1.5 text-[11px] font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                            >
                                <Edit3 className="h-3.5 w-3.5" />
                                Edit
                            </Link>
                            {!role.is_system && (
                                <button
                                    type="button"
                                    onClick={() => setDeleteTarget(role)}
                                    className="inline-flex items-center gap-1 rounded-lg border border-rose-200 px-3 py-1.5 text-[11px] font-semibold text-rose-600 transition-colors hover:bg-rose-50"
                                >
                                    <Trash2 className="h-3.5 w-3.5" />
                                    Hapus
                                </button>
                            )}
                        </div>
                    </div>
                ))}
            </div>

            <ConfirmModal
                isOpen={!!deleteTarget}
                onClose={() => setDeleteTarget(null)}
                onConfirm={confirmDelete}
                title="Konfirmasi Hapus Role"
                message={`Role ${deleteTarget?.display_name} beserta hubungannya dengan permission akan dihapus. Role yang masih dipakai pengguna akan ditolak sistem.`}
                confirmText="Ya, Hapus Role"
                isProcessing={isDeleting}
            />
        </AdminLayout>
    );
}
