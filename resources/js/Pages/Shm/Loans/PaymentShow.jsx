import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { formatRupiah, formatDate, formatDateTime } from '@/utils/format';
import { ArrowLeft, History, Wallet, PlayCircle } from 'lucide-react';

/**
 * Detail satu transaksi pembayaran pinjaman SHM (Ticket 86/87, diperluas T88).
 *
 * SELURUH nilai berasal dari record yang sudah tersimpan
 * (`shm_loan_payments` + `shm_loan_payment_allocations` +
 * `shm_loan_installments` + `audit_logs`) — tidak ada perhitungan alokasi
 * di React. Ticket 88 menambahkan: jatuh tempo pada breakdown alokasi,
 * info rate persisted pada ringkasan pinjaman, dan timeline audit
 * (created/allocated) yang bersifat READ-ONLY — membuka halaman ini tidak
 * pernah mengubah data finansial apa pun.
 *
 * Sisa belum teralokasi (overpayment) ditampilkan eksplisit — tidak
 * dibuang, tidak dipaksa menjadi angsuran berikutnya (PENDING T89).
 */
function Field({ label, children }) {
    return (
        <div>
            <dt className="text-xs text-gray-500">{label}</dt>
            <dd className="font-medium">{children ?? '-'}</dd>
        </div>
    );
}

