import React, { useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import ShmLayout from "@/Layouts/ShmLayout";
import { User, Shield, Search } from "lucide-react";

export default function Index({ users, filters = {}, flash = {} }) {
    const [search, setSearch] = useState(filters.search || "");

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(
            route("shm.settings.users.index"),
            { search },
            { preserveState: true, replace: true }
        );
    };

    return (
        <ShmLayout title="Akses Pengguna SHM" pageTitle="Akses Pengguna SHM">
            <Head title="Akses Pengguna SHM" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-xl font-bold tracking-tight text-slate-900">
                            Akses Pengguna SHM
                        </h1>
                        <p className="text-sm text-slate-500">
                            Kelola penetapan dan pencabutan role operasional BSM Makro untuk setiap pengguna.
                        </p>
                    </div>

                    <form onSubmit={handleSearch} className="flex items-center gap-2">
                        <div className="relative">
                            <Search className="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Cari nama atau email..."
                                className="w-64 rounded-lg border border-slate-200 pl-9 pr-3 py-1.5 text-sm focus:border-bsm-accent focus:outline-none"
                            />
                        </div>
                        <button
                            type="submit"
                            className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 transition-colors"
                        >
                            Cari
                        </button>
                    </form>
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
                                    <th className="px-6 py-4">Pengguna</th>
                                    <th className="px-6 py-4">Status Akun</th>
                                    <th className="px-6 py-4">Role SHM</th>
                                    <th className="px-6 py-4 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {users.data.length === 0 ? (
                                    <tr>
                                        <td colSpan="4" className="px-6 py-8 text-center text-slate-400">
                                            Tidak ada data pengguna yang ditemukan.
                                        </td>
                                    </tr>
                                ) : (
                                    users.data.map((user) => (
                                        <tr key={user.id} className="hover:bg-slate-50/50">
                                            <td className="px-6 py-4">
                                                <div className="flex items-center gap-3">
                                                    <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                                                        <User className="h-4 w-4" />
                                                    </div>
                                                    <div>
                                                        <div className="font-medium text-slate-900">{user.name}</div>
                                                        <div className="text-xs text-slate-400">{user.email}</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-6 py-4">
                                                {user.is_active ? (
                                                    <span className="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                                                        Aktif
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
                                                        Nonaktif
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4">
                                                <div className="flex flex-wrap gap-1.5">
                                                    {user.shm_roles.length === 0 ? (
                                                        <span className="text-xs text-slate-400 italic">
                                                            Tanpa Role SHM
                                                        </span>
                                                    ) : (
                                                        user.shm_roles.map((r) => (
                                                            <span
                                                                key={r.id}
                                                                className="inline-flex items-center gap-1 rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700"
                                                            >
                                                                <Shield className="h-3 w-3 text-slate-400" />
                                                                {r.display_name}
                                                            </span>
                                                        ))
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 text-right">
                                                <Link
                                                    href={route("shm.settings.users.show", user.id)}
                                                    className="inline-flex items-center rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50 transition-colors"
                                                >
                                                    Kelola Akses
                                                </Link>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </ShmLayout>
    );
}
