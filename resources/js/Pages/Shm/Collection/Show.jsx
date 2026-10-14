import React from 'react';
import { Head, Link } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import { formatDate, formatDateTime, formatRupiah } from '@/utils/format';
import {
    AlertTriangle,
    ArrowLeft,
    CalendarDays,
    Clock,
    CreditCard,
    FileText,
    History,
    Lock,
    UserRound,
    Users,
} from 'lucide-react';

/**
 * Detail monitoring Collection satu pinjaman (Ticket 90).
 *
 * READ-ONLY: menampilkan data yang SUDAH tersimpan — nasabah SHM + anggota
 * koperasi terkait (relasi existing), data pinjaman, ringkasan tunggakan,
 * jadwal angsuran dengan presentation state yang derived dari amounts,
 * riwayat pembayaran + alokasinya, dan timeline dari audit trail existing.
 *
 * Tidak ada tombol aksi: tidak ada pembayaran, penalty, collector,
 * escalation, maupun perubahan status — semuanya masih PENDING BUSINESS
 * CONFIRMATION. State angsuran adalah turunan tampilan, bukan status DB baru.
 */
function Field({ label, children }) {
    return (
        <div>
            <dt className="text-xs text-gray-500">{label}</dt>
            <dd className="font-medium text-slate-800">{children ?? '-'}</dd>
        </div>
    );
}

const INSTALLMENT_STATE = {
    paid: {
        label: 'Lunas',
        className: 'bg-emerald-50 text-emerald-700',
    },
    overdue: {
        label: 'Terlambat',
        className: 'bg-rose-50 text-rose-700',
    },
    partial: {
        label: 'Dibayar sebagian',
        className: 'bg-amber-50 text-amber-700',
    },
    scheduled: {
        label: 'Belum jatuh tempo',
        className: 'bg-slate-100 text-slate-600',
    },
};

const PAYMENT_STATUS = {
    posted: { label: 'Tersimpan', className: 'bg-emerald-50 text-emerald-700' },
    reversed: { label: 'Dibatalkan', className: 'bg-slate-100 text-slate-500' },
};

