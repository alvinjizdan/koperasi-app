import React from "react";
import { Head, Link, useForm } from "@inertiajs/react";
import ShmLayout from "@/Layouts/ShmLayout";
import { ArrowLeft, Save } from "lucide-react";

export default function Create({ permissions = {} }) {
    const { data, setData, post, processing, errors } = useForm({
        name: "",
        display_name: "",
        description: "",
        permissions: [],
    });

    const togglePermission = (id) => {
        if (data.permissions.includes(id)) {
            setData("permissions", data.permissions.filter((pId) => pId !== id));
        } else {
            setData("permissions", [...data.permissions, id]);
        }
    };

    const toggleModule = (modulePermissions) => {
        const moduleIds = modulePermissions.map((p) => p.id);
        const allSelected = moduleIds.every((id) => data.permissions.includes(id));

        if (allSelected) {
            setData(
                "permissions",
                data.permissions.filter((id) => !moduleIds.includes(id))
            );
        } else {
            const newSelection = Array.from(new Set([...data.permissions, ...moduleIds]));
            setData("permissions", newSelection);
        }
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route("shm.settings.roles.store"));
    };

    return (
        <ShmLayout title="Tambah Role SHM" pageTitle="Tambah Role SHM">
            <Head title="Tambah Role SHM" />

            <div className="mx-auto max-w-4xl space-y-6">
                <div className="flex items-center gap-3">
                    <Link
                        href={route("shm.settings.roles.index")}
                        className="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-50 transition-colors"
                    >
                        <ArrowLeft className="h-4 w-4 mr-1" />
                        Kembali
                    </Link>
                    <div>
                        <h1 className="text-xl font-bold tracking-tight text-slate-900">
                            Tambah Role SHM
                        </h1>
                        <p className="text-sm text-slate-500">
                            Buat role baru untuk pengguna operasional BSM Makro (SHM/SHGB).
                        </p>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
                        <h2 className="text-base font-semibold text-slate-900">Informasi Role</h2>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label className="block text-xs font-medium uppercase text-slate-600 mb-1">
                                    Identifier (System Name)
                                </label>
                                <input
                                    type="text"
                                    value={data.name}
                                    onChange={(e) => setData("name", e.target.value.toLowerCase().replace(/[^a-z0-9_-]/g, ""))}
                                    placeholder="contoh: surveyor_makro"
                                    className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-bsm-accent focus:outline-none"
                                    required
                                />
                                {errors.name && <p className="text-xs text-rose-600 mt-1">{errors.name}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-medium uppercase text-slate-600 mb-1">
                                    Display Name
                                </label>
                                <input
                                    type="text"
                                    value={data.display_name}
                                    onChange={(e) => setData("display_name", e.target.value)}
                                    placeholder="contoh: Surveyor SHM"
                                    className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-bsm-accent focus:outline-none"
                                    required
                                />
                                {errors.display_name && <p className="text-xs text-rose-600 mt-1">{errors.display_name}</p>}
                            </div>
                        </div>

                        <div>
                            <label className="block text-xs font-medium uppercase text-slate-600 mb-1">
                                Deskripsi
                            </label>
                            <textarea
                                value={data.description}
                                onChange={(e) => setData("description", e.target.value)}
                                rows={2}
                                placeholder="Jelaskan cakupan wewenang role ini..."
                                className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-bsm-accent focus:outline-none"
                            />
                            {errors.description && <p className="text-xs text-rose-600 mt-1">{errors.description}</p>}
                        </div>
                    </div>

                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-6">
                        <div className="flex items-center justify-between">
                            <div>
                                <h2 className="text-base font-semibold text-slate-900">Hak Akses (Permissions)</h2>
                                <p className="text-xs text-slate-500">Pilih hak akses yang diberikan untuk role ini.</p>
                            </div>
                            <span className="text-xs font-medium text-slate-500">
                                {data.permissions.length} dipilih
                            </span>
                        </div>

                        {errors.permissions && (
                            <p className="text-xs text-rose-600">{errors.permissions}</p>
                        )}

                        <div className="space-y-6">
                            {Object.entries(permissions).map(([moduleName, modulePermissions]) => {
                                const allSelected = modulePermissions.every((p) => data.permissions.includes(p.id));
                                return (
                                    <div key={moduleName} className="rounded-lg border border-slate-100 bg-slate-50/50 p-4">
                                        <div className="flex items-center justify-between border-b border-slate-200/60 pb-3 mb-3">
                                            <span className="font-semibold text-sm text-slate-800 uppercase tracking-wider">
                                                Modul: {moduleName}
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() => toggleModule(modulePermissions)}
                                                className="text-xs text-bsm-accent hover:underline font-medium"
                                            >
                                                {allSelected ? "Batal Pilih Semua" : "Pilih Semua"}
                                            </button>
                                        </div>

                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                            {modulePermissions.map((permission) => (
                                                <label
                                                    key={permission.id}
                                                    className="flex items-start gap-2.5 rounded p-1.5 hover:bg-white text-xs cursor-pointer select-none"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={data.permissions.includes(permission.id)}
                                                        onChange={() => togglePermission(permission.id)}
                                                        className="mt-0.5 rounded border-slate-300 text-bsm-accent focus:ring-bsm-accent"
                                                    />
                                                    <div>
                                                        <div className="font-medium text-slate-800">{permission.display_name}</div>
                                                        <div className="text-[10px] font-mono text-slate-400">{permission.name}</div>
                                                    </div>
                                                </label>
                                            ))}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    <div className="flex items-center justify-end gap-3">
                        <Link
                            href={route("shm.settings.roles.index")}
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
                            Simpan Role
                        </button>
                    </div>
                </form>
            </div>
        </ShmLayout>
    );
}
