import React from 'react';
import StatusBadge from '@/Components/Admin/StatusBadge';
import { formatDateTime } from '@/utils/format';
import {
    ClipboardCheck,
    CheckCircle2,
    AlertTriangle,
    XCircle,
    Clock,
    ShieldAlert,
    ShieldCheck,
    Info,
} from 'lucide-react';

/**
 * Komponen ringkasan Credit Checking untuk halaman Memorandum SHM (Ticket CC-06).
 *
 * Menampilkan:
 * 1. Status pengerjaan (Pending, In Progress, Completed).
 * 2. Hasil keputusan / outcome (PASS, REVIEW, FAIL) jika sudah Completed.
 * 3. Indikator dispensasi / exception aktif (FAIL Override atau Completion Bypass).
 * 4. Peringatan prasyarat kelayakan pengajuan memorandum.
 * 5. Pembedaan konteks: Draft (live checking dari application) vs Submitted/Under Review/Approved (immutable snapshot).
 *
 * @param {Object} props
 * @param {Object|null} props.creditCheck - Konteks live credit check dari aplikasi (saat draft)
 * @param {Object|null} props.snapshot - Immutable credit check snapshot dari memorandum / revision
 * @param {Object|null} props.activeException - Dispensasi aktif pada aplikasi
 * @param {boolean} props.isDraft - Status memorandum saat ini draft
 * @param {boolean} props.isSnapshotView - Menampilkan snapshot historis/arsip
 */
