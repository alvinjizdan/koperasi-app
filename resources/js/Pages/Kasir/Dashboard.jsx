import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import StatCard from '@/Components/Admin/StatCard';
import EmptyState from '@/Components/Admin/EmptyState';
import CashierSessionCard from '@/Components/Admin/CashierSessionCard';
import {
    ArrowDownCircle,
    ArrowUpCircle,
    Banknote,
    CheckCircle2,
    AlertCircle,
    History,
    LineChart,
    Lock,
    PlusCircle,
    Receipt,
} from 'lucide-react';
import { formatRupiah, formatDate, formatDateTime } from '@/utils/format';

export default function Dashboard({ active_session, recent_transactions, today, previous_session, flash }) {
    const hasActiveSession = Boolean(active_session);

    return (
        <AdminLayout title="Kasir" pageTitle="Modul Kasir">
            <div className="space-y-6">
                {flash?.success && (
                    <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        <AlertCircle className="h-4 w-4 flex-shrink-0" />
                        {flash.error}
                    </div>
                )}

                <CashierSessionCard session={active_session} showActions />

                {/* Aksi cepat */}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Link
                        href={route('kasir.penerimaan.create')}
                        className={`flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs transition-all hover:shadow-md active:scale-[0.99] ${hasActiveSession ? '' : 'pointer-events-none opacity-50'}`}
                    >
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                            <ArrowDownCircle className="h-5 w-5" />
                        </div>
                        <div>
                            <p className="text-sm font-semibold text-slate-900">Penerimaan Kas</p>
                            <p className="text-xs text-slate-500">Catat uang masuk</p>
                        </div>
                    </Link>

                    <Link
                        href={route('kasir.pengeluaran.create')}
                        className={`flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs transition-all hover:shadow-md active:scale-[0.99] ${hasActiveSession ? '' : 'pointer-events-none opacity-50'}`}
                    >
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-rose-100 text-rose-700">
                            <ArrowUpCircle className="h-5 w-5" />
                        </div>
                        <div>
                            <p className="text-sm font-semibold text-slate-900">Pengeluaran Kas</p>
                            <p className="text-xs text-slate-500">Catat uang keluar</p>
                        </div>
                    </Link>

                    <Link
                        href={route('kasir.setoran.create')}
                        className={`flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs transition-all hover:shadow-md active:scale-[0.99] ${hasActiveSession ? '' : 'pointer-events-none opacity-50'}`}
                    >
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 text-blue-700">
                            <Banknote className="h-5 w-5" />
                        </div>
                        <div>
                            <p className="text-sm font-semibold text-slate-900">Setoran Kasir</p>
                            <p className="text-xs text-slate-500">Setor ke kas pusat</p>
                        </div>
                    </Link>

                    {hasActiveSession ? (
                        <Link
                            href={route('kasir.sesi.close.create', active_session.id)}
                            className="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs transition-all hover:shadow-md active:scale-[0.99]"
                        >
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-200 text-slate-700">
                                <Lock className="h-5 w-5" />
                            </div>
                            <div>
                                <p className="text-sm font-semibold text-slate-900">Tutup Kasir</p>
                                <p className="text-xs text-slate-500">Hitung saldo fisik</p>
                            </div>
                        </Link>
                    ) : (
                        <Link
                            href={route('kasir.sesi.create')}
                            className="flex items-center gap-3 rounded-xl border border-emerald-300 bg-emerald-50 p-4 shadow-xs transition-all hover:shadow-md active:scale-[0.99]"
                        >
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-600 text-white">
                                <PlusCircle className="h-5 w-5" />
                            </div>
                            <div>
                                <p className="text-sm font-semibold text-emerald-900">Buka Sesi Kasir</p>
                                <p className="text-xs text-emerald-800">Mulai operasional kas</p>
                            </div>
                        </Link>
                    )}
                </div>

                {/* Ringkasan hari ini */}
                <div>
                    <h2 className="mb-3 text-sm font-semibold uppercase tracking-wider text-slate-500">
                        Ringkasan Kas Hari Ini — {formatDate(today.operational_date)}
                    </h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            title="Saldo Awal"
                            value={formatRupiah(today.opening_balance)}
                            icon={Banknote}
                            statusNote={`${today.sessions_count} sesi hari ini`}
                        />
                        <StatCard
                            title="Penerimaan"
                            value={formatRupiah(today.income_total)}
                            icon={ArrowDownCircle}
                            statusNote="Dari transaksi sah"
                        />
                        <StatCard
                            title="Pengeluaran"
                            value={formatRupiah(today.expense_total)}
                            icon={ArrowUpCircle}
                            statusNote={`Setoran: ${formatRupiah(today.deposit_total)}`}
                        />
                        <StatCard
                            title="Saldo Kas Sistem"
                            value={formatRupiah(today.system_balance)}
                            icon={LineChart}
                            statusNote={
                                today.open_sessions_count > 0
                                    ? `${today.open_sessions_count} sesi masih terbuka`
                                    : 'Semua sesi tertutup'
                            }
                        />
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    {/* Transaksi terakhir pada sesi aktif */}
                    <div className="lg:col-span-2">
                        <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                            <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                                <h2 className="text-sm font-semibold text-slate-900">Transaksi Terakhir Sesi Aktif</h2>
                                <Link
                                    href={route('kasir.transaksi.index')}
                                    className="inline-flex items-center gap-1 text-xs font-medium text-emerald-700 hover:text-emerald-900"
                                >
                                    <History className="h-3.5 w-3.5" />
                                    Lihat semua
                                </Link>
                            </div>

                            {recent_transactions.length === 0 ? (
                                <div className="p-4">
                                    <EmptyState
                                        icon={Receipt}
                                        title="Belum ada transaksi"
                                        description={
                                            hasActiveSession
                                                ? 'Transaksi pada sesi aktif akan tampil di sini.'
                                                : 'Buka sesi kasir untuk mulai mencatat transaksi kas.'
                                        }
                                    />
                                </div>
                            ) : (
                                <div className="divide-y divide-slate-100">
                                    {recent_transactions.map((transaction) => (
                                        <Link
                                            key={transaction.id}
                                            href={route('kasir.transaksi.show', transaction.id)}
                                            className="flex items-center justify-between gap-3 px-5 py-3 transition-colors hover:bg-slate-50"
                                        >
                                            <div className="min-w-0">
                                                <p className="font-mono text-xs font-medium text-slate-900">
                                                    {transaction.transaction_number}
                                                </p>
                                                <p className="truncate text-xs text-slate-500">
                                                    {transaction.category || transaction.type_label}
                                                    {transaction.description ? ` — ${transaction.description}` : ''}
                                                </p>
                                                <p className="text-[11px] text-slate-400">
                                                    {formatDateTime(transaction.transacted_at)}
                                                </p>
                                            </div>
                                            <p
                                                className={`whitespace-nowrap text-sm font-semibold tabular-nums ${
                                                    transaction.increases_cash ? 'text-emerald-700' : 'text-rose-700'
                                                }`}
                                            >
                                                {transaction.increases_cash ? '+' : '-'}
                                                {formatRupiah(transaction.amount)}
                                            </p>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Referensi sesi sebelumnya */}
                    <div className="space-y-4">
                        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                            <h2 className="text-sm font-semibold text-slate-900">Sesi Terakhir Ditutup</h2>
                            {previous_session ? (
                                <dl className="mt-3 space-y-2 text-sm">
                                    <div className="flex justify-between gap-2">
                                        <dt className="text-slate-500">Nomor</dt>
                                        <dd className="font-mono text-xs font-medium text-slate-900">
                                            {previous_session.session_number}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-2">
                                        <dt className="text-slate-500">Tanggal</dt>
                                        <dd className="text-slate-900">{formatDate(previous_session.operational_date)}</dd>
                                    </div>
                                    <div className="flex justify-between gap-2">
                                        <dt className="text-slate-500">Saldo Fisik</dt>
                                        <dd className="tabular-nums text-slate-900">
                                            {formatRupiah(previous_session.physical_closing_balance)}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-2">
                                        <dt className="text-slate-500">Selisih</dt>
                                        <dd
                                            className={`tabular-nums ${
                                                previous_session.difference === 0 ? 'text-slate-900' : 'text-rose-700'
                                            }`}
                                        >
                                            {formatRupiah(previous_session.difference)}
                                        </dd>
                                    </div>
                                </dl>
                            ) : (
                                <p className="mt-3 text-xs text-slate-500">Belum ada sesi kasir yang ditutup.</p>
                            )}
                            <p className="mt-3 border-t border-slate-100 pt-3 text-[11px] leading-relaxed text-slate-400">
                                Saldo awal sesi baru tidak ditetapkan otomatis dari sesi sebelumnya; operator mengisi saldo
                                awal secara manual.
                            </p>
                        </div>

                        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                            <h2 className="text-sm font-semibold text-slate-900">Akses Cepat</h2>
                            <div className="mt-3 space-y-2">
                                <Link
                                    href={route('kasir.sesi.index')}
                                    className="block rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                                >
                                    Riwayat Sesi Kasir
                                </Link>
                                <Link
                                    href={route('kasir.ringkasan.index')}
                                    className="block rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                                >
                                    Ringkasan Kas Harian
                                </Link>
                                <Link
                                    href={route('kasir.transaksi.index')}
                                    className="block rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                                >
                                    Daftar Transaksi Kas
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
