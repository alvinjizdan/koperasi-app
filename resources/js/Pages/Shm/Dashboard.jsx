import React from "react";
import { Link } from "@inertiajs/react";
import ShmLayout from "@/Layouts/ShmLayout";
import StatCard from "@/Components/Admin/StatCard";
import StatusBadge from "@/Components/Admin/StatusBadge";
import {
    ArrowRight,
    CheckCircle2,
    ClipboardCheck,
    FileText,
    Gavel,
    Play,
    UserRound,
    XCircle,
} from "lucide-react";
import { formatDateTime } from "@/utils/format";

/**
 * Peta warna badge untuk tahap pengajuan. Kunci mengikuti StatusBadge
 * existing; tidak ada warna baru.
 */
const APPLICATION_BADGES = {
    draft: "PENDING",
    submitted: "PENDING",
    credit_checking: "REVIEW",
    memorandum: "REVIEW",
    approval: "REVIEW",
    approved: "COMPLETED",
    document_check: "REVIEW",
    rejected: "FAILED",
    ready_for_disbursement: "REVIEW",
    disbursed: "COMPLETED",
    completed: "COMPLETED",
    cancelled: "FAILED",
};

/**
 * Urutan pipeline workflow existing (ShmApplicationStatus::timeline()).
 * Rejected ditampilkan TERPISAH sebagai branch, bukan tahap lanjutan.
 */
const PIPELINE_KEYS = [
    "submitted",
    "credit_checking",
    "memorandum",
    "approval",
    "approved",
    "document_check",
    "ready_for_disbursement",
    "disbursed",
    "completed",
];

const PIPELINE_LABELS = {
    submitted: "Masuk",
    credit_checking: "Credit Checking",
    memorandum: "Memorandum",
    approval: "Approval",
    approved: "Approved",
    document_check: "Document Check",
    ready_for_disbursement: "Siap Pencairan",
    disbursed: "Disbursed",
    completed: "Completed",
};

const REJECTED_KEY = "rejected";

/**
 * Peta metric -> route existing untuk metric yang dapat diklik. Tidak ada
 * endpoint navigasi baru; yang tidak punya daftar khusus tidak diklik.
 */
const PIPELINE_LINKS = {
    submitted: "shm.exports.masuk",
    approval: "shm.approvals.index",
    approved: "shm.exports.approve",
    rejected: "shm.exports.reject",
    disbursed: "shm.exports.pencairan",
};

/**
 * Link navigasi modul operasional existing.
 */
const NAVIGATION_LINKS = [
    { label: "Proses Marketing", route: "shm.applications.index" },
    { label: "Approval", route: "shm.approvals.index" },
    { label: "Kelengkapan Dokumen", route: "shm.documents.index" },
    { label: "Data Nasabah Approve", route: "shm.exports.approve" },
    { label: "Data Nasabah Reject", route: "shm.exports.reject" },
    { label: "Data Nasabah Pencairan", route: "shm.exports.pencairan" },
];

/**
 * Dashboard aplikasi SHM (Ticket 48, ditingkatkan Ticket 56).
 *
 * Seluruh angka dihitung server dari tabel SHM yang sudah ada. TIDAK ada KPI
 * rekaan (pendapatan, tingkat approval, nilai pencairan, kolektibilitas) karena
 * datanya belum ada dan aturan bisnisnya belum diputuskan KSP.
 *
 * Cakupan data dijelaskan eksplisit pada tiap kartu supaya angka tidak
 * ditafsirkan sebagai data seluruh koperasi.
 */
