import React from "react";
import { Head, Link, useForm } from "@inertiajs/react";
import ShmLayout from "@/Layouts/ShmLayout";
import { ArrowLeft, Shield, Save, User as UserIcon } from "lucide-react";

export default function Show({ user, assignableRoles = [] }) {
    const { data, setData, patch, processing, errors } = useForm({
        roles: user.shm_roles ? user.shm_roles.map((r) => r.id) : [],
    });

    const toggleRole = (roleId) => {
        if (data.roles.includes(roleId)) {
            setData("roles", data.roles.filter((id) => id !== roleId));
        } else {
            setData("roles", [...data.roles, roleId]);
        }
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        patch(route("shm.settings.users.update-roles", user.id));
    };

    return (
        <ShmLayout title={`Akses SHM: ${user.name}`} pageTitle={`Akses SHM: ${user.name}`}>
            <Head title={`Akses SHM: ${user.name}`} />

            <div className="mx-auto max-w-3xl space-y-6">
                <div className="flex items-center gap-3">
                    <Link
                        href={route("shm.settings.users.index")}
                        className="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-50 transition-colors"
                    >
                        <ArrowLeft className="h-4 w-4 mr-1" />
                        Kembali ke Daftar
                    </Link>
                    <div>
                        <h1 className="text-xl font-bold tracking-tight text-slate-900">
                            Kelola Akses SHM: {user.name}
                        </h1>
                        <p className="text-sm text-slate-500">
                            Tugaskan atau cabut role operasional BSM Makro untuk akun ini.
                        </p>
                    </div>
                </div>

                <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="flex items-center gap-4">
                        <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-slate-100 text-slate-600">
                            <UserIcon className="h-6 w-6" />
                        </div>
                        <div>
                            <div className="text-lg font-semibold text-slate-900">{user.name}</div>
                            <div className="text-sm text-slate-500">{user.email}</div>
                        </div>
                        <div className="ml-auto">
                            {user.is_active ? (
                                <span className="inline-flex items-center rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700">
                                    Akun Aktif
                                </span>
                            ) : (
                                <span className="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-500">
                                    Akun Nonaktif
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                            <div>
                                <h2 className="text-base font-semibold text-slate-900">Penugasan Role SHM</h2>
                                <p className="text-xs text-slate-500">
                                    Hanya menampilkan role yang termasuk dalam domain operasional BSM Makro (makro).
                                </p>
                            </div>
                            <span className="text-xs font-medium text-slate-500">
                                {data.roles.length} role dipilih
                            </span>
                        </div>

                        {errors.roles && (
                            <p className="text-xs text-rose-600">{errors.roles}</p>
                        )}

                        <div className="grid grid-cols-1 gap-3">
                            {assignableRoles.length === 0 ? (
                                <p className="text-sm text-slate-400 py-4 text-center">
                                    Tidak ada role SHM aktif yang tersedia.
                                </p>
                            ) : (
                                assignableRoles.map((role) => {
                                    const isSelected = data.roles.includes(role.id);
                                    return (
                                        <label
                                            key={role.id}
                                            className={`flex items-start gap-3 rounded-lg border p-4 cursor-pointer select-none transition-colors ${
                                                isSelected
                                                    ? "border-bsm-accent bg-bsm-accent/5 text-slate-900"
                                                    : "border-slate-200 hover:bg-slate-50 text-slate-700"
                                            }`}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={isSelected}
                                                onChange={() => toggleRole(role.id)}
                                                className="mt-1 rounded border-slate-300 text-bsm-accent focus:ring-bsm-accent"
                                            />
                                            <div className="flex-1">
                                                <div className="flex items-center gap-2">
                                                    <Shield className="h-4 w-4 text-bsm-accent" />
                                                    <span className="font-medium text-sm text-slate-900">
                                                        {role.display_name}
                                                    </span>
                                                    <span className="font-mono text-[11px] text-slate-400">
                                                        ({role.name})
                                                    </span>
                                                </div>
                                                <p className="text-xs text-slate-500 mt-1">
                                                    {role.description || "Tidak ada deskripsi"}
                                                </p>
                                            </div>
                                        </label>
                                    );
                                })
                            )}
                        </div>
                    </div>

                    <div className="flex items-center justify-end gap-3">
                        <Link
                            href={route("shm.settings.users.index")}
                            className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 transition-colors"
                        >
                            Batal
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-500 disabled:opacity-50 transition-colors"
                        >
                            <Save className="h-4 w-4" />
                            Simpan Perubahan Akses
                        </button>
                    </div>
                </form>
            </div>
        </ShmLayout>
    );
}
