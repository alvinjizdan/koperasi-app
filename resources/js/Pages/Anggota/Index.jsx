import React, { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import {
    Users,
    UserPlus,
    Search,
    Filter,
    Eye,
    Edit3,
    UserX,
    CheckCircle2,
    AlertCircle,
    ChevronLeft,
    ChevronRight,
} from 'lucide-react';

export default function Index({ members, filters, flash }) {
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');
    const [deactivateMember, setDeactivateMember] = useState(null);
    const [isDeactivating, setIsDeactivating] = useState(false);

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        router.get(
            route('anggota.index'),
            { search, status },
            { preserveState: true, replace: true }
        );
    };

    const handleStatusChange = (e) => {
        const newStatus = e.target.value;
        setStatus(newStatus);
        router.get(
            route('anggota.index'),
            { search, status: newStatus },
            { preserveState: true, replace: true }
        );
    };

    const confirmDeactivate = () => {
        if (!deactivateMember) return;
        setIsDeactivating(true);

        router.patch(
            route('anggota.deactivate', deactivateMember.id),
            {},
            {
                onSuccess: () => {
                    setDeactivateMember(null);
                    setIsDeactivating(false);
                },
                onError: () => {
                    setIsDeactivating(false);
                },
                onFinish: () => {
                    setIsDeactivating(false);
                },
            }
        );
    };

    return (
        <AdminLayout
            title="Daftar Anggota"
            pageTitle="Manajemen Keanggotaan KSP"
        >
            {/* Flash Banner Messages */}
            {flash?.success && (
                <div className="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs sm:text-sm text-emerald-800 shadow-xs">
                    <CheckCircle2 className="h-5 w-5 text-emerald-600 shrink-0" />
                    <span className="font-medium">{flash.success}</span>
                </div>
            )}
            {flash?.error && (
                <div className="mb-6 flex items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs sm:text-sm text-rose-800 shadow-xs">
                    <AlertCircle className="h-5 w-5 text-rose-600 shrink-0" />
                    <span className="font-medium">{flash.error}</span>
                </div>
            )}

            {/* Header Module Section */}
            <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                <div>
                    <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl flex items-center gap-2">
                        <Users className="h-6 w-6 text-emerald-600" />
                        <span>Daftar Anggota Koperasi</span>
                    </h2>
                    <p className="mt-1 text-xs sm:text-sm text-slate-500">
                        Pengelolaan seluruh data anggota terdaftar di KSP Bima
                        Sakti Mandiri.
                    </p>
                </div>

                <Link
                    href={route('anggota.create')}
                    className="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2.5 text-xs sm:text-sm font-semibold text-white shadow-xs hover:bg-slate-800 transition-all active:scale-95 focus-visible:outline-2 focus-visible:outline-slate-900"
                >
                    <UserPlus className="h-4 w-4" />
                    <span>Tambah Anggota Baru</span>
                </Link>
            </div>

            {/* Filter & Search Bar */}
            <div className="mb-6 rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                <form
                    onSubmit={handleSearchSubmit}
                    className="flex flex-col sm:flex-row gap-3"
                >
                    {/* Input Search */}
                    <div className="relative flex-1">
                        <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <Search className="h-4 w-4 text-slate-400" />
                        </div>
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cari berdasarkan nama, nomor anggota, atau NIK..."
                            className="w-full rounded-lg border border-slate-300 bg-slate-50/50 pl-9 pr-4 py-2 text-xs sm:text-sm text-slate-900 placeholder:text-slate-400 focus:border-slate-900 focus:bg-white focus:outline-none focus:ring-1 focus:ring-slate-900"
                        />
                    </div>

                    {/* Filter Status Select */}
                    <div className="flex items-center gap-2 min-w-[180px]">
                        <Filter className="h-4 w-4 text-slate-400 shrink-0" />
                        <select
                            value={status}
                            onChange={handleStatusChange}
                            className="w-full rounded-lg border border-slate-300 bg-slate-50/50 px-3 py-2 text-xs sm:text-sm font-medium text-slate-700 focus:border-slate-900 focus:bg-white focus:outline-none focus:ring-1 focus:ring-slate-900"
                        >
                            <option value="all">Semua Status</option>
                            <option value="active">Aktif</option>
                            <option value="inactive">Nonaktif</option>
                        </select>
                    </div>

                    {/* Submit Search Button */}
                    <button
                        type="submit"
                        className="rounded-lg bg-slate-100 px-4 py-2 text-xs sm:text-sm font-semibold text-slate-800 hover:bg-slate-200 transition-colors active:scale-95 border border-slate-200"
                    >
                        Cari Data
                    </button>
                </form>
            </div>

            {/* Tabel Data Anggota */}
            <div className="rounded-xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                {members.data && members.data.length > 0 ? (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs sm:text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th scope="col" className="px-6 py-3.5">
                                        No. Anggota
                                    </th>
                                    <th scope="col" className="px-6 py-3.5">
                                        Nama Lengkap / NIK
                                    </th>
                                    <th scope="col" className="px-6 py-3.5">
                                        No. Telepon
                                    </th>
                                    <th scope="col" className="px-6 py-3.5">
                                        Tanggal Bergabung
                                    </th>
                                    <th scope="col" className="px-6 py-3.5">
                                        Status
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-6 py-3.5 text-right"
                                    >
                                        Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 text-slate-700">
                                {members.data.map((item) => (
                                    <tr
                                        key={item.id}
                                        className="hover:bg-slate-50/80 transition-colors"
                                    >
                                        <td className="px-6 py-4 font-semibold text-slate-900 whitespace-nowrap">
                                            {item.member_number}
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="font-semibold text-slate-900">
                                                {item.full_name}
                                            </div>
                                            {item.identity_number && (
                                                <div className="text-xs text-slate-400">
                                                    NIK: {item.identity_number}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap">
                                            {item.phone || '-'}
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap">
                                            {item.joined_at || '-'}
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap">
                                            {item.status === 'active' ? (
                                                <span className="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">
                                                    Aktif
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600">
                                                    Nonaktif
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-6 py-4 text-right whitespace-nowrap">
                                            <div className="flex items-center justify-end gap-1.5">
                                                <Link
                                                    href={route(
                                                        'anggota.show',
                                                        item.id
                                                    )}
                                                    className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-900 transition-colors"
                                                    title="Lihat Detail"
                                                >
                                                    <Eye className="h-4 w-4" />
                                                </Link>

                                                <Link
                                                    href={route(
                                                        'anggota.edit',
                                                        item.id
                                                    )}
                                                    className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-900 transition-colors"
                                                    title="Edit Data"
                                                >
                                                    <Edit3 className="h-4 w-4" />
                                                </Link>

                                                {item.status === 'active' && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setDeactivateMember(
                                                                item
                                                            )
                                                        }
                                                        className="rounded-lg p-1.5 text-rose-500 hover:bg-rose-50 hover:text-rose-700 transition-colors"
                                                        title="Nonaktifkan Anggota"
                                                    >
                                                        <UserX className="h-4 w-4" />
                                                    </button>
                                                )}
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
                            title="Tidak Ada Data Anggota"
                            description={
                                search || status !== 'all'
                                    ? 'Tidak ditemukan anggota yang sesuai dengan kata kunci pencarian atau filter status.'
                                    : 'Belum ada anggota yang terdaftar di dalam sistem KSP Bima Sakti Mandiri.'
                            }
                            action={
                                <Link
                                    href={route('anggota.create')}
                                    className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-slate-800 transition-all active:scale-95"
                                >
                                    <UserPlus className="h-4 w-4" />
                                    <span>Daftarkan Anggota Pertama</span>
                                </Link>
                            }
                        />
                    </div>
                )}

                {/* Pagination Controls */}
                {members.links && members.links.length > 3 && (
                    <div className="flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-slate-200 bg-slate-50/50 px-6 py-4 text-xs text-slate-500">
                        <div>
                            Menampilkan{' '}
                            <span className="font-semibold text-slate-900">
                                {members.from || 0}
                            </span>{' '}
                            sampai{' '}
                            <span className="font-semibold text-slate-900">
                                {members.to || 0}
                            </span>{' '}
                            dari total{' '}
                            <span className="font-semibold text-slate-900">
                                {members.total || 0}
                            </span>{' '}
                            anggota
                        </div>

                        <div className="flex items-center gap-1">
                            {members.links.map((link, idx) => {
                                if (!link.url) {
                                    return (
                                        <span
                                            key={idx}
                                            className="px-3 py-1.5 rounded-md text-slate-400 bg-slate-100/60 font-medium"
                                            dangerouslySetInnerHTML={{
                                                __html: link.label,
                                            }}
                                        />
                                    );
                                }

                                return (
                                    <Link
                                        key={idx}
                                        href={link.url}
                                        className={`px-3 py-1.5 rounded-md font-semibold transition-colors ${
                                            link.active
                                                ? 'bg-slate-900 text-white shadow-xs'
                                                : 'text-slate-700 hover:bg-slate-200/80 bg-white border border-slate-200'
                                        }`}
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                );
                            })}
                        </div>
                    </div>
                )}
            </div>

            {/* Modal Konfirmasi Nonaktifkan */}
            <ConfirmModal
                isOpen={!!deactivateMember}
                onClose={() => setDeactivateMember(null)}
                onConfirm={confirmDeactivate}
                title="Konfirmasi Nonaktifkan Anggota"
                message={`Apakah Anda yakin ingin mengubah status keanggotaan ${deactivateMember?.full_name} (${deactivateMember?.member_number}) menjadi Nonaktif? Data dan riwayat transaksi tidak akan dihapus.`}
                confirmText="Ya, Nonaktifkan"
                cancelText="Batal"
                isProcessing={isDeactivating}
            />
        </AdminLayout>
    );
}
