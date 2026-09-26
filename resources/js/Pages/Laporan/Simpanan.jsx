import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import StatCard from '@/Components/Admin/StatCard';
import ReportFilters from '@/Components/Admin/ReportFilters';
import ReportTable from '@/Components/Admin/ReportTable';
import { ArrowDownCircle, ArrowUpCircle, Eye, PiggyBank, Scale, Wallet } from 'lucide-react';
import { formatRupiah, formatNumber, formatDate } from '@/utils/format';

export default function Simpanan({ rows, summary, period, filters, options, report_slug, export_route, operational_timezone }) {
    const columns = [
        { key: 'account_number', label: 'Nomor Rekening', className: 'font-mono text-xs font-medium text-slate-900' },
        {
            key: 'member',
            label: 'Anggota',
            render: (row) => (
                <div>
                    <p className="font-medium text-slate-900">{row.member_name || '-'}</p>
                    <p className="font-mono text-[11px] text-slate-400">{row.member_number || '-'}</p>
                </div>
            ),
        },
        { key: 'product_name', label: 'Produk', render: (row) => row.product_name || '-' },
        { key: 'opened_at', label: 'Tanggal Buka', render: (row) => formatDate(row.opened_at) },
        {
            key: 'status',
            label: 'Status',
            render: (row) => (
                <span
                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                        row.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'
                    }`}
                >
                    {row.status_label}
                </span>
            ),
        },
        {
            key: 'balance',
            label: 'Saldo Saat Ini',
            align: 'right',
            className: 'font-semibold tabular-nums text-slate-900',
            render: (row) => formatRupiah(row.balance),
        },
        {
            key: 'period_deposits',
            label: 'Setoran Periode',
            align: 'right',
            className: 'tabular-nums text-emerald-700',
            render: (row) => formatRupiah(row.period_deposits),
        },
        {
            key: 'period_withdrawals',
            label: 'Penarikan Periode',
            align: 'right',
            className: 'tabular-nums text-rose-700',
            render: (row) => formatRupiah(row.period_withdrawals),
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
        <AdminLayout title="Laporan Simpanan" pageTitle="Laporan Simpanan">
            <div className="space-y-6">
                <ReportFilters
                    routeName="laporan.simpanan"
                    filters={filters}
                    periodLabel={period?.label}
                    timezone={operational_timezone}
                    exportRoute={export_route}
                    reportSlug={report_slug}
                    fields={[
                        {
                            name: 'search',
                            label: 'Pencarian',
                            type: 'text',
                            placeholder: 'Nomor rekening atau nama anggota',
                        },
                        { name: 'product_id', label: 'Produk', type: 'select', options: [{ value: 'all', label: 'Semua Produk' }, ...options.products] },
                        { name: 'account_status', label: 'Status Rekening', type: 'select', options: options.accountStatuses },
                    ]}
                />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        title="Total Saldo Simpanan"
                        value={formatRupiah(summary.total_balance)}
                        icon={Wallet}
                        statusNote={`${formatNumber(summary.active_accounts)} rekening aktif dari ${formatNumber(summary.accounts)} rekening terfilter`}
                        scope="Sumber: saldo resmi rekening, bukan total mutasi periode"
                    />
                    <StatCard
                        title="Setoran Periode"
                        value={formatRupiah(summary.period_deposits)}
                        icon={ArrowDownCircle}
                        statusNote={period?.label}
                        scope="Hanya transaksi berstatus selesai"
                    />
                    <StatCard
                        title="Penarikan Periode"
                        value={formatRupiah(summary.period_withdrawals)}
                        icon={ArrowUpCircle}
                        statusNote={period?.label}
                        scope="Hanya transaksi berstatus selesai"
                    />
                    <StatCard
                        title="Mutasi Bersih Periode"
                        value={formatRupiah(summary.period_net)}
                        icon={Scale}
                        statusNote="Setoran dikurangi penarikan"
                        scope="Tidak sama dengan saldo rekening"
                    />
                </div>

                <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-[11px] leading-relaxed text-slate-600">
                    Jasa atau bunga simpanan tidak dihitung karena rumusnya belum dikonfirmasi. Saldo saat ini hanya
                    berubah bila rekening benar-benar bertransaksi, sehingga filter tanggal tidak mengubah saldo.
                </div>

                <ReportTable
                    columns={columns}
                    rows={rows.data}
                    pagination={rows}
                    emptyIcon={PiggyBank}
                    emptyTitle="Tidak ada rekening sesuai filter"
                    emptyDescription="Ubah periode, produk, status rekening, atau kata kunci pencarian."
                />
            </div>
        </AdminLayout>
    );
}