export default function CreditCheckSummaryCard({
    creditCheck = null,
    snapshot = null,
    activeException = null,
    isDraft = true,
    isSnapshotView = false,
}) {
    // Tentukan sumber data: gunakan snapshot jika snapshot view atau jika bukan draft dan snapshot ada
    const useSnapshot = isSnapshotView || (!isDraft && snapshot?.credit_check);
    const data = useSnapshot ? snapshot?.credit_check : creditCheck;
    const exceptionData = useSnapshot ? snapshot?.exception_authorization : activeException;

    const status = data?.status ?? 'pending';
    const outcome = data?.outcome ?? null;
    const isCompleted = status === 'completed';

    // Helper teks & ikon untuk outcome
    const getOutcomeIcon = (out) => {
        switch (out?.toLowerCase()) {
            case 'pass':
                return <CheckCircle2 className="h-4 w-4 text-emerald-600" />;
            case 'review':
                return <AlertTriangle className="h-4 w-4 text-amber-600" />;
            case 'fail':
                return <XCircle className="h-4 w-4 text-rose-600" />;
            default:
                return null;
        }
    };

    const hasActiveException = Boolean(
        exceptionData &&
        (exceptionData.is_excepted || exceptionData.status === 'authorized' || exceptionData.exception_number)
    );

    return (
        <section className="rounded-lg bg-white p-5 shadow-sm border border-slate-200">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
                <h2 className="text-sm font-semibold uppercase tracking-wider text-slate-700 flex items-center gap-1.5">
                    <ClipboardCheck className="h-4 w-4 text-bsm-primary" />
                    {useSnapshot ? 'Snapshot Hasil Credit Checking (Tersimpan)' : 'Status & Hasil Credit Checking'}
                </h2>
                {useSnapshot && (
                    <span className="inline-flex items-center gap-1 text-[11px] font-medium text-slate-500 bg-slate-100 px-2 py-0.5 rounded border border-slate-200">
                        <Clock className="h-3 w-3" />
                        Beku saat Submit
                    </span>
                )}
            </div>

            {/* Banner Dispensasi / Exception jika ada */}
            {hasActiveException && (
                <div className="mb-4 rounded-md border border-amber-300 bg-amber-50/80 p-3.5 text-xs text-amber-900 flex items-start gap-3">
                    <ShieldAlert className="h-5 w-5 text-amber-600 shrink-0 mt-0.5" />
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <span className="font-semibold uppercase tracking-wide text-[11px] bg-amber-200 text-amber-900 px-2 py-0.5 rounded">
                                {exceptionData.exception_type === 'fail_override'
                                    ? 'Dispensasi: FAIL Override'
                                    : 'Dispensasi: Completion Bypass'}
                            </span>
                            <span className="font-mono text-slate-700 text-[11px]">
                                #{exceptionData.exception_number}
                            </span>
                        </div>
                        <p className="text-slate-700 text-xs leading-relaxed">
                            {exceptionData.official_disclosure_summary ||
                                'Dispensasi telah disetujui secara resmi oleh Direktur Utama.'}
                        </p>
                        <p className="text-[11px] text-slate-500">
                            Disetujui oleh: <span className="font-medium text-slate-700">{exceptionData.authorized_by_name ?? 'Direktur Utama'}</span>{' '}
                            {exceptionData.authorized_at && `pada ${formatDateTime(exceptionData.authorized_at)}`}
                        </p>
                    </div>
                </div>
            )}

            {!data ? (
                /* Kondisi belum ada credit check sama sekali */
                <div className="rounded-md border border-slate-200 bg-slate-50 p-4 text-center">
                    <Info className="h-5 w-5 text-slate-400 mx-auto mb-1.5" />
                    <p className="text-xs font-medium text-slate-600">
                        Pemeriksaan Credit Checking belum dimulai untuk pengajuan ini.
                    </p>
                    {isDraft && !hasActiveException && (
                        <p className="text-[11px] text-rose-600 mt-1 font-medium">
                            Perhatian: Credit Checking wajib berstatus Completed sebelum memorandum dapat diajukan untuk approval.
                        </p>
                    )}
                </div>
            ) : (
                /* Data Credit Check Tersedia */
                <div className="space-y-4">
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
                        <div className="rounded-lg border border-slate-100 bg-slate-50/60 p-3">
                            <span className="text-slate-400 block mb-1">Status Pemeriksaan</span>
                            <div className="mt-0.5">
                                <StatusBadge
                                    status={status === 'completed' ? 'COMPLETED' : status === 'in_progress' ? 'IN_PROGRESS' : 'PENDING'}
                                    label={data.status_label ?? (isCompleted ? 'Selesai' : 'Sedang Diproses')}
                                />
                            </div>
                        </div>

                        <div className="rounded-lg border border-slate-100 bg-slate-50/60 p-3">
                            <span className="text-slate-400 block mb-1">Hasil (Outcome)</span>
                            <div className="mt-0.5 flex items-center gap-1.5">
                                {outcome ? (
                                    <>
                                        {getOutcomeIcon(outcome)}
                                        <StatusBadge
                                            status={outcome.toUpperCase()}
                                            label={data.outcome_label ?? outcome.toUpperCase()}
                                        />
                                    </>
                                ) : (
                                    <span className="text-slate-400 italic">Menunggu Hasil</span>
                                )}
                            </div>
                        </div>

                        <div className="rounded-lg border border-slate-100 bg-slate-50/60 p-3">
                            <span className="text-slate-400 block mb-1">Petugas Pemeriksa</span>
                            <span className="font-medium text-slate-800 text-xs">
                                {data.checker ?? data.checker_name ?? '-'}
                            </span>
                        </div>

                        <div className="rounded-lg border border-slate-100 bg-slate-50/60 p-3">
                            <span className="text-slate-400 block mb-1">Waktu Selesai</span>
                            <span className="font-medium text-slate-800 text-xs">
                                {data.completed_at ? formatDateTime(data.completed_at) : '-'}
                            </span>
                        </div>
                    </div>

                    {/* Ringkasan Temuan / Result */}
                    <div>
                        <h4 className="text-xs font-semibold text-slate-600 mb-1">Ringkasan Catatan Pemeriksaan:</h4>
                        <div className="rounded-md border border-slate-200 bg-slate-50 p-3 text-xs text-slate-800 whitespace-pre-wrap leading-relaxed">
                            {data.result || data.result_summary ? (
                                data.result || data.result_summary
                            ) : (
                                <span className="text-slate-400 italic">Tidak ada catatan ringkasan.</span>
                            )}
                        </div>
                    </div>

                    {/* Petunjuk Operasional & Guard Status saat Draft */}
                    {isDraft && !isSnapshotView && (
                        <div className="border-t border-slate-100 pt-3">
                            {status !== 'completed' && !hasActiveException && (
                                <div className="rounded-md bg-amber-50 border border-amber-200 p-2.5 text-xs text-amber-800 flex items-start gap-2">
                                    <AlertTriangle className="h-4 w-4 text-amber-600 shrink-0 mt-0.5" />
                                    <span>
                                        Pemeriksaan Credit Checking masih berstatus <strong>{data.status_label || status}</strong>. 
                                        Pengajuan memorandum terkunci hingga pemeriksaan berstatus Completed atau dispensasi diterbitkan.
                                    </span>
                                </div>
                            )}

                            {isCompleted && (
                                <div className="rounded-md bg-emerald-50 border border-emerald-200 p-2.5 text-xs text-emerald-800 flex items-start gap-2">
                                    <ShieldCheck className="h-4 w-4 text-emerald-600 shrink-0 mt-0.5" />
                                    <span>
                                        Pemeriksaan Credit Checking telah <strong>Completed</strong>. 
                                        Memorandum memenuhi kriteria kelayakan untuk diajukan approval.
                                    </span>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}
