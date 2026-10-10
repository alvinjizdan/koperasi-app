import React from 'react';
import { Head, Link } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import { formatDate, formatDateTime, formatRupiah } from '@/utils/format';
import { ArrowLeft, Calculator } from 'lucide-react';

const PERIOD_LABEL = { yearly: 'tahun', monthly: 'bulan' };

/**
 * Detail record Pinjaman SHM (Ticket 64).
 *
 * Tabel angsuran hanya ditampilkan bila record menyimpan pilihan eksplisit
 * metode & periode rate. Tanpa keduanya, formula angsuran SHM masih PENDING
 * BUSINESS CONFIRMATION sehingga tidak ada jadwal yang boleh dirender.
 */
export default function Show({ loan, result }) {
    const rows = result?.rows ?? [];

    const columns = [
        { key: 'period', label: 'No', align: 'center', render: (row) => row.period },
        {
            key: 'principal',
            label: 'Pokok',
            align: 'right',
            render: (row) => formatRupiah(row.principal),
        },
        {
            key: 'interest',
            label: 'Jasa/Bunga',
            align: 'right',
            render: (row) => formatRupiah(row.interest),
        },
        {
            key: 'total',
            label: 'Total',
            align: 'right',
            render: (row) => formatRupiah(row.total),
        },
        {
            key: 'balance',
            label: 'Saldo',
            align: 'right',
            render: (row) => formatRupiah(row.balance),
        },
    ];

    return (
        <ShmLayout title={`Pinjaman ${loan.loan_number}`}>
            <Head title={`Pinjaman ${loan.loan_number}`} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link
                            href={route('shm.loans.index')}
                            className="mb-1 inline-flex items-center gap-1 text-sm text-indigo-600 hover:underline"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Kembali ke daftar pinjaman
                        </Link>
                        <h1 className="text-xl font-semibold text-gray-800">
                            {loan.loan_number}
                        </h1>
                        <p className="text-sm text-gray-500">
                            {loan.customer?.name} &middot; {loan.product?.name}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link href={route('shm.loans.simulation')}>
                            <span className="inline-flex items-center gap-2 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100">
                                <Calculator className="h-4 w-4" />
                                Simulasi Pinjaman
                            </span>
                        </Link>
                        <span
                            className={`rounded-full px-3 py-1 text-sm font-medium ${
                                loan.status === 'active'
                                    ? 'bg-emerald-50 text-emerald-700'
                                    : 'bg-slate-100 text-slate-600'
                            }`}
                        >
                            {loan.status === 'active' ? 'Berjalan' : 'Ditutup'}
                        </span>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <section className="rounded-lg bg-white p-5 shadow lg:col-span-1">
                        <h2 className="mb-3 text-sm font-semibold uppercase text-slate-600">
                            Informasi Pinjaman
                        </h2>
                        <dl className="space-y-3 text-sm">
                            <div>
                                <dt className="text-xs text-gray-500">No. Pinjaman</dt>
                                <dd className="font-medium">{loan.loan_number}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Nasabah SHM</dt>
                                <dd className="font-medium">{loan.customer?.name}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Produk Pinjaman</dt>
                                <dd className="font-medium">{loan.product?.name}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Plafon</dt>
                                <dd className="text-lg font-semibold text-indigo-700">
                                    {formatRupiah(loan.principal)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Tenor</dt>
                                <dd className="font-medium">{loan.tenor_months} bulan</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Bunga / Jasa</dt>
                                <dd className="font-medium">
                                    {Number(loan.rate_percent)}%
                                    {loan.rate_period
                                        ? ` per ${PERIOD_LABEL[loan.rate_period]}`
                                        : ' — periode rate belum dipilih'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Saldo Pokok</dt>
                                <dd className="font-medium">{formatRupiah(loan.outstanding)}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Lokasi</dt>
                                <dd>{loan.location || '-'}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Tujuan Pinjaman</dt>
                                <dd>{loan.purpose || '-'}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Dicairkan</dt>
                                <dd>{loan.disbursed_at ? formatDate(loan.disbursed_at) : '-'}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Terdaftar</dt>
                                <dd>{formatDateTime(loan.created_at)}</dd>
                            </div>
                        </dl>
                    </section>

                    <section className="space-y-4 lg:col-span-2">
                        <h2 className="text-sm font-semibold uppercase text-slate-600">
                            Tabel Angsuran
                        </h2>

                        {result ? (
                            <>
                                <div className="flex flex-wrap gap-4 rounded-lg bg-white p-4 text-sm shadow">
                                    <div>
                                        <p className="text-xs text-gray-500">Metode</p>
                                        <p className="font-medium">
                                            {result.method === 'flat'
                                                ? 'Bunga flat'
                                                : result.method === 'effective'
                                                  ? 'Bunga efektif'
                                                  : 'Anuitas'}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xs text-gray-500">Total jasa</p>
                                        <p className="font-medium">
                                            {formatRupiah(result.total_interest)}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xs text-gray-500">
                                            Total kewajiban
                                        </p>
                                        <p className="font-medium">
                                            {formatRupiah(result.total_obligation)}
                                        </p>
                                    </div>
                                </div>

                                <ReportTable
                                    columns={columns}
                                    rows={rows}
                                    emptyTitle="Belum ada jadwal angsuran"
                                    emptyDescription="Jadwal angsuran akan tampil di sini."
                                />
                            </>
                        ) : (
                            <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                                Formula angsuran pinjaman ini belum ditentukan.
                                Metode (flat / efektif / anuitas) dan periode rate
                                (per bulan / per tahun) adalah{' '}
                                <strong>PENDING BUSINESS CONFIRMATION</strong>, sehingga
                                tabel angsuran tidak ditampilkan. Rate tersimpan pada
                                record ini: <strong>{Number(loan.rate_percent)}%</strong>.
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </ShmLayout>
    );
}
