import React from 'react';
import { formatDateTime } from '@/utils/format';
import {
    CheckCircle2,
    Clock,
    UserCheck,
    AlertTriangle,
    ShieldCheck,
} from 'lucide-react';

export default function MemorandumApprovalCard({ memorandum }) {
    return (
        <section className="rounded-lg bg-white p-5 shadow-sm border border-slate-200">
            <h2 className="text-sm font-semibold uppercase tracking-wider text-slate-600 mb-3 flex items-center gap-1.5">
                <ShieldCheck className="h-4 w-4 text-bsm-primary" />
                Informasi Approval Memorandum
            </h2>

            {/* Banner Penolakan jika pernah ditolak dan kini berstatus Draft */}
            {memorandum.has_been_rejected && memorandum.status === 'draft' && (
                <div className="mb-4 rounded-md border border-rose-200 bg-rose-50 p-3.5 text-xs text-rose-800 space-y-1">
                    <div className="flex items-center gap-1.5 font-semibold text-rose-900">
                        <AlertTriangle className="h-4 w-4 text-rose-600 shrink-0" />
                        Memorandum Pernah Ditolak (Perlu Perbaikan)
                    </div>
                    {memorandum.rejected_at && (
                        <div className="text-[11px] text-rose-700">
                            Waktu penolakan: {formatDateTime(memorandum.rejected_at)}
                        </div>
                    )}
                    {memorandum.rejection_notes && (
                        <div className="mt-1 rounded bg-white/70 border border-rose-200/60 p-2 text-rose-900 italic">
                            Catatan penolakan: "{memorandum.rejection_notes}"
                        </div>
                    )}
                </div>
            )}

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 rounded-md border border-slate-100 bg-slate-50 p-4 text-xs">
                {/* 1. Pengaju */}
                <div>
                    <span className="text-slate-400 block mb-1">Diajukan Oleh</span>
                    <span className="font-medium text-slate-800 block text-xs">
                        {memorandum.submitted_by_name ?? (
                            memorandum.submitted_at ? (memorandum.creator ?? 'Pengaju') : <span className="text-slate-400 italic">Belum Diajukan</span>
                        )}
                    </span>
                    {memorandum.submitted_at && (
                        <span className="text-[11px] text-slate-500 font-mono">
                            {formatDateTime(memorandum.submitted_at)}
                        </span>
                    )}
                </div>

                {/* 2. Reviewer */}
                <div>
                    <span className="text-slate-400 block mb-1">Pemeriksa (Reviewer)</span>
                    <span className="font-medium text-slate-800 block text-xs">
                        {memorandum.reviewed_by_name ?? <span className="text-slate-400 italic">Belum Direview</span>}
                    </span>
                    {memorandum.reviewed_at && (
                        <span className="text-[11px] text-slate-500 font-mono">
                            {formatDateTime(memorandum.reviewed_at)}
                        </span>
                    )}
                </div>

                {/* 3. Approver */}
                <div>
                    <span className="text-slate-400 block mb-1">Penyetujui (Approver)</span>
                    <span className="font-medium text-slate-800 block text-xs">
                        {memorandum.approved_by_name ?? <span className="text-slate-400 italic">Belum Disetujui</span>}
                    </span>
                    {memorandum.approved_at && (
                        <span className="text-[11px] text-slate-500 font-mono">
                            {formatDateTime(memorandum.approved_at)}
                        </span>
                    )}
                </div>
            </div>
        </section>
    );
}

