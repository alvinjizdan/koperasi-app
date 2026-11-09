import React from 'react';
import { formatDateTime } from '@/utils/format';
import {
    History,
    Send,
    Play,
    CheckCircle2,
    XCircle,
    RotateCcw,
    FileText,
} from 'lucide-react';

const getActionIcon = (action) => {
    switch (action) {
        case 'submitted':
            return <Send className="h-3.5 w-3.5 text-indigo-600" />;
        case 'resubmitted':
            return <RotateCcw className="h-3.5 w-3.5 text-indigo-600" />;
        case 'review_started':
            return <Play className="h-3.5 w-3.5 text-amber-600" />;
        case 'approved':
            return <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" />;
        case 'rejected':
            return <XCircle className="h-3.5 w-3.5 text-rose-600" />;
        default:
            return <FileText className="h-3.5 w-3.5 text-slate-500" />;
    }
};

const getBadgeStyle = (action) => {
    switch (action) {
        case 'submitted':
        case 'resubmitted':
            return 'bg-indigo-50 text-indigo-700 border-indigo-200';
        case 'review_started':
            return 'bg-amber-50 text-amber-700 border-amber-200';
        case 'approved':
            return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        case 'rejected':
            return 'bg-rose-50 text-rose-700 border-rose-200';
        default:
            return 'bg-slate-50 text-slate-700 border-slate-200';
    }
};

export default function MemorandumApprovalTimeline({ histories = [] }) {
    if (!histories || histories.length === 0) {
        return (
            <div className="rounded-lg bg-white p-5 shadow-sm border border-slate-200 text-xs text-slate-400 italic">
                Belum ada riwayat aktivitas approval tercatat.
            </div>
        );
    }

    return (
        <section className="rounded-lg bg-white p-5 shadow-sm border border-slate-200">
            <h2 className="text-sm font-semibold uppercase tracking-wider text-slate-600 mb-4 flex items-center gap-1.5">
                <History className="h-4 w-4 text-bsm-primary" />
                Riwayat Workflow Approval (Audit Trail)
            </h2>

            <div className="relative pl-6 space-y-6 before:absolute before:bottom-2 before:top-2 before:left-2 before:w-0.5 before:bg-slate-200">
                {histories.map((item, idx) => (
                    <div key={item.id || idx} className="relative">
                        {/* Dot / Icon */}
                        <div className="absolute -left-[27px] top-0 flex h-6 w-6 items-center justify-center rounded-full bg-white border border-slate-200 shadow-sm">
                            {getActionIcon(item.action)}
                        </div>

                        {/* Content */}
                        <div className="rounded-lg border border-slate-100 bg-slate-50 p-3 text-xs space-y-1.5">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span
                                    className={`rounded-full border px-2 py-0.5 text-[11px] font-semibold uppercase ${getBadgeStyle(
                                        item.action
                                    )}`}
                                >
                                    {item.action_label}
                                </span>
                                <span className="text-[11px] text-slate-400 font-mono">
                                    {formatDateTime(item.created_at)}
                                </span>
                            </div>

                            <div className="text-slate-700">
                                Dilakukan oleh:{' '}
                                <strong className="font-semibold text-slate-900">
                                    {item.performed_by_name}
                                </strong>
                            </div>

                            {item.notes && (
                                <div className="mt-1.5 rounded border border-slate-200 bg-white p-2.5 text-slate-700 italic text-[11px] whitespace-pre-wrap leading-relaxed">
                                    "{item.notes}"
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

