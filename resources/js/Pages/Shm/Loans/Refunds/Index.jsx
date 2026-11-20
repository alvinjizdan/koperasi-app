import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { formatRupiah, formatDate, formatDateTime } from '@/utils/format';
import { 
    RotateCcw, 
    Search, 
    Filter, 
    ArrowRight, 
    CheckCircle2, 
    XCircle, 
    Clock, 
    AlertCircle,
    Eye,
    Receipt,
    Wallet
} from 'lucide-react';

export default function Index({ refunds, filters }) {
    const [search, setSearch] = useState(filters?.search || '');
    const [status, setStatus] = useState(filters?.status || '');

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(
            route('shm.loans.refunds.index'),
            { search: search || undefined, status: status || undefined },
            { preserveState: true, replace: true }
        );
    };

    const statusBadge = (st) => {
        switch (st) {
            case 'pending':
                return <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 border border-amber-200"><Clock className="w-3 h-3" /> Menunggu Approval</span>;
            case 'approved':
                return <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700 border border-blue-200"><CheckCircle2 className="w-3 h-3" /> Disetujui</span>;
            case 'paid':
                return <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 border border-emerald-200"><CheckCircle2 className="w-3 h-3" /> Selesai Dibayar</span>;
            case 'rejected':
                return <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700 border border-red-200"><XCircle className="w-3 h-3" /> Ditolak</span>;
            case 'cancelled':
                return <span className="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2.5 py-0.5 text-xs font-medium text-slate-700 border border-slate-200"><AlertCircle className="w-3 h-3" /> Dibatalkan</span>;
            case 'reversed':
                return <span className="inline-flex items-center gap-1 rounded-full bg-purple-50 px-2.5 py-0.5 text-xs font-medium text-purple-700 border border-purple-200"><RotateCcw className="w-3 h-3" /> Dibalikkan</span>;
            default:
                return <span className="inline-flex items-center rounded-full bg-gray-50 px-2.5 py-0.5 text-xs font-medium text-gray-700">{st}</span>;
        }
    };

    return (
        <ShmLayout title="Daftar Refund Kelebihan Bayar SHM">
            <Head title="Refund Pelunasan SHM" />

            <div className="space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-bold tracking-tight text-slate-900">Refund Kelebihan Bayar Pelunasan</h1>
                        <p className="text-sm text-slate-500">
                            Pengelolaan pengembalian dana overpayment pelunasan pinjaman SHM (Akun 2-1302).
                        </p>
                    </div>
                </div>

                {/* Filter & Search Bar */}
                <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <form onSubmit={handleSearch} className="flex flex-col sm:flex-row gap-3">
                        <div className="relative flex-1">
                            <Search className="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Cari nomor refund, nasabah, pinjaman, atau settlement..."
                                className="w-full rounded-lg border border-slate-200 pl-9 pr-4 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>
                        <div className="w-full sm:w-48">
                            <select
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
                                className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                            >
                                <option value="">Semua Status</option>
                                <option value="pending">Menunggu Approval</option>
                                <option value="approved">Disetujui</option>
                                <option value="paid">Selesai Dibayar</option>
                                <option value="rejected">Ditolak</option>
                                <option value="cancelled">Dibatalkan</option>
                                <option value="reversed">Dibalikkan</option>
                            </select>
                        </div>
                        <button
                            type="submit"
                            className="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                        >
                            Filter
                        </button>
                    </form>
                </div>

                {/* Tabel Data Refund */}
                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm text-slate-600">
                            <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">No. Referensi</th>
                                    <th className="px-4 py-3">Nasabah & Pinjaman</th>
                                    <th className="px-4 py-3">Ref. Settlement</th>
                                    <th className="px-4 py-3 text-right">Nominal Refund</th>
                                    <th className="px-4 py-3">Metode</th>
                                    <th className="px-4 py-3 text-center">Status</th>
                                    <th className="px-4 py-3">Tanggal Pengajuan</th>
                                    <th className="px-4 py-3 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {refunds.data && refunds.data.length > 0 ? (
                                    refunds.data.map((item) => (
                                        <tr key={item.id} className="hover:bg-slate-50/80 transition-colors">
                                            <td className="px-4 py-3 font-mono font-medium text-slate-900">
                                                {item.refund_reference}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="font-medium text-slate-900">{item.customer?.name}</div>
                                                <div className="text-xs text-slate-500 font-mono">{item.loan?.loan_number}</div>
                                            </td>
                                            <td className="px-4 py-3 font-mono text-xs text-slate-600">
                                                {item.settlement?.settlement_reference}
                                            </td>
                                            <td className="px-4 py-3 text-right font-semibold text-slate-900">
                                                {formatRupiah(item.amount)}
                                            </td>
                                            <td className="px-4 py-3 capitalize text-xs">
                                                {item.payout_method === 'bank_transfer' ? 'Transfer Bank' : 'Tunai / Kasir'}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                {statusBadge(item.status)}
                                            </td>
                                            <td className="px-4 py-3 text-xs text-slate-500">
                                                {formatDate(item.requested_at)}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                <Link
                                                    href={route('shm.loans.refunds.show', item.id)}
                                                    className="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200"
                                                >
                                                    <Eye className="w-3.5 h-3.5" /> Detail
                                                </Link>
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="8" className="px-4 py-8 text-center text-slate-400">
                                            Tidak ada riwayat permohonan refund ditemukan.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {refunds.links && refunds.links.length > 3 && (
                        <div className="border-t border-slate-100 px-4 py-3 flex items-center justify-between text-xs text-slate-500">
                            <div>
                                Menampilkan {refunds.from || 0} - {refunds.to || 0} dari {refunds.total || 0} data
                            </div>
                            <div className="flex gap-1">
                                {refunds.links.map((link, idx) => (
                                    <Link
                                        key={idx}
                                        href={link.url || '#'}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                        className={`px-3 py-1 rounded border ${
                                            link.active
                                                ? 'bg-indigo-600 text-white border-indigo-600 font-medium'
                                                : !link.url
                                                ? 'text-slate-300 border-slate-200 cursor-not-allowed'
                                                : 'text-slate-600 border-slate-200 hover:bg-slate-50'
                                        }`}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </ShmLayout>
    );
}

