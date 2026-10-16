import React from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Plus, ArrowLeft, Save, ShieldCheck } from 'lucide-react';

export default function Create({ modules }) {
    const { auth } = usePage().props;

    // T105 (UX saja): hanya permission yang dipegang actor yang dapat dicentang;
    // backend menolak permintaan di luar kewenangan pada request POST.
    const canGrant = (name) => auth?.is_superadmin || (auth?.permissions ?? []).includes(name);

    const { data, setData, post, processing, errors } = useForm({
        name: '',
        display_name: '',
        description: '',
        permissions: [],
    });

    const togglePermission = (name) => {
        if (!canGrant(name)) return;
        const next = data.permissions.includes(name)
            ? data.permissions.filter((p) => p !== name)
            : [...data.permissions, name];
        setData('permissions', next);
    };

    const toggleModule = (module) => {
        const names = module.actions.map((a) => a.name).filter(canGrant);
        if (names.length === 0) return;
        const allSelected = names.every((n) => data.permissions.includes(n));
        const next = allSelected
            ? data.permissions.filter((p) => !names.includes(p))
            : [...new Set([...data.permissions, ...names])];
        setData('permissions', next);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('pengaturan.roles.store'));
    };

    return (
        <AdminLayout title="Tambah Role" pageTitle="Form Role Baru">
            <div className="mb-6 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        <Plus className="h-6 w-6 text-emerald-600" />
                        <span>Role Baru</span>
                    </h2>
                    <p className="mt-1 text-xs text-slate-500 sm:text-sm">
                        Tetapkan hanya permission yang benar-benar diperlukan oleh role ini.
                    </p>
                </div>
                <Link
                    href={route('pengaturan.roles.index')}
                    className="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 active:scale-95 sm:text-sm"
                >
                    <ArrowLeft className="h-4 w-4" />
                    <span>Kembali</span>
                </Link>
            </div>

            <form onSubmit={handleSubmit} className="mx-auto max-w-4xl space-y-6">
                <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:p-8">
                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div>
                            <label htmlFor="name" className="block text-xs font-semibold text-slate-900 sm:text-sm">
                                Kode Role <span className="text-rose-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="mis. kasir_cabang"
                                className={`mt-1.5 w-full rounded-lg border px-3.5 py-2 text-xs focus:outline-none focus:ring-1 sm:text-sm ${
                                    errors.name
                                        ? 'border-rose-400 bg-rose-50/30 focus:border-rose-600 focus:ring-rose-600'
                                        : 'border-slate-300 bg-white focus:border-slate-900 focus:ring-slate-900'
                                }`}
                            />
                            {errors.name ? (
                                <p className="mt-1 text-xs font-medium text-rose-600">{errors.name}</p>
                            ) : (
                                <p className="mt-1 text-[11px] text-slate-400">Huruf kecil, angka, dan underscore saja.</p>
                            )}
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
                                placeholder="mis. Kasir Cabang"
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

                <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:p-8">
                    <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                        <ShieldCheck className="h-4 w-4 text-slate-500" />
                        Permission
                    </h3>
                    <div className="mt-4 space-y-5">
                        {modules.map((module) => {
                            const names = module.actions.map((a) => a.name).filter(canGrant);
                            const allSelected = names.length > 0 && names.every((n) => data.permissions.includes(n));

                            return (
                                <div key={module.module}>
                                    <label className={`flex items-center gap-2 ${names.length > 0 ? 'cursor-pointer' : 'opacity-50'}`}>
                                        <input
                                            type="checkbox"
                                            checked={allSelected}
                                            onChange={() => toggleModule(module)}
                                            disabled={names.length === 0}
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
                                                    !canGrant(action.name)
                                                        ? 'cursor-not-allowed border-slate-100 bg-slate-50 opacity-60'
                                                        : data.permissions.includes(action.name)
                                                          ? 'cursor-pointer border-emerald-400 bg-emerald-50/60'
                                                          : 'cursor-pointer border-slate-200 bg-white hover:bg-slate-50'
                                                }`}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={data.permissions.includes(action.name)}
                                                    onChange={() => togglePermission(action.name)}
                                                    disabled={!canGrant(action.name)}
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
                    {errors.permissions && (
                        <p className="mt-3 text-xs font-medium text-rose-600">{errors.permissions}</p>
                    )}
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
                        disabled={processing}
                        className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-6 py-2.5 text-xs font-semibold text-white shadow-xs transition-all hover:bg-slate-800 disabled:opacity-50 active:scale-95 sm:text-sm"
                    >
                        <Save className="h-4 w-4" />
                        <span>{processing ? 'Menyimpan...' : 'Simpan Role'}</span>
                    </button>
                </div>
            </form>
        </AdminLayout>
    );
}
