import React, { useState } from 'react';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import StatusBadge from '@/Components/Admin/StatusBadge';
import { formatDateTime, formatDate } from '@/utils/format';
import {
    History,
    FileText,
    CheckCircle2,
    AlertTriangle,
    XCircle,
    Download,
    Eye,
    ShieldCheck,
    Hash,
    Building2,
    UserRound,
    Clock,
    FileCheck,
} from 'lucide-react';

/**
 * Komponen riwayat revisi pengajuan memorandum (Ticket CC-06, Option A).
 *
 * Menampilkan:
 * 1. Daftar seluruh revisi pengajuan memorandum (urutan terbaru di atas).
 * 2. Nomor revisi, waktu submit, pengaju, ringkasan mitigasi, dan cuplikan content hash.
 * 3. Modal detail revisi untuk menginspeksi snapshot debitur, agunan, credit checking,
 *    dispensasi (jika ada), dan dokumen buyback historis (dengan tombol download yang aman).
 *
 * @param {Object} props
 * @param {Array} props.submissions - Array objek riwayat revisi ShmMemorandumSubmission
 * @param {string} props.memorandumId - ID memorandum aktif
 */
export default function MemorandumRevisionHistory({ submissions = [], memorandumId }) {
    const [selectedRevision, setSelectedRevision] = useState(null);

    if (!submissions || submissions.length === 0) {
        return (
            <section className="rounded-lg bg-white p-5 shadow-sm border border-slate-200">
                <h2 className="text-sm font-semibold uppercase tracking-wider text-slate-700 flex items-center gap-1.5 mb-2">
                    <History className="h-4 w-4 text-bsm-primary" />
                    Riwayat Revisi Pengajuan
                </h2>
                <div className="rounded-md border border-dashed border-slate-200 p-4 text-center">
                    <p className="text-xs text-slate-400 italic">
                        Belum ada riwayat pengajuan tersimpan. Dokumen snapshot dibuat otomatis saat memorandum pertama kali diajukan untuk approval.
                    </p>
                </div>
            </section>
        );
    }

    const handleOpenDetail = (sub) => {
        setSelectedRevision(sub);
    };

    const handleCloseDetail = () => {
        setSelectedRevision(null);
    };

    return (
        <section className="rounded-lg bg-white p-5 shadow-sm border border-slate-200">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
                <h2 className="text-sm font-semibold uppercase tracking-wider text-slate-700 flex items-center gap-1.5">
                    <History className="h-4 w-4 text-bsm-primary" />
                    Riwayat Revisi Pengajuan ({submissions.length})
                </h2>
                <span className="text-[11px] text-slate-500 font-medium bg-slate-100 px-2 py-0.5 rounded border border-slate-200">
                    Immutable Audit Trail
                </span>
            </div>

            <div className="divide-y divide-slate-100">
                {submissions.map((sub) => {
                    const ccOutcome = sub.credit_check_snapshot?.credit_check?.outcome;
                    const ccOutcomeLabel = sub.credit_check_snapshot?.credit_check?.outcome_label;
                    const isExcepted = Boolean(sub.exception_snapshot?.is_excepted || sub.exception_snapshot?.exception_number);

                    return (
                        <div
                            key={sub.id || sub.revision_number}
                            className="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-slate-50/50 px-2 rounded-lg transition-colors"
                        >
                            <div className="space-y-1">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                                        Revisi #{sub.revision_number}
                                    </span>
                                    <span className="text-xs text-slate-500">
                                        {formatDateTime(sub.submitted_at)}
                                    </span>
                                    {ccOutcome && (
                                        <StatusBadge
                                            status={ccOutcome.toUpperCase()}
                                            label={ccOutcomeLabel ?? ccOutcome.toUpperCase()}
                                        />
                                    )}
                                    {isExcepted && (
                                        <span className="inline-flex items-center gap-1 text-[10px] font-semibold bg-amber-100 text-amber-800 px-1.5 py-0.5 rounded">
                                            Dispensasi
                                        </span>
                                    )}
                                </div>
                                <div className="text-xs text-slate-600 flex items-center gap-3">
                                    <span>Diajukan oleh: <strong>{sub.submitted_by_name}</strong></span>
                                    <span className="font-mono text-[11px] text-slate-400 flex items-center gap-1">
                                        <Hash className="h-3 w-3" />
                                        {sub.content_hash ? sub.content_hash.substring(0, 8) + '...' : '-'}
                                    </span>
                                </div>
                                {sub.risk_mitigation_notes && (
                                    <p className="text-[11px] text-amber-800 bg-amber-50/60 border border-amber-100 px-2 py-1 rounded line-clamp-1">
                                        Mitigasi: {sub.risk_mitigation_notes}
                                    </p>
                                )}
                            </div>

                            <button
                                type="button"
                                onClick={() => handleOpenDetail(sub)}
                                className="inline-flex items-center justify-center gap-1.5 text-xs text-indigo-600 hover:text-indigo-800 bg-indigo-50 hover:bg-indigo-100 px-3 py-1.5 rounded-md font-medium transition-colors shrink-0 self-start sm:self-center"
                            >
                                <Eye className="h-3.5 w-3.5" />
                                Rincian Snapshot
                            </button>
                        </div>
                    );
                })}
            </div>

            {/* Modal Detail Snapshot Imutabel */}
            {selectedRevision && (
                <Modal show={true} onClose={handleCloseDetail} maxWidth="2xl">
                    <div className="p-6 max-h-[85vh] overflow-y-auto space-y-5 text-slate-800">
                        {/* Header Modal */}
                        <div className="border-b border-slate-200 pb-3 flex items-start justify-between">
                            <div>
                                <div className="flex items-center gap-2">
                                    <h3 className="text-base font-bold text-slate-800">
                                        Rincian Snapshot Revisi #{selectedRevision.revision_number}
                                    </h3>
                                    <span className="text-[11px] font-semibold bg-indigo-100 text-indigo-800 px-2 py-0.5 rounded">
                                        {selectedRevision.memorandum_type === 'buyback' ? 'Buyback' : 'SHM/SHGB'}
                                    </span>
                                </div>
                                <p className="text-xs text-slate-500 mt-0.5">
                                    Diajukan oleh <span className="font-medium text-slate-700">{selectedRevision.submitted_by_name}</span> pada {formatDateTime(selectedRevision.submitted_at)}
                                </p>
                            </div>
                            <span className="text-[11px] font-mono text-slate-500 bg-slate-100 px-2 py-1 rounded border border-slate-200 flex items-center gap-1">
                                <ShieldCheck className="h-3.5 w-3.5 text-emerald-600" />
                                {selectedRevision.template_version}
                            </span>
                        </div>

                        {/* Content Hash Bar */}
                        <div className="rounded-md border border-slate-200 bg-slate-50 p-2.5 text-xs text-slate-600 space-y-1">
                            <div className="flex items-center justify-between">
                                <span className="text-slate-500 font-medium">Checksum Integritas (SHA-256):</span>
                                <span className="text-[10px] text-slate-400">Verifikasi Integritas Data</span>
                            </div>
                            <div className="font-mono text-slate-800 text-[11px] break-all bg-white p-1.5 rounded border border-slate-200 select-all font-semibold">
                                {selectedRevision.content_hash || '-'}
                            </div>
                        </div>

                        {/* Naskah & Catatan Revisi */}
                        <div className="space-y-1">
                            <h4 className="text-xs font-semibold text-slate-700 uppercase tracking-wide flex items-center gap-1">
                                <FileText className="h-3.5 w-3.5 text-bsm-primary" />
                                Catatan / Naskah Memorandum Saat Diajukan
                            </h4>
                            <div className="rounded-md border border-slate-200 bg-slate-50 p-3 text-xs text-slate-800 whitespace-pre-wrap leading-relaxed">
                                {selectedRevision.narrative_notes || <span className="text-slate-400 italic">Tidak ada catatan memorandum.</span>}
                            </div>
                        </div>

                        {/* Catatan Mitigasi Risiko jika ada */}
                        {selectedRevision.risk_mitigation_notes && (
                            <div className="space-y-1">
                                <h4 className="text-xs font-semibold text-amber-800 uppercase tracking-wide flex items-center gap-1">
                                    <AlertTriangle className="h-3.5 w-3.5 text-amber-600" />
                                    Catatan Mitigasi Risiko (Outcome REVIEW)
                                </h4>
                                <div className="rounded-md border border-amber-200 bg-amber-50/70 p-3 text-xs text-amber-950 whitespace-pre-wrap leading-relaxed">
                                    {selectedRevision.risk_mitigation_notes}
                                </div>
                            </div>
                        )}

                        {/* Snapshot Credit Checking */}
                        <div className="space-y-2">
                            <h4 className="text-xs font-semibold text-slate-700 uppercase tracking-wide flex items-center gap-1">
                                <ShieldCheck className="h-3.5 w-3.5 text-bsm-primary" />
                                Snapshot Hasil Credit Checking
                            </h4>
                            {selectedRevision.credit_check_snapshot?.credit_check ? (
                                <div className="rounded-md border border-slate-200 bg-slate-50 p-3 text-xs space-y-2">
                                    <div className="flex items-center gap-3">
                                        <span className="text-slate-500">Hasil:</span>
                                        <StatusBadge
                                            status={(selectedRevision.credit_check_snapshot.credit_check.outcome || 'PASS').toUpperCase()}
                                            label={selectedRevision.credit_check_snapshot.credit_check.outcome_label || selectedRevision.credit_check_snapshot.credit_check.outcome}
                                        />
                                        <span className="text-slate-500 ml-auto">
                                            Pemeriksa: <strong>{selectedRevision.credit_check_snapshot.credit_check.checker_name ?? '-'}</strong>
                                        </span>
                                    </div>
                                    <p className="text-slate-700 leading-relaxed border-t border-slate-200/70 pt-2">
                                        {selectedRevision.credit_check_snapshot.credit_check.result_summary ?? '-'}
                                    </p>
                                </div>
                            ) : (
                                <p className="text-xs text-slate-400 italic">Snapshot credit checking tidak tersedia pada revisi ini.</p>
                            )}
                        </div>

                        {/* Snapshot Dispensasi jika ada */}
                        {selectedRevision.exception_snapshot?.is_excepted && (
                            <div className="space-y-1">
                                <h4 className="text-xs font-semibold text-amber-800 uppercase tracking-wide flex items-center gap-1">
                                    <AlertTriangle className="h-3.5 w-3.5 text-amber-600" />
                                    Dispensasi Khusus (Direktur Utama)
                                </h4>
                                <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-xs space-y-1">
                                    <div className="flex items-center justify-between">
                                        <span className="font-semibold text-amber-900 uppercase">
                                            {selectedRevision.exception_snapshot.exception_type === 'fail_override' ? 'FAIL Override' : 'Completion Bypass'}
                                        </span>
                                        <span className="font-mono text-slate-700 text-[11px]">
                                            #{selectedRevision.exception_snapshot.exception_number}
                                        </span>
                                    </div>
                                    <p className="text-slate-700">
                                        {selectedRevision.exception_snapshot.official_disclosure_summary}
                                    </p>
                                    <p className="text-[11px] text-slate-500">
                                        Disetujui oleh: {selectedRevision.exception_snapshot.authorized_by_name} ({formatDateTime(selectedRevision.exception_snapshot.authorized_at)})
                                    </p>
                                </div>
                            </div>
                        )}

                        {/* Snapshot Debitur & Agunan */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                            <div className="rounded-md border border-slate-200 bg-slate-50 p-3 space-y-1">
                                <span className="font-semibold text-slate-700 flex items-center gap-1">
                                    <UserRound className="h-3.5 w-3.5 text-bsm-primary" />
                                    Snapshot Nasabah
                                </span>
                                <div className="pt-1 text-slate-600 space-y-0.5">
                                    <p><span className="text-slate-400">Nama:</span> <strong className="text-slate-800">{selectedRevision.borrower_snapshot?.name ?? '-'}</strong></p>
                                    <p><span className="text-slate-400">No. Nasabah:</span> {selectedRevision.borrower_snapshot?.customer_number ?? '-'}</p>
                                    <p><span className="text-slate-400">NIK:</span> {selectedRevision.borrower_snapshot?.nik ?? '-'}</p>
                                    <p><span className="text-slate-400">Telepon:</span> {selectedRevision.borrower_snapshot?.phone ?? '-'}</p>
                                </div>
                            </div>

                            <div className="rounded-md border border-slate-200 bg-slate-50 p-3 space-y-1">
                                <span className="font-semibold text-slate-700 flex items-center gap-1">
                                    <Building2 className="h-3.5 w-3.5 text-bsm-primary" />
                                    Snapshot Agunan ({selectedRevision.collateral_snapshot?.length ?? 0})
                                </span>
                                <div className="pt-1 text-slate-600 space-y-1">
                                    {selectedRevision.collateral_snapshot && selectedRevision.collateral_snapshot.length > 0 ? (
                                        selectedRevision.collateral_snapshot.map((col, idx) => (
                                            <div key={idx} className="border-b border-slate-200/50 pb-1 last:border-0 last:pb-0">
                                                <p className="font-medium text-slate-800">{col.certificate_number ?? '-'}</p>
                                                <p className="text-[11px] text-slate-500">a.n. {col.owner_name ?? '-'} ({col.surface_area ? col.surface_area + ' m²' : '-'})</p>
                                            </div>
                                        ))
                                    ) : (
                                        <p className="text-slate-400 italic">Tidak ada agunan terdaftar.</p>
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* Snapshot Dokumen Buyback jika ada */}
                        {selectedRevision.buyback_documents && selectedRevision.buyback_documents.length > 0 && (
                            <div className="space-y-1.5">
                                <h4 className="text-xs font-semibold text-slate-700 uppercase tracking-wide flex items-center gap-1">
                                    <FileCheck className="h-3.5 w-3.5 text-bsm-primary" />
                                    Snapshot Dokumen Legalitas Buyback ({selectedRevision.buyback_documents.length})
                                </h4>
                                <div className="rounded-md border border-slate-200 divide-y divide-slate-100 bg-slate-50 text-xs">
                                    {selectedRevision.buyback_documents.map((doc, idx) => (
                                        <div key={idx} className="p-2.5 flex items-center justify-between gap-2">
                                            <div className="min-w-0">
                                                <p className="font-medium text-slate-800 truncate">{doc.file_name}</p>
                                                <p className="text-[11px] text-slate-400 uppercase">{doc.document_type} &bull; {(doc.file_size / 1024).toFixed(0)} KB</p>
                                            </div>
                                            {doc.id && (
                                                <a
                                                    href={route('shm.memorandums.buyback.documents.download', [
                                                        memorandumId,
                                                        doc.id,
                                                    ])}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex items-center gap-1 text-[11px] text-indigo-600 hover:text-indigo-800 bg-white border border-slate-200 px-2 py-1 rounded font-medium hover:bg-slate-50 shrink-0"
                                                >
                                                    <Download className="h-3 w-3" />
                                                    Unduh
                                                </a>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Footer Modal */}
                        <div className="border-t border-slate-200 pt-4 flex justify-end">
                            <SecondaryButton onClick={handleCloseDetail} className="text-xs">
                                Tutup
                            </SecondaryButton>
                        </div>
                    </div>
                </Modal>
            )}
        </section>
    );
}
