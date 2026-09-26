import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import StatCard from '@/Components/Admin/StatCard';
import ReportFilters from '@/Components/Admin/ReportFilters';
import ReportTable from '@/Components/Admin/ReportTable';
import { Banknote, Eye, Scale, Wallet } from 'lucide-react';
import { formatRupiah, formatNumber, formatDate } from '@/utils/format';

export default function KasHarian({ rows, summary, period, filters, options, report_slug, export_route, operational_timezone }) {
    const columns = [
        { key: 'operational_date', label: 'Tanggal', render: (row) => formatDate(row.operational_date) },
        {
            key: 'operator',
            label: 'Operator',
            render: (row) => (
                <div>
                    <p className="font-medium text-slate-900">{row.operator?.name || '-'}</p>
                    <p className="font-mono text-[11px] text-slate-400">{row.session_number}</p>
                </div>
            ),
        },
        {
            key: 'opening_balance',
            label: 'Saldo Awal',
            align: 'right',
            className: 'tabular-nums text-slate-900',
            render: (row) => formatRupiah(row.opening_balance),
        },
        {
            key: 'income_total',
            label: 'Penerimaan',
            align: 'right',
            className: 'tabular-nums text-emerald-700',
            render: (row) => formatRupiah(row.income_total),
        },
        {
            key: 'expense_total',
            label: 'Pengeluaran',
            align: 'right',
            className: 'tabular-nums text-rose-700',
            render: (row) => formatRupiah(row.expense_total),
        },
        {
            key: 'deposit_total',
            label: 'Setoran',
            align: 'right',
            className: 'tabular-nums text-blue-700',
            render: (row) => formatRupiah(row.deposit_total),
        },
        {
            key: 'system_balance',
            label: 'Saldo Akhir Sistem',
            align: 'right',
            className: 'font-semibold tabular-nums text-slate-900',
            render: (row) => formatRupiah(row.system_balance),
        },
        {
            key: 'physical_closing_balance',
            label: 'Saldo Fisik',
            align: 'right',
            className: 'tabular-nums text-slate-700',
            render: (row) => (row.physical_closing_balance === null ? '—' : formatRupiah(row.physical_closing_balance)),
        },
        {
            key: 'difference',
            label: 'Selisih Kas',
            align: 'right',
            className: 'tabular-nums',
            render: (row) =>
                row.difference === null ? (
                    <span className="text-slate-400">—</span>
                ) : (
                    <span className={row.difference === 0 ? 'text-slate-700' : 'font-semibold text-rose-700'}>
                        {formatRupiah(row.difference)}
                    </span>
                ),
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => (
                <span
                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                        row.status === 'OPEN' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'
                    }`}
                >
                    {row.status_label}
                </span>
            ),
        },
        {
            key: 'action',
            label: 'Aksi',
            align: 'right',
            render: (row) => (
                <Link
                    href={row.url}
                    className="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                >
                    <Eye className="h-3.5 w-3.5" />
                    Detail
                </Link>
            ),
        },
    ];

    return (
        <AdminLayout title="Laporan Kas Harian" pageTitle="Laporan Kas Harian">
            <div className="space-y-6">
                <ReportFilters
                    routeName="laporan.kas-harian"
                    filters={filters}
                    periodLabel={period?.label}
                    timezone={operational_timezone}
                    exportRoute={export_route}
                    reportSlug={report_slug}
                    fields={[
                        { name: 'operator_id', label: 'Operator', type: 'select', options: [{ value: 'all', label: 'Semua Operator' }, ...options.operators] },
                        { name: 'status', label: 'Status Sesi', type: 'select', options: options.statuses },
                    ]}
                />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        title="Sesi Kasir"
                        value={formatNumber(summary.sessions_count)}
                        icon={Wallet}
                        statusNote={`${formatNumber(summary.open_sessions_count)} terbuka • ${formatNumber(summary.closed_sessions_count)} ditutup`}
                        scope={period?.label}
                    />
                    <StatCard
                        title="Total Mutasi Periode"
                        value={`${formatRupiah(summary.income_total)} / ${formatRupiah(summary.expense_total)}`}
                        icon={Banknote}
                        statusNote={`Setoran internal: ${formatRupiah(summary.deposit_total)}`}
                        scope="Penerimaan / pengeluaran dari transaksi berlaku"
                    />
                    <StatCard
                        title="Kas pada Sesi Terbuka"
                        value={formatRupiah(summary.cash_on_hand_open_sessions)}
                        icon={Wallet}
                        available={summary.open_sessions_count > 0}
                        statusNote={`${formatNumber(summary.open_sessions_count)} sesi belum ditutup`}
                        scope="Saldo berjalan sesi terbuka, dihitung per sesi"
                    />
                    <StatCard
                        title="Total Selisih Kas"
                        value={formatRupiah(summary.difference_total)}
                        icon={Scale}
                        available={summary.has_closed_session}
                        statusNote="Hanya dari sesi yang sudah ditutup"
                        scope="Saldo fisik dikurangi saldo akhir sistem"
                    />
                </div>

                <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-[11px] leading-relaxed text-slate-600">
                    Setiap sesi kasir ditampilkan terpisah. Saldo akhir antar-sesi tidak dijumlahkan sebagai total saldo
                    kas koperasi karena uang yang sama tidak boleh dihitung dua kali. Setoran kasir merupakan perpindahan
                    kas antar-lokasi internal, bukan pengeluaran operasional.
                </div>

                <ReportTable
                    columns={columns}
                    rows={rows.data}
                    pagination={rows}
                    emptyIcon={Wallet}
                    emptyTitle="Tidak ada sesi kasir"
                    emptyDescription="Ubah periode, operator, atau status sesi untuk melihat data lainnya."
                />
            </div>
        </AdminLayout>
    );
}
