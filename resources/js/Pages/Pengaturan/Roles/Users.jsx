import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { ScrollArea, ScrollBar } from '@/Components/ui/scroll-area';
import EmptyState from '@/Components/Admin/EmptyState';
import { ArrowLeft, ShieldCheck, Eye } from 'lucide-react';
import { formatDateTime } from '@/utils/format';

export default function Users({ role, users }) {
    return (
        <AdminLayout title={`Pengguna Role ${role.display_name}`} pageTitle="Pengguna per Role">
            <div className="mb-6 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        <ShieldCheck className="h-6 w-6 text-emerald-600" />
                        <span>Role: {role.display_name}</span>
                    </h2>
                    <p className="mt-1 font-mono text-[11px] text-slate-400">{role.name}</p>
                    {role.description && <p className="mt-1 text-xs text-slate-500 sm:text-sm">{role.description}</p>}
                </div>
                <Link
                    href={route('pengaturan.roles.index')}
                    className="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 active:scale-95 sm:text-sm"
                >
                    <ArrowLeft className="h-4 w-4" />
                    <span>Kembali ke Role</span>
                </Link>
            </div>

            <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                {users.data.length > 0 ? (
                    <ScrollArea>
<ScrollBar orientation="horizontal" />
                        <table className="w-full text-left text-xs sm:text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th scope="col" className="px-6 py-3.5">Nama / Email</th>
                                    <th scope="col" className="px-6 py-3.5">Status</th>
                                    <th scope="col" className="px-6 py-3.5">Login Terakhir</th>
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
                                        <td className="whitespace-nowrap px-6 py-4">
                                            {user.is_active ? (
                                                <span className="inline-flex rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-800">
                                                    Aktif
                                                </span>
                                            ) : (
                                                <span className="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600">
                                                    Nonaktif
                                                </span>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-xs text-slate-500">
                                            {user.last_login_at ? formatDateTime(user.last_login_at) : 'Belum pernah'}
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            <Link
                                                href={route('pengaturan.users.show', user.id)}
                                                className="inline-flex rounded-lg p-1.5 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-900"
                                                title="Detail Pengguna"
                                            >
                                                <Eye className="h-4 w-4" />
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </ScrollArea>
                ) : (
                    <div className="p-8">
                        <EmptyState
                            icon={ShieldCheck}
                            title="Belum Ada Pengguna"
                            description={`Belum ada akun yang memegang role ${role.display_name}. Tetapkan role ini dari halaman detail pengguna.`}
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
        </AdminLayout>
    );
}
