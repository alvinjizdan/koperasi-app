import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import StatCard from '@/Components/Admin/StatCard';
import ReportFilters from '@/Components/Admin/ReportFilters';
import ReportTable from '@/Components/Admin/ReportTable';
import { AlertTriangle, CalendarClock, Download, Eye, Info, Landmark, ShieldCheck, ShieldAlert } from 'lucide-react';
import { formatRupiah, formatNumber, formatDate, formatDateTime } from '@/utils/format';

function HistoryBadge({ row }) {
    // Status verifikasi historis berasal dari data service:
    // snapshot dibuat SETELAH tanggal posisi = bukan histori terverifikasi.
    const verified = !row.captured_after_as_of;
    const cls = verified
        ? 'bg-emerald-100 text-emerald-800'
        : 'bg-amber-100 text-amber-800';

    return (
        <span className={`inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold ${cls}`}>
            {verified ? 'Terverifikasi' : 'Bukan histori terverifikasi'}
        </span>
    );
}

export default function PosisiPinjaman({ rows, summary, availability, filters, operational_timezone }) {
    const columns = [
        { key: 'loan_number', label: 'Nomor Pinjaman', className: 'font-mono text-xs font-medium text-slate-900' },
        {
            key: 'member',
            label: 'Anggota',
            render: (row) => (
                <div>
                    <p className="font-medium text-slate-900">{row.loan?.member?.full_name || '-'}</p>
                    <p className="font-mono text-[11px] text-slate-400">{row.loan?.member?.member_number || '-'}</p>
                </div>
            ),
        },
        { key: 'as_of_date', label: 'Tanggal Posisi', render: (row) => formatDate(row.as_of_date) },
        {
            key: 'captured_at',
            label: 'Waktu Snapshot',
            render: (row) => <span className="text-slate-600">{formatDateTime(row.created_at)}</span>,
        },
        {
            key: 'outstanding_principal',
            label: 'Pokok Terutang',
            align: 'right',
            className: 'tabular-nums text-slate-900',
            render: (row) => formatRupiah(row.outstanding_principal),
        },
        {
            key: 'outstanding_interest',
            label: 'Bunga Terutang',
            align: 'right',
            className: 'tabular-nums text-slate-700',
            render: (row) => formatRupiah(row.outstanding_interest),
        },
        {
            key: 'outstanding_penalty',
            label: 'Penalti Terutang',
            align: 'right',
            className: 'tabular-nums text-slate-700',
            render: (row) => formatRupiah(row.outstanding_penalty),
        },
        {
            key: 'total_owed',
            label: 'Total Kewajiban',
            align: 'right',
            className: 'font-semibold tabular-nums text-rose-700',
            render: (row) => formatRupiah(row.total_owed),
        },
        {
            key: 'history',
            label: 'Status Histori',
            render: (row) => <HistoryBadge row={row} />,
        },
        {
            key: 'action',
            label: 'Aksi',
            align: 'right',
            render: (row) => (
                <Link
                    href={route('pinjaman.show', row.loan_id)}
                    className="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                >
                    <Eye className="h-3.5 w-3.5" />
                    Pinjaman
                </Link>
            ),
        },
    ];

    return (
        <AdminLayout title="Posisi Pinjaman per Tanggal" pageTitle="Posisi Pinjaman per Tanggal">
            <div className="space-y-6">
                <ReportFilters
                    routeName="laporan.posisi-pinjaman"
                    filters={filters}
                    showPeriod={false}
                    showDateRange={false}
                    timezone={operational_timezone}
                    fields={[
                        { name: 'search', label: 'Pencarian', type: 'text', placeholder: 'Nomor pinjaman, nama, atau nomor anggota' },
                        { name: 'as_of_date', label: 'Tanggal Posisi', type: 'date' },
                    ]}
                />

                {/* Ekspor CSV — mengikuti filter aktif; otorisasi ditegakkan di backend
                    (laporan.export). Tidak menambah filter atau mengubah data. */}
                <div className="flex justify-end">
                    <a
                        href={route('laporan.posisi-pinjaman.export', {
                            as_of_date: filters.as_of_date,
                            ...(filters.search ? { search: filters.search } : {}),
                        })}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800 transition-colors hover:bg-emerald-100"
                    >
                        <Download className="h-3.5 w-3.5" />
                        Ekspor CSV
                    </a>
                </div>

                {/* Status ketersediaan histori — eksplisit dan jujur terhadap data */}
                <div className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                    {availability.snapshots_on_date > 0 ? (
                        <div className="flex items-start gap-2">
                            <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                            <p className="text-xs leading-relaxed text-slate-600">
                                {formatNumber(availability.snapshots_on_date)} snapshot tersedia per tanggal{' '}
                                <span className="font-semibold">{formatDate(availability.as_of_date)}</span>. Nilai di bawah
                                berasal dari snapshot tersimpan — bukan perhitungan ulang dan bukan saldo hari ini.
                                Snapshot yang dibuat setelah tanggal posisinya ditandai{' '}
                                <span className="font-semibold">Bukan histori terverifikasi</span>.
                            </p>
                        </div>
                    ) : availability.first_ever_as_of_date ? (
                        <div className="flex items-start gap-2">
                            <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                            <p className="text-xs leading-relaxed text-slate-600">
                                Tidak ada snapshot per tanggal{' '}
                                <span className="font-semibold">{formatDate(availability.as_of_date)}</span>. Histori
                                posisi tersedia mulai{' '}
                                <span className="font-semibold">{formatDate(availability.first_ever_as_of_date)}</span>
                                {availability.nearest_as_of_date && (
                                    <>
                                        {' '}
                                        — snapshot terdekat tersedia per{' '}
                                        <span className="font-semibold">{formatDate(availability.nearest_as_of_date)}</span>{' '}
                                        (ditampilkan hanya sebagai informasi, bukan nilai tanggal yang diminta)
                                    </>
                                )}
                                .
                            </p>
                        </div>
                    ) : (
                        <div className="flex items-start gap-2">
                            <Info className="mt-0.5 h-4 w-4 shrink-0 text-slate-500" />
                            <p className="text-xs leading-relaxed text-slate-600">
                                Belum ada snapshot posisi pinjaman. Histori akan tersedia setelah snapshot pertama
                                diambil melalui perintah <code className="rounded bg-slate-100 px-1 font-mono text-[10px]">php artisan loan-snapshots:capture</code>.
                                Saldo pinjaman saat ini tidak ditampilkan sebagai pengganti histori.
                            </p>
                        </div>
                    )}
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        title="Total Kewajiban"
                        value={formatRupiah(summary.total_owed)}
                        icon={Landmark}
                        statusNote={`${formatNumber(summary.snapshots_count)} snapshot`}
                        scope={`Per tanggal ${formatDate(summary.as_of_date)}`}
                    />
                    <StatCard
                        title="Pokok Terutang"
                        value={formatRupiah(summary.total_outstanding_principal)}
                        icon={Landmark}
                        statusNote="Dari snapshot tersimpan"
                        scope="Tidak dihitung ulang"
                    />
                    <StatCard
                        title="Bunga & Penalti Terutang"
                        value={`${formatRupiah(summary.total_outstanding_interest)} / ${formatRupiah(summary.total_outstanding_penalty)}`}
                        icon={CalendarClock}
                        statusNote="Bunga / penalti dari snapshot"
                        scope="Tidak dihitung ulang"
                    />
                    <StatCard
                        title="Rentang Histori"
                        value={availability.first_ever_as_of_date ? `Mulai ${formatDate(availability.first_ever_as_of_date)}` : 'Belum tersedia'}
                        icon={AlertTriangle}
                        statusNote={availability.first_ever_as_of_date ? 'Sebelum tanggal ini tidak ada data' : 'Snapshot pertama belum diambil'}
                        scope="Histori sebelum snapshot pertama tidak direkonstruksi"
                    />
                </div>

                <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-[11px] leading-relaxed text-slate-600">
                    Laporan ini murni berbasis snapshot posisi yang tersimpan pada tanggal posisi yang sama (tidak
                    mencampur tanggal berbeda, tidak menampilkan saldo hari ini). Histori sebelum snapshot pertama
                    tidak tersedia dan tidak direkonstruksi dari konfigurasi bunga/penalti saat ini.
                </div>

                <ReportTable
                    columns={columns}
                    rows={rows.data}
                    pagination={rows}
                    emptyIcon={AlertTriangle}
                    emptyTitle="Tidak ada snapshot posisi"
                    emptyDescription={
                        availability.snapshots_on_date === 0 && !availability.first_ever_as_of_date
                            ? 'Histori belum tersedia. Ambil snapshot pertama melalui perintah loan-snapshots:capture.'
                            : 'Tidak ada snapshot untuk filter ini. Coba tanggal posisi lain.'
                    }
                />
            </div>
        </AdminLayout>
    );
}
