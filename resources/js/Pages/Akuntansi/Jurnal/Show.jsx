import React, { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { ScrollArea, ScrollBar } from '@/Components/ui/scroll-area';
import { formatRupiah } from '@/utils/format';
import { ArrowLeft, CheckCircle2, AlertCircle, Undo2, X } from 'lucide-react';

const STATUS_STYLES = {
    DRAFT: 'bg-slate-100 text-slate-600 border-slate-200',
    POSTED: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    REVERSED: 'bg-rose-50 text-rose-700 border-rose-200',
};

export default function Show({ entry }) {
    const { auth } = usePage().props;
    const permissions = auth?.permissions ?? [];

    const [showReverseModal, setShowReverseModal] = useState(false);
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);

    const canPost = permissions.includes('accounting.journals.post');
    const canReverse = permissions.includes('accounting.journals.reverse');

    const doPost = () => {
        setProcessing(true);
        router.post(route('akuntansi.jurnal.post', entry.id), {}, { onFinish: () => setProcessing(false) });
    };

    const doReverse = () => {
        setProcessing(true);
        router.post(route('akuntansi.jurnal.reverse', entry.id), { reason }, {
            onFinish: () => {
                setProcessing(false);
                setShowReverseModal(false);
                setReason('');
            },
        });
    };

    return (
        <AdminLayout title={`Jurnal ${entry.journal_number}`} pageTitle="Akuntansi — Jurnal Umum">
            <div className="mb-6">
                <Link href={route('akuntansi.jurnal.index')} className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 hover:text-slate-900">
                    <ArrowLeft className="h-4 w-4" /> Kembali ke Daftar Jurnal
                </Link>
            </div>

            <div className="mb-6 rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-3">
                            <h2 className="font-mono text-lg font-bold text-slate-900">{entry.journal_number}</h2>
                            <span className={`rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${STATUS_STYLES[entry.status] ?? ''}`}>
                                {entry.status}
                            </span>
                        </div>
                        <p className="mt-1 text-xs sm:text-sm text-slate-500">{entry.description ?? 'Tanpa deskripsi'}</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {entry.status === 'DRAFT' && canPost && (
                            <button
                                type="button"
                                onClick={doPost}
                                disabled={processing || !entry.is_balanced}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-2 text-xs sm:text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                                title={!entry.is_balanced ? 'Jurnal belum seimbang' : undefined}
                            >
                                <CheckCircle2 className="h-4 w-4" /> Posting
                            </button>
                        )}
                        {entry.status === 'POSTED' && canReverse && !entry.reversed_by_entry_id && (
                            <button
                                type="button"
                                onClick={() => setShowReverseModal(true)}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-rose-50 px-3.5 py-2 text-xs sm:text-sm font-semibold text-rose-700 hover:bg-rose-100"
                            >
                                <Undo2 className="h-4 w-4" /> Reversal
                            </button>
                        )}
                        {entry.status === 'REVERSED' && (
                            <span className="text-xs text-slate-500">Dibatalkan oleh jurnal {entry.reversed_by_number ?? entry.reversed_by_entry_id}</span>
                        )}
                    </div>
                </div>

                <dl className="mt-4 grid grid-cols-1 gap-x-6 gap-y-2 text-xs sm:grid-cols-4">
                    <div><dt className="text-slate-400">Tanggal Transaksi</dt><dd className="font-semibold text-slate-900">{entry.transaction_date}</dd></div>
                    <div><dt className="text-slate-400">Dibuat Oleh</dt><dd className="font-semibold text-slate-900">{entry.created_by ?? '-'}</dd></div>
                    <div><dt className="text-slate-400">Diposting Oleh</dt><dd className="font-semibold text-slate-900">{entry.posted_by ? `${entry.posted_by}` : '-'}</dd></div>
                    <div><dt className="text-slate-400">Sumber</dt><dd className="font-semibold text-slate-900">{entry.source_type ? `${entry.source_type.split('\\').pop()}#${entry.source_id}` : 'manual'}</dd></div>
                </dl>
            </div>

            <ScrollArea className="rounded-xl border border-slate-200 bg-white shadow-xs">
<ScrollBar orientation="horizontal" />
                <table className="min-w-full divide-y divide-slate-200 text-xs sm:text-sm">
                    <thead className="bg-slate-50">
                        <tr>
                            <th className="px-4 py-3 text-left font-semibold text-slate-500">#</th>
                            <th className="px-4 py-3 text-left font-semibold text-slate-500">Akun</th>
                            <th className="px-4 py-3 text-left font-semibold text-slate-500">Deskripsi</th>
                            <th className="px-4 py-3 text-right font-semibold text-slate-500">Debit</th>
                            <th className="px-4 py-3 text-right font-semibold text-slate-500">Kredit</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {entry.lines.map((line) => (
                            <tr key={line.id}>
                                <td className="px-4 py-3 text-slate-500">{line.line_order}</td>
                                <td className="px-4 py-3 font-mono text-slate-900">{line.account_code} — {line.account_name}</td>
                                <td className="px-4 py-3 text-slate-600">{line.description ?? '-'}</td>
                                <td className="px-4 py-3 text-right text-slate-900">{line.debit > 0 ? formatRupiah(line.debit) : '-'}</td>
                                <td className="px-4 py-3 text-right text-slate-900">{line.credit > 0 ? formatRupiah(line.credit) : '-'}</td>
                            </tr>
                        ))}
                        <tr className="bg-slate-50 font-semibold">
                            <td colSpan={3} className="px-4 py-3 text-right text-slate-700">Total</td>
                            <td className="px-4 py-3 text-right text-slate-900">{formatRupiah(entry.total_debit)}</td>
                            <td className="px-4 py-3 text-right text-slate-900">{formatRupiah(entry.total_credit)}</td>
                        </tr>
                    </tbody>
                </table>
            </ScrollArea>

            <div className={`mt-4 inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-xs font-semibold ${entry.is_balanced ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700'}`}>
                {entry.is_balanced ? <CheckCircle2 className="h-4 w-4" /> : <AlertCircle className="h-4 w-4" />}
                {entry.is_balanced ? 'Jurnal seimbang (debit = kredit).' : 'Jurnal TIDAK seimbang — posting ditolak sampai seimbang.'}
            </div>

            {showReverseModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
                    <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-xs" onClick={() => !processing && setShowReverseModal(false)} />
                    <div className="relative w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-xl">
                        <button type="button" onClick={() => setShowReverseModal(false)} className="absolute top-4 right-4 rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                            <X className="h-5 w-5" />
                        </button>
                        <h3 className="text-base font-semibold text-slate-900">Reversal Jurnal</h3>
                        <p className="mt-1 text-xs sm:text-sm text-slate-500">
                            Jurnal pembalik akan dibuat untuk <span className="font-mono font-semibold">{entry.journal_number}</span>.
                            Jurnal asal tidak diubah dan ditandai REVERSED.
                        </p>
                        <label className="mt-4 block text-xs font-semibold text-slate-600" htmlFor="reverse-reason">Alasan reversal (wajib) *</label>
                        <textarea
                            id="reverse-reason"
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            rows={3}
                            maxLength={500}
                            className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                            placeholder="Minimal 5 karakter"
                            required
                        />
                        <div className="mt-5 flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                            <button type="button" onClick={() => setShowReverseModal(false)} className="rounded-lg border border-slate-300 px-4 py-2 text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                Batal
                            </button>
                            <button
                                type="button"
                                onClick={doReverse}
                                disabled={processing || reason.trim().length < 5}
                                className="rounded-lg bg-rose-600 px-4 py-2 text-xs sm:text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-50"
                            >
                                {processing ? 'Memproses...' : 'Ya, Reversal'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}
