import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { formatRupiah, formatDate, formatDateTime } from '@/utils/format';
import {
    ArrowLeft,
    CheckCircle2,
    XCircle,
    RotateCcw,
    AlertCircle,
    Clock,
    Wallet,
    Building2,
    Receipt,
    User,
    Calendar,
    FileText,
} from 'lucide-react';

export default function Show({ refund, summary }) {
    const [actionModal, setActionModal] = useState(null); // 'approve', 'reject', 'cancel', 'payout', 'reverse', 'confirm_recovery'
    const [reason, setReason] = useState('');
    const [payoutRef, setPayoutRef] = useState('');
    const [payoutNotes, setPayoutNotes] = useState('');
    const [recipientName, setRecipientName] = useState(refund.customer?.name || '');
    const [evidenceFile, setEvidenceFile] = useState(null);
    const [recoveryAmount, setRecoveryAmount] = useState(refund.amount || '');
    const [recoveryRef, setRecoveryRef] = useState('');
    const [recoveryMethod, setRecoveryMethod] = useState('cash');
    const [recoveryNotes, setRecoveryNotes] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);

    const handleAction = (e) => {
        e.preventDefault();
        setIsSubmitting(true);

        let endpoint = '';
        const formData = new FormData();

        switch (actionModal) {
            case 'approve':
                endpoint = route('shm.loans.refunds.approve', refund.id);
                break;
            case 'reject':
                endpoint = route('shm.loans.refunds.reject', refund.id);
                formData.append('reason', reason);
                break;
            case 'cancel':
                endpoint = route('shm.loans.refunds.cancel', refund.id);
                formData.append('reason', reason);
                break;
            case 'payout':
                endpoint = route('shm.loans.refunds.payout', refund.id);
                formData.append('payout_reference', payoutRef);
                formData.append('payout_notes', payoutNotes);
                if (recipientName) formData.append('recipient_name', recipientName);
                if (evidenceFile) formData.append('evidence', evidenceFile);
                break;
            case 'reverse':
                endpoint = route('shm.loans.refunds.reverse', refund.id);
                formData.append('reason', reason);
                break;
            case 'confirm_recovery':
                endpoint = route('shm.loans.refunds.confirm_recovery', refund.id);
                formData.append('amount', recoveryAmount);
                formData.append('recovery_reference', recoveryRef);
                formData.append('recovery_method', recoveryMethod);
                formData.append('recovery_notes', recoveryNotes);
                if (evidenceFile) formData.append('evidence', evidenceFile);
                break;
            default:
                setIsSubmitting(false);
                return;
        }

        router.post(endpoint, formData, {
            preserveScroll: true,
            onSuccess: () => {
                setActionModal(null);
                setReason('');
                setPayoutRef('');
                setPayoutNotes('');
                setEvidenceFile(null);
                setRecoveryRef('');
                setRecoveryNotes('');
                setIsSubmitting(false);
            },
            onError: () => {
                setIsSubmitting(false);
            },
        });
    };

    const statusBadge = (st) => {
        switch (st) {
            case 'pending':
                return <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700 border border-amber-200"><Clock className="w-3.5 h-3.5" /> Menunggu Approval</span>;
            case 'approved':
                return <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700 border border-blue-200"><CheckCircle2 className="w-3.5 h-3.5" /> Disetujui (Menunggu Pembayaran)</span>;
            case 'paid':
                return <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700 border border-emerald-200"><CheckCircle2 className="w-3.5 h-3.5" /> Selesai Dibayarkan</span>;
            case 'recovery_pending':
                return <span className="inline-flex items-center gap-1 rounded-full bg-purple-50 px-3 py-1 text-xs font-medium text-purple-700 border border-purple-200"><RotateCcw className="w-3.5 h-3.5" /> Reversal (Menunggu Pemulihan Dana)</span>;
            case 'recovered':
                return <span className="inline-flex items-center gap-1 rounded-full bg-teal-50 px-3 py-1 text-xs font-medium text-teal-700 border border-teal-200"><CheckCircle2 className="w-3.5 h-3.5" /> Dana Riil Dipulihkan</span>;
            case 'rejected':
                return <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-3 py-1 text-xs font-medium text-red-700 border border-red-200"><XCircle className="w-3.5 h-3.5" /> Ditolak</span>;
            case 'cancelled':
                return <span className="inline-flex items-center gap-1 rounded-full bg-slate-50 px-3 py-1 text-xs font-medium text-slate-700 border border-slate-200"><AlertCircle className="w-3.5 h-3.5" /> Dibatalkan</span>;
            case 'reversed':
                return <span className="inline-flex items-center gap-1 rounded-full bg-purple-50 px-3 py-1 text-xs font-medium text-purple-700 border border-purple-200"><RotateCcw className="w-3.5 h-3.5" /> Dibalikkan (Reversed)</span>;
            default:
                return <span className="inline-flex items-center rounded-full bg-gray-50 px-3 py-1 text-xs font-medium text-gray-700">{st}</span>;
        }
    };

    return (
        <ShmLayout title={`Detail Refund ${refund.refund_reference}`}>
            <Head title={`Refund ${refund.refund_reference}`} />

            <div className="space-y-6 max-w-5xl mx-auto">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Link
                            href={route('shm.loans.refunds.index')}
                            className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 shadow-sm"
                        >
                            <ArrowLeft className="h-4 w-4" />
                        </Link>
                        <div>
                            <div className="flex items-center gap-3">
                                <h1 className="text-xl font-bold tracking-tight text-slate-900 font-mono">
                                    {refund.refund_reference}
                                </h1>
                                {statusBadge(refund.status)}
                            </div>
                            <p className="text-xs text-slate-500 mt-0.5">
                                Diajukan pada {formatDateTime(refund.requested_at)} oleh {refund.requester?.name}
                            </p>
                        </div>
                    </div>

                    {/* Tombol Aksi Workflow */}
                    <div className="flex items-center gap-2">
                        {refund.status === 'pending' && (
                            <>
                                <button
                                    onClick={() => setActionModal('approve')}
                                    className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-1.5 text-xs font-medium text-white hover:bg-emerald-700 shadow-sm"
                                >
                                    <CheckCircle2 className="w-3.5 h-3.5" /> Setujui Refund
                                </button>
                                <button
                                    onClick={() => setActionModal('reject')}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-white px-3.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50"
                                >
                                    <XCircle className="w-3.5 h-3.5" /> Tolak
                                </button>
                                <button
                                    onClick={() => setActionModal('cancel')}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50"
                                >
                                    Batalkan
                                </button>
                            </>
                        )}

                        {refund.status === 'approved' && (
                            <>
                                <button
                                    onClick={() => setActionModal('payout')}
                                    className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3.5 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 shadow-sm"
                                >
                                    <Receipt className="w-3.5 h-3.5" /> Konfirmasi Pembayaran Kas/Bank
                                </button>
                                <button
                                    onClick={() => setActionModal('cancel')}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50"
                                >
                                    Batalkan
                                </button>
                            </>
                        )}

                        {refund.status === 'paid' && (
                            <button
                                onClick={() => setActionModal('reverse')}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-purple-200 bg-white px-3.5 py-1.5 text-xs font-medium text-purple-600 hover:bg-purple-50"
                            >
                                <RotateCcw className="w-3.5 h-3.5" /> Ajukan Reversal
                            </button>
                        )}

                        {refund.status === 'recovery_pending' && (
                            <button
                                onClick={() => setActionModal('confirm_recovery')}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-teal-600 px-3.5 py-1.5 text-xs font-medium text-white hover:bg-teal-700 shadow-sm"
                            >
                                <CheckCircle2 className="w-3.5 h-3.5" /> Konfirmasi Penerimaan Dana Kembali
                            </button>
                        )}
                    </div>
                </div>

                {/* Ringkasan Finansial Settlement */}
                {summary && (
                    <div className="grid grid-cols-1 sm:grid-cols-4 gap-4">
                        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div className="text-xs text-slate-500 font-medium">Total Overpayment Asal</div>
                            <div className="text-lg font-bold text-slate-900 mt-1">{formatRupiah(summary.overpayment_amount)}</div>
                            <div className="text-[11px] text-slate-400 mt-1">Dicatat di akun 2-1302</div>
                        </div>
                        <div className="rounded-xl border border-amber-100 bg-amber-50/50 p-4 shadow-sm">
                            <div className="text-xs text-amber-700 font-medium">Dalam Proses (Reservasi)</div>
                            <div className="text-lg font-bold text-amber-900 mt-1">
                                {formatRupiah(parseFloat(summary.pending_reservations) + parseFloat(summary.approved_reservations))}
                            </div>
                            <div className="text-[11px] text-amber-600 mt-1">Pending + Approved</div>
                        </div>
                        <div className="rounded-xl border border-emerald-100 bg-emerald-50/50 p-4 shadow-sm">
                            <div className="text-xs text-emerald-700 font-medium">Total Sudah Dibayar</div>
                            <div className="text-lg font-bold text-emerald-900 mt-1">{formatRupiah(summary.paid_amount)}</div>
                            <div className="text-[11px] text-emerald-600 mt-1">Pengembalian final (Paid)</div>
                        </div>
                        <div className="rounded-xl border border-indigo-100 bg-indigo-50/50 p-4 shadow-sm">
                            <div className="text-xs text-indigo-700 font-medium">Sisa Refundable</div>
                            <div className="text-lg font-bold text-indigo-900 mt-1">{formatRupiah(summary.remaining_refundable)}</div>
                            <div className="text-[11px] text-indigo-600 mt-1">Saldo yang masih dapat diminta</div>
                        </div>
                    </div>
                )}

                {/* Detail Informasi Refund */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {/* Kartu Informasi Permohonan */}
                    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
                        <h2 className="text-sm font-bold text-slate-900 border-b border-slate-100 pb-2 flex items-center gap-2">
                            <Wallet className="w-4 h-4 text-indigo-600" /> Rincian Pengajuan Refund
                        </h2>
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                            <div>
                                <dt className="text-xs text-slate-500">Nominal Refund</dt>
                                <dd className="text-base font-bold text-slate-900 mt-0.5">{formatRupiah(refund.amount)}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-slate-500">Metode Pengeluaran</dt>
                                <dd className="font-medium text-slate-800 capitalize mt-0.5">
                                    {refund.payout_method === 'bank_transfer' ? 'Transfer Bank' : 'Kasir Tunai'}
                                </dd>
                            </div>
                            {refund.payout_method === 'bank_transfer' && refund.payout_details && (
                                <div className="col-span-2 rounded-lg bg-slate-50 p-3 border border-slate-100 space-y-1">
                                    <div className="text-xs text-slate-500 font-medium">Rekening Tujuan:</div>
                                    <div className="text-sm font-semibold text-slate-800">{refund.payout_details.bank_name}</div>
                                    <div className="text-sm font-mono text-slate-900">{refund.payout_details.account_number}</div>
                                    <div className="text-xs text-slate-600">a.n. {refund.payout_details.account_holder}</div>
                                </div>
                            )}
                            <div className="col-span-2">
                                <dt className="text-xs text-slate-500">Alasan Permohonan</dt>
                                <dd className="text-slate-700 mt-0.5">{refund.reason || '-'}</dd>
                            </div>
                        </dl>
                    </div>

                    {/* Kartu Referensi Settlement & Nasabah */}
                    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
                        <h2 className="text-sm font-bold text-slate-900 border-b border-slate-100 pb-2 flex items-center gap-2">
                            <Building2 className="w-4 h-4 text-indigo-600" /> Referensi Sumber Settlement
                        </h2>
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                            <div>
                                <dt className="text-xs text-slate-500">Nasabah</dt>
                                <dd className="font-semibold text-slate-900 mt-0.5">{refund.customer?.name}</dd>
                                <dd className="text-xs text-slate-500 font-mono">{refund.customer?.customer_number}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-slate-500">No. Pinjaman</dt>
                                <dd className="font-semibold text-slate-900 font-mono mt-0.5">{refund.loan?.loan_number}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-slate-500">No. Ref. Settlement</dt>
                                <dd className="font-semibold text-slate-900 font-mono mt-0.5">{refund.settlement?.settlement_reference}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-slate-500">Tgl Pelunasan</dt>
                                <dd className="text-slate-800 mt-0.5">{formatDate(refund.settlement?.settlement_date)}</dd>
                            </div>
                            <div className="col-span-2 pt-2 border-t border-slate-100 flex justify-end">
                                <Link
                                    href={route('shm.loans.pelunasan.show', refund.shm_loan_id)}
                                    className="text-xs font-medium text-indigo-600 hover:text-indigo-700"
                                >
                                    Buka Halaman Settlement Pinjaman →
                                </Link>
                            </div>
                        </dl>
                    </div>
                </div>

                {/* Status Pengakuan Akuntansi & Riwayat Transaksi Recovery */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {/* Status Akuntansi (Linkage & Idempotency) */}
                    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-3">
                        <h2 className="text-sm font-bold text-slate-900 border-b border-slate-100 pb-2 flex items-center gap-2">
                            <Receipt className="w-4 h-4 text-emerald-600" /> Status Pengakuan Akuntansi SHM
                        </h2>
                        {refund.accounting_links && refund.accounting_links.length > 0 ? (
                            <div className="space-y-2">
                                {refund.accounting_links.map((link) => (
                                    <div key={link.id} className="rounded-lg border border-slate-100 bg-slate-50/50 p-3 text-xs space-y-1">
                                        <div className="flex items-center justify-between">
                                            <span className="font-semibold text-slate-800">
                                                Jurnal #{link.journal_entry?.journal_number || link.journal_entry_id}
                                            </span>
                                            <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold ${link.status === 'POSTED' ? 'bg-emerald-100 text-emerald-800' : link.status === 'REVERSED' ? 'bg-purple-100 text-purple-800' : 'bg-amber-100 text-amber-800'}`}>
                                                {link.status}
                                            </span>
                                        </div>
                                        <div className="text-slate-500">
                                            Kanal: <span className="font-mono text-slate-700 capitalize">{link.recognition_channel.replace('_', ' ')}</span>
                                            {link.cash_transaction && ` (${link.cash_transaction.transaction_number})`}
                                        </div>
                                        <div className="text-[11px] text-slate-400">
                                            Ditautkan oleh {link.linker?.name} pada {formatDateTime(link.linked_at)}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="rounded-lg bg-amber-50/50 border border-amber-200/60 p-3 text-xs text-amber-800 space-y-1">
                                <div className="font-semibold">Belum Diakui dalam Akuntansi (Unrecognized)</div>
                                <div className="text-[11px] text-amber-700">
                                    Sesuai Option A, operasional refund tidak membuat auto-jurnal. Pengakuan dilakukan manual melalui Arus Kas Keluar atau XLSX Import Center dengan mencantumkan referensi refund ini.
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Riwayat Parsial Pengembalian Dana Riil (Recoveries) */}
                    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-3">
                        <h2 className="text-sm font-bold text-slate-900 border-b border-slate-100 pb-2 flex items-center gap-2">
                            <RotateCcw className="w-4 h-4 text-indigo-600" /> Riwayat Transaksi Pemulihan Dana
                        </h2>
                        {refund.recoveries && refund.recoveries.length > 0 ? (
                            <div className="space-y-2">
                                {refund.recoveries.map((rec) => (
                                    <div key={rec.id} className="rounded-lg border border-slate-100 bg-slate-50/50 p-3 text-xs space-y-1">
                                        <div className="flex items-center justify-between">
                                            <span className="font-bold text-slate-900 font-mono">{rec.recovery_reference}</span>
                                            <span className="font-bold text-emerald-700">{formatRupiah(rec.amount)}</span>
                                        </div>
                                        <div className="flex items-center justify-between text-slate-500 text-[11px]">
                                            <span>Metode: {rec.recovery_method === 'cash' ? 'Kas Tunai' : 'Transfer Bank'}</span>
                                            <span className="font-mono text-slate-700">{rec.recovery_reference_external || '-'}</span>
                                        </div>
                                        <div className="text-[11px] text-slate-400">
                                            Dikonfirmasi oleh {rec.confirmer?.name || rec.requester?.name} pada {formatDateTime(rec.confirmed_at || rec.requested_at)}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="rounded-lg bg-slate-50 p-3 text-xs text-slate-500">
                                Belum ada transaksi pemulihan dana (recovery) yang dicatat.
                            </div>
                        )}
                    </div>
                </div>

                {/* Audit & Eksekusi Pembayaran */}
                {(refund.paid_at || refund.rejected_at || refund.cancelled_at || refund.reversed_at) && (
                    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-3">
                        <h2 className="text-sm font-bold text-slate-900 border-b border-slate-100 pb-2 flex items-center gap-2">
                            <Receipt className="w-4 h-4 text-indigo-600" /> Catatan Bukti & Eksekusi
                        </h2>
                        {refund.paid_at && (
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm bg-emerald-50/40 p-3 rounded-lg border border-emerald-100">
                                <div>
                                    <div className="text-xs text-emerald-800 font-medium">Waktu Pembayaran</div>
                                    <div className="text-emerald-950 font-semibold mt-0.5">{formatDateTime(refund.paid_at)}</div>
                                    <div className="text-xs text-emerald-700">Oleh: {refund.payer?.name}</div>
                                </div>
                                <div>
                                    <div className="text-xs text-emerald-800 font-medium">Ref. Bukti Pembayaran</div>
                                    <div className="text-emerald-950 font-mono font-semibold mt-0.5">{refund.payout_reference || '-'}</div>
                                </div>
                                <div>
                                    <div className="text-xs text-emerald-800 font-medium">Catatan Payout</div>
                                    <div className="text-emerald-950 mt-0.5">{refund.payout_notes || '-'}</div>
                                </div>
                            </div>
                        )}
                        {refund.rejected_at && (
                            <div className="p-3 bg-red-50 text-xs text-red-800 rounded-lg border border-red-100">
                                <span className="font-semibold">Ditolak pada {formatDateTime(refund.rejected_at)} oleh {refund.rejecter?.name}:</span> {refund.rejection_reason}
                            </div>
                        )}
                        {refund.cancelled_at && (
                            <div className="p-3 bg-slate-50 text-xs text-slate-800 rounded-lg border border-slate-200">
                                <span className="font-semibold">Dibatalkan pada {formatDateTime(refund.cancelled_at)} oleh {refund.canceller?.name}:</span> {refund.cancellation_reason}
                            </div>
                        )}
                        {refund.reversed_at && (
                            <div className="p-3 bg-purple-50 text-xs text-purple-800 rounded-lg border border-purple-200">
                                <span className="font-semibold">Dibalikkan (Reversal) pada {formatDateTime(refund.reversed_at)} oleh {refund.reverser?.name}:</span> {refund.reversal_reason}
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* Modal Dialog Tindakan */}
            {actionModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl space-y-4">
                        <h3 className="text-base font-bold text-slate-900 capitalize">
                            {actionModal === 'approve' && 'Setujui Permohonan Refund'}
                            {actionModal === 'reject' && 'Tolak Permohonan Refund'}
                            {actionModal === 'cancel' && 'Batalkan Permohonan Refund'}
                            {actionModal === 'payout' && 'Konfirmasi Pembayaran Refund'}
                            {actionModal === 'reverse' && 'Ajukan Reversal Pembayaran Refund'}
                            {actionModal === 'confirm_recovery' && 'Konfirmasi Penerimaan Kembali Dana (Recovery)'}
                        </h3>

                        <form onSubmit={handleAction} className="space-y-4">
                            {actionModal === 'approve' && (
                                <p className="text-sm text-slate-600">
                                    Apakah Anda yakin ingin menyetujui refund sebesar{' '}
                                    <strong className="text-slate-900">{formatRupiah(refund.amount)}</strong> untuk{' '}
                                    <strong>{refund.customer?.name}</strong>?
                                </p>
                            )}

                            {(actionModal === 'reject' || actionModal === 'cancel' || actionModal === 'reverse') && (
                                <div>
                                    <label className="block text-xs font-medium text-slate-700">
                                        Alasan {actionModal === 'reject' ? 'Penolakan' : actionModal === 'cancel' ? 'Pembatalan' : 'Reversal'} <span className="text-red-500">*</span>
                                    </label>
                                    <textarea
                                        value={reason}
                                        onChange={(e) => setReason(e.target.value)}
                                        rows={3}
                                        required
                                        placeholder="Tuliskan alasan lengkap..."
                                        className="mt-1 w-full rounded-lg border border-slate-200 p-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                    />
                                    {actionModal === 'reverse' && (
                                        <p className="mt-1 text-[11px] text-amber-600">
                                            Catatan: Pengajuan reversal akan memindahkan status ke "recovery_pending". Saldo overpayment belum dipulihkan sampai penerimaan dana riil dikonfirmasi.
                                        </p>
                                    )}
                                </div>
                            )}

                            {actionModal === 'payout' && (
                                <div className="space-y-3">
                                    {refund.payout_method === 'cash' ? (
                                        <div>
                                            <label className="block text-xs font-medium text-slate-700">
                                                Nama Penerima Kas Tunai <span className="text-red-500">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                value={recipientName}
                                                onChange={(e) => setRecipientName(e.target.value)}
                                                required
                                                placeholder="Nama lengkap penerima..."
                                                className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                            />
                                        </div>
                                    ) : (
                                        <div>
                                            <label className="block text-xs font-medium text-slate-700">
                                                Nomor Referensi Transfer Bank <span className="text-red-500">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                value={payoutRef}
                                                onChange={(e) => setPayoutRef(e.target.value)}
                                                required
                                                placeholder="Contoh: TRF-BCA-889102"
                                                className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                            />
                                        </div>
                                    )}
                                    <div>
                                        <label className="block text-xs font-medium text-slate-700">
                                            Upload Bukti Fisik / Resi Transfer <span className="text-red-500">*</span>
                                        </label>
                                        <input
                                            type="file"
                                            required
                                            accept=".pdf,.jpg,.jpeg,.png"
                                            onChange={(e) => setEvidenceFile(e.target.files[0])}
                                            className="mt-1 w-full text-xs text-slate-500 file:mr-2 file:py-1 file:px-3 file:rounded file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-medium text-slate-700">Catatan Tambahan (Opsional)</label>
                                        <textarea
                                            value={payoutNotes}
                                            onChange={(e) => setPayoutNotes(e.target.value)}
                                            rows={2}
                                            placeholder="Catatan kasir..."
                                            className="mt-1 w-full rounded-lg border border-slate-200 p-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        />
                                    </div>
                                </div>
                            )}

                            {actionModal === 'confirm_recovery' && (
                                <div className="space-y-3">
                                    <div>
                                        <label className="block text-xs font-medium text-slate-700">
                                            Nominal Dana Kembali <span className="text-red-500">*</span>
                                        </label>
                                        <input
                                            type="number"
                                            value={recoveryAmount}
                                            onChange={(e) => setRecoveryAmount(e.target.value)}
                                            required
                                            max={refund.amount}
                                            className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-medium text-slate-700">
                                            No. Referensi Bukti Penerimaan / BKM <span className="text-red-500">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            value={recoveryRef}
                                            onChange={(e) => setRecoveryRef(e.target.value)}
                                            required
                                            placeholder="Contoh: BKM-REC-001"
                                            className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-medium text-slate-700">
                                            Metode Penerimaan <span className="text-red-500">*</span>
                                        </label>
                                        <select
                                            value={recoveryMethod}
                                            onChange={(e) => setRecoveryMethod(e.target.value)}
                                            className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"
                                        >
                                            <option value="cash">Kas Tunai</option>
                                            <option value="bank_transfer">Transfer Bank</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-medium text-slate-700">
                                            Upload Bukti Setoran / Tanda Terima <span className="text-red-500">*</span>
                                        </label>
                                        <input
                                            type="file"
                                            required
                                            accept=".pdf,.jpg,.jpeg,.png"
                                            onChange={(e) => setEvidenceFile(e.target.files[0])}
                                            className="mt-1 w-full text-xs text-slate-500 file:mr-2 file:py-1 file:px-3 file:rounded file:border-0 file:text-xs file:font-semibold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100"
                                        />
                                    </div>
                                </div>
                            )}

                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setActionModal(null)}
                                    disabled={isSubmitting}
                                    className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-medium text-slate-600 hover:bg-slate-50"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    disabled={isSubmitting}
                                    className="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    {isSubmitting ? 'Memproses...' : 'Konfirmasi'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </ShmLayout>
    );
}

