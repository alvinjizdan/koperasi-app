import React, { useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import ShmLayout from "@/Layouts/ShmLayout";
import ConfirmModal from "@/Components/Admin/ConfirmModal";
import { Shield, Plus, Edit2, Trash2 } from "lucide-react";

export default function Index({ roles = [], flash = {} }) {
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const confirmDelete = () => {
        if (!deleteTarget) return;
        setIsDeleting(true);
        router.delete(route("shm.settings.roles.destroy", deleteTarget.id), {
            onSuccess: () => {
                setDeleteTarget(null);
                setIsDeleting(false);
            },
            onError: () => setIsDeleting(false),
            onFinish: () => setIsDeleting(false),
        });
    };

    return (
        <ShmLayout title="Pengaturan Role SHM" pageTitle="Pengaturan Role SHM">
            <Head title="Pengaturan Role SHM" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-xl font-bold tracking-tight text-slate-900">
                            Role & Hak Akses SHM
                        </h1>
                        <p className="text-sm text-slate-500">
                            Kelola role operasional yang berlaku khusus pada domain BSM Makro (SHM/SHGB).
                        </p>
                    </div>

                    <Link
                        href={route("shm.settings.roles.create")}
                        className="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-500 transition-colors"
                    >
                        <Plus className="h-4 w-4" />
                        Tambah Role SHM
                    </Link>
                </div>

                {flash?.success && (
                    <div className="rounded-lg bg-emerald-50 p-4 text-sm text-emerald-800 border border-emerald-200">
                        {flash.success}
                    </div>
                )}

                {flash?.error && (
                    <div className="rounded-lg bg-rose-50 p-4 text-sm text-rose-800 border border-rose-200">
                        {flash.error}
                    </div>
                )}

                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm text-slate-600">
                            <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                <tr>
                                    <th className="px-6 py-4">Role</th>
                                    <th className="px-6 py-4">Identifier</th>
                                    <th className="px-6 py-4">Pengguna</th>
                                    <th className="px-6 py-4">Status</th>
                                    <th className="px-6 py-4 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {roles.length === 0 ? (
                                    <tr>
                                        <td colSpan="5" className="px-6 py-8 text-center text-slate-400">
                                            Belum ada role SHM yang terdaftar.
                                        </td>
                                    </tr>
                                ) : (
                                    roles.map((role) => (
                                        <tr key={role.id} className="hover:bg-slate-50/50">
                                            <td className="px-6 py-4">
                                                <div className="flex items-center gap-3">
                                                    <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                                                        <Shield className="h-4 w-4" />
                                                    </div>
                                                    <div>
                                                        <div className="font-medium text-slate-900">
                                                            {role.display_name}
                                                        </div>
                                                        <div className="text-xs text-slate-400">
                                                            {role.description || "Tidak ada deskripsi"}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 font-mono text-xs text-slate-600">
                                                {role.name}
                                            </td>
                                            <td className="px-6 py-4">
                                                <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">
                                                    {role.users_count} Pengguna
                                                </span>
                                            </td>
                                            <td className="px-6 py-4">
                                                {role.is_active ? (
                                                    <span className="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                                                        Aktif
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
                                                        Nonaktif
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <Link
                                                        href={route("shm.settings.roles.edit", role.id)}
                                                        className="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50 transition-colors"
                                                    >
                                                        <Edit2 className="h-3.5 w-3.5 mr-1" />
                                                        Edit
                                                    </Link>
                                                    {!role.is_system && (
                                                        <button
                                                            type="button"
                                                            className="inline-flex items-center rounded-md p-1.5 text-rose-600 hover:bg-rose-50 hover:text-rose-700 transition-colors"
                                                            onClick={() => setDeleteTarget(role)}
                                                            title="Hapus Role"
                                                        >
                                                            <Trash2 className="h-3.5 w-3.5" />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <ConfirmModal
                isOpen={!!deleteTarget}
                onClose={() => setDeleteTarget(null)}
                onConfirm={confirmDelete}
                title="Konfirmasi Hapus Role SHM"
                message={`Anda akan menghapus role ${deleteTarget?.display_name} (Entity: ${deleteTarget?.entity || 'makro'}). ${
                    deleteTarget?.users_count > 0
                        ? `Saat ini terdapat ${deleteTarget.users_count} pengguna yang terhubung ke role ini. Sistem akan menolak penghapusan bila masih ada pengguna aktif.`
                        : 'Pengguna yang terhubung dengan role ini dapat kehilangan akses yang berasal dari role tersebut.'
                } Tindakan ini tidak dapat dibatalkan.`}
                confirmText="Ya, Hapus Role"
                isProcessing={isDeleting}
            />
        </ShmLayout>
    );
}
