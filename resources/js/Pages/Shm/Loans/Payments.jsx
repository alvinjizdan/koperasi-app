import React, { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import useTableSort from '@/hooks/useTableSort';
import { formatRupiah, formatDate } from '@/utils/format';
import { Search, Wallet } from 'lucide-react';

/**
 * Halaman Pembayaran / Angsuran SHM (Ticket 86, diperluas T88).
 *
 * Menampilkan daftar TRANSAKSI pembayaran (`shm_loan_payments`) beserta form
 * pencatatannya. Domain SHM: tidak pernah membaca `installment_payments`
 * maupun jadwal angsuran koperasi.
 *
 * T88 menambahkan layer MONITORING: search (referensi / no. pinjaman /
 * nasabah), filter status & rentang tanggal & status alokasi — seluruhnya
 * diQUERY server-side (React tidak memfilter array finansial di browser).
 * Kolom alokasi menampilkan nilai DERIVED dari server (bukan kolom baru).
 *
 * Batas yang disengaja (bukan kelalaian):
 * - form TIDAK meminta alokasi principal/bunga/denda — alokasi adalah T87;
 * - nominal TIDAK dibatasi ke angsuran — overpayment disimpan apa adanya;
 * - kolom kanal bersifat teks opsional: daftar kanal resmi masih PENDING
 *   BUSINESS CONFIRMATION sehingga tidak ada nilai enum yang dipaksakan;
 * - TIDAK ada perhitungan finansial di React: seluruh nilai berasal dari
 *   record yang sudah tersimpan di server.
 *
 * `idempotency_key` dibangkitkan sekali per pengisian form sehingga
 * double-submit/retry tidak pernah membuat dua pembayaran (server
 * mengembalikan pembayaran pertama).
 */
export default function Payments({ payments, loans, sort, filters, today, flash }) {
    const handleSort = useTableSort('shm.loans.payments.index');

    // Filter T88 — dikirim sebagai query string, dipertahankan oleh
    // pagination & sorting (keduanya menyalin window.location.search).
    const [search, setSearch] = useState(filters?.search ?? '');
    const [status, setStatus] = useState(filters?.status ?? 'all');
    const [allocation, setAllocation] = useState(filters?.allocation ?? 'all');
    const [dateFrom, setDateFrom] = useState(filters?.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters?.date_to ?? '');

    const applyFilters = (event) => {
        event.preventDefault();

        router.get(
            route('shm.loans.payments.index'),
            {
                search: search || undefined,
                status: status !== 'all' ? status : undefined,
                allocation: allocation !== 'all' ? allocation : undefined,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
            },
            { preserveState: true },
        );
    };

    const resetFilters = () => {
        setSearch('');
        setStatus('all');
        setAllocation('all');
        setDateFrom('');
        setDateTo('');
        router.get(route('shm.loans.payments.index'), {}, { preserveState: true });
    };

    const generateKey = () =>
        typeof crypto !== 'undefined' && crypto.randomUUID
            ? crypto.randomUUID()
            : `${Date.now()}-${Math.random().toString(16).slice(2)}`;

    const form = useForm({
        loan_id: '',
        payment_date: today || '',
        amount: '',
        payment_channel: '',
        notes: '',
        idempotency_key: generateKey(),
    });

    const submit = (event) => {
        event.preventDefault();

        form.post(route('shm.loans.payments.store'), {
            // Kunci baru hanya setelah pembayaran benar-benar tercatat, sehingga
            // percobaan ulang (retry) tetap memakai kunci yang sama.
            onSuccess: () => {
                form.reset('amount', 'notes');
                form.setData('idempotency_key', generateKey());
            },
        });
    };

    const statusLabel = (status) =>
        status === 'reversed' ? 'Dibalik' : 'Tercatat';

    // Label alokasi dari nilai derived server (T88) — bukan perhitungan React.
    const allocationLabel = {
        none: 'Belum dialokasikan',
        partial: 'Sebagian dialokasikan',
        full: 'Selesai dialokasikan',
    };

    const allocationBadgeClass = {
        none: 'bg-slate-100 text-slate-600',
        partial: 'bg-amber-50 text-amber-700',
        full: 'bg-emerald-50 text-emerald-700',
    };

    const columns = [
        {
            key: 'payment_reference',
            label: 'Referensi',
            sortable: true,
            render: (row) => (
                <Link
                    href={route('shm.loans.payments.show', row.id)}
                    className="font-medium text-indigo-600 hover:underline"
                >
                    {row.payment_reference}
                </Link>
            ),
        },
        {
            key: 'loan',
            label: 'No. Pinjaman',
            sortable: false,
            render: (row) => (
                <Link
                    href={route('shm.loans.show', row.loan?.id)}
                    className="text-slate-700 hover:underline"
                >
                    {row.loan?.loan_number || '-'}
                </Link>
            ),
        },
        {
            key: 'customer',
            label: 'Nasabah',
            sortable: false,
            render: (row) => row.customer?.name || '-',
        },
        {
            key: 'payment_date',
            label: 'Tanggal Bayar',
            sortable: true,
            render: (row) =>
                row.payment_date ? formatDate(row.payment_date) : '-',
        },
        {
            key: 'amount',
            label: 'Nominal',
            sortable: true,
            align: 'right',
            render: (row) => formatRupiah(row.amount),
        },
        {
            key: 'payment_channel',
            label: 'Kanal',
            sortable: false,
            render: (row) => row.payment_channel || '-',
        },
        {
            key: 'allocation_status',
            label: 'Alokasi',
            sortable: false,
            render: (row) => (
                <span
                    className={`rounded-full px-2 py-1 text-xs font-medium ${
                        allocationBadgeClass[row.allocation_status] ??
                        'bg-slate-100 text-slate-600'
                    }`}
                >
                    {allocationLabel[row.allocation_status] ?? '-'}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            sortable: true,
            render: (row) => (
                <span className="rounded-full bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">
                    {statusLabel(row.status)}
                </span>
            ),
        },
        {
            key: 'creator',
            label: 'Dicatat oleh',
            sortable: false,
            render: (row) => row.creator?.name || '-',
        },
        {
            key: 'action',
            label: 'Aksi',
            sortable: false,
            align: 'right',
            render: (row) => (
                <Link
                    href={route('shm.loans.payments.show', row.id)}
                    className="inline-flex items-center gap-1 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-100"
                >
                    Detail
                </Link>
            ),
        },
    ];

    return (
        <ShmLayout title="Pembayaran Pinjaman BSM Makro">
            <Head title="Pembayaran Pinjaman BSM Makro" />

            <div className="space-y-6">
                <div>
                    <h1 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
                        <Wallet className="h-5 w-5 text-slate-400" />
                        Pembayaran / Angsuran
                    </h1>
                    <p className="text-sm text-gray-500">
                        Transaksi pembayaran atas pinjaman BSM Makro yang sudah
                        dicairkan. Alokasi ke angsuran diproses terpisah, sehingga
                        pembayaran di bawah belum mengubah status angsuran.
                    </p>
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}

                {flash?.error && (
                    <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
                        {flash.error}
                    </div>
                )}

                <form
                    onSubmit={applyFilters}
                    className="flex flex-wrap items-end gap-4 rounded-lg bg-white p-4 shadow"
                >
                    <div className="min-w-56 flex-1">
                        <label className="text-xs font-semibold text-slate-700">
                            Cari
                        </label>
                        <input
                            type="text"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Referensi, no. pinjaman, atau nasabah"
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-700">
                            Status
                        </label>
                        <select
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            className="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="all">Semua status</option>
                            <option value="posted">Tercatat</option>
                            <option value="reversed">Dibalik</option>
                        </select>
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-700">
                            Alokasi
                        </label>
                        <select
                            value={allocation}
                            onChange={(event) => setAllocation(event.target.value)}
                            className="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="all">Semua alokasi</option>
                            <option value="none">Belum dialokasikan</option>
                            <option value="partial">Sebagian</option>
                            <option value="full">Selesai</option>
                        </select>
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-700">
                            Dari tanggal
                        </label>
                        <input
                            type="date"
                            value={dateFrom}
                            onChange={(event) => setDateFrom(event.target.value)}
                            className="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-700">
                            Sampai tanggal
                        </label>
                        <input
                            type="date"
                            value={dateTo}
                            onChange={(event) => setDateTo(event.target.value)}
                            className="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <PrimaryButton type="submit">
                        <Search className="mr-2 h-4 w-4" />
                        Terapkan
                    </PrimaryButton>
                    <SecondaryButton type="button" onClick={resetFilters}>
                        Atur ulang
                    </SecondaryButton>
                </form>

                <form
                    onSubmit={submit}
                    className="space-y-5 rounded-lg bg-white p-6 shadow"
                >
                    <h2 className="text-sm font-semibold uppercase text-slate-600">
                        Catat Pembayaran
                    </h2>

                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="loan_id" value="Pinjaman" />
                            <select
                                id="loan_id"
                                value={form.data.loan_id}
                                onChange={(e) =>
                                    form.setData('loan_id', e.target.value)
                                }
                                className="mt-1 block w-full rounded-lg border-slate-300 text-sm"
                            >
                                <option value="">— Pilih pinjaman —</option>
                                {loans.map((loan) => (
                                    <option key={loan.id} value={loan.id}>
                                        {loan.loan_number} — {loan.customer?.name}
                                    </option>
                                ))}
                            </select>
                            <InputError
                                message={form.errors.loan_id}
                                className="mt-1"
                            />
                            {loans.length === 0 && (
                                <p className="mt-1 text-xs text-slate-500">
                                    Belum ada pinjaman aktif yang sudah dicairkan
                                    dalam lingkup Anda.
                                </p>
                            )}
                        </div>

                        <div>
                            <InputLabel
                                htmlFor="payment_date"
                                value="Tanggal Pembayaran"
                            />
                            <TextInput
                                id="payment_date"
                                type="date"
                                max={today}
                                value={form.data.payment_date}
                                onChange={(e) =>
                                    form.setData('payment_date', e.target.value)
                                }
                                className="mt-1"
                            />
                            <InputError
                                message={form.errors.payment_date}
                                className="mt-1"
                            />
                        </div>

                        <div>
                            <InputLabel htmlFor="amount" value="Nominal (Rp)" />
                            <TextInput
                                id="amount"
                                inputMode="decimal"
                                value={form.data.amount}
                                onChange={(e) =>
                                    form.setData(
                                        'amount',
                                        e.target.value.replace(/[^0-9.]/g, ''),
                                    )
                                }
                                className="mt-1 text-right tabular-nums"
                                placeholder="0"
                            />
                            <InputError
                                message={form.errors.amount}
                                className="mt-1"
                            />
                            <p className="mt-1 text-xs text-slate-500">
                                Nominal disimpan persis seperti yang dikirim —
                                tidak dibatasi ke nilai angsuran.
                            </p>
                        </div>

                        <div>
                            <InputLabel
                                htmlFor="payment_channel"
                                value="Kanal Pembayaran"
                            />
                            <TextInput
                                id="payment_channel"
                                value={form.data.payment_channel}
                                onChange={(e) =>
                                    form.setData(
                                        'payment_channel',
                                        e.target.value,
                                    )
                                }
                                className="mt-1"
                                placeholder="Opsional — daftar kanal resmi belum ditetapkan"
                            />
                            <InputError
                                message={form.errors.payment_channel}
                                className="mt-1"
                            />
                        </div>
                    </div>

                    <div>
                        <InputLabel htmlFor="notes" value="Catatan" />
                        <textarea
                            id="notes"
                            rows={2}
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            placeholder="Opsional"
                        />
                        <InputError message={form.errors.notes} className="mt-1" />
                    </div>

                    <div className="flex items-center justify-end gap-3">
                        <PrimaryButton
                            type="submit"
                            disabled={
                                form.processing ||
                                !form.data.loan_id ||
                                !form.data.amount
                            }
                        >
                            {form.processing ? 'Menyimpan…' : 'Catat Pembayaran'}
                        </PrimaryButton>
                    </div>
                </form>

                <ReportTable
                    columns={columns}
                    rows={payments.data}
                    pagination={payments}
                    sort={sort}
                    onSort={handleSort}
                    routeName="shm.loans.payments.index"
                    emptyTitle="Belum ada pembayaran"
                    emptyDescription="Transaksi pembayaran pinjaman BSM Makro akan tampil di sini."
                />
            </div>
        </ShmLayout>
    );
}
