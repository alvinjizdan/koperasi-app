import React, { useState } from "react";
import { Link, router, useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { ScrollArea, ScrollBar } from "@/Components/ui/scroll-area";
import ConfirmModal from "@/Components/Admin/ConfirmModal";
import {
    Landmark,
    ChevronLeft,
    CheckCircle2,
    AlertCircle,
    XCircle,
    ArrowUpCircle,
    Calendar,
    Receipt,
    User,
} from "lucide-react";
import { formatRupiah, formatDate } from "@/utils/format";

export default function Show({ loan, summary, flash }) {
    const [showApproveModal, setShowApproveModal] = useState(false);
    const [showRejectModal, setShowRejectModal] = useState(false);

    const approveForm = useForm({});
    const rejectForm = useForm({ rejection_reason: "" });

    const handleApprove = () => {
        approveForm.post(route("pinjaman.approve", loan.id), {
            onSuccess: () => setShowApproveModal(false),
        });
    };

    const handleReject = () => {
        rejectForm.post(route("pinjaman.reject", loan.id), {
            onSuccess: () => setShowRejectModal(false),
        });
    };

    const getStatusBadge = (st) => {
        switch (st) {
            case "PENDING":
                return (
                    <span className="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">
                        Menunggu Persetujuan
                    </span>
                );
            case "APPROVED":
                return (
                    <span className="inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-800">
                        Disetujui
                    </span>
                );
            case "REJECTED":
                return (
                    <span className="inline-flex items-center rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-800">
                        Ditolak
                    </span>
                );
            case "DISBURSED":
            case "ACTIVE":
                return (
                    <span className="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">
                        Aktif / Berjalan
                    </span>
                );
            case "PAID_OFF":
                return (
                    <span className="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-800">
                        Lunas
                    </span>
                );
            default:
                return (
                    <span className="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                        {st}
                    </span>
                );
        }
    };

    return (
        <AdminLayout
            title={`Pinjaman ${loan.loan_number}`}
            pageTitle="Detail Pinjaman"
        >
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <Link
                        href={route("pinjaman.index")}
                        className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-800 transition-colors"
                    >
                        <ChevronLeft className="h-4 w-4" />
                        Daftar pinjaman
                    </Link>

                    {/* Actions based on loan status */}
                    <div className="flex gap-2">
                        {loan.status === "PENDING" && (
                            <>
                                <button
                                    type="button"
                                    onClick={() => setShowRejectModal(true)}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-800 hover:bg-rose-100 active:scale-95 transition-all"
                                >
                                    <XCircle className="h-4 w-4" />
                                    Tolak
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setShowApproveModal(true)}
                                    className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-4 py-1.5 text-sm font-medium text-white hover:bg-emerald-800 active:scale-95 transition-all"
                                >
                                    <CheckCircle2 className="h-4 w-4" />
                                    Setujui
                                </button>
                            </>
                        )}

                        {loan.status === "APPROVED" && (
                            <Link
                                href={route(
                                    "pinjaman.disburse.create",
                                    loan.id,
                                )}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-blue-700 px-4 py-1.5 text-sm font-medium text-white hover:bg-blue-800 active:scale-95 transition-all"
                            >
                                <ArrowUpCircle className="h-4 w-4" />
                                Cairkan Pinjaman
                            </Link>
                        )}

                        {(loan.status === "ACTIVE" ||
                            loan.status === "DISBURSED") && (
                            <div className="flex gap-2">
                                <Link
                                    href={route("angsuran.schedule", loan.id)}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 active:scale-95 transition-all"
                                >
                                    <Calendar className="h-4 w-4" />
                                    Jadwal Angsuran
                                </Link>
                                <Link
                                    href={route("angsuran.pay.create", loan.id)}
                                    className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-4 py-1.5 text-sm font-medium text-white hover:bg-emerald-800 active:scale-95 transition-all"
                                >
                                    <Receipt className="h-4 w-4" />
                                    Bayar Angsuran
                                </Link>
                            </div>
                        )}
                    </div>
                </div>

                {flash?.success && (
                    <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        <AlertCircle className="h-4 w-4 flex-shrink-0" />
                        {flash.error}
                    </div>
                )}

                {/* Header card */}
                <div className="rounded-xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                    <div className="border-b border-slate-100 px-6 py-4 bg-slate-50 flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                                <Landmark className="h-5 w-5" />
                            </div>
                            <div>
                                <p className="font-mono text-sm font-semibold text-slate-900">
                                    {loan.loan_number}
                                </p>
                                <p className="text-xs text-slate-500">
                                    Pengajuan:{" "}
                                    {formatDate(loan.submission_date)}
                                </p>
                            </div>
                        </div>
                        <div>{getStatusBadge(loan.status)}</div>
                    </div>

                    <div className="grid grid-cols-2 divide-x divide-slate-100 sm:grid-cols-4">
                        <div className="px-5 py-4">
                            <p className="text-xs text-slate-500">
                                Anggota Pemohon
                            </p>
                            <p className="mt-0.5 text-sm font-semibold text-slate-900">
                                {loan.member?.full_name}
                            </p>
                            <p className="text-xs text-slate-400 font-mono">
                                {loan.member?.member_number}
                            </p>
                        </div>
                        <div className="px-5 py-4">
                            <p className="text-xs text-slate-500">
                                Nominal Pokok
                            </p>
                            <p className="mt-0.5 text-base font-bold tabular-nums text-slate-900">
                                {formatRupiah(loan.amount)}
                            </p>
                        </div>
                        <div className="px-5 py-4">
                            <p className="text-xs text-slate-500">Tenor</p>
                            <p className="mt-0.5 text-sm font-semibold text-slate-900">
                                {loan.tenor_months} Bulan
                            </p>
                        </div>
                        <div className="px-5 py-4">
                            <p className="text-xs text-slate-500">
                                Sisa Kewajiban Pokok
                            </p>
                            <p className="mt-0.5 text-base font-bold tabular-nums text-emerald-700">
                                {formatRupiah(summary.remaining_balance)}
                            </p>
                        </div>
                    </div>

                    {/* Rejection notice if rejected */}
                    {loan.status === "REJECTED" && (
                        <div className="border-t border-rose-100 bg-rose-50 px-6 py-3 text-xs text-rose-800">
                            <span className="font-semibold">
                                Alasan Penolakan:
                            </span>{" "}
                            {loan.rejection_reason || "-"}
                        </div>
                    )}
                </div>

                {/* Audit process trail */}
                <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-3">
                        Audit Jejak Proses
                    </h3>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3 text-xs">
                        <div className="flex items-start gap-2">
                            <User className="h-4 w-4 text-slate-400 flex-shrink-0 mt-0.5" />
                            <div>
                                <p className="font-medium text-slate-700">
                                    Dicatat oleh
                                </p>
                                <p className="text-slate-900 font-semibold">
                                    {loan.creator?.name || "-"}
                                </p>
                                <p className="text-slate-400">
                                    {formatDate(loan.submission_date)}
                                </p>
                            </div>
                        </div>
                        <div className="flex items-start gap-2">
                            <User className="h-4 w-4 text-slate-400 flex-shrink-0 mt-0.5" />
                            <div>
                                <p className="font-medium text-slate-700">
                                    Persetujuan
                                </p>
                                <p className="text-slate-900 font-semibold">
                                    {loan.status === "REJECTED"
                                        ? `Ditolak oleh ${loan.rejector?.name || "-"}`
                                        : loan.approver
                                          ? `Disetujui oleh ${loan.approver.name}`
                                          : "Belum diproses"}
                                </p>
                                <p className="text-slate-400">
                                    {loan.approved_at
                                        ? formatDate(loan.approved_at)
                                        : loan.rejected_at
                                          ? formatDate(loan.rejected_at)
                                          : "-"}
                                </p>
                            </div>
                        </div>
                        <div className="flex items-start gap-2">
                            <User className="h-4 w-4 text-slate-400 flex-shrink-0 mt-0.5" />
                            <div>
                                <p className="font-medium text-slate-700">
                                    Pencairan
                                </p>
                                <p className="text-slate-900 font-semibold">
                                    {loan.disburser
                                        ? `Dicairkan oleh ${loan.disburser.name}`
                                        : "Belum dicairkan"}
                                </p>
                                <p className="text-slate-400">
                                    {loan.disbursed_at
                                        ? formatDate(loan.disbursed_at)
                                        : "-"}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Schedules list preview */}
                {loan.schedules && loan.schedules.length > 0 && (
                    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                        <div className="flex items-center justify-between mb-3">
                            <h3 className="text-sm font-semibold text-slate-900">
                                Ringkasan Jadwal Angsuran
                            </h3>
                            <Link
                                href={route("angsuran.schedule", loan.id)}
                                className="text-xs text-emerald-700 font-medium hover:underline"
                            >
                                Lihat Selengkapnya →
                            </Link>
                        </div>
                        <ScrollArea>
                            <ScrollBar orientation="horizontal" />
                            <table className="min-w-full divide-y divide-slate-100 text-xs">
                                <thead>
                                    <tr className="bg-slate-50 text-slate-500 font-semibold">
                                        <th className="px-3 py-2 text-left">
                                            Angsuran Ke
                                        </th>
                                        <th className="px-3 py-2 text-left">
                                            Jatuh Tempo
                                        </th>
                                        <th className="px-3 py-2 text-right">
                                            Tagihan
                                        </th>
                                        <th className="px-3 py-2 text-right">
                                            Dibayar
                                        </th>
                                        <th className="px-3 py-2 text-right">
                                            Sisa
                                        </th>
                                        <th className="px-3 py-2 text-left">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {loan.schedules.slice(0, 5).map((sch) => (
                                        <tr key={sch.id}>
                                            <td className="px-3 py-2 font-medium text-slate-900">
                                                #{sch.installment_number}
                                            </td>
                                            <td className="px-3 py-2 text-slate-600">
                                                {formatDate(sch.due_date)}
                                            </td>
                                            <td className="px-3 py-2 text-right font-medium text-slate-900">
                                                {formatRupiah(sch.total_amount)}
                                            </td>
                                            <td className="px-3 py-2 text-right text-emerald-700">
                                                {formatRupiah(sch.paid_amount)}
                                            </td>
                                            <td className="px-3 py-2 text-right text-slate-700">
                                                {formatRupiah(
                                                    sch.remaining_amount,
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                <span
                                                    className={`inline-flex rounded px-2 py-0.5 text-[10px] font-semibold ${
                                                        sch.status === "PAID"
                                                            ? "bg-emerald-100 text-emerald-800"
                                                            : sch.status ===
                                                                "OVERDUE"
                                                              ? "bg-rose-100 text-rose-800"
                                                              : sch.status ===
                                                                  "DUE"
                                                                ? "bg-amber-100 text-amber-800"
                                                                : "bg-slate-100 text-slate-600"
                                                    }`}
                                                >
                                                    {sch.status}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </ScrollArea>
                    </div>
                )}
            </div>

            {/* Modal Confirm Approve */}
            <ConfirmModal
                isOpen={showApproveModal}
                onClose={() => setShowApproveModal(false)}
                onConfirm={handleApprove}
                isProcessing={approveForm.processing}
                title="Setujui Pinjaman"
                message={`Apakah Anda yakin ingin menyetujui pengajuan pinjaman ${loan.loan_number} sebesar ${formatRupiah(loan.amount)} atas nama ${loan.member?.full_name}?`}
                confirmText="Ya, Setujui"
            />

            {/* Modal Confirm Reject */}
            {showRejectModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div
                        className="fixed inset-0 bg-slate-900/60 backdrop-blur-xs"
                        onClick={() => setShowRejectModal(false)}
                    />
                    <div className="relative w-full max-w-md rounded-xl bg-white p-6 shadow-xl border border-slate-200">
                        <h3 className="text-base font-semibold text-slate-900 mb-2">
                            Tolak Pinjaman
                        </h3>
                        <p className="text-xs text-slate-500 mb-4">
                            Berikan alasan penolakan untuk pinjaman{" "}
                            {loan.loan_number}.
                        </p>
                        <textarea
                            rows={3}
                            value={rejectForm.data.rejection_reason}
                            onChange={(e) =>
                                rejectForm.setData(
                                    "rejection_reason",
                                    e.target.value,
                                )
                            }
                            placeholder="Alasan penolakan..."
                            className="w-full rounded-lg border border-slate-300 p-2.5 text-sm focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
                        />
                        {rejectForm.errors.rejection_reason && (
                            <p className="mt-1 text-xs text-rose-600">
                                {rejectForm.errors.rejection_reason}
                            </p>
                        )}
                        <div className="mt-4 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setShowRejectModal(false)}
                                className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                            >
                                Batal
                            </button>
                            <button
                                type="button"
                                onClick={handleReject}
                                disabled={
                                    rejectForm.processing ||
                                    !rejectForm.data.rejection_reason
                                }
                                className="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-rose-700 disabled:opacity-50"
                            >
                                Tolak Pinjaman
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}
