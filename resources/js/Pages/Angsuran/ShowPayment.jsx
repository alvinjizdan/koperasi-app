import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Receipt, ChevronLeft, ShieldAlert } from 'lucide-react';
import { formatRupiah, formatDate, formatDateTime } from '@/utils/format';

const STATUS_BADGES = {
    COMPLETED: <span className="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">Selesai</span>,
    REVERSED: <span className="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-800">Dibatalkan (Reversal)</span>,
};

export default function ShowPayment({ payment }) {
    const isReversalEntry = payment.entry_type === 'reversal';
    const isReversedOriginal = payment.status === 'REVERSED' && !isReversalEntry;
    const canReverse = payment.status === 'COMPLETED' && !isReversalEntry;

    const { data, setData, post, processing, errors } = useForm({ reason: '' });

    const submitReversal = (e) => {
        e.preventDefault();
        if (!confirm('Pembayaran ini akan dibatalkan melalui reversal. Lanjutkan?')) {
            return;
        }
        post(route('angsuran.payment.reverse', payment.id));
    };

    return (
        <AdminLayout title={`Transaksi ${payment.payment_number}`} pageTitle="Detail Pembayaran Angsuran">
            <div className="max-w-2xl space-y-6">
                <Link
                    href={route('angsuran.schedule', payment.loan?.id)}
                    className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-800 transition-colors"
                >
                    <ChevronLeft className="h-4 w-4" />
                    Kembali ke jadwal angsuran
                </Link>

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                    {/* Header */}
                    <div className="border-b border-slate-100 px-6 py-4 bg-emerald-50">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                                <Receipt className="h-5 w-5" />
                            </div>
                            <div>
                                <h2 className="font-semibold text-slate-900">Pembayaran Angsuran</h2>
                                <p className="font-mono text-xs text-slate-500">{payment.payment_number}</p>
                            </div>
                            <div className="ml-auto">
                                {STATUS_BADGES[payment.status] ?? (
                                    <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-700">
                                        {payment.status}
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>

                    {isReversedOriginal && (
                        <div className="flex items-start gap-2 border-b border-red-100 bg-red-50 px-6 py-3">
                            <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
                            <p className="text-xs text-red-700">
                                Pembayaran ini telah dibatalkan melalui reversal dan tidak lagi dihitung dalam sisa kewajiban pinjaman.
                                {payment.reversals?.[0] && (
                                    <>
                                        {' '}
                                        Lihat transaksi penyesuaian:{' '}
                                        <Link href={route('angsuran.payment.show', payment.reversals[0].id)} className="font-mono underline">
                                            {payment.reversals[0].payment_number}
                                        </Link>
                                    </>
                                )}
                            </p>
                        </div>
                    )}

                    {isReversalEntry && payment.reversedOriginal && (
                        <div className="border-b border-amber-100 bg-amber-50 px-6 py-3">
                            <p className="text-xs text-amber-800">
                                Transaksi penyesuaian (reversal) untuk pembayaran{' '}
                                <Link href={route('angsuran.payment.show', payment.reversedOriginal.id)} className="font-mono font-semibold underline">
                                    {payment.reversedOriginal.payment_number}
                                </Link>
                                . Efek pembayaran asal telah dikembalikan ke jadwal angsuran.
                            </p>
                        </div>
                    )}

                    {/* Nominal */}
                    <div className="px-6 py-5 border-b border-slate-100">
                        <p className="text-xs text-slate-500 mb-1">Nominal Pembayaran</p>
                        <p className="text-3xl font-bold tabular-nums text-emerald-700">
                            {formatRupiah(payment.amount)}
                        </p>
                    </div>

                    {/* Details grid */}
                    <dl className="grid grid-cols-1 divide-y divide-slate-100 sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                        <div className="px-6 py-4">
                            <dt className="text-xs text-slate-500">Tanggal Pembayaran</dt>
                            <dd className="mt-0.5 text-sm font-medium text-slate-900">{formatDate(payment.payment_date)}</dd>
                        </div>
                        <div className="px-6 py-4">
                            <dt className="text-xs text-slate-500">Dicatat Pada</dt>
                            <dd className="mt-0.5 text-sm font-medium text-slate-900">{formatDateTime(payment.created_at)}</dd>
                        </div>
                        <div className="px-6 py-4 sm:col-span-2 border-t border-slate-100">
                            <dt className="text-xs text-slate-500">Pinjaman</dt>
                            <dd className="mt-0.5">
                                <Link
                                    href={route('pinjaman.show', payment.loan?.id)}
                                    className="font-mono text-sm font-medium text-slate-900 underline"
                                >
                                    {payment.loan?.loan_number}
                                </Link>
                                <p className="text-xs text-slate-500">{payment.loan?.member?.full_name}</p>
                            </dd>
                        </div>
                        <div className="px-6 py-4">
                            <dt className="text-xs text-slate-500">Operator</dt>
                            <dd className="mt-0.5 text-sm text-slate-900">{payment.operator?.name || '-'}</dd>
                        </div>
                        {payment.schedule && (
                            <div className="px-6 py-4">
                                <dt className="text-xs text-slate-500">Dialokasikan Ke</dt>
                                <dd className="mt-0.5 text-sm font-medium text-slate-900">Angsuran #{payment.schedule.installment_number}</dd>
                            </div>
                        )}
                        {payment.notes && (
                            <div className="px-6 py-4 sm:col-span-2 border-t border-slate-100">
                                <dt className="text-xs text-slate-500">Catatan</dt>
                                <dd className="mt-0.5 text-sm text-slate-900">{payment.notes}</dd>
                            </div>
                        )}
                    </dl>

                    {/* Reversal form — hanya pembayaran asal yang belum direversi */}
                    {canReverse && (
                        <form onSubmit={submitReversal} className="border-t border-slate-100 bg-slate-50 px-6 py-5 space-y-3">
                            <div>
                                <label htmlFor="reason" className="block text-xs font-semibold text-slate-700 mb-1">
                                    Reversal Pembayaran
                                </label>
                                <p className="text-xs text-slate-500 mb-2">
                                    Membatalkan efek pembayaran ini: kewajiban angsuran dikembalikan dan kas dikoreksi.
                                    Pembayaran asal tetap tercatat dan tidak dapat diubah.
                                </p>
                                <textarea
                                    id="reason"
                                    value={data.reason}
                                    onChange={(e) => setData('reason', e.target.value)}
                                    rows={2}
                                    placeholder="Alasan reversal (min. 5 karakter), mis. salah input nominal"
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-400"
                                />
                                {errors.reason && <p className="mt-1 text-xs text-red-600">{errors.reason}</p>}
                                {errors.payment && <p className="mt-1 text-xs text-red-600">{errors.payment}</p>}
                            </div>
                            <button
                                type="submit"
                                disabled={processing || data.reason.trim().length < 5}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                            >
                                <ShieldAlert className="h-4 w-4" />
                                Proses Reversal
                            </button>
                        </form>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
