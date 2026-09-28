import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import { formatRupiah, formatDateTime } from '@/utils/format';
import {
    User,
    ArrowLeft,
    Edit3,
    UserX,
    Calendar,
    Phone,
    MapPin,
    CreditCard,
    CheckCircle2,
    AlertCircle,
    Receipt,
    PiggyBank,
    Landmark,
    Clock,
} from 'lucide-react';

export default function Show({ member, financialSummary, flash }) {
    const [isDeactivating, setIsDeactivating] = useState(false);
    const [showConfirmModal, setShowConfirmModal] = useState(false);

    // Ticket 17 BUG-02: ringkasan finansial dari backend; fallback aman bila
    // tidak tersedia (mis. share lama).
    const summary = financialSummary ?? {
        total_savings_balance: 0,
        savings_account_count: 0,
        active_loans_outstanding: 0,
        active_loan_count: 0,
    };

    const handleDeactivate = () => {
        setIsDeactivating(true);
        router.patch(
            route('anggota.deactivate', member.id),
            {},
            {
                onSuccess: () => {
                    setShowConfirmModal(false);
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
            title={`Detail ${member.full_name}`}
            pageTitle="Detail Profile Anggota"
        >
            {/* Flash Banners */}
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
                <div className="flex items-center gap-4">
                    <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-slate-900 text-white font-bold text-lg">
                        {member.full_name.charAt(0).toUpperCase()}
                    </div>
                    <div>
                        <div className="flex items-center gap-3">
                            <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                                {member.full_name}
                            </h2>
                            {member.status === 'active' ? (
                                <span className="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">
                                    Aktif
                                </span>
                            ) : (
                                <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600">
                                    Nonaktif
                                </span>
                            )}
                        </div>
                        <p className="mt-0.5 text-xs sm:text-sm font-medium text-slate-500">
                            Nomor Anggota: {member.member_number}
                        </p>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Link
                        href={route('anggota.index')}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors active:scale-95"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        <span>Kembali</span>
                    </Link>

                    <Link
                        href={route('anggota.edit', member.id)}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3.5 py-2 text-xs sm:text-sm font-semibold text-white hover:bg-slate-800 transition-colors active:scale-95 shadow-xs"
                    >
                        <Edit3 className="h-4 w-4" />
                        <span>Edit Profil</span>
                    </Link>

                    {member.status === 'active' && (
                        <button
                            type="button"
                            onClick={() => setShowConfirmModal(true)}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-rose-50 px-3.5 py-2 text-xs sm:text-sm font-semibold text-rose-700 hover:bg-rose-100 transition-colors active:scale-95"
                        >
                            <UserX className="h-4 w-4" />
                            <span>Nonaktifkan</span>
                        </button>
                    )}
                </div>
            </div>

            {/* Grid Detail & Metadata */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3 mb-8">
                {/* Information Cards (2/3 width) */}
                <div className="lg:col-span-2 space-y-6">
                    {/* Detail Identitas */}
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                        <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500 pb-3 border-b border-slate-100 mb-4">
                            Detail Identitas & Kontak
                        </h3>

                        <dl className="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 text-xs sm:text-sm">
                            <div>
                                <dt className="text-slate-400 font-medium flex items-center gap-1.5">
                                    <User className="h-3.5 w-3.5 text-slate-400" />
                                    <span>Nama Lengkap</span>
                                </dt>
                                <dd className="mt-1 font-semibold text-slate-900">
                                    {member.full_name}
                                </dd>
                            </div>

                            <div>
                                <dt className="text-slate-400 font-medium flex items-center gap-1.5">
                                    <CreditCard className="h-3.5 w-3.5 text-slate-400" />
                                    <span>Nomor Identitas (NIK)</span>
                                </dt>
                                <dd className="mt-1 font-semibold text-slate-900">
                                    {member.identity_number ||
                                        'Belum diisi (Opsional)'}
                                </dd>
                            </div>

                            <div>
                                <dt className="text-slate-400 font-medium flex items-center gap-1.5">
                                    <Phone className="h-3.5 w-3.5 text-slate-400" />
                                    <span>Nomor Telepon</span>
                                </dt>
                                <dd className="mt-1 font-semibold text-slate-900">
                                    {member.phone || 'Belum diisi'}
                                </dd>
                            </div>

                            <div>
                                <dt className="text-slate-400 font-medium flex items-center gap-1.5">
                                    <Calendar className="h-3.5 w-3.5 text-slate-400" />
                                    <span>Tanggal Bergabung</span>
                                </dt>
                                <dd className="mt-1 font-semibold text-slate-900">
                                    {member.joined_at || '-'}
                                </dd>
                            </div>

                            <div className="sm:col-span-2">
                                <dt className="text-slate-400 font-medium flex items-center gap-1.5">
                                    <MapPin className="h-3.5 w-3.5 text-slate-400" />
                                    <span>Alamat Tempat Tinggal</span>
                                </dt>
                                <dd className="mt-1 font-medium text-slate-900 whitespace-pre-line">
                                    {member.address ||
                                        'Belum diisi alamat domisili.'}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    {/* Area Riwayat Transaksi Finansial (Placeholder / Empty State) */}
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                            <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500">
                                Ringkasan Simpanan & Pinjaman
                            </h3>
                            <span className="text-xs text-slate-400 font-medium">
                                Data Keuangan
                            </span>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                            <div className="rounded-lg border border-slate-100 bg-slate-50 p-4">
                                <div className="flex items-center gap-2 text-xs font-medium text-slate-500">
                                    <PiggyBank className="h-4 w-4 text-emerald-600" />
                                    <span>Total Saldo Simpanan</span>
                                </div>
                                <p className="mt-2 text-lg font-bold text-slate-900">
                                    {formatRupiah(summary.total_savings_balance)}
                                </p>
                                <span className="text-[11px] text-slate-400">
                                    {summary.savings_account_count > 0
                                        ? `${summary.savings_account_count} rekening simpanan`
                                        : 'Belum ada rekening simpanan'}
                                </span>
                            </div>

                            <div className="rounded-lg border border-slate-100 bg-slate-50 p-4">
                                <div className="flex items-center gap-2 text-xs font-medium text-slate-500">
                                    <Landmark className="h-4 w-4 text-emerald-600" />
                                    <span>Sisa Kewajiban Pinjaman Aktif</span>
                                </div>
                                <p className="mt-2 text-lg font-bold text-slate-900">
                                    {formatRupiah(summary.active_loans_outstanding)}
                                </p>
                                <span className="text-[11px] text-slate-400">
                                    {summary.active_loan_count > 0
                                        ? `${summary.active_loan_count} pinjaman aktif`
                                        : 'Tidak ada pinjaman aktif'}
                                </span>
                            </div>
                        </div>

                        {summary.savings_account_count === 0 && summary.active_loan_count === 0 && (
                            <div className="rounded-lg border border-dashed border-slate-200 bg-slate-50/50 p-6 text-center">
                                <Receipt className="h-8 w-8 text-slate-400 mx-auto mb-2" />
                                <p className="text-xs sm:text-sm font-semibold text-slate-800">
                                    Belum Ada Riwayat Transaksi Finansial
                                </p>
                                <p className="mt-1 text-xs text-slate-500 max-w-md mx-auto">
                                    Anggota belum memiliki rekening simpanan
                                    maupun pinjaman aktif dalam sistem.
                                </p>
                            </div>
                        )}
                    </div>
                </div>

                {/* System Metadata Sidebar (1/3 width) */}
                <div className="space-y-6">
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                        <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500 pb-3 border-b border-slate-100 mb-4">
                            Informasi Sistem
                        </h3>

                        <dl className="space-y-3 text-xs">
                            <div>
                                <dt className="text-slate-400 font-medium">
                                    ID Record Database
                                </dt>
                                <dd className="mt-0.5 font-mono text-slate-900">
                                    #{member.id}
                                </dd>
                            </div>

                            <div>
                                <dt className="text-slate-400 font-medium">
                                    Waktu Terdaftar (Created)
                                </dt>
                                <dd className="mt-0.5 font-medium text-slate-900 flex items-center gap-1.5">
                                    <Clock className="h-3.5 w-3.5 text-slate-400" />
                                    <span>{formatDateTime(member.created_at)}</span>
                                </dd>
                            </div>

                            <div>
                                <dt className="text-slate-400 font-medium">
                                    Pembaruan Terakhir (Updated)
                                </dt>
                                <dd className="mt-0.5 font-medium text-slate-900 flex items-center gap-1.5">
                                    <Clock className="h-3.5 w-3.5 text-slate-400" />
                                    <span>{formatDateTime(member.updated_at)}</span>
                                </dd>
                            </div>

                            <div className="pt-3 border-t border-slate-100">
                                <dt className="text-slate-400 font-medium">
                                    Integritas Data Status
                                </dt>
                                <dd className="mt-1 text-slate-600">
                                    Record dilindungi oleh PostgreSQL unique
                                    constraint pada nomor anggota.
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

            {/* Modal Konfirmasi Nonaktifkan */}
            <ConfirmModal
                isOpen={showConfirmModal}
                onClose={() => setShowConfirmModal(false)}
                onConfirm={handleDeactivate}
                title="Konfirmasi Nonaktifkan Anggota"
                message={`Apakah Anda yakin ingin mengubah status keanggotaan ${member.full_name} (${member.member_number}) menjadi Nonaktif? Status nonaktif dapat dipulihkan kapan saja via edit.`}
                confirmText="Ya, Nonaktifkan"
                cancelText="Batal"
                isProcessing={isDeactivating}
            />
        </AdminLayout>
    );
}
