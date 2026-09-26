import React from 'react';
import { Link } from '@inertiajs/react';
import { Clock, Lock, PlusCircle, AlertCircle } from 'lucide-react';
import { formatRupiah, formatDate, formatDateTime } from '@/utils/format';

/**
 * Kartu status sesi kasir. Menampilkan sesi terbuka milik operator atau
 * mengarahkan operator untuk membuka sesi terlebih dahulu.
 */
export default function CashierSessionCard({ session, showActions = false }) {
    if (!session) {
        return (
            <div className="flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-start gap-3">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700">
                        <AlertCircle className="h-5 w-5" />
                    </div>
                    <div>
                        <p className="text-sm font-semibold text-amber-900">Belum ada sesi kasir terbuka</p>
                        <p className="text-xs text-amber-800">
                            Transaksi kas hanya dapat dicatat setelah sesi kasir dibuka.
                        </p>
                    </div>
                </div>
                <Link
                    href={route('kasir.sesi.create')}
                    className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-2 text-xs font-semibold text-white transition-all hover:bg-emerald-800 active:scale-95"
                >
                    <PlusCircle className="h-3.5 w-3.5" />
                    Buka Sesi Kasir
                </Link>
            </div>
        );
    }

    return (
        <div className="rounded-xl border border-emerald-200 bg-white shadow-xs">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
                <div className="flex items-center gap-3">
                    <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                        <Clock className="h-5 w-5" />
                    </div>
                    <div>
                        <p className="text-xs text-slate-500">Sesi Kasir Aktif</p>
                        <p className="font-mono text-sm font-semibold text-slate-900">{session.session_number}</p>
                    </div>
                </div>
                <span className="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-semibold text-emerald-800">
                    TERBUKA
                </span>
            </div>

            <div className="grid grid-cols-2 gap-4 px-5 py-4 lg:grid-cols-4">
                <div>
                    <p className="text-xs text-slate-500">Tanggal Operasional</p>
                    <p className="text-sm font-semibold text-slate-900">{formatDate(session.operational_date)}</p>
                </div>
                <div>
                    <p className="text-xs text-slate-500">Operator</p>
                    <p className="text-sm font-semibold text-slate-900">{session.operator?.name || '-'}</p>
                </div>
                <div>
                    <p className="text-xs text-slate-500">Saldo Awal</p>
                    <p className="text-sm font-semibold tabular-nums text-slate-900">
                        {formatRupiah(session.opening_balance)}
                    </p>
                </div>
                <div>
                    <p className="text-xs text-slate-500">Saldo Kas Sistem</p>
                    <p className="text-sm font-bold tabular-nums text-emerald-700">
                        {formatRupiah(session.totals?.closing_balance ?? session.system_balance)}
                    </p>
                </div>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-5 py-3">
                <p className="text-xs text-slate-500">Dibuka {formatDateTime(session.opened_at)}</p>
                {showActions && (
                    <div className="flex flex-wrap gap-2">
                        <Link
                            href={route('kasir.penerimaan.create')}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                        >
                            Penerimaan
                        </Link>
                        <Link
                            href={route('kasir.pengeluaran.create')}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                        >
                            Pengeluaran
                        </Link>
                        <Link
                            href={route('kasir.setoran.create')}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                        >
                            Setoran
                        </Link>
                        <Link
                            href={route('kasir.sesi.close.create', session.id)}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white transition-all hover:bg-slate-900 active:scale-95"
                        >
                            <Lock className="h-3.5 w-3.5" />
                            Tutup Kasir
                        </Link>
                    </div>
                )}
            </div>
        </div>
    );
}
