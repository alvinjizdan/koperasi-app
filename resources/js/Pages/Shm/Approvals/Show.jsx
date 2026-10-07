import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import StatusBadge from '@/Components/Admin/StatusBadge';
import SecondaryButton from '@/Components/SecondaryButton';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import { formatDateTime } from '@/utils/format';
import {
    ArrowLeft,
    AlertTriangle,
    ClipboardCheck,
    FileText,
    FolderOpen,
    Gavel,
    ScrollText,
    UserRound,
    X,
} from 'lucide-react';

/**
 * Peta warna badge tahap pengajuan pada modul approval.
 *
 * Kunci mengikuti StatusBadge existing; hanya tahap keputusan yang relevan.
 */
const APPLICATION_BADGES = {
    approval: 'REVIEW',
    approved: 'COMPLETED',
    rejected: 'FAILED',
};

/**
 * Chip status dokumen read-only. Semantik mengikuti T51/T53: `pending`
 * berarti belum lengkap, `complete`/`not_applicable` dianggap tercatat.
 * Tidak ada daftar dokumen wajib yang dikarang di sini.
 */
function DocumentStatusChip({ status }) {
    if (status === 'complete') {
        return <StatusBadge status="COMPLETED" label="Tercatat" />;
    }

    if (status === 'not_applicable') {
        return <StatusBadge status="REVIEW" label="Tidak Berlaku" />;
    }

    return <StatusBadge status="PENDING" label="Pending" />;
}

/**
 * Detail approval satu pengajuan (Ticket 54).
 *
 * Seluruh bagian bersifat READ-ONLY kecuali form keputusan: approve/reject
 * mengirim ke endpoint pengajuan existing sehingga workflow, actor, timestamp,
 * dan audit tetap berasal dari ShmWorkflowService. Halaman ini tidak pernah
 * menulis status sendiri.
 */
