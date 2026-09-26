import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Edit3, ArrowLeft, Save, ShieldCheck, Lock } from 'lucide-react';

export default function Edit({ role, modules }) {
    const { data, setData, put, processing, errors } = useForm({
        display_name: role.display_name || '',
        description: role.description || '',
        permissions: role.permissions || [],
    });

    const isSuperadmin = role.is_superadmin;

    const togglePermission = (name) => {
        if (isSuperadmin) return;
        const next = data.permissions.includes(name)
            ? data.permissions.filter((p) => p !== name)
            : [...data.permissions, name];
        setData('permissions', next);
    };

    const toggleModule = (module) => {
        if (isSuperadmin) return;
        const names = module.actions.map((a) => a.name);
        const allSelected = names.every((n) => data.permissions.includes(n));
        const next = allSelected
            ? data.permissions.filter((p) => !names.includes(p))
            : [...new Set([...data.permissions, ...names])];
        setData('permissions', next);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('pengaturan.roles.update', role.id));
    };

    return (
        <AdminLayout title={`Edit Role: ${role.display_name}`} pageTitle="Edit Role & Permission">
            <div className="mb-6 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        <Edit3 className="h-6 w-6 text-emerald-600" />
                        <span>Edit Role {role.display_name}</span>
                    </h2>
                    <p className="mt-1 font-mono text-[11px] text-slate-400">{role.name}</p>
                </div>
                <Link
                    href={route('pengaturan.roles.index')}
                    className="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 active:scale-95 sm:text-sm"
                >
                    <ArrowLeft className="h-4 w-4" />
                    <span>Kembali</span>
                </Link>
            </div>

            {isSuperadmin && (
                <div className="mb-6 flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-800 sm:text-sm">
                    <ShieldCheck className="h-5 w-5 shrink-0 text-amber-600" />
                    <span className="font-medium">
                        Role Superadmin selalu memiliki seluruh permission dan tidak dapat diubah.
                    </span>
                </div>
            )}

            <form onSubmit={handleSubmit} className="mx-auto max-w-4xl space-y-6">
                <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:p-8">
                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div>
                            <label htmlFor="name" className="block text-xs font-semibold text-slate-500 sm:text-sm">
                                Kode Role
                            </label>
                            <input
                                type="text"
                                id="name"
                                value={role.name}
                                disabled
                                className="mt-1.5 w-full cursor-not-allowed rounded-lg border border-slate-200 bg-slate-100 px-3.5 py-2 font-mono text-xs text-slate-500 sm:text-sm"
                            />
                            <p className="mt-1 text-[11px] text-slate-400">Kode role tidak dapat diubah.</p>
                        </div>
                        <div>
                            <label htmlFor="display_name" className="block text-xs font-semibold text-slate-900 sm:text-sm">
                                Nama Tampilan <span className="text-rose-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="display_name"
                                value={data.display_name}
                                onChange={(e) => setData('display_name', e.target.value)}
                                className={`mt-1.5 w-full rounded-lg border px-3.5 py-2 text-xs focus:outline-none focus:ring-1 sm:text-sm ${
                                    errors.display_name
                                        ? 'border-rose-400 bg-rose-50/30 focus:border-rose-600 focus:ring-rose-600'
                                        : 'border-slate-300 bg-white focus:border-slate-900 focus:ring-slate-900'
                                }`}
                            />
                            {errors.display_name && (
                                <p className="mt-1 text-xs font-medium text-rose-600">{errors.display_name}</p>
                            )}
                        </div>
                        <div className="sm:col-span-2">
                            <label htmlFor="description" className="block text-xs font-semibold text-slate-900 sm:text-sm">
                                Deskripsi
                            </label>
                            <textarea
                                id="description"
                                rows={2}
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                className="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900 sm:text-sm"
                            />
                        </div>
                    </div>
                </div>

                <div className={`rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:p-8 ${isSuperadmin ? 'opacity-60' : ''}`}>
                    <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                        <Lock className="h-4 w-4 text-slate-500" />
                        Permission
                        <span className="text-[11px] font-normal text-slate-400">
                            ({data.permissions.length} dipilih)
                        </span>
                    </h3>
                    <div className="mt-4 space-y-5">
                        {modules.map((module) => {
                            const names = module.actions.map((a) => a.name);
                            const allSelected = names.every((n) => data.permissions.includes(n));

                            return (
                                <div key={module.module}>
                                    <label className={`flex items-center gap-2 ${isSuperadmin ? '' : 'cursor-pointer'}`}>
                                        <input
                                            type="checkbox"
                                            checked={isSuperadmin ? true : allSelected}
                                            onChange={() => toggleModule(module)}
                                            disabled={isSuperadmin}
                                            className="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-900"
                                        />
                                        <span className="text-xs font-bold uppercase tracking-wider text-slate-700 sm:text-sm">
                                            {module.label}
                                        </span>
                                    </label>
                                    <div className="mt-2 grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                                        {module.actions.map((action) => (
                                            <label
                                                key={action.name}
                                                className={`flex items-start gap-2.5 rounded-lg border p-2.5 transition-colors ${
                                                    isSuperadmin
                                                        ? 'border-emerald-200 bg-emerald-50/40'
                                                        : data.permissions.includes(action.name)
                                                          ? 'cursor-pointer border-emerald-400 bg-emerald-50/60'
                                                          : 'cursor-pointer border-slate-200 bg-white hover:bg-slate-50'
                                                }`}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={isSuperadmin || data.permissions.includes(action.name)}
                                                    onChange={() => togglePermission(action.name)}
                                                    disabled={isSuperadmin}
                                                    className="mt-0.5 h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-900"
                                                />
                                                <div className="min-w-0">
                                                    <p className="font-mono text-[11px] font-semibold text-slate-900">
                                                        {action.name}
                                                    </p>
                                                    <p className="truncate text-[11px] text-slate-500">{action.description}</p>
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
                        href={route('pengaturan.roles.index')}
                        className="rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 active:scale-95 sm:text-sm"
                    >
                        Batal
                    </Link>
                    <button
                        type="submit"
                        disabled={processing || isSuperadmin}
                        className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-6 py-2.5 text-xs font-semibold text-white shadow-xs transition-all hover:bg-slate-800 disabled:opacity-50 active:scale-95 sm:text-sm"
                    >
                        <Save className="h-4 w-4" />
                        <span>{processing ? 'Menyimpan...' : 'Simpan Perubahan'}</span>
                    </button>
                </div>
            </form>
        </AdminLayout>
    );
}