export default function Dashboard({ scope, metrics, flash }) {
    const seesEverything = scope === "all";
    const scopeNote = seesEverything
        ? "Seluruh pengajuan SHM."
        : "Hanya pengajuan SHM milik Anda.";

    const creditChecking = metrics.credit_checking;
    const applications = metrics.applications;
    const memorandumDistribution = metrics.memorandum_distribution ?? [];

    const statusCount = (key) => {
        const found = applications.by_status.find(
            (item) => item.status === key,
        );
        return found ? found.total : 0;
    };

    const pipeline = PIPELINE_KEYS.map((key) => ({
        key,
        label: PIPELINE_LABELS[key],
        total: statusCount(key),
        link: PIPELINE_LINKS[key] ?? null,
    }));
    const rejected = {
        key: REJECTED_KEY,
        label: "Rejected",
        total: statusCount(REJECTED_KEY),
        link: PIPELINE_LINKS[REJECTED_KEY] ?? null,
    };

    const recent = metrics.recent_applications ?? [];

    const renderCount = (item) => {
        const inner = (
            <>
                <span className="text-slate-600">{item.label}</span>
                <span className="flex items-center gap-1 font-semibold text-slate-900">
                    {item.total}
                    {item.link && (
                        <ArrowRight className="h-3 w-3 text-slate-400" />
                    )}
                </span>
            </>
        );

        return item.link ? (
            <Link
                key={item.key}
                href={route(item.link)}
                className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm transition-colors hover:border-indigo-300 hover:bg-indigo-50/40"
            >
                {inner}
            </Link>
        ) : (
            <div
                key={item.key}
                className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm"
            >
                {inner}
            </div>
        );
    };

    return (
        <ShmLayout title="Dashboard SHM">
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold text-gray-800">
                            Dashboard SHM
                        </h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Ringkasan operasional modul SHM/SHGB. Hanya berisi
                            metrik yang datanya memang tersedia.
                        </p>
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        {flash.error}
                    </div>
                )}

                {/* SECTION 1 — Summary */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        title="Total Pengajuan"
                        value={applications.total}
                        icon={FileText}
                        tone="blue"
                        scope={scopeNote}
                    />
                    <StatCard
                        title="Masuk"
                        value={applications.masuk}
                        icon={ClipboardCheck}
                        tone="indigo"
                        statusNote="Pengajuan yang sudah disubmit"
                        scope={`Semantics modul export (submitted_at terisi). ${scopeNote}`}
                    />
                    <StatCard
                        title="Credit Checking — Belum Dimulai"
                        value={creditChecking.not_started}
                        icon={Play}
                        tone="amber"
                        statusNote="Sudah masuk tahap proses"
                        scope={`Pengajuan yang belum memiliki catatan credit checking. ${scopeNote}`}
                    />
                    <StatCard
                        title="Credit Checking — Selesai"
                        value={creditChecking.completed}
                        icon={CheckCircle2}
                        tone="emerald"
                        statusNote={`${creditChecking.in_progress} sedang berjalan`}
                        scope={`Hasil pencatatan manual, bukan keputusan kelayakan. ${scopeNote}`}
                    />
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {/* Ticket 57: jumlah approval yang menunggu keputusan
                        menurut ShmApproval.status = pending — bukan status
                        pengajuan. Kartu clickable menuju antrean approval
                        existing; hanya dirender untuk pengguna yang menerima
                        angkanya (pemegang permission approval). */}
                    {typeof metrics.pending_approval === "number" && (
                        <Link
                            href={route("shm.approvals.index")}
                            className="block rounded-xl transition-transform active:scale-[0.99]"
                        >
                            <StatCard
                                title="Pending Approval"
                                value={metrics.pending_approval}
                                icon={Gavel}
                                tone="amber"
                                statusNote="Klik untuk membuka antrean approval"
                                scope="Dihitung dari keputusan approval yang belum diambil (ShmApproval pending)."
                            />
                        </Link>
                    )}
                    <StatCard
                        title="Approval (menunggu)"
                        value={statusCount("approval")}
                        icon={Gavel}
                        tone="amber"
                        scope={`Pengajuan pada tahap keputusan. ${scopeNote}`}
                    />
                    <StatCard
                        title="Approved"
                        value={statusCount("approved")}
                        icon={CheckCircle2}
                        tone="emerald"
                        scope={scopeNote}
                    />
                    <StatCard
                        title="Rejected"
                        value={rejected.total}
                        icon={XCircle}
                        tone="rose"
                        scope={scopeNote}
                    />
                    <StatCard
                        title="Nasabah SHM"
                        value={metrics.customers.total}
                        icon={UserRound}
                        tone="indigo"
                        statusNote={`${metrics.customers.active} nasabah aktif`}
                        scope="Master data nasabah SHM, terpisah dari anggota koperasi."
                    />
                </div>

                <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
                    {/* SECTION 2 — Pipeline */}
                    <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs xl:col-span-2">
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                            <h2 className="flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <ClipboardCheck className="h-4 w-4" />
                                Pipeline Workflow
                            </h2>
                            <Link
                                href={route("shm.applications.index")}
                                className="text-xs font-semibold text-indigo-600 hover:underline"
                            >
                                Buka daftar pengajuan
                            </Link>
                        </div>

                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            {pipeline.map(renderCount)}
                        </div>

                        <div className="mt-4 border-t border-slate-100 pt-3">
                            <p className="mb-2 text-[11px] font-medium uppercase text-slate-400">
                                Keluar branch — tidak melanjutkan pipeline
                            </p>
                            <div className="sm:max-w-xs">{renderCount(rejected)}</div>
                        </div>

                        <p className="mt-4 border-t border-slate-100 pt-3 text-[11px] leading-relaxed text-slate-400">
                            {scopeNote} Angka per tahap dihitung dari status
                            pengajuan saat ini; metric bergaris bawah dapat
                            diklik menuju daftar data yang relevan.
                        </p>
                    </section>

                    {/* SECTION 3 — Memorandum distribution */}
                    <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                        <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                            <FileText className="h-4 w-4" />
                            Distribusi Memorandum
                        </h2>
                        <ul className="space-y-2">
                            {memorandumDistribution.map((item) => (
                                <li
                                    key={item.type}
                                    className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm"
                                >
                                    <span className="text-slate-600">
                                        {item.label}
                                    </span>
                                    <span className="font-semibold text-slate-900">
                                        {item.total}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-4 border-t border-slate-100 pt-3 text-[11px] leading-relaxed text-slate-400">
                            Dihitung dari memorandum terakhir tiap pengajuan.
                            Pengajuan yang belum memiliki memorandum tidak
                            dimasukkan ke kategori mana pun.
                        </p>
                    </section>
                </div>

                {/* SECTION 4 — Marketing Operational Summary */}
                <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <h2 className="flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                            <ClipboardCheck className="h-4 w-4" />
                            Marketing Operational Summary
                        </h2>
                    </div>

                    {metrics.marketing_summary && metrics.marketing_summary.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-xs">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="whitespace-nowrap px-2 py-2 text-left font-semibold text-slate-600">Marketing</th>
                                        <th className="whitespace-nowrap px-2 py-2 text-right font-semibold text-slate-600">Total</th>
                                        <th className="whitespace-nowrap px-2 py-2 text-right font-semibold text-slate-600">Masuk</th>
                                        <th className="whitespace-nowrap px-2 py-2 text-right font-semibold text-slate-600">Credit Checking</th>
                                        <th className="whitespace-nowrap px-2 py-2 text-right font-semibold text-slate-600">Memorandum</th>
                                        <th className="whitespace-nowrap px-2 py-2 text-right font-semibold text-slate-600">Approval</th>
                                        <th className="whitespace-nowrap px-2 py-2 text-right font-semibold text-slate-600">Approved</th>
                                        <th className="whitespace-nowrap px-2 py-2 text-right font-semibold text-slate-600">Rejected</th>
                                        <th className="whitespace-nowrap px-2 py-2 text-right font-semibold text-slate-600">Document Check</th>
                                        <th className="whitespace-nowrap px-2 py-2 text-right font-semibold text-slate-600">Ready for Disbursement</th>
                                        <th className="whitespace-nowrap px-2 py-2 text-right font-semibold text-slate-600">Disbursed</th>
                                        <th className="whitespace-nowrap px-2 py-2 text-right font-semibold text-slate-600">Completed</th>
                                        <th className="whitespace-nowrap px-2 py-2 text-right font-semibold text-slate-600">Pending Approval</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {metrics.marketing_summary.map((row) => (
                                        <tr key={row.marketing_id} className="hover:bg-slate-50">
                                            <td className="whitespace-nowrap px-2 py-2 text-left text-slate-700">{row.marketing_name}</td>
                                            <td className="whitespace-nowrap px-2 py-2 text-right font-medium text-slate-900">{row.total}</td>
                                            <td className="whitespace-nowrap px-2 py-2 text-right text-slate-700">{row.masuk}</td>
                                            <td className="whitespace-nowrap px-2 py-2 text-right text-slate-700">{row.credit_checking}</td>
                                            <td className="whitespace-nowrap px-2 py-2 text-right text-slate-700">{row.memorandum}</td>
                                            <td className="whitespace-nowrap px-2 py-2 text-right text-slate-700">{row.approval}</td>
                                            <td className="whitespace-nowrap px-2 py-2 text-right text-slate-700">{row.approved}</td>
                                            <td className="whitespace-nowrap px-2 py-2 text-right text-slate-700">{row.rejected}</td>
                                            <td className="whitespace-nowrap px-2 py-2 text-right text-slate-700">{row.document_check}</td>
                                            <td className="whitespace-nowrap px-2 py-2 text-right text-slate-700">{row.ready_for_disbursement}</td>
                                            <td className="whitespace-nowrap px-2 py-2 text-right text-slate-700">{row.disbursed}</td>
                                            <td className="whitespace-nowrap px-2 py-2 text-right text-slate-700">{row.completed}</td>
                                            <td className="whitespace-nowrap px-2 py-2 text-right text-slate-700">{row.pending_approval}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <p className="text-sm text-gray-500">Belum ada data operasional Marketing.</p>
                    )}
                </section>

                {/* SECTION 5 — Recent applications */}
                <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <h2 className="flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                            <FileText className="h-4 w-4" />
                            Pengajuan Terbaru
                        </h2>
                        <Link
                            href={route("shm.applications.index")}
                            className="text-xs font-semibold text-indigo-600 hover:underline"
                        >
                            Lihat semua
                        </Link>
                    </div>

                    {recent.length === 0 ? (
                        <p className="text-sm text-gray-500">
                            Belum ada pengajuan pada cakupan data Anda.
                        </p>
                    ) : (
                        <ul className="divide-y divide-slate-100">
                            {recent.map((row) => (
                                <li key={row.id}>
                                    <Link
                                        href={route(
                                            "shm.applications.show",
                                            row.id,
                                        )}
                                        className="flex flex-wrap items-center justify-between gap-2 px-1 py-2.5 text-sm transition-colors hover:bg-slate-50"
                                    >
                                        <div className="min-w-0">
                                            <p className="font-medium text-indigo-600">
                                                {row.application_number}
                                            </p>
                                            <p className="truncate text-[11px] text-slate-400">
                                                {row.customer_label}
                                                {row.customer_number
                                                    ? ` · ${row.customer_number}`
                                                    : ""}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <span className="text-xs text-slate-500">
                                                {row.memorandum_type_label ?? (
                                                    <span className="text-slate-300">
                                                        -
                                                    </span>
                                                )}
                                            </span>
                                            <StatusBadge
                                                status={
                                                    APPLICATION_BADGES[
                                                        row.status
                                                    ] ?? "PENDING"
                                                }
                                                label={row.status_label}
                                            />
                                            <span className="w-32 text-right text-[11px] text-slate-400">
                                                {row.updated_at
                                                    ? formatDateTime(
                                                          row.updated_at,
                                                      )
                                                    : "-"}
                                            </span>
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {/* SECTION 5 — Navigation */}
                <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                    <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                        <ArrowRight className="h-4 w-4" />
                        Modul Operasional
                    </h2>
                    <div className="flex flex-wrap gap-2">
                        {NAVIGATION_LINKS.map((link) => (
                            <Link
                                key={link.route}
                                href={route(link.route)}
                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition-colors hover:border-indigo-300 hover:bg-indigo-50/40 hover:text-indigo-700"
                            >
                                {link.label}
                            </Link>
                        ))}
                    </div>
                </section>
            </div>
        </ShmLayout>
    );
}
