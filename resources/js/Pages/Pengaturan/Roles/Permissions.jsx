import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { ArrowLeft, ShieldCheck, Check, Minus } from 'lucide-react';

export default function Permissions({ modules, roles }) {
    const rolePermissionSet = (role) => new Set(role.permissions);

    return (
        <AdminLayout title="Matriks Permission" pageTitle="Matriks Role & Permission">
            <div className="mb-6 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        <ShieldCheck className="h-6 w-6 text-emerald-600" />
                        <span>Matriks Role &times; Permission</span>
                    </h2>
                    <p className="mt-1 text-xs text-slate-500 sm:text-sm">
                        Tinjauan dampak permission per role. Superadmin otomatis memperoleh seluruh permission.
                    </p>
                </div>
                <Link
                    href={route('pengaturan.roles.index')}
                    className="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 active:scale-95 sm:text-sm"
                >
                    <ArrowLeft className="h-4 w-4" />
                    <span>Daftar Role</span>
                </Link>
            </div>

            <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs sm:text-sm">
                        <thead className="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th scope="col" className="px-6 py-3.5">Permission</th>
                                {roles.map((role) => (
                                    <th key={role.id} scope="col" className="px-3 py-3.5 text-center">
                                        {role.display_name}
                                        {role.is_superadmin && (
                                            <span className="block text-[10px] font-normal normal-case text-amber-600">
                                                semua akses
                                            </span>
                                        )}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-slate-700">
                            {modules.map((module) => (
                                <React.Fragment key={module.module}>
                                    <tr className="bg-slate-50/80">
                                        <td
                                            colSpan={roles.length + 1}
                                            className="px-6 py-2 text-[11px] font-bold uppercase tracking-wider text-slate-600"
                                        >
                                            {module.label}
                                        </td>
                                    </tr>
                                    {module.actions.map((action) => (
                                        <tr key={action.name} className="transition-colors hover:bg-slate-50/60">
                                            <td className="px-6 py-2.5">
                                                <span className="font-mono text-[11px] font-semibold text-slate-900">
                                                    {action.name}
                                                </span>
                                                <span className="block text-[11px] text-slate-400">{action.description}</span>
                                            </td>
                                            {roles.map((role) => {
                                                const allowed = role.is_superadmin || rolePermissionSet(role).has(action.name);
                                                return (
                                                    <td key={role.id} className="px-3 py-2.5 text-center">
                                                        {allowed ? (
                                                            <Check className="mx-auto h-4 w-4 text-emerald-600" />
                                                        ) : (
                                                            <Minus className="mx-auto h-4 w-4 text-slate-300" />
                                                        )}
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))}
                                </React.Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AdminLayout>
    );
}
