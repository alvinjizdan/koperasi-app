import React, { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { ScrollArea, ScrollBar } from '@/Components/ui/scroll-area';
import EmptyState from '@/Components/Admin/EmptyState';
import { formatRupiah } from '@/utils/format';
import {
    Plus,
    Search,
    Filter,
    ArrowDownLeft,
    ArrowUpRight,
    CheckCircle,
    XCircle,
    Clock,
    AlertCircle,
    Edit2,
    Trash2,
    Send,
    RotateCcw
} from 'lucide-react';

export default function CashFlowIndex({
    title,
    type,
    transactions,
    filters,
    cashAccounts,
    counterpartAccounts,
    permissions,
}) {
    const isOutflow = type === 'OUTFLOW';
    const routePrefix = isOutflow ? 'shm.cash-outflow' : 'shm.cash-inflow';
    const amountLabel = isOutflow ? 'Kas Keluar' : 'Kas Masuk';
    const pageHeaderTitle = isOutflow ? 'Arus Kas Keluar' : 'Arus Kas Masuk';
    const pageHeaderSubtitle = isOutflow
        ? 'Pencatatan pengeluaran kas BSM Makro terintegrasi dengan Buku Besar & Neraca SHM.'
        : 'Pencatatan penerimaan kas BSM Makro terintegrasi dengan Buku Besar & Neraca SHM.';

    // Search & Filter state
    const [search, setSearch] = useState(filters.search ?? '');
    const [statusFilter, setStatusFilter] = useState(filters.status ?? '');
    const [startDate, setStartDate] = useState(filters.start_date ?? '');
    const [endDate, setEndDate] = useState(filters.end_date ?? '');

    // Modal state
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
    const [editingTx, setEditingTx] = useState(null);
    const [reversingTx, setReversingTx] = useState(null);
    const [reversalReason, setReversalReason] = useState('');

    // Form hook
    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        type: type,
        transaction_date: new Date().toISOString().split('T')[0],
        cash_account_id: cashAccounts[0]?.id || '',
        account_id: '',
        amount: '',
        description: '',
    });

    const handleFilterSubmit = (e) => {
        e.preventDefault();
        router.get(
            route(`${routePrefix}.index`),
            {
                search: search || '',
                status: statusFilter || '',
                start_date: startDate || '',
                end_date: endDate || '',
            },
            { preserveState: true, replace: true }
        );
    };

    const handleResetFilter = () => {
        setSearch('');
        setStatusFilter('');
        setStartDate('');
        setEndDate('');
        router.get(route(`${routePrefix}.index`), {}, { preserveState: false, replace: true });
    };

    const openCreateModal = () => {
        setEditingTx(null);
        clearErrors();
        setData({
            type: type,
            transaction_date: new Date().toISOString().split('T')[0],
            cash_account_id: cashAccounts[0]?.id || '',
            account_id: '',
            amount: '',
            description: '',
        });
        setIsCreateModalOpen(true);
    };

    const openEditModal = (tx) => {
        setEditingTx(tx);
        clearErrors();
        setData({
            type: type,
            transaction_date: tx.transaction_date,
            cash_account_id: tx.cash_account_id,
            account_id: tx.account_id,
            amount: tx.amount,
            description: tx.description || '',
        });
        setIsCreateModalOpen(true);
    };

    const handleSubmitForm = (e) => {
        e.preventDefault();
        if (editingTx) {
            put(route(`${routePrefix}.update`, editingTx.id), {
                onSuccess: () => {
                    setIsCreateModalOpen(false);
                    setEditingTx(null);
                    reset();
                },
            });
        } else {
            post(route(`${routePrefix}.store`), {
                onSuccess: () => {
                    setIsCreateModalOpen(false);
                    reset();
                },
            });
        }
    };

    const handleDelete = (tx) => {
        if (confirm(`Apakah Anda yakin ingin menghapus draft transaksi ${tx.transaction_number}?`)) {
            router.delete(route(`${routePrefix}.destroy`, tx.id));
        }
    };

    const handlePost = (tx) => {
        if (confirm(`Posting transaksi ${tx.transaction_number} ke Buku Besar & Neraca SHM? Transaksi yang sudah diposting tidak dapat diubah.`)) {
            router.post(route(`${routePrefix}.post`, tx.id));
        }
    };

    const handleReverseSubmit = (e) => {
        e.preventDefault();
        if (!reversingTx || !reversalReason.trim()) return;

        router.post(
            route(`${routePrefix}.reverse`, reversingTx.id),
            { reason: reversalReason },
            {
                onSuccess: () => {
                    setReversingTx(null);
                    setReversalReason('');
                },
            }
        );
    };

    const items = transactions.data ?? [];
    const hasData = items.length > 0;

    return (
        <ShmLayout title={pageHeaderTitle} pageTitle={`Akuntansi BSM Makro — ${pageHeaderTitle}`}>
            {/* Header */}
            <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <div className="flex items-center gap-2">
                        {isOutflow ? (
                            <ArrowUpRight className="h-6 w-6 text-rose-600" />
                        ) : (
                            <ArrowDownLeft className="h-6 w-6 text-emerald-600" />
                        )}
                        <h1 className="text-xl font-semibold text-gray-800">{pageHeaderTitle}</h1>
                    </div>
                    <p className="mt-1 text-sm text-gray-500">{pageHeaderSubtitle}</p>
                </div>
                {permissions.canCreate && (
                    <div>
                        <button
                            type="button"
                            onClick={openCreateModal}
                            className={`inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition-colors ${
                                isOutflow
                                    ? 'bg-rose-600 hover:bg-rose-700'
                                    : 'bg-emerald-600 hover:bg-emerald-700'
                            }`}
                        >
                            <Plus className="h-4 w-4" />
                            Tambah {isOutflow ? 'Kas Keluar' : 'Kas Masuk'}
                        </button>
                    </div>
                )}
            </div>

            {/* Filter Form */}
            <form onSubmit={handleFilterSubmit} className="mb-6 rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label className="text-xs font-semibold text-slate-600 block mb-1">Cari Perkiraan / No. Bukti</label>
                        <div className="relative">
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Cari..."
                                className="w-full rounded-lg border border-slate-300 pl-9 pr-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none"
                            />
                            <Search className="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                        </div>
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-600 block mb-1">Status</label>
                        <select
                            value={statusFilter}
                            onChange={(e) => setStatusFilter(e.target.value)}
                            className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none"
                        >
                            <option value="">Semua Status</option>
                            <option value="DRAFT">DRAFT</option>
                            <option value="POSTED">POSTED</option>
                            <option value="REVERSED">REVERSED</option>
                        </select>
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-600 block mb-1">Dari Tanggal</label>
                        <input
                            type="date"
                            value={startDate}
                            onChange={(e) => setStartDate(e.target.value)}
                            className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none"
                        />
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-600 block mb-1">Sampai Tanggal</label>
                        <input
                            type="date"
                            value={endDate}
                            onChange={(e) => setEndDate(e.target.value)}
                            className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none"
                        />
                    </div>
                </div>
                <div className="mt-4 flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                    <button
                        type="button"
                        onClick={handleResetFilter}
                        className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                    >
                        Reset Filter
                    </button>
                    <button
                        type="submit"
                        className="inline-flex items-center gap-1.5 rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-900"
                    >
                        <Filter className="h-4 w-4" />
                        Terapkan Filter
                    </button>
                </div>
            </form>

            {/* Main Table: User-approved columns: Nama Perkiraan, Kas Masuk / Keluar, Timestamp */}
            <div className="rounded-xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                <ScrollArea className="w-full">
                    <table className="w-full border-collapse text-left text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                <th scope="col" className="py-3.5 px-4">Nama Perkiraan</th>
                                <th scope="col" className="py-3.5 px-4 text-right">{amountLabel}</th>
                                <th scope="col" className="py-3.5 px-4">Timestamp</th>
                                <th scope="col" className="py-3.5 px-4 text-center">Status</th>
                                <th scope="col" className="py-3.5 px-4 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {hasData ? (
                                items.map((tx) => (
                                    <tr key={tx.id} className="hover:bg-slate-50/75 transition-colors">
                                        <td className="py-3 px-4">
                                            <div className="font-semibold text-slate-900">{tx.nama_perkiraan}</div>
                                            <div className="text-xs text-slate-500 flex items-center gap-2 mt-0.5">
                                                <span>{tx.transaction_number}</span>
                                                <span>•</span>
                                                <span>Kas/Bank: {tx.cash_account_name}</span>
                                                {tx.description && (
                                                    <>
                                                        <span>•</span>
                                                        <span className="italic">{tx.description}</span>
                                                    </>
                                                )}
                                            </div>
                                        </td>
                                        <td className="py-3 px-4 text-right">
                                            <div className={`font-bold font-mono text-base ${isOutflow ? 'text-rose-600' : 'text-emerald-700'}`}>
                                                {tx.formatted_amount}
                                            </div>
                                            <div className="text-xs text-slate-400">
                                                Tgl: {tx.formatted_transaction_date}
                                            </div>
                                        </td>
                                        <td className="py-3 px-4 whitespace-nowrap">
                                            <div className="flex items-center gap-1.5 text-xs text-slate-600 font-mono">
                                                <Clock className="h-3.5 w-3.5 text-slate-400" />
                                                <span>{tx.timestamp}</span>
                                            </div>
                                        </td>
                                        <td className="py-3 px-4 text-center whitespace-nowrap">
                                            {tx.status === 'POSTED' && (
                                                <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 border border-emerald-200">
                                                    <CheckCircle className="h-3 w-3" />
                                                    POSTED
                                                </span>
                                            )}
                                            {tx.status === 'DRAFT' && (
                                                <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 border border-amber-200">
                                                    <AlertCircle className="h-3 w-3" />
                                                    DRAFT
                                                </span>
                                            )}
                                            {tx.status === 'REVERSED' && (
                                                <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-medium text-rose-700 border border-rose-200" title={tx.reversal_reason}>
                                                    <XCircle className="h-3 w-3" />
                                                    REVERSED
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-3 px-4 text-right whitespace-nowrap">
                                            <div className="inline-flex items-center gap-1.5">
                                                {tx.status === 'DRAFT' && (
                                                    <>
                                                        {permissions.canCreate && (
                                                            <>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => openEditModal(tx)}
                                                                    title="Edit Draft"
                                                                    className="p-1.5 text-slate-500 hover:text-slate-800 rounded hover:bg-slate-100"
                                                                >
                                                                    <Edit2 className="h-4 w-4" />
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => handleDelete(tx)}
                                                                    title="Hapus Draft"
                                                                    className="p-1.5 text-rose-500 hover:text-rose-700 rounded hover:bg-rose-50"
                                                                >
                                                                    <Trash2 className="h-4 w-4" />
                                                                </button>
                                                            </>
                                                        )}
                                                        {permissions.canPost && (
                                                            <button
                                                                type="button"
                                                                onClick={() => handlePost(tx)}
                                                                title="Posting ke Neraca"
                                                                className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded bg-emerald-600 text-white hover:bg-emerald-700"
                                                            >
                                                                <Send className="h-3 w-3" />
                                                                Posting
                                                            </button>
                                                        )}
                                                    </>
                                                )}
                                                {tx.status === 'POSTED' && permissions.canReverse && (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setReversingTx(tx);
                                                            setReversalReason('');
                                                        }}
                                                        title="Batalkan / Reversal Transaksi"
                                                        className="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded border border-rose-300 text-rose-700 hover:bg-rose-50"
                                                    >
                                                        <RotateCcw className="h-3 w-3" />
                                                        Reversal
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={5} className="py-12">
                                        <EmptyState
                                            title="Belum Ada Transaksi"
                                            description={`Belum ada data ${pageHeaderTitle.toLowerCase()} yang tercatat pada filter ini.`}
                                        />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                    <ScrollBar orientation="horizontal" />
                </ScrollArea>

                {/* Pagination */}
                {transactions.links && transactions.links.length > 3 && (
                    <div className="flex items-center justify-between border-t border-slate-200 bg-white px-4 py-3 sm:px-6">
                        <div className="text-xs text-slate-500">
                            Menampilkan <span className="font-semibold">{transactions.from || 0}</span> - <span className="font-semibold">{transactions.to || 0}</span> dari <span className="font-semibold">{transactions.total || 0}</span> transaksi
                        </div>
                        <div className="flex items-center gap-1">
                            {transactions.links.map((link, idx) => (
                                <button
                                    key={idx}
                                    type="button"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.visit(link.url, { preserveState: true })}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                    className={`px-3 py-1 text-xs rounded-md border ${
                                        link.active
                                            ? 'bg-slate-800 text-white border-slate-800 font-semibold'
                                            : link.url
                                            ? 'border-slate-300 text-slate-700 hover:bg-slate-50'
                                            : 'border-slate-200 text-slate-300 cursor-not-allowed'
                                    }`}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Create / Edit Modal */}
            {isCreateModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                        <h2 className="text-lg font-semibold text-slate-900 mb-1">
                            {editingTx ? `Edit Draft ${pageHeaderTitle}` : `Tambah ${pageHeaderTitle}`}
                        </h2>
                        <p className="text-xs text-slate-500 mb-4">
                            Isi detail transaksi di bawah ini. Draft akan dicatat dan dapat diposting ke Neraca SHM.
                        </p>

                        <form onSubmit={handleSubmitForm} className="space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">Tanggal Transaksi Accounting *</label>
                                <input
                                    type="date"
                                    value={data.transaction_date}
                                    onChange={(e) => setData('transaction_date', e.target.value)}
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                                />
                                {errors.transaction_date && <p className="mt-1 text-xs text-rose-600">{errors.transaction_date}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">Akun Kas / Bank SHM *</label>
                                <select
                                    value={data.cash_account_id}
                                    onChange={(e) => setData('cash_account_id', e.target.value)}
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                                >
                                    {cashAccounts.map((acc) => (
                                        <option key={acc.id} value={acc.id}>
                                            {acc.code} - {acc.name}
                                        </option>
                                    ))}
                                </select>
                                {errors.cash_account_id && <p className="mt-1 text-xs text-rose-600">{errors.cash_account_id}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">Nama Perkiraan (Akun Lawan) *</label>
                                <select
                                    value={data.account_id}
                                    onChange={(e) => setData('account_id', e.target.value)}
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                                >
                                    <option value="">-- Pilih Akun Perkiraan SHM --</option>
                                    {counterpartAccounts.map((acc) => (
                                        <option key={acc.id} value={acc.id}>
                                            {acc.code} - {acc.name} ({acc.category})
                                        </option>
                                    ))}
                                </select>
                                {errors.account_id && <p className="mt-1 text-xs text-rose-600">{errors.account_id}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">Nominal ({amountLabel}) *</label>
                                <div className="relative">
                                    <span className="absolute left-3 top-2 text-sm text-slate-400 font-semibold">Rp</span>
                                    <input
                                        type="number"
                                        step="any"
                                        min="1"
                                        value={data.amount}
                                        onChange={(e) => setData('amount', e.target.value)}
                                        placeholder="0"
                                        className="w-full rounded-lg border border-slate-300 pl-10 pr-3 py-2 text-sm focus:border-emerald-500 focus:outline-none font-mono"
                                    />
                                </div>
                                {errors.amount && <p className="mt-1 text-xs text-rose-600">{errors.amount}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">Keterangan Transaksi</label>
                                <textarea
                                    rows={2}
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="Contoh: Penerimaan pembayaran termin..."
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                                />
                                {errors.description && <p className="mt-1 text-xs text-rose-600">{errors.description}</p>}
                            </div>

                            <div className="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setIsCreateModalOpen(false);
                                        setEditingTx(null);
                                    }}
                                    className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className={`rounded-lg px-4 py-2 text-sm font-semibold text-white ${
                                        isOutflow ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700'
                                    } disabled:opacity-50`}
                                >
                                    {processing ? 'Menyimpan...' : editingTx ? 'Simpan Perubahan' : 'Simpan Draft'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Reversal Reason Modal */}
            {reversingTx && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                        <div className="flex items-center gap-2 text-rose-600 mb-2">
                            <RotateCcw className="h-5 w-5" />
                            <h2 className="text-base font-semibold text-slate-900">Reversal {reversingTx.transaction_number}</h2>
                        </div>
                        <p className="text-xs text-slate-600 mb-4">
                            Tindakan ini akan membuat jurnal pembalik otomatis di Neraca & Buku Besar SHM dan membatalkan saldo transaksi ini.
                        </p>

                        <form onSubmit={handleReverseSubmit} className="space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">Alasan Reversal / Pembatalan *</label>
                                <textarea
                                    rows={3}
                                    required
                                    value={reversalReason}
                                    onChange={(e) => setReversalReason(e.target.value)}
                                    placeholder="Jelaskan alasan pembatalan transaksi..."
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-rose-500 focus:outline-none"
                                />
                            </div>

                            <div className="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => setReversingTx(null)}
                                    className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    disabled={!reversalReason.trim()}
                                    className="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-50"
                                >
                                    Konfirmasi Reversal
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </ShmLayout>
    );
}

