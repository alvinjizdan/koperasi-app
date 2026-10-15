import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import PrimaryButton from '@/Components/PrimaryButton';
import useTableSort from '@/hooks/useTableSort';
import { formatDate, formatRupiah } from '@/utils/format';
import { Clock, Lock, Search } from 'lucide-react';

/**
 * Collection monitoring & work queue SHM (Ticket 90).
 *
 * READ-ONLY: daftar pinjaman aktif + sudah cair yang memiliki angsuran
 * lewat jatuh tempo dengan outstanding aktual. Seluruh filter/search/sort/
 * pagination berjalan di server — tidak ada filtering client-side dan tidak
 * ada data yang dimuat penuh ke frontend.
 *
 * Tidak ada aksi pembayaran, penalty, collector, escalation, maupun
 * perubahan status pinjaman di halaman ini: pembayaran berjalan lewat
 * modul Pembayaran SHM, penagihan/aktivitas serta transisi status berada
 * di halaman detail Collection, tidak ada role collector (keputusan
 * bisnis), dan escalation otomatis/penalty masih PENDING BUSINESS
 * CONFIRMATION.
 */
export default function Index({ loans, sort, filters, products, today }) {
    const handleSort = useTableSort('shm.collection.index');

    const [search, setSearch] = useState(filters.search || '');
    const [productId, setProductId] = useState(
        filters.product_id ? String(filters.product_id) : '',
    );
    const [minDays, setMinDays] = useState(
        filters.min_days_overdue !== null && filters.min_days_overdue !== undefined
            ? String(filters.min_days_overdue)
            : '',
    );
    const [maxDays, setMaxDays] = useState(
        filters.max_days_overdue !== null && filters.max_days_overdue !== undefined
            ? String(filters.max_days_overdue)
            : '',
    );

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('shm.collection.index'),
            {
                search: search || undefined,
                product_id: productId || undefined,
                min_days_overdue: minDays || undefined,
                max_days_overdue: maxDays || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const daysBadge = (days) =>
        days > 0 ? (
            <span className="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700">
                {days} hari
            </span>
        ) : (
            <span className="text-slate-400">-</span>
        );

    const columns = [
        {
            key: 'loan_number',
            label: 'No. Pinjaman',
            sortable: true,
            render: (row) => (
                <Link
                    href={route('shm.collection.show', row.id)}
                    className="font-medium text-indigo-600 hover:underline"
                >
                    {row.loan_number}
                </Link>
            ),
        },
        {
            key: 'customer',
            label: 'Nasabah',
            sortable: false,
            render: (row) => (
                <div>
                    <p className="font-medium text-slate-700">
                        {row.customer?.name || '-'}
                    </p>
                    <p className="text-xs text-slate-400">
                        {[row.customer?.customer_number, row.customer?.nik]
                            .filter(Boolean)
                            .join(' • ') || '-'}
                    </p>
                </div>
            ),
        },
        {
            key: 'product',
            label: 'Produk',
            sortable: false,
            render: (row) => row.product?.name || '-',
        },
        {
            key: 'disbursed_at',
            label: 'Tanggal Pencairan',
            sortable: true,
            render: (row) =>
                row.disbursed_at ? formatDate(row.disbursed_at) : '-',
        },
        {
            key: 'earliest_overdue_due_date',
            label: 'Jatuh Tempo Terlambat',
            sortable: true,
            render: (row) =>
                row.earliest_overdue_due_date
                    ? formatDate(row.earliest_overdue_due_date)
                    : '-',
        },
        {
            key: 'days_overdue',
            label: 'Hari Tunggakan',
            sortable: false,
            align: 'right',
            render: (row) => daysBadge(row.days_overdue),
        },
        {
            key: 'overdue_installments_count',
            label: 'Angsuran Terlambat',
            sortable: true,
            align: 'right',
            render: (row) => `${row.overdue?.installments_count ?? 0}x`,
        },
        {
            key: 'outstanding_principal',
            label: 'Sisa Pokok',
            sortable: true,
            align: 'right',
            render: (row) => formatRupiah(row.overdue?.outstanding_principal ?? 0),
        },
        {
            key: 'outstanding_interest',
            label: 'Sisa Bunga',
            sortable: true,
            align: 'right',
            render: (row) => formatRupiah(row.overdue?.outstanding_interest ?? 0),
        },
        {
            key: 'overdue_total',
            label: 'Total Tunggakan',
            sortable: false,
            align: 'right',
            render: (row) => (
                <span className="font-semibold text-rose-700">
                    {formatRupiah(row.overdue?.total_outstanding ?? 0)}
                </span>
            ),
        },
        {
            key: 'last_payment_date',
            label: 'Pembayaran Terakhir',
            sortable: false,
            render: (row) =>
                row.last_payment_date ? formatDate(row.last_payment_date) : '-',
        },
        {
            key: 'action',
            label: 'Aksi',
            sortable: false,
            align: 'right',
            render: (row) => (
                <Link
                    href={route('shm.collection.show', row.id)}
                    className="inline-flex items-center gap-1 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-100"
                >
                    Detail
                </Link>
            ),
        },
    ];

    return (
        <ShmLayout title="Collection">
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
                            <Clock className="h-5 w-5 text-slate-400" />
                            Collection — Monitoring Tunggakan
                        </h1>
                        <p className="text-sm text-gray-500">
                            Pinjaman aktif yang sudah cair dan memiliki angsuran
                            lewat jatuh tempo. Hari ini:{' '}
                            {today ? formatDate(today) : '-'}.
                        </p>
                    </div>
                </div>

                <div className="flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                    <Lock className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                    <p>
                        Halaman monitoring <strong>read-only</strong>. Pembayaran
                        berjalan lewat modul Pembayaran SHM dan penagihan lewat
                        detail Collection; penalty serta escalation otomatis
                        masih <strong>PENDING BUSINESS CONFIRMATION</strong>.
                    </p>
                </div>

                <form onSubmit={applyFilters} className="flex flex-wrap items-end gap-3">
                    <div>
                        <label className="text-xs font-semibold text-slate-700">
                            Cari
                        </label>
                        <input
                            type="text"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="No. pinjaman, nama, atau no. nasabah"
                            className="mt-1 block w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-700">
                            Produk
                        </label>
                        <select
                            value={productId}
                            onChange={(event) => setProductId(event.target.value)}
                            className="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="">Semua produk</option>
                            {(products || []).map((product) => (
                                <option key={product.id} value={product.id}>
                                    {product.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-700">
                            Min. hari tunggakan
                        </label>
                        <input
                            type="number"
                            min="0"
                            value={minDays}
                            onChange={(event) => setMinDays(event.target.value)}
                            placeholder="0"
                            className="mt-1 block w-32 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-700">
                            Maks. hari tunggakan
                        </label>
                        <input
                            type="number"
                            min="0"
                            value={maxDays}
                            onChange={(event) => setMaxDays(event.target.value)}
                            placeholder="mis. 30"
                            className="mt-1 block w-32 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <PrimaryButton type="submit">
                        <Search className="mr-2 h-4 w-4" />
                        Terapkan
                    </PrimaryButton>
                </form>

                <ReportTable
                    columns={columns}
                    rows={loans.data}
                    pagination={loans}
                    sort={sort}
                    onSort={handleSort}
                    routeName="shm.collection.index"
                    emptyTitle="Belum ada tunggakan Collection"
                    emptyDescription="Pinjaman aktif yang sudah cair dengan angsuran lewat jatuh tempo akan tampil di sini."
                />
            </div>
        </ShmLayout>
    );
}