export default function PaymentShow({ payment, timeline, flash }) {
    const statusLabel =
        payment.status === 'reversed' ? 'Dibalik' : 'Tercatat';

    const summary = payment.allocation_summary ?? {};
    const allocations = payment.allocations ?? [];
    const hasAllocations = allocations.length > 0;
    const unallocated = parseFloat(summary.unallocated_amount ?? '0');
    const canAllocate =
        payment.status === 'posted' &&
        payment.loan?.status === 'active' &&
        unallocated > 0;

    const handleAllocate = () => {
        router.post(
            route('shm.loans.payments.allocate', payment.id),
            {},
            { preserveScroll: true },
        );
    };

    return (
        <ShmLayout title={`Pembayaran ${payment.payment_reference}`}>
            <Head title={`Pembayaran ${payment.payment_reference}`} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link
                            href={route('shm.loans.payments.index')}
                            className="mb-1 inline-flex items-center gap-1 text-sm text-indigo-600 hover:underline"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Kembali ke daftar pembayaran
                        </Link>
                        <h1 className="text-xl font-semibold text-gray-800">
                            {payment.payment_reference}
                        </h1>
                        <p className="text-sm text-gray-500">
                            Transaksi pembayaran pinjaman SHM.
                        </p>
                    </div>
                    <span className="rounded-full bg-emerald-50 px-3 py-1 text-sm font-medium text-emerald-700">
                        {statusLabel}
                    </span>
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}

                {flash?.error && (
                    <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
                        {flash.error}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <section className="rounded-lg bg-white p-5 shadow">
                        <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                            <Wallet className="h-4 w-4 text-slate-400" />
                            Transaksi Pembayaran
                        </h2>
                        <dl className="space-y-3 text-sm">
                            <Field label="Referensi Pembayaran">
                                {payment.payment_reference}
                            </Field>
                            <Field label="Tanggal Pembayaran">
                                {payment.payment_date
                                    ? formatDate(payment.payment_date)
                                    : '-'}
                            </Field>
                            <Field label="Nominal">
                                <span className="text-lg font-semibold text-indigo-700">
                                    {formatRupiah(payment.amount)}
                                </span>
                            </Field>
                            <Field label="Kanal Pembayaran">
                                {payment.payment_channel || '—'}
                            </Field>
                            <Field label="Status">{statusLabel}</Field>
                            <Field label="Catatan">{payment.notes || '—'}</Field>
                            <Field label="Dicatat oleh">
                                {payment.creator?.name || '-'}
                            </Field>
                            <Field label="Dicatat pada">
                                {formatDateTime(payment.created_at)}
                            </Field>
                        </dl>
                    </section>

                    <section className="rounded-lg bg-white p-5 shadow">
                        <h2 className="mb-3 text-sm font-semibold uppercase text-slate-600">
                            Pinjaman Terkait
                        </h2>
                        <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                            <Field label="No. Pinjaman">
                                <Link
                                    href={route('shm.loans.show', payment.loan?.id)}
                                    className="text-indigo-600 hover:underline"
                                >
                                    {payment.loan?.loan_number}
                                </Link>
                            </Field>
                            <Field label="Status Pinjaman">
                                {payment.loan?.status === 'active'
                                    ? 'Berjalan'
                                    : 'Ditutup'}
                            </Field>
                            <Field label="Nasabah">
                                {payment.loan?.customer?.name || '-'}
                            </Field>
                            <Field label="No. Nasabah">
                                {payment.loan?.customer?.customer_number || '-'}
                            </Field>
                            <Field label="Produk">
                                {payment.loan?.product?.name || '-'}
                            </Field>
                            <Field label="Tenor">
                                {payment.loan?.tenor_months
                                    ? `${payment.loan.tenor_months} bulan`
                                    : '-'}
                            </Field>
                            <Field label="Rate">
                                {payment.loan?.rate_percent != null
                                    ? `${payment.loan.rate_percent}%${
                                          payment.loan.rate_period
                                              ? ` / ${payment.loan.rate_period}`
                                              : ''
                                      }${
                                          payment.loan.calculation_method
                                              ? ` (${payment.loan.calculation_method})`
                                              : ''
                                      }`
                                    : '-'}
                            </Field>
                            <Field label="Jatuh Tempo Pertama">
                                {payment.loan?.first_due_date
                                    ? formatDate(payment.loan.first_due_date)
                                    : '-'}
                            </Field>
                            <Field label="Plafon">
                                {formatRupiah(payment.loan?.principal)}
                            </Field>
                            <Field label="Saldo Pokok Saat Ini">
                                {formatRupiah(payment.loan?.outstanding)}
                            </Field>
                            <Field label="Tanggal Pencairan">
                                {payment.loan?.disbursed_at
                                    ? formatDate(payment.loan.disbursed_at)
                                    : '-'}
                            </Field>
                        </dl>
                    </section>
                </div>

                <section className="rounded-lg bg-white p-5 shadow">
                    <h2 className="mb-3 text-sm font-semibold uppercase text-slate-600">
                        Alokasi
                    </h2>

                    {!hasAllocations && (
                        <div className="rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
                            <p className="font-semibold">Belum dialokasikan</p>
                            <p className="mt-1 text-xs">
                                Pembayaran ini belum mengalokasikan dana ke
                                angsuran. Alokasi diproses secara server-side
                                dengan urutan bunga lalu pokok; penalty belum
                                dihitung (PENDING).
                            </p>
                        </div>
                    )}

                    {hasAllocations && (
                        <>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="border-b text-xs uppercase text-slate-500">
                                            <th className="py-2 pr-4">Angsuran</th>
                                            <th className="py-2 pr-4">Jatuh Tempo</th>
                                            <th className="py-2 pr-4">Penalty</th>
                                            <th className="py-2 pr-4">Bunga</th>
                                            <th className="py-2 pr-4">Pokok</th>
                                            <th className="py-2 pr-4">Total</th>
                                            <th className="py-2">Dicatat</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {allocations.map((row) => (
                                            <tr
                                                key={row.id}
                                                className="border-b last:border-0"
                                            >
                                                <td className="py-2 pr-4 font-medium">
                                                    #{row.installment_number ?? '—'}
                                                </td>
                                                <td className="py-2 pr-4">
                                                    {row.due_date
                                                        ? formatDate(row.due_date)
                                                        : '—'}
                                                </td>
                                                <td className="py-2 pr-4">
                                                    {formatRupiah(row.penalty_amount)}
                                                </td>
                                                <td className="py-2 pr-4">
                                                    {formatRupiah(row.interest_amount)}
                                                </td>
                                                <td className="py-2 pr-4">
                                                    {formatRupiah(row.principal_amount)}
                                                </td>
                                                <td className="py-2 pr-4 font-semibold">
                                                    {formatRupiah(row.total_amount)}
                                                </td>
                                                <td className="py-2 text-xs text-slate-500">
                                                    {formatDateTime(row.created_at)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <div className="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                <div className="rounded-lg bg-indigo-50 px-3 py-2">
                                    <p className="text-xs text-slate-500">
                                        Total teralokasi
                                    </p>
                                    <p className="font-semibold text-indigo-700">
                                        {formatRupiah(summary.allocated_amount)}
                                    </p>
                                </div>
                                <div
                                    className={`rounded-lg px-3 py-2 ${
                                        unallocated > 0
                                            ? 'bg-amber-50'
                                            : 'bg-slate-50'
                                    }`}
                                >
                                    <p className="text-xs text-slate-500">
                                        Sisa belum teralokasi
                                    </p>
                                    <p
                                        className={`font-semibold ${
                                            unallocated > 0
                                                ? 'text-amber-700'
                                                : 'text-slate-600'
                                        }`}
                                    >
                                        {formatRupiah(summary.unallocated_amount)}
                                    </p>
                                </div>
                            </div>

                            {unallocated > 0 && (
                                <p className="mt-2 text-xs text-slate-500">
                                    Sisa belum teralokasi disimpan apa adanya —
                                    dapat digunakan pada pelunasan (settlement,
                                    workflow T89); perlakuan akuntansi/refund
                                    tetap PENDING BUSINESS CONFIRMATION.
                                </p>
                            )}
                        </>
                    )}

                    {canAllocate && (
                        <div className="mt-4">
                            <button
                                type="button"
                                onClick={handleAllocate}
                                disabled={router.processing}
                                className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                            >
                                <PlayCircle className="h-4 w-4" />
                                {router.processing
                                    ? 'Memproses alokasi…'
                                    : 'Alokasikan ke Angsuran'}
                            </button>
                        </div>
                    )}
                </section>

                {timeline && timeline.length > 0 && (
                    <section className="rounded-lg bg-white p-5 shadow">
                        <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                            <History className="h-4 w-4 text-slate-400" />
                            Riwayat (Timeline)
                        </h2>
                        <ol className="space-y-3 text-sm">
                            {timeline.map((event) => (
                                <li
                                    key={event.id}
                                    className="flex items-start gap-3 border-b border-slate-100 pb-3 last:border-0 last:pb-0"
                                >
                                    <span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-indigo-400" />
                                    <div>
                                        <p className="font-medium text-slate-700">
                                            {event.action ===
                                            'shm.loan.payment.created'
                                                ? 'Pembayaran dicatat'
                                                : 'Alokasi ke angsuran'}
                                        </p>
                                        {event.description && (
                                            <p className="text-xs text-slate-500">
                                                {event.description}
                                            </p>
                                        )}
                                        <p className="mt-0.5 text-xs text-slate-400">
                                            {formatDateTime(event.created_at)}
                                            {event.actor
                                                ? ` — ${event.actor}`
                                                : ''}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </section>
                )}
            </div>
        </ShmLayout>
    );
}