export default function Show({ collection, timeline, today }) {
    const { loan, overdue, schedule, payments } = collection;

    const scheduleColumns = [
        {
            key: 'installment_number',
            label: 'No.',
            align: 'right',
            sortable: false,
            render: (row) => row.installment_number,
        },
        {
            key: 'due_date',
            label: 'Jatuh Tempo',
            sortable: false,
            render: (row) => (row.due_date ? formatDate(row.due_date) : '-'),
        },
        {
            key: 'principal_amount',
            label: 'Pokok',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.principal_amount),
        },
        {
            key: 'interest_amount',
            label: 'Bunga',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.interest_amount),
        },
        {
            key: 'paid_principal_amount',
            label: 'Terbayar Pokok',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.paid_principal_amount),
        },
        {
            key: 'paid_interest_amount',
            label: 'Terbayar Bunga',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.paid_interest_amount),
        },
        {
            key: 'outstanding_principal',
            label: 'Sisa Pokok',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.outstanding_principal),
        },
        {
            key: 'outstanding_interest',
            label: 'Sisa Bunga',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.outstanding_interest),
        },
        {
            key: 'outstanding_total',
            label: 'Sisa Tagihan',
            align: 'right',
            sortable: false,
            render: (row) => (
                <span className="font-semibold">
                    {formatRupiah(row.outstanding_total)}
                </span>
            ),
        },
        {
            key: 'state',
            label: 'Status',
            sortable: false,
            render: (row) => {
                const state = INSTALLMENT_STATE[row.state] || INSTALLMENT_STATE.scheduled;
                return (
                    <div className="flex items-center justify-end gap-1">
                        <span
                            className={`rounded-full px-2 py-0.5 text-xs font-medium ${state.className}`}
                        >
                            {state.label}
                        </span>
                        {row.overdue_days !== null && row.overdue_days !== undefined && (
                            <span className="text-xs text-rose-600">
                                {row.overdue_days} hari
                            </span>
                        )}
                    </div>
                );
            },
        },
    ];

    const paymentColumns = [
        {
            key: 'payment_reference',
            label: 'Referensi',
            sortable: false,
            render: (row) => (
                <span className="font-medium text-slate-700">
                    {row.payment_reference}
                </span>
            ),
        },
        {
            key: 'payment_date',
            label: 'Tanggal',
            sortable: false,
            render: (row) => (row.payment_date ? formatDate(row.payment_date) : '-'),
        },
        {
            key: 'amount',
            label: 'Nominal',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.amount),
        },
        {
            key: 'allocated_interest',
            label: 'Alokasi Bunga',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.allocated_interest),
        },
        {
            key: 'allocated_principal',
            label: 'Alokasi Pokok',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.allocated_principal),
        },
        {
            key: 'allocated_penalty',
            label: 'Alokasi Denda',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.allocated_penalty),
        },
        {
            key: 'allocated_total',
            label: 'Total Teralokasi',
            align: 'right',
            sortable: false,
            render: (row) => (
                <span className="font-semibold">
                    {formatRupiah(row.allocated_total)}
                </span>
            ),
        },
        {
            key: 'unallocated',
            label: 'Belum Teralokasi',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.unallocated),
        },
        {
            key: 'status',
            label: 'Status',
            sortable: false,
            render: (row) => {
                const status = PAYMENT_STATUS[row.status] || PAYMENT_STATUS.posted;
                return (
                    <span
                        className={`rounded-full px-2 py-0.5 text-xs font-medium ${status.className}`}
                    >
                        {status.label}
                    </span>
                );
            },
        },
    ];

    return (
        <ShmLayout title={`Collection ${loan.loan_number}`}>
            <Head title={`Collection — ${loan.loan_number}`} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link
                            href={route('shm.collection.index')}
                            className="mb-2 inline-flex items-center gap-1 text-sm text-indigo-600 hover:underline"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Kembali ke daftar Collection
                        </Link>
                        <h1 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
                            <Clock className="h-5 w-5 text-slate-400" />
                            {loan.loan_number}
                        </h1>
                        <p className="text-sm text-gray-500">
                            Detail monitoring tunggakan — data aktual dari
                            jadwal angsuran dan pembayaran tersimpan.
                        </p>
                    </div>
                </div>

                <div className="flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                    <Lock className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                    <p>
                        Halaman <strong>read-only</strong>. Tidak ada aksi
                        pembayaran, penalty, collector, maupun perubahan status
                        dari halaman ini — semuanya masih{' '}
                        <strong>PENDING BUSINESS CONFIRMATION</strong>.
                    </p>
                </div>

                {/* Overdue summary */}
                <section className="rounded-lg bg-white p-5 shadow">
                    <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                        <AlertTriangle className="h-4 w-4 text-rose-500" />
                        Ringkasan Tunggakan
                    </h2>
                    <dl className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
                        <Field label="Jatuh tempo terlambat">
                            {overdue.earliest_overdue_due_date
                                ? formatDate(overdue.earliest_overdue_due_date)
                                : '-'}
                        </Field>
                        <Field label="Hari tunggakan">
                            <span className="text-rose-700">
                                {overdue.days_overdue} hari
                            </span>
                        </Field>
                        <Field label="Angsuran terlambat">
                            {overdue.installments_count}x
                        </Field>
                        <Field label="Sisa pokok">
                            {formatRupiah(overdue.outstanding_principal)}
                        </Field>
                        <Field label="Sisa bunga">
                            {formatRupiah(overdue.outstanding_interest)}
                        </Field>
                        <Field label="Total tunggakan">
                            <span className="text-rose-700">
                                {formatRupiah(overdue.total_outstanding)}
                            </span>
                        </Field>
                    </dl>
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Customer */}
                    <section className="rounded-lg bg-white p-5 shadow">
                        <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                            <UserRound className="h-4 w-4 text-slate-400" />
                            Nasabah SHM
                        </h2>
                        <dl className="grid grid-cols-2 gap-4">
                            <Field label="Nama">{loan.customer?.name}</Field>
                            <Field label="No. Nasabah">
                                {loan.customer?.customer_number}
                            </Field>
                            <Field label="NIK">{loan.customer?.nik}</Field>
                            <Field label="Telepon">{loan.customer?.phone}</Field>
                            <Field label="Alamat">{loan.customer?.address}</Field>
                            <Field label="Status nasabah">
                                <span
                                    className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                                        loan.customer?.is_active
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : 'bg-slate-100 text-slate-500'
                                    }`}
                                >
                                    {loan.customer?.is_active ? 'Aktif' : 'Nonaktif'}
                                </span>
                            </Field>
                        </dl>
                        <div className="mt-4 rounded-lg border border-slate-100 bg-slate-50 p-3">
                            <p className="mb-1 flex items-center gap-1 text-xs font-semibold uppercase text-slate-500">
                                <Users className="h-3.5 w-3.5" />
                                Anggota koperasi terkait
                            </p>
                            {loan.customer?.member ? (
                                <p className="text-sm text-slate-700">
                                    {loan.customer.member.name}{' '}
                                    <span className="text-xs text-slate-400">
                                        ({loan.customer.member.member_number})
                                    </span>
                                </p>
                            ) : (
                                <p className="text-sm text-slate-500">
                                    Tidak terhubung ke anggota koperasi.
                                </p>
                            )}
                        </div>
                    </section>

                    {/* Loan */}
                    <section className="rounded-lg bg-white p-5 shadow">
                        <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                            <FileText className="h-4 w-4 text-slate-400" />
                            Pinjaman
                        </h2>
                        <dl className="grid grid-cols-2 gap-4">
                            <Field label="No. Pinjaman">{loan.loan_number}</Field>
                            <Field label="Produk">
                                {loan.product
                                    ? `${loan.product.name}${loan.product.code ? ` (${loan.product.code})` : ''}`
                                    : '-'}
                            </Field>
                            <Field label="Plafon">
                                {formatRupiah(loan.principal)}
                            </Field>
                            <Field label="Tenor">{loan.tenor_months} bulan</Field>
                            <Field label="Bunga/Jasa">
                                {Number(loan.rate_percent)}%
                                {loan.rate_period
                                    ? ` / ${loan.rate_period === 'monthly' ? 'bulan' : 'tahun'}`
                                    : ''}
                            </Field>
                            <Field label="Metode hitung">
                                {loan.calculation_method || '-'}
                            </Field>
                            <Field label="Tanggal pencairan">
                                {loan.disbursed_at
                                    ? formatDate(loan.disbursed_at)
                                    : '-'}
                            </Field>
                            <Field label="Jatuh tempo pertama">
                                {loan.first_due_date
                                    ? formatDate(loan.first_due_date)
                                    : '-'}
                            </Field>
                            <Field label="Tujuan">{loan.purpose}</Field>
                            <Field label="Lokasi">{loan.location}</Field>
                        </dl>
                    </section>
                </div>

                {/* Installment schedule */}
                <section className="rounded-lg bg-white p-5 shadow">
                    <h2 className="mb-1 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                        <CalendarDays className="h-4 w-4 text-slate-400" />
                        Jadwal Angsuran
                    </h2>
                    <p className="mb-4 text-xs text-slate-500">
                        Status adalah turunan tampilan dari jumlah terbayar dan
                        tanggal jatuh tempo — bukan status database baru.
                        Hari ini: {today ? formatDate(today) : '-'}.
                    </p>
                    <ReportTable
                        columns={scheduleColumns}
                        rows={schedule}
                        emptyTitle="Jadwal angsuran belum tersedia"
                        emptyDescription="Pinjaman ini tidak memiliki baris jadwal angsuran."
                    />
                </section>

                {/* Payment history */}
                <section className="rounded-lg bg-white p-5 shadow">
                    <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                        <CreditCard className="h-4 w-4 text-slate-400" />
                        Riwayat Pembayaran
                    </h2>
                    <ReportTable
                        columns={paymentColumns}
                        rows={payments}
                        emptyTitle="Belum ada pembayaran"
                        emptyDescription="Riwayat pembayaran pinjaman ini akan tampil di sini."
                    />
                </section>

                {/* Timeline */}
                <section className="rounded-lg bg-white p-5 shadow">
                    <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                        <History className="h-4 w-4 text-slate-400" />
                        Timeline (Audit Trail)
                    </h2>
                    {!timeline || timeline.length === 0 ? (
                        <p className="text-sm text-gray-500">
                            Belum ada aktivitas tercatat.
                        </p>
                    ) : (
                        <ol className="space-y-3">
                            {timeline.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="flex items-start gap-3 border-b border-slate-50 pb-3 last:border-b-0 last:pb-0"
                                >
                                    <span className="mt-0.5 h-2 w-2 shrink-0 rounded-full bg-indigo-400" />
                                    <div className="min-w-0">
                                        <p className="text-sm text-slate-700">
                                            {entry.description || entry.action}
                                        </p>
                                        <p className="text-xs text-slate-400">
                                            {entry.action}
                                            {entry.actor ? ` • ${entry.actor}` : ''}
                                            {entry.created_at
                                                ? ` • ${formatDateTime(entry.created_at)}`
                                                : ''}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    )}
                </section>
            </div>
        </ShmLayout>
    );
}