export default function Show({
    application,
    properties,
    credit_check,
    memorandum,
    approval,
    documents,
    documents_summary,
    document_requirement_context,
    can_decide,
    history,
    flash,
}) {
    const customer = application?.customer;
    const summary = documents_summary ?? { total: 0, complete: 0, pending: 0, is_complete: false };

    return (
        <ShmLayout title={`Approval - ${application?.application_number ?? '-'}`}>
            <Head title={`Approval - ${application?.application_number ?? '-'}`} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link
                            href={route('shm.approvals.index')}
                            className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"
                        >
                            <ArrowLeft className="h-3.5 w-3.5" />
                            Kembali ke Daftar Approval
                        </Link>
                        <h1 className="text-xl font-semibold text-gray-800">Approval</h1>
                        <p className="mt-1 flex items-center gap-2 text-sm text-gray-500">
                            {application?.application_number ?? '-'}
                            <StatusBadge
                                status={APPLICATION_BADGES[application?.status] ?? 'PENDING'}
                                label={application?.status_label ?? '-'}
                            />
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Link href={route('shm.applications.show', application.id)}>
                            <SecondaryButton>Buka Pengajuan</SecondaryButton>
                        </Link>
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

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <FileText className="h-4 w-4" />
                                Pengajuan
                            </h2>
                            <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt className="text-xs text-gray-500">No. Pengajuan</dt>
                                    <dd className="font-medium">{application?.application_number ?? '-'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Tahap</dt>
                                    <dd>
                                        <StatusBadge
                                            status={APPLICATION_BADGES[application?.status] ?? 'PENDING'}
                                            label={application?.status_label ?? '-'}
                                        />
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Marketing</dt>
                                    <dd>{application?.marketing ?? '-'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Dibuat</dt>
                                    <dd>{application?.created_at ? formatDateTime(application.created_at) : '-'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Disubmit</dt>
                                    <dd>{application?.submitted_at ? formatDateTime(application.submitted_at) : '-'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Diperbarui</dt>
                                    <dd>{application?.updated_at ? formatDateTime(application.updated_at) : '-'}</dd>
                                </div>
                                {application?.approved_at && (
                                    <div>
                                        <dt className="text-xs text-gray-500">Disetujui</dt>
                                        <dd>{formatDateTime(application.approved_at)}</dd>
                                    </div>
                                )}
                                {application?.rejected_at && (
                                    <div>
                                        <dt className="text-xs text-gray-500">Ditolak</dt>
                                        <dd>{formatDateTime(application.rejected_at)}</dd>
                                    </div>
                                )}
                            </dl>
                            {application?.notes && (
                                <p className="mt-3 whitespace-pre-line border-t border-slate-100 pt-3 text-sm text-gray-600">
                                    {application.notes}
                                </p>
                            )}
                        </section>

                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <UserRound className="h-4 w-4" />
                                Nasabah SHM
                            </h2>
                            {!customer ? (
                                <p className="text-sm text-gray-500">Data nasabah tidak ditemukan.</p>
                            ) : (
                                <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt className="text-xs text-gray-500">No. Nasabah</dt>
                                        <dd className="font-medium">{customer.customer_number ?? '-'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500">Nama</dt>
                                        <dd>{customer.name ?? '-'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500">NIK</dt>
                                        <dd>{customer.nik ?? '-'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500">Telepon</dt>
                                        <dd>{customer.phone ?? '-'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500">Email</dt>
                                        <dd>{customer.email ?? '-'}</dd>
                                    </div>
                                    <div className="sm:col-span-2">
                                        <dt className="text-xs text-gray-500">Alamat</dt>
                                        <dd>{customer.address ?? '-'}</dd>
                                    </div>
                                </dl>
                            )}
                        </section>

                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <FolderOpen className="h-4 w-4" />
                                Objek SHM/SHGB
                            </h2>
                            {properties?.length === 0 ? (
                                <p className="text-sm text-gray-500">Belum ada objek yang dicatat.</p>
                            ) : (
                                properties.map((property) => (
                                    <div key={property.id} className="mb-3 rounded border border-slate-200 p-3 text-sm last:mb-0">
                                        <p className="font-medium">
                                            {property.certificate_type}
                                            {property.certificate_number ? ` - ${property.certificate_number}` : ''}
                                        </p>
                                        <p className="text-gray-600">{property.address}</p>
                                        {property.owner_name && (
                                            <p className="text-xs text-gray-500">Pemilik: {property.owner_name}</p>
                                        )}
                                    </div>
                                ))
                            )}
                        </section>

                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <ScrollText className="h-4 w-4" />
                                Memorandum
                            </h2>
                            {!memorandum ? (
                                <p className="text-sm text-gray-500">
                                    Belum ada memorandum yang tercatat untuk pengajuan ini.
                                </p>
                            ) : (
                                <div className="space-y-2 text-sm">
                                    <p className="font-medium">
                                        Kategori: {memorandum.type_label ?? '-'}
                                    </p>
                                    <p>Status: {memorandum.status_label}</p>
                                    {memorandum.creator && (
                                        <p className="text-xs text-gray-500">Dicatat oleh: {memorandum.creator}</p>
                                    )}
                                    {memorandum.submitted_at && (
                                        <p className="text-xs text-gray-500">
                                            Diajukan: {formatDateTime(memorandum.submitted_at)}
                                        </p>
                                    )}
                                    {memorandum.notes && (
                                        <p className="whitespace-pre-line border-t border-slate-100 pt-2 text-gray-600">
                                            {memorandum.notes}
                                        </p>
                                    )}
                                </div>
                            )}
                        </section>

                        <section className="rounded-lg bg-white p-5 shadow">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <h2 className="mb-0 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                    <FileText className="h-4 w-4" />
                                    Kelengkapan Dokumen
                                </h2>
                                <Link
                                    href={route('shm.documents.show', application.id)}
                                    className="text-xs font-semibold text-indigo-600 hover:underline"
                                >
                                    Lihat Kelengkapan Dokumen
                                </Link>
                            </div>

                            <p className="mb-3 mt-2 text-xs text-gray-500">
                                Informasi read-only. {document_requirement_context?.note}
                            </p>

                            {documents?.length === 0 ? (
                                <p className="text-sm text-gray-500">Belum ada item dokumen yang dicatat.</p>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {documents.map((document) => (
                                        <li
                                            key={document.id}
                                            className="flex items-center justify-between rounded border border-slate-200 px-3 py-2"
                                        >
                                            <span>{document.document_name}</span>
                                            <DocumentStatusChip status={document.status} />
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <p className="mt-3 text-xs text-gray-500">
                                {summary.total} item — {summary.complete} tercatat, {summary.pending} pending.
                                {' '}Kelengkapan dokumen tidak otomatis menyetujui pengajuan dan bukan syarat
                                keputusan approval pada workflow existing.
                            </p>
                        </section>

                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <ClipboardCheck className="h-4 w-4" />
                                Riwayat
                            </h2>
                            {history?.length === 0 ? (
                                <p className="text-sm text-gray-500">Belum ada riwayat.</p>
                            ) : (
                                <ul className="space-y-3 text-xs">
                                    {history.map((entry) => (
                                        <li key={entry.id} className="border-l-2 border-slate-200 pl-3">
                                            <p className="font-medium text-gray-700">{entry.action}</p>
                                            <p className="text-gray-400">
                                                {entry.actor?.name ?? 'Sistem'} - {formatDateTime(entry.created_at)}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </div>

                    <div className="space-y-6">
                        <DecisionSection
                            application={application}
                            approval={approval}
                            canDecide={can_decide}
                        />

                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <ClipboardCheck className="h-4 w-4" />
                                Referensi Credit Checking
                            </h2>
                            {!credit_check ? (
                                <p className="text-sm text-gray-500">
                                    Belum ada catatan credit checking. Menyelesaikan credit checking tidak
                                    pernah menyetujui atau menolak pengajuan secara otomatis.
                                </p>
                            ) : (
                                <div className="space-y-2 text-sm">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusBadge status={credit_check.status_badge} label={credit_check.status_label} />
                                        {credit_check.outcome && (
                                            <StatusBadge status={credit_check.outcome_badge} label={credit_check.outcome_label} />
                                        )}
                                    </div>
                                    <p className="text-xs text-gray-500">
                                        Petugas: {credit_check.checker ?? '-'}
                                    </p>
                                    {credit_check.completed_at && (
                                        <p className="text-xs text-gray-500">
                                            Selesai: {formatDateTime(credit_check.completed_at)}
                                        </p>
                                    )}
                                    {credit_check.result && (
                                        <p className="whitespace-pre-line text-gray-600">{credit_check.result}</p>
                                    )}
                                </div>
                            )}
                        </section>
                    </div>
                </div>
            </div>
        </ShmLayout>
    );
}

/**
 * Form keputusan approval.
 *
 * Keputusan hanya tersedia bila pengajuan memang berada pada tahap approval
 * dan pengguna memegang permission `shm.approval.decide` (dievaluasi server).
 * Tombol tidak pernah mengubah status langsung: keduanya membuka konfirmasi,
 * lalu mengirim ke endpoint pengajuan existing (shm.applications.approve /
 * shm.applications.reject) sehingga state machine dan audit tetap milik
 * ShmWorkflowService.
 *
 * Field notes/reason adalah field existing pada shm_approvals dan
 * DecideShmApprovalRequest; tidak ada kolom baru.
 */
function DecisionSection({ application, approval, canDecide }) {
    const [notes, setNotes] = useState('');
    const [showApprove, setShowApprove] = useState(false);
    const [showReject, setShowReject] = useState(false);
    const [errors, setErrors] = useState({});

    const decide = (routeName, close) => {
        setErrors({});
        router.post(
            route(routeName, application.id),
            { notes: notes || null },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setNotes('');
                    close();
                },
                onError: (e) => setErrors(e),
            },
        );
    };

    return (
        <section className="rounded-lg bg-white p-5 shadow">
            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                <Gavel className="h-4 w-4" />
                Keputusan Approval
            </h2>

            {approval ? (
                <div className="mb-3 space-y-1 text-sm">
                    <p className="font-medium">Status: {approval.status}</p>
                    {approval.decider && (
                        <p className="text-xs text-gray-500">Keputusan oleh: {approval.decider}</p>
                    )}
                    {approval.decided_at && (
                        <p className="text-xs text-gray-500">
                            {formatDateTime(approval.decided_at)}
                        </p>
                    )}
                    {approval.notes && (
                        <p className="whitespace-pre-line border-t border-slate-100 pt-2">{approval.notes}</p>
                    )}
                </div>
            ) : (
                <p className="mb-3 text-sm text-gray-500">Belum ada keputusan approval.</p>
            )}

            {canDecide && (
                <div className="space-y-3 border-t border-slate-100 pt-4">
                    <div>
                        <label htmlFor="decision_notes" className="block text-xs font-medium text-slate-600">
                            Catatan Keputusan (opsional)
                        </label>
                        <textarea
                            id="decision_notes"
                            rows={3}
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <p className="text-xs text-gray-500">
                        Keputusan memakai workflow existing. Pengajuan disetujui masuk ke tahap
                        Kelengkapan Dokumen; yang ditolak dapat dikembalikan ke draft oleh pemegang
                        kewenangan.
                    </p>
                    <div className="flex flex-wrap justify-end gap-2">
                        <button
                            type="button"
                            onClick={() => setShowReject(true)}
                            className="inline-flex items-center rounded-lg bg-rose-600 px-4 py-2 text-xs font-semibold text-white transition-all hover:bg-rose-700 active:scale-95"
                        >
                            Tolak
                        </button>
                        <button
                            type="button"
                            onClick={() => setShowApprove(true)}
                            className="inline-flex items-center rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white transition-all hover:bg-emerald-700 active:scale-95"
                        >
                            Setujui
                        </button>
                    </div>
                </div>
            )}

            {!canDecide && !approval && (
                <p className="text-xs text-gray-400">
                    Pengajuan belum berada pada tahap keputusan atau Anda tidak memegang kewenangan
                    approval.
                </p>
            )}

            <ConfirmModal
                isOpen={showApprove}
                onClose={() => setShowApprove(false)}
                onConfirm={() => decide('shm.applications.approve', () => setShowApprove(false))}
                title="Setujui pengajuan ini?"
                message={`Pengajuan ${application?.application_number ?? '-'} atas nama ${application?.customer_label ?? '-'} akan disetujui dan masuk ke tahap Kelengkapan Dokumen.`}
                confirmText="Ya, Setujui"
            />

            {showReject && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
                    <div
                        className="fixed inset-0 bg-slate-900/60 backdrop-blur-xs transition-opacity"
                        onClick={() => setShowReject(false)}
                    />
                    <div className="relative w-full max-w-md transform overflow-hidden rounded-xl bg-white p-6 text-left shadow-xl transition-all border border-slate-200">
                        <button
                            type="button"
                            onClick={() => setShowReject(false)}
                            className="absolute top-4 right-4 rounded-lg p-1 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600"
                            aria-label="Tutup"
                        >
                            <X className="h-5 w-5" />
                        </button>

                        <div className="flex items-start gap-4">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-600 sm:h-12 sm:w-12">
                                <AlertTriangle className="h-6 w-6" />
                            </div>
                            <div>
                                <h3 className="text-base font-semibold text-slate-900">Tolak pengajuan ini?</h3>
                                <p className="mt-1 text-xs sm:text-sm text-slate-500">
                                    Pengajuan {application?.application_number ?? '-'} atas nama{' '}
                                    {application?.customer_label ?? '-'} akan ditolak dan dapat
                                    dikembalikan ke draft untuk diperbaiki.
                                </p>
                            </div>
                        </div>

                        <div className="mt-4">
                            <label htmlFor="reject_notes" className="block text-xs font-medium text-slate-600">
                                Alasan / Catatan (opsional)
                            </label>
                            <textarea
                                id="reject_notes"
                                rows={3}
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            />
                            {errors.notes && <p className="mt-1 text-xs text-rose-600">{errors.notes}</p>}
                            {errors.status && <p className="mt-1 text-xs text-rose-600">{errors.status}</p>}
                        </div>

                        <div className="mt-6 flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setShowReject(false)}
                                className="inline-flex justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors active:scale-95"
                            >
                                Batal
                            </button>
                            <button
                                type="button"
                                onClick={() => decide('shm.applications.reject', () => setShowReject(false))}
                                className="inline-flex justify-center rounded-lg bg-rose-600 px-4 py-2 text-xs sm:text-sm font-semibold text-white hover:bg-rose-700 transition-colors active:scale-95 shadow-xs"
                            >
                                Ya, Tolak
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </section>
    );
}
