import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import StatCard from '@/Components/Admin/StatCard';
import EmptyState from '@/Components/Admin/EmptyState';
import ReportFilters from '@/Components/Admin/ReportFilters';
import {
    Activity,
    AlertCircle,
    ArrowDownCircle,
    ArrowUpCircle,
    Banknote,
    Calendar,
    CheckCircle2,
    Landmark,
    PiggyBank,
    Receipt,
    Scale,
    Users,
    Wallet,
} from 'lucide-react';
import { formatRupiah, formatNumber, formatDate } from '@/utils/format';

const STATUS_TONES = {
    PENDING: 'bg-amber-100 text-amber-800',
    APPROVED: 'bg-blue-100 text-blue-800',
    REJECTED: 'bg-rose-100 text-rose-800',
    DISBURSED: 'bg-indigo-100 text-indigo-800',
    ACTIVE: 'bg-emerald-100 text-emerald-800',
    PAID_OFF: 'bg-slate-200 text-slate-700',
    CANCELLED: 'bg-slate-200 text-slate-600',
};

export default function Dashboard({
    today_formatted,
    period,
    operational_timezone,
    metrics,
    filters,
    system_status,
}) {
    const { members, savings, loans, cash, activities } = metrics;

    return (
        <AdminLayout title="Dashboard Utama" pageTitle="Dashboard KSP Bima Sakti Mandiri">
            <div className="space-y-8">
                {/* Header */}
                <div className="flex flex-col justify-between gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:flex-row sm:items-center">
                    <div>
                        <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                            Ringkasan Operasional Koperasi
                        </h2>
                        <p className="mt-1 text-sm text-slate-500">
                            Seluruh angka dihitung langsung dari data modul Anggota, Simpanan, Pinjaman, Angsuran, dan
                            Kasir.
                        </p>
                    </div>
                    {today_formatted && (
                        <div className="flex items-center gap-2 self-start rounded-lg border border-slate-200 bg-slate-100 px-3.5 py-2 text-xs font-semibold text-slate-700 sm:self-center">
                            <Calendar className="h-4 w-4 text-emerald-600" />
                            <span>{today_formatted}</span>
                        </div>
                    )}
                </div>

                {/* Filter periode */}
                <div className="space-y-2">
                    <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-700">
                        Filter Periode
                    </h3>
                    <ReportFilters
                        routeName="dashboard"
                        filters={filters}
                        fields={[]}
                        periodLabel={period?.label}
                        timezone={operational_timezone}
                    />
                    <p className="text-[11px] leading-relaxed text-slate-500">
                        Filter periode hanya memengaruhi metrik transaksi. Posisi saldo dan angka kas hari ini dihitung
                        terpisah agar saldo historis tidak berubah saat filter diubah.
                    </p>
                </div>

                {/* Posisi saat ini */}
                <section>
                    <h3 className="mb-3 text-sm font-semibold uppercase tracking-wider text-slate-700">
                        Posisi Saat Ini (Tidak Terpengaruh Filter Periode)
                    </h3>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            title="Anggota Terdaftar"
                            value={formatNumber(members.total)}
                            icon={Users}
                            statusNote={`${formatNumber(members.active)} aktif • ${formatNumber(members.inactive)} nonaktif`}
                            scope="Sumber: tabel anggota"
                        />
                        <StatCard
                            title="Total Saldo Simpanan"
                            value={formatRupiah(savings.total_balance)}
                            icon={PiggyBank}
                            available={savings.has_accounts}
                            statusNote={
                                savings.has_accounts
                                    ? `${formatNumber(savings.active_accounts)} rekening aktif dari ${formatNumber(savings.total_accounts)} rekening`
                                    : 'Belum ada rekening simpanan'
                            }
                            scope="Sumber: saldo rekening simpanan aktif"
                        />
                        <StatCard
                            title="Total Pinjaman Dicairkan"
                            value={formatRupiah(loans.disbursed_amount)}
                            icon={Landmark}
                            available={loans.has_disbursements}
                            statusNote={
                                loans.has_disbursements
                                    ? `${formatNumber(loans.disbursement_count)} pencairan tercatat`
                                    : 'Belum ada pinjaman dicairkan'
                            }
                            scope="Sumber: tabel pencairan pinjaman, bukan nominal pengajuan"
                        />
                        <StatCard
                            title="Sisa Kewajiban Pinjaman"
                            value={formatRupiah(loans.outstanding_amount)}
                            icon={Scale}
                            available={loans.has_disbursements}
                            statusNote="Nominal dicairkan dikurangi pembayaran angsuran berhasil"
                            scope="Rumus resmi modul Pinjaman; dihitung dari transaksi sah"
                        />
                    </div>
                </section>

                {/* Aktivitas periode */}
                <section>
                    <h3 className="mb-3 text-sm font-semibold uppercase tracking-wider text-slate-700">
                        Aktivitas Periode — {period?.label}
                    </h3>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            title="Anggota Baru"
                            value={formatNumber(members.new_in_period)}
                            icon={Users}
                            statusNote="Berdasarkan tanggal bergabung"
                            scope={`Cakupan: ${formatDate(period?.date_from)} – ${formatDate(period?.date_to)}`}
                        />
                        <StatCard
                            title="Setoran Simpanan"
                            value={formatRupiah(savings.period_deposits)}
                            icon={ArrowDownCircle}
                            statusNote={`Penarikan: ${formatRupiah(savings.period_withdrawals)}`}
                            scope="Hanya transaksi simpanan berstatus selesai"
                        />
                        <StatCard
                            title="Pembayaran Angsuran"
                            value={formatRupiah(loans.period_payments_total)}
                            icon={Receipt}
                            statusNote={`${formatNumber(loans.period_payments_count)} pembayaran berhasil`}
                            scope="Pembayaran dibatalkan tidak dihitung"
                        />
                        <StatCard
                            title="Pinjaman Dicairkan"
                            value={formatRupiah(loans.period_disbursed_amount)}
                            icon={Landmark}
                            statusNote={`${formatNumber(loans.period_disbursed_count)} pencairan • ${formatNumber(loans.period_submitted_count)} pengajuan baru`}
                            scope="Pengajuan, persetujuan, dan pencairan dipisahkan"
                        />
                    </div>
                </section>

                {/* Kasir hari ini */}
                <section>
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-700">
                            Kasir — Tanggal Operasional {formatDate(cash.operational_date)}
                        </h3>
                        <span
                            className={`inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ${
                                cash.open_sessions_count > 0
                                    ? 'bg-emerald-100 text-emerald-800'
                                    : cash.has_sessions
                                      ? 'bg-slate-200 text-slate-700'
                                      : 'bg-amber-100 text-amber-800'
                            }`}
                        >
                            {cash.status_label}
                        </span>
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            title="Saldo Awal Kas Hari Ini"
                            value={formatRupiah(cash.opening_balance)}
                            icon={Wallet}
                            available={cash.has_sessions}
                            statusNote={`${formatNumber(cash.sessions_count)} sesi kasir hari ini`}
                            scope="Diambil dari sesi kasir pada tanggal operasional ini"
                        />
                        <StatCard
                            title="Penerimaan Kas"
                            value={formatRupiah(cash.income_total)}
                            icon={ArrowDownCircle}
                            available={cash.has_sessions}
                            statusNote={`Pengeluaran: ${formatRupiah(cash.expense_total)}`}
                            scope="Transaksi kas berstatus berlaku"
                        />
                        <StatCard
                            title="Setoran Kasir"
                            value={formatRupiah(cash.deposit_total)}
                            icon={Banknote}
                            available={cash.has_sessions}
                            statusNote="Perpindahan kas internal, bukan pengeluaran operasional"
                            scope="Tidak dicampur dengan penerimaan atau pengeluaran eksternal"
                        />
                        <StatCard
                            title="Saldo Akhir Kas Sistem"
                            value={formatRupiah(cash.closing_balance)}
                            icon={Scale}
                            available={cash.has_sessions}
                            statusNote={
                                cash.has_closed_session
                                    ? `Selisih kas hari ini: ${formatRupiah(cash.difference_total)}`
                                    : 'Belum ada sesi yang ditutup hari ini'
                            }
                            scope="Saldo awal + penerimaan − pengeluaran − setoran"
                        />
                    </div>
                </section>

                {/* Komposisi pinjaman */}
                <section className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                        <h3 className="text-sm font-semibold text-slate-900">Pinjaman Berdasarkan Status</h3>
                        <Link
                            href={route('laporan.pinjaman')}
                            className="text-xs font-medium text-emerald-700 hover:text-emerald-900"
                        >
                            Buka laporan pinjaman
                        </Link>
                    </div>
                    {!loans.has_loans ? (
                        <div className="p-4">
                            <EmptyState
                                icon={Landmark}
                                title="Belum ada data pinjaman"
                                description="Ringkasan status pinjaman akan tampil setelah ada pengajuan."
                            />
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 lg:grid-cols-4">
                            {loans.by_status.map((group) => (
                                <div key={group.status} className="rounded-lg border border-slate-200 p-3">
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs font-medium text-slate-600">{group.label}</span>
                                        <span
                                            className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                                STATUS_TONES[group.status] || 'bg-slate-100 text-slate-600'
                                            }`}
                                        >
                                            {formatNumber(group.count)}
                                        </span>
                                    </div>
                                    <p className="mt-2 text-sm font-semibold tabular-nums text-slate-900">
                                        {formatRupiah(group.amount)}
                                    </p>
                                    <p className="text-[11px] text-slate-400">Nominal pengajuan per status</p>
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                {/* Aktivitas terbaru */}
                <section className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div className="rounded-xl border border-slate-200 bg-white shadow-xs lg:col-span-2">
                        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                            <div className="flex items-center gap-2">
                                <Activity className="h-5 w-5 text-emerald-600" />
                                <h3 className="text-sm font-semibold text-slate-900">Transaksi Terbaru</h3>
                            </div>
                            <span className="text-xs text-slate-400">Simpanan, angsuran, dan kas</span>
                        </div>

                        {activities.transactions.length === 0 ? (
                            <div className="p-4">
                                <EmptyState
                                    icon={Activity}
                                    title="Belum ada transaksi"
                                    description="Aktivitas simpanan, angsuran, dan kas akan tampil di sini."
                                />
                            </div>
                        ) : (
                            <div className="divide-y divide-slate-100">
                                {activities.transactions.map((item, index) => (
                                    <Link
                                        key={`${item.kind}-${index}`}
                                        href={item.url || '#'}
                                        className="flex items-center justify-between gap-3 px-5 py-3 transition-colors hover:bg-slate-50"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium text-slate-900">{item.title}</p>
                                            <p className="truncate text-xs text-slate-500">{item.subtitle}</p>
                                            <p className="text-[11px] text-slate-400">{item.at}</p>
                                        </div>
                                        <p
                                            className={`whitespace-nowrap text-sm font-semibold tabular-nums ${
                                                item.increases ? 'text-emerald-700' : 'text-rose-700'
                                            }`}
                                        >
                                            {item.increases ? '+' : '−'}
                                            {formatRupiah(item.amount)}
                                        </p>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="space-y-6">
                        <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                            <div className="border-b border-slate-100 px-5 py-4">
                                <h3 className="text-sm font-semibold text-slate-900">Anggota Terbaru</h3>
                            </div>
                            {activities.members.length === 0 ? (
                                <p className="px-5 py-4 text-xs text-slate-500">Belum ada data anggota.</p>
                            ) : (
                                <ul className="divide-y divide-slate-100">
                                    {activities.members.map((member) => (
                                        <li key={member.id} className="flex items-center justify-between gap-2 px-5 py-2.5">
                                            <Link
                                                href={member.url}
                                                className="min-w-0 text-sm text-slate-800 hover:text-emerald-700"
                                            >
                                                <span className="block truncate font-medium">{member.full_name}</span>
                                                <span className="text-[11px] text-slate-400">
                                                    {member.member_number} • {formatDate(member.joined_at)}
                                                </span>
                                            </Link>
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${
                                                    member.status === 'active'
                                                        ? 'bg-emerald-100 text-emerald-800'
                                                        : 'bg-slate-200 text-slate-600'
                                                }`}
                                            >
                                                {member.status === 'active' ? 'Aktif' : 'Nonaktif'}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>

                        <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                            <div className="border-b border-slate-100 px-5 py-4">
                                <h3 className="text-sm font-semibold text-slate-900">Pinjaman Terbaru</h3>
                            </div>
                            {activities.loans.length === 0 ? (
                                <p className="px-5 py-4 text-xs text-slate-500">Belum ada data pinjaman.</p>
                            ) : (
                                <ul className="divide-y divide-slate-100">
                                    {activities.loans.map((loan) => (
                                        <li key={loan.id} className="flex items-center justify-between gap-2 px-5 py-2.5">
                                            <Link href={loan.url} className="min-w-0 text-sm hover:text-emerald-700">
                                                <span className="block truncate font-medium text-slate-800">
                                                    {loan.member_name}
                                                </span>
                                                <span className="font-mono text-[11px] text-slate-400">
                                                    {loan.loan_number}
                                                </span>
                                            </Link>
                                            <div className="text-right">
                                                <p className="text-xs font-semibold tabular-nums text-slate-900">
                                                    {formatRupiah(loan.amount)}
                                                </p>
                                                <span className="text-[10px] font-medium text-slate-500">
                                                    {loan.status_label}
                                                </span>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>

                        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                            <h3 className="text-sm font-semibold text-slate-900">Status Sistem</h3>
                            <div className="mt-3 space-y-2 text-xs text-slate-600">
                                <div className="flex items-center gap-2">
                                    <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                                    <span className="font-medium text-slate-800">{system_status?.name}</span>
                                </div>
                                <p className="text-slate-500">
                                    Lingkungan: {system_status?.environment} • Status: {system_status?.status}
                                </p>
                                <p className="flex items-start gap-2 text-slate-500">
                                    <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-600" />
                                    Zona waktu operasional laporan: {operational_timezone}. Bunga, jasa simpanan, dan
                                    denda tidak dihitung karena rumusnya belum dikonfirmasi.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </AdminLayout>
    );
}
