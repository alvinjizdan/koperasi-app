import React, { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { formatRupiah, formatDate, formatDateTime } from '@/utils/format';
import {
    ArrowLeft,
    AlertTriangle,
    BadgeCheck,
    CheckCircle2,
    Clock,
    Link2,
    RotateCcw,
    ScrollText,
    ShieldCheck,
    Wallet,
    XCircle,
} from 'lucide-react';

/**
 * Pelunasan / Settlement Pinjaman SHM — Ticket 89B (Model B).
 *
 * Seluruh nilai finansial dihitung SERVER (ShmLoanSettlementService) dan
 * React TIDAK PERNAH menghitung pokok/bunga/discount/penalty/overpayment.
 *
 * Lifecycle yang ditampilkan & dijalankan dari halaman ini:
 *   pending → approved → payment_recorded → payment_verified → completed
 *   pending → rejected (pengajuan ulang = baris baru)
 *
 * - Penalty diisi MANUAL oleh user berwenang (tidak pernah auto-calc).
 * - Pembayaran pelunasan memakai Payment T86 yang sudah tercatat; status
 *   `posted` BUKAN verifikasi — verifikasi adalah aksi eksplisit terpisah.
 * - Overpayment (pembayaran > kewajiban) diposting ke Simpanan Pokok
 *   anggota sumber oleh server melalui relasi ShmCustomer → Member;
 *   completion ditolak deterministik bila hubungan member tidak tersedia,
 *   tidak ditemukan, atau anggota nonaktif (T89E).
 */
function Field({ label, children }) {
    return (
        <div>
            <dt className="text-xs text-gray-500">{label}</dt>
            <dd className="font-medium">{children ?? '-'}</dd>
        </div>
    );
}

function MoneyRow({ label, value, muted, highlight, negative, note }) {
    return (
        <div className="flex items-start justify-between gap-4 border-b border-slate-100 py-2 last:border-0">
            <div>
                <div
                    className={`text-sm ${muted ? 'text-slate-500' : 'text-slate-700'}`}
                >
                    {label}
                </div>
                {note && <div className="text-xs text-slate-400">{note}</div>}
            </div>
            <div
                className={`text-sm font-semibold ${
                    highlight
                        ? 'text-lg text-indigo-700'
                        : negative
                          ? 'text-rose-600'
                          : muted
                            ? 'text-slate-500'
                            : 'text-slate-800'
                }`}
            >
                {formatRupiah(value)}
            </div>
        </div>
    );
}

const STATUS_LABELS = {
    pending: 'Menunggu Approval',
    approved: 'Disetujui',
    payment_recorded: 'Pembayaran Tercatat',
    payment_verified: 'Pembayaran Terverifikasi',
    completed: 'Selesai',
    reversed: 'Dibatalkan (Reversed)',
    rejected: 'Ditolak',
};

function StatusBadge({ status }) {
    const styles = {
        pending: 'bg-amber-50 text-amber-700',
        approved: 'bg-sky-50 text-sky-700',
        payment_recorded: 'bg-indigo-50 text-indigo-700',
        payment_verified: 'bg-violet-50 text-violet-700',
        completed: 'bg-emerald-50 text-emerald-700',
        reversed: 'bg-rose-50 text-rose-700 border border-rose-200',
        rejected: 'bg-rose-50 text-rose-700',
    };

    return (
        <span
            className={`rounded-full px-3 py-1 text-sm font-medium ${styles[status] || 'bg-slate-100 text-slate-600'}`}
        >
            {STATUS_LABELS[status] || status}
        </span>
    );
}

export default function Settlement({
    loan,
    preview,
    settlement,
    payments,
    timeline,
    correction_preview,
    corrections,
    flash,
    error,
    errors,
}) {
    const { data, setData, post, processing } = useForm({
        penalty: '',
        notes: '',
    });

    const [selectedPaymentId, setSelectedPaymentId] = useState('');
    const [showCorrectionModal, setShowCorrectionModal] = useState(false);
    const [correctionReason, setCorrectionReason] = useState('');
    const [correctionPenalty, setCorrectionPenalty] = useState('');
    const [correctionNotes, setCorrectionNotes] = useState('');
    const [isSubmittingCorrection, setIsSubmittingCorrection] = useState(false);

    if (!loan) {
        return (
            <ShmLayout title="Pelunasan Pinjaman">
                <Head title="Pelunasan Pinjaman" />
                <div className="space-y-6">
                    <Link
                        href={route('shm.loans.pelunasan.index')}
                        className="inline-flex items-center gap-1 text-sm text-indigo-600 hover:underline"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Kembali ke daftar pelunasan
                    </Link>
                    <div className="flex items-start gap-3 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            {error ||
                                errors?.loan ||
                                'Pinjaman tidak layak untuk pelunasan.'}
                        </span>
                    </div>
                </div>
            </ShmLayout>
        );
    }

    const status = settlement?.status || null;
    const loanClosed = loan.status !== 'active';

    const submitRequest = (event) => {
        event.preventDefault();
        router.post(
            route('shm.loans.pelunasan.store', loan.id),
            {
                penalty: data.penalty === '' ? 0 : data.penalty,
                notes: data.notes || null,
            },
            { preserveScroll: true },
        );
    };

    const approve = () => {
        if (window.confirm('Setujui pengajuan pelunasan ini?')) {
            router.post(
                route('shm.loans.pelunasan.approve', loan.id),
                {},
                { preserveScroll: true },
            );
        }
    };

    const reject = () => {
        const reason = window.prompt('Alasan penolakan (opsional):');
        if (reason !== null) {
            router.post(
                route('shm.loans.pelunasan.reject', loan.id),
                { reason: reason || null },
                { preserveScroll: true },
            );
        }
    };

    const recordPayment = () => {
        if (!selectedPaymentId) return;
        router.post(
            route('shm.loans.pelunasan.payment', loan.id),
            { payment_id: selectedPaymentId },
            { preserveScroll: true },
        );
    };

    const verifyPayment = () => {
        if (
            window.confirm(
                'Verifikasi pembayaran pelunasan? Tindakan ini menyatakan pembayaran telah diterima dan memenuhi kewajiban.',
            )
        ) {
            router.post(
                route('shm.loans.pelunasan.payment.verify', loan.id),
                {},
                { preserveScroll: true },
            );
        }
    };

    const complete = () => {
        if (
            window.confirm(
                'Selesaikan pelunasan? Pinjaman akan ditutup (aktif → tutup). Tindakan ini bersifat final.',
            )
        ) {
            router.post(
                route('shm.loans.pelunasan.complete', loan.id),
                {},
                { preserveScroll: true },
            );
        }
    };

    const submitCorrection = (e) => {
        e.preventDefault();
        if (!correctionReason || correctionReason.trim().length < 5) {
            alert('Alasan koreksi pelunasan wajib diisi (minimal 5 karakter).');
            return;
        }

        setIsSubmittingCorrection(true);
        router.post(
            route('shm.loans.pelunasan.correct', loan.id),
            {
                reason: correctionReason.trim(),
                penalty: correctionPenalty === '' ? 0 : correctionPenalty,
                notes: correctionNotes || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setShowCorrectionModal(false);
                    setCorrectionReason('');
                    setCorrectionPenalty('');
                    setCorrectionNotes('');
                    setIsSubmittingCorrection(false);
                },
                onError: () => {
                    setIsSubmittingCorrection(false);
                },
            },
        );
    };

    const discount = settlement?.discount || null;
    const previewDiscountBlocked =
        preview?.discount_status === 'no_policy' || preview?.settlement_amount === null;

    return (
        <ShmLayout title={`Pelunasan ${loan.loan_number}`}>
            <Head title={`Pelunasan ${loan.loan_number}`} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link
                            href={route('shm.loans.pelunasan.index')}
                            className="mb-1 inline-flex items-center gap-1 text-sm text-indigo-600 hover:underline"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Kembali ke daftar pelunasan
                        </Link>
                        <h1 className="text-xl font-semibold text-gray-800">
                            Pelunasan {loan.loan_number}
                        </h1>
                        <p className="text-sm text-gray-500">
                            Kewajiban pelunasan dihitung server dari persisted
                            state — React tidak menghitung nilai finansial.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {status && <StatusBadge status={status} />}
                        <span
                            className={`rounded-full px-3 py-1 text-sm font-medium ${
                                loanClosed
                                    ? 'bg-slate-200 text-slate-600'
                                    : 'bg-emerald-50 text-emerald-700'
                            }`}
                        >
                            Pinjaman: {loanClosed ? 'Tutup' : 'Aktif'}
                        </span>
                    </div>
                </div>

                {flash?.success && (
                    <div className="flex items-start gap-2 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{flash.success}</span>
                    </div>
                )}

                {(flash?.error || errors?.loan) && (
                    <div className="flex items-start gap-2 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{flash?.error || errors?.loan}</span>
                    </div>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {/* --------------------- Info Pinjaman --------------------- */}
                    <section className="rounded-lg bg-white p-5 shadow">
                        <h2 className="mb-3 text-sm font-semibold uppercase text-slate-600">
                            Informasi Pinjaman
                        </h2>
                        <dl className="space-y-3 text-sm">
                            <Field label="No. Pinjaman">
                                {loan.loan_number}
                            </Field>
                            <Field label="Nasabah">
                                {loan.customer?.name || '-'}
                                {loan.customer?.customer_number
                                    ? ` (${loan.customer.customer_number})`
                                    : ''}
                            </Field>
                            <Field label="Produk">{loan.product?.name || '-'}</Field>
                            <Field label="Plafon Asli">
                                {formatRupiah(loan.principal)}
                            </Field>
                            <Field label="Tenor">{loan.tenor_months} bulan</Field>
                            <Field label="Rate Persisted">
                                {loan.rate_percent != null
                                    ? `${loan.rate_percent}% (${loan.calculation_method || 'flat'}, ${loan.rate_period || '-'})`
                                    : '—'}
                            </Field>
                            <Field label="Dicairkan">
                                {loan.disbursed_at
                                    ? formatDate(loan.disbursed_at)
                                    : '—'}
                            </Field>
                        </dl>
                    </section>

                    {/* --------------- Rincian Kewajiban ---------------------- */}
                    {preview && (
                    <section className="rounded-lg bg-white p-5 shadow">
                        <h2 className="mb-3 text-sm font-semibold uppercase text-slate-600">
                            Rincian Kewajiban Pelunasan
                        </h2>

                        <div className="text-xs text-slate-400">
                            Perhitungan oleh server per{' '}
                            {preview?.as_of
                                ? formatDate(preview.as_of)
                                : '—'}
                            .
                        </div>

                        <div className="mt-2">
                            <MoneyRow
                                label="Outstanding Principal"
                                value={preview?.outstanding_principal ?? '0'}
                                note="Σ sisa pokok angsuran dari persisted state"
                            />
                            <MoneyRow
                                label="Due Interest (jatuh tempo)"
                                value={preview?.outstanding_interest_due ?? '0'}
                                note="Bunga angsuran yang sudah jatuh tempo — TIDAK didiskon"
                            />
                            <MoneyRow
                                label="Future / Remaining Interest"
                                value={preview?.remaining_interest ?? '0'}
                                note={`Sisa bunga masa depan (${preview?.remaining_months ?? 0} bulan tersisa) — dasar discount`}
                            />
                            <MoneyRow
                                label="Unallocated Payment"
                                value={preview?.unallocated_payment ?? '0'}
                                muted
                                note="Pembayaran belum teralokasi (informasi)"
                            />
                            <MoneyRow
                                label="Settlement Amount (est. tanpa penalty)"
                                value={preview?.settlement_amount ?? '0'}
                                highlight
                                note={
                                    previewDiscountBlocked
                                        ? 'TIDAK dapat dihitung — lihat catatan kontrak di bawah'
                                        : 'Pokok outstanding + due interest + remaining interest (setelah discount bila eligible)'
                                }
                            />
                        </div>
                    </section>
                    )}
                </div>

                {/* --------------------- Discount --------------------- */}
                {preview && (
                <section className="rounded-lg bg-white p-5 shadow">
                    <h2 className="mb-3 text-sm font-semibold uppercase text-slate-600">
                        Diskon Bunga Pelunasan
                    </h2>

                    {preview?.eligible === false ? (
                        <div className="flex items-center gap-2 rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-600">
                            <XCircle className="h-4 w-4 shrink-0 text-slate-400" />
                            Plafon asli ≤ Rp50.000.000 — tidak eligible diskon;
                            bunga mengikuti perhitungan flat penuh.
                        </div>
                    ) : preview?.discount_status === 'no_policy' ? (
                        <div className="flex items-start gap-2 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>
                                {preview?.error ||
                                    'Policy diskon untuk sisa bulan ini belum dikonfigurkan.'}{' '}
                                Pengajuan pelunasan ditahan sampai admin
                                mengatur policy pada{' '}
                                <Link
                                    href={route(
                                        'shm.loans.pelunasan.policies.index',
                                    )}
                                    className="font-medium underline"
                                >
                                    Master Policy Diskon
                                </Link>
                                .
                            </span>
                        </div>
                    ) : (
                        <div className="text-sm text-slate-700">
                            <div className="flex flex-wrap items-center gap-2">
                                <BadgeCheck className="h-4 w-4 text-emerald-600" />
                                <span className="font-medium">
                                    Eligible diskon (plafon asli &gt;
                                    Rp50.000.000)
                                </span>
                            </div>
                            <dl className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <Field label="Sisa Bulan">
                                    {preview?.remaining_months ?? '-'} bulan
                                </Field>
                                <Field label="Policy Dipakai (preview)">
                                    {preview?.policy_label || '-'}
                                </Field>
                            </dl>
                        </div>
                    )}
                </section>
                )}

                {/* ------------- Settlement (jika sudah diajukan) ------------- */}
                {settlement && (
                    <section className="rounded-lg border border-indigo-200 bg-white p-5 shadow">
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h2 className="flex items-center gap-2 text-sm font-semibold uppercase text-indigo-700">
                                <ScrollText className="h-4 w-4" />
                                Settlement {settlement.settlement_reference}
                            </h2>
                            <StatusBadge status={settlement.status} />
                        </div>

                        <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                            <Field label="Tanggal Settlement">
                                {settlement.settlement_date
                                    ? formatDate(settlement.settlement_date)
                                    : '—'}
                            </Field>
                            <Field label="Catatan">
                                {settlement.notes || '—'}
                            </Field>
                        </dl>

                        <div className="mt-4">
                            <MoneyRow
                                label="Outstanding Principal"
                                value={settlement.outstanding_principal}
                            />
                            <MoneyRow
                                label="Due Interest (jatuh tempo)"
                                value={settlement.outstanding_interest}
                                note="TIDAK didiskon"
                            />
                            <MoneyRow
                                label="Future Interest (dasar discount)"
                                value={discount?.base_amount ?? '0'}
                                muted={!discount?.applied}
                                note={
                                    discount?.applied
                                        ? `Policy snapshot: "${discount?.policy_label || '-'}"`
                                        : 'Tanpa diskon'
                                }
                            />
                            {discount?.applied && (
                                <>
                                    <MoneyRow
                                        label={`Diskon (${discount?.percent ?? '-'}%)`}
                                        value={discount?.amount ?? '0'}
                                        negative
                                        note="Snapshot immutable — perubahan policy berikutnya tidak menghitung ulang settlement ini"
                                    />
                                    <MoneyRow
                                        label="Interest After Discount"
                                        value={discount?.interest_after_discount ?? '0'}
                                    />
                                </>
                            )}
                            <MoneyRow
                                label="Penalty (manual)"
                                value={settlement.outstanding_penalty}
                                note="Diisi manual saat pengajuan — tidak dihitung otomatis"
                            />
                            <MoneyRow
                                label="Total Settlement Obligation"
                                value={settlement.settlement_amount}
                                highlight
                            />
                            <MoneyRow
                                label="Pembayaran Tertaut"
                                value={settlement.payment?.amount ?? '0'}
                                note={
                                    settlement.payment
                                        ? `${settlement.payment.payment_reference} · ${formatDate(settlement.payment.payment_date)}`
                                        : 'Belum ada pembayaran pelunasan tertaut'
                                }
                            />
                            <MoneyRow
                                label="Overpayment"
                                value={settlement.overpayment_amount ?? '0'}
                                muted
                                note="Tujuan: Simpanan Pokok Koperasi milik anggota sumber (ShmCustomer → Member) — diblokir bila hubungan member tidak tersedia atau anggota nonaktif"
                            />
                        </div>

                        <div className="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                            <div className="rounded-md bg-slate-50 p-3">
                                <div className="text-xs text-slate-500">
                                    Status Approval
                                </div>
                                <div className="mt-1 font-medium">
                                    {settlement.status === 'pending'
                                        ? 'Menunggu keputusan'
                                        : settlement.status === 'rejected'
                                          ? 'Ditolak'
                                          : 'Disetujui'}
                                </div>
                            </div>
                            <div className="rounded-md bg-slate-50 p-3">
                                <div className="text-xs text-slate-500">
                                    Pencatatan Pembayaran
                                </div>
                                <div className="mt-1 font-medium">
                                    {settlement.payment
                                        ? `Tercatat ${formatDateTime(settlement.payment.recorded_at)}`
                                        : 'Belum tercatat'}
                                </div>
                            </div>
                            <div className="rounded-md bg-slate-50 p-3">
                                <div className="text-xs text-slate-500">
                                    Verifikasi Pembayaran
                                </div>
                                <div className="mt-1 font-medium">
                                    {settlement.payment?.verified_at
                                        ? `Terverifikasi ${formatDateTime(settlement.payment.verified_at)}${settlement.payment?.verified_by ? ` oleh ${settlement.payment.verified_by}` : ''}`
                                        : 'Belum diverifikasi'}
                                </div>
                            </div>
                        </div>

                        {/* ---- Aksi workflow ---- */}
                        <div className="mt-5 flex flex-wrap gap-3">
                            {status === 'pending' && (
                                <>
                                    <button
                                        type="button"
                                        onClick={approve}
                                        className="inline-flex items-center gap-2 rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
                                    >
                                        <CheckCircle2 className="h-4 w-4" />
                                        Setujui
                                    </button>
                                    <button
                                        type="button"
                                        onClick={reject}
                                        className="inline-flex items-center gap-2 rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700"
                                    >
                                        <XCircle className="h-4 w-4" />
                                        Tolak
                                    </button>
                                </>
                            )}

                            {(status === 'approved' ||
                                status === 'payment_recorded') && (
                                <div className="flex w-full flex-wrap items-end gap-3 rounded-md bg-slate-50 p-3">
                                    <div className="flex-1 min-w-[16rem]">
                                        <label className="block text-xs text-slate-500">
                                            Tautkan pembayaran pelunasan (Payment
                                            T86 yang sudah tercatat)
                                        </label>
                                        <select
                                            className="mt-1 w-full rounded-md border-slate-300 text-sm"
                                            value={selectedPaymentId}
                                            onChange={(e) =>
                                                setSelectedPaymentId(
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="">
                                                — pilih pembayaran —
                                            </option>
                                            {(payments ?? []).map((p) => (
                                                <option
                                                    key={p.id}
                                                    value={p.id}
                                                >
                                                    {p.payment_reference} ·{' '}
                                                    {formatDate(
                                                        p.payment_date,
                                                    )}{' '}
                                                    · {formatRupiah(p.amount)}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={recordPayment}
                                        disabled={!selectedPaymentId}
                                        className="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                                    >
                                        <Link2 className="h-4 w-4" />
                                        Tautkan Pembayaran
                                    </button>
                                </div>
                            )}

                            {status === 'payment_recorded' && (
                                <button
                                    type="button"
                                    onClick={verifyPayment}
                                    className="inline-flex items-center gap-2 rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700"
                                >
                                    <ShieldCheck className="h-4 w-4" />
                                    Verifikasi Pembayaran
                                </button>
                            )}

                            {status === 'payment_verified' && (
                                <button
                                    type="button"
                                    onClick={complete}
                                    className="inline-flex items-center gap-2 rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800"
                                >
                                    <Wallet className="h-4 w-4" />
                                    Selesaikan Pelunasan & Tutup Pinjaman
                                </button>
                            )}

                            {status === 'completed' && (
                                <div className="flex flex-wrap items-center justify-between gap-3 w-full">
                                    <div className="flex items-center gap-2 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                                        <BadgeCheck className="h-4 w-4" />
                                        Pelunasan selesai — pinjaman sudah ditutup.
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => setShowCorrectionModal(true)}
                                        className="inline-flex items-center gap-2 rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 shadow-sm"
                                    >
                                        <RotateCcw className="h-4 w-4" />
                                        Koreksi Pelunasan
                                    </button>
                                </div>
                            )}

                            {status === 'reversed' && (
                                <div className="flex items-center gap-2 rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-800 border border-rose-200 w-full">
                                    <AlertTriangle className="h-4 w-4 text-rose-600 shrink-0" />
                                    Pelunasan ini telah dibatalkan (REVERSED) melalui koreksi non-destruktif (Policy B).
                                </div>
                            )}
                        </div>

                        {/* ---- Timeline ---- */}
                        <h3 className="mb-2 mt-6 flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <Clock className="h-4 w-4" />
                            Timeline Audit
                        </h3>
                        {(timeline ?? []).length === 0 ? (
                            <p className="text-sm text-slate-500">
                                Belum ada peristiwa audit settlement.
                            </p>
                        ) : (
                            <ul className="space-y-2 text-sm text-slate-700">
                                {timeline.map((item) => (
                                    <li key={item.id}>
                                        <span className="font-medium">
                                            {item.action}
                                        </span>{' '}
                                        — {item.description}
                                        <div className="text-xs text-slate-400">
                                            {item.actor || 'Sistem'} ·{' '}
                                            {formatDateTime(item.created_at)}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {/* ---- Riwayat Koreksi ---- */}
                        {corrections && corrections.length > 0 && (
                            <div className="mt-6 border-t border-slate-100 pt-4">
                                <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-700">
                                    <RotateCcw className="h-4 w-4 text-amber-600" />
                                    Riwayat Koreksi Pelunasan
                                </h3>
                                <div className="space-y-3">
                                    {corrections.map((corr) => (
                                        <div
                                            key={corr.id}
                                            className="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2 font-medium text-slate-800">
                                                <span>Koreksi #{corr.id}</span>
                                                <span className="text-xs text-slate-500">
                                                    {formatDateTime(corr.created_at)}
                                                </span>
                                            </div>
                                            <p className="mt-1 text-slate-600">
                                                <strong>Alasan:</strong> {corr.reason}
                                            </p>
                                            <div className="mt-2 text-xs text-slate-500">
                                                Diproses oleh:{' '}
                                                <span className="font-medium text-slate-700">
                                                    {corr.creator || 'Sistem'}
                                                </span>{' '}
                                                · Original:{' '}
                                                <span className="font-mono">
                                                    {corr.original_reference || '-'}
                                                </span>{' '}
                                                · Pengganti:{' '}
                                                <span className="font-mono">
                                                    {corr.replacement_reference || '-'}
                                                </span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </section>
                )}

                {/* -------- Form pengajuan (belum ada settlement) -------- */}
                {!settlement && !loanClosed && (
                    <section className="rounded-lg border border-indigo-200 bg-white p-5 shadow">
                        <h2 className="mb-3 text-sm font-semibold uppercase text-indigo-700">
                            Ajukan Pelunasan
                        </h2>

                        {previewDiscountBlocked && (
                            <div className="mb-4 flex items-start gap-2 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                <span>
                                    Pengajuan pelunasan ditahan: policy diskon
                                    untuk pinjaman &gt; Rp50 juta dengan sisa{' '}
                                    {preview?.remaining_months ?? '?'} bulan
                                    belum dikonfigurkan.
                                </span>
                            </div>
                        )}

                        <form onSubmit={submitRequest} className="space-y-4">
                            <div>
                                <label className="block text-xs text-slate-500">
                                    Penalty (Rp) — diisi MANUAL, sistem tidak
                                    menghitung otomatis
                                </label>
                                <input
                                    type="number"
                                    min="0"
                                    step="1"
                                    className="mt-1 w-full rounded-md border-slate-300 text-sm"
                                    value={data.penalty}
                                    onChange={(e) =>
                                        setData('penalty', e.target.value)
                                    }
                                    placeholder="0"
                                />
                                {errors?.penalty && (
                                    <p className="mt-1 text-xs text-rose-600">
                                        {errors.penalty}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label className="block text-xs text-slate-500">
                                    Catatan (opsional)
                                </label>
                                <textarea
                                    className="mt-1 w-full rounded-md border-slate-300 text-sm"
                                    rows={2}
                                    maxLength={1000}
                                    value={data.notes}
                                    onChange={(e) =>
                                        setData('notes', e.target.value)
                                    }
                                />
                            </div>

                            <button
                                type="submit"
                                disabled={processing || previewDiscountBlocked}
                                className="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                            >
                                Ajukan Pelunasan
                            </button>
                        </form>
                    </section>
                )}

                {/* -------- Catatan kontrak PENDING -------- */}
                {preview && (
                <section className="rounded-lg border border-amber-200 bg-amber-50 p-5">
                    <h2 className="mb-2 flex items-center gap-2 text-sm font-semibold uppercase text-amber-800">
                        <AlertTriangle className="h-4 w-4" />
                        Komponen Belum Dikonfirmasi
                    </h2>

                    <ul className="list-inside list-disc space-y-1 text-sm text-amber-900">
                        {(preview?.pending_notes ?? []).map((note) => (
                            <li key={note}>{note}</li>
                        ))}
                    </ul>
                </section>
                )}

                {/* -------- Modal Koreksi Pelunasan -------- */}
                {showCorrectionModal && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                        <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                                <h3 className="text-lg font-semibold text-slate-800 flex items-center gap-2">
                                    <RotateCcw className="h-5 w-5 text-amber-600" />
                                    Koreksi Pelunasan (Policy B)
                                </h3>
                                <button
                                    type="button"
                                    onClick={() => setShowCorrectionModal(false)}
                                    className="text-slate-400 hover:text-slate-600"
                                >
                                    ✕
                                </button>
                            </div>

                            <form onSubmit={submitCorrection} className="mt-4 space-y-4">
                                <div className="rounded-lg bg-amber-50 p-3 text-xs text-amber-800 leading-relaxed border border-amber-200">
                                    <strong>Prinsip Non-Destruktif (Policy B):</strong> Transaksi original tidak akan dihapus. Statusnya akan diubah menjadi <span className="font-semibold text-rose-700">REVERSED</span>, pinjaman dibuka kembali (active), sisa angsuran dipulihkan, kompensasi lebih bayar dilakukan jika ada, dan settlement pengganti berstatus pending akan diterbitkan.
                                </div>

                                {correction_preview && (
                                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3 text-xs space-y-1">
                                        <div className="font-medium text-slate-700 mb-1">Dampak Pemulihan Finansial:</div>
                                        <div className="flex justify-between text-slate-600">
                                            <span>Settlement Original:</span>
                                            <span className="font-mono font-medium">{correction_preview.original_settlement?.reference}</span>
                                        </div>
                                        <div className="flex justify-between text-slate-600">
                                            <span>Nominal Kewajiban Original:</span>
                                            <span className="font-medium">{formatRupiah(correction_preview.original_settlement?.settlement_amount)}</span>
                                        </div>
                                        <div className="flex justify-between text-slate-600">
                                            <span>Kompensasi Lebih Bayar:</span>
                                            <span className="font-medium">{formatRupiah(correction_preview.original_settlement?.overpayment_amount)}</span>
                                        </div>
                                        <div className="flex justify-between text-slate-600">
                                            <span>Angsuran Dipulihkan:</span>
                                            <span className="font-medium">{correction_preview.restored_state?.installments_to_restore} angsuran</span>
                                        </div>
                                    </div>
                                )}

                                <div>
                                    <label className="block text-xs font-medium text-slate-700">
                                        Alasan Koreksi <span className="text-rose-600">*</span>
                                    </label>
                                    <textarea
                                        required
                                        minLength={5}
                                        maxLength={1000}
                                        rows={3}
                                        className="mt-1 w-full rounded-md border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        placeholder="Contoh: Kesalahan perhitungan denda / pembatalan pelunasan oleh manajemen..."
                                        value={correctionReason}
                                        onChange={(e) => setCorrectionReason(e.target.value)}
                                    />
                                    <p className="mt-1 text-xs text-slate-400">Minimal 5 karakter, tidak boleh kosong.</p>
                                </div>

                                <div>
                                    <label className="block text-xs font-medium text-slate-700">
                                        Penalty Settlement Pengganti (opsional)
                                    </label>
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        className="mt-1 w-full rounded-md border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        placeholder="0"
                                        value={correctionPenalty}
                                        onChange={(e) => setCorrectionPenalty(e.target.value)}
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-medium text-slate-700">
                                        Catatan Tambahan (opsional)
                                    </label>
                                    <textarea
                                        rows={2}
                                        maxLength={1000}
                                        className="mt-1 w-full rounded-md border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        value={correctionNotes}
                                        onChange={(e) => setCorrectionNotes(e.target.value)}
                                    />
                                </div>

                                <div className="mt-5 flex justify-end gap-3 pt-2 border-t border-slate-100">
                                    <button
                                        type="button"
                                        disabled={isSubmittingCorrection}
                                        onClick={() => setShowCorrectionModal(false)}
                                        className="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                                    >
                                        Batal
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={isSubmittingCorrection}
                                        className="inline-flex items-center gap-2 rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 disabled:opacity-50 shadow-sm"
                                    >
                                        <RotateCcw className="h-4 w-4" />
                                        {isSubmittingCorrection ? 'Memproses...' : 'Proses Koreksi Pelunasan'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}
            </div>
        </ShmLayout>
    );
}
