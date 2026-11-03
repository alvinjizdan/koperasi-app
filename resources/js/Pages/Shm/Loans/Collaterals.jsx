import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import PrimaryButton from '@/Components/PrimaryButton';
import { formatRupiah, formatDate } from '@/utils/format';
import { ShieldCheck, Search, FileText, Image as ImageIcon, Eye, X } from 'lucide-react';

export default function Collaterals({ collaterals, availableTypes, filters }) {
    const [search, setSearch] = useState(filters.search || '');
    const [type, setType] = useState(filters.type || 'all');
    const [selectedDetail, setSelectedDetail] = useState(null);

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('shm.loans.collaterals.index'),
            {
                search: search || undefined,
                type: type !== 'all' ? type : undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const columns = [
        {
            key: 'index',
            label: 'No',
            align: 'center',
            render: (_row, index) => {
                const currentPage = collaterals.current_page || 1;
                const perPage = collaterals.per_page || 15;
                return (currentPage - 1) * perPage + index + 1;
            },
        },
        {
            key: 'customer',
            label: 'Nasabah',
            render: (row) => (
                <div>
                    <p className="font-semibold text-slate-800">{row.customer?.name || '-'}</p>
                    <p className="text-xs font-mono text-slate-500">
                        {row.customer?.customer_number || '-'}
                        {row.customer?.nik && ` • NIK: ${row.customer.nik}`}
                    </p>
                </div>
            ),
        },
        {
            key: 'loan',
            label: 'Pinjaman',
            render: (row) => (
                <div>
                    {row.loan ? (
                        <Link
                            href={route('shm.loans.show', row.loan.id)}
                            className="font-semibold text-indigo-600 hover:underline"
                        >
                            {row.loan.loan_number}
                        </Link>
                    ) : (
                        <span className="text-slate-400">-</span>
                    )}
                    {row.loan?.product_name && (
                        <p className="text-xs text-slate-500">{row.loan.product_name}</p>
                    )}
                </div>
            ),
        },
        {
            key: 'collateral_type',
            label: 'Jenis Jaminan',
            render: (row) => (
                <span className="inline-flex items-center rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                    {row.collateral_type}
                </span>
            ),
        },
        {
            key: 'collateral_name',
            label: 'Deskripsi Jaminan',
            render: (row) => (
                <div>
                    <p className="font-medium text-slate-800">{row.collateral_name}</p>
                    {row.notes && (
                        <p className="text-xs text-slate-500 line-clamp-1">{row.notes}</p>
                    )}
                </div>
            ),
        },
        {
            key: 'estimated_value',
            label: 'Nilai Jaminan',
            align: 'right',
            render: (row) => (
                <span className="font-mono font-medium text-slate-700">
                    {formatRupiah(row.estimated_value)}
                </span>
            ),
        },
        {
            key: 'binding_status',
            label: 'Status Pengikatan',
            render: (row) => (
                <div>
                    <span className="text-xs text-slate-700 font-medium capitalize">
                        {row.binding_status || '-'}
                    </span>
                    {row.binding_date && (
                        <p className="text-[11px] text-slate-400">{formatDate(row.binding_date)}</p>
                    )}
                </div>
            ),
        },
        {
            key: 'action',
            label: 'Aksi',
            align: 'right',
            render: (row) => (
                <button
                    type="button"
                    onClick={() => setSelectedDetail(row)}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition-colors"
                >
                    <Eye className="h-3.5 w-3.5 text-slate-500" />
                    <span>Detail</span>
                </button>
            ),
        },
    ];

    return (
        <ShmLayout title="Jaminan Pinjaman BSM Makro">
            <div className="space-y-6">
                <div>
                    <h1 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
                        <ShieldCheck className="h-6 w-6 text-emerald-600" />
                        Jaminan Pinjaman BSM Makro
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Daftar jaminan yang terkait dengan pinjaman nasabah BSM Makro. Menunjukkan hubungan terintegrasi: Nasabah &rarr; Pinjaman &rarr; Jaminan.
                    </p>
                </div>

                <form onSubmit={applyFilters} className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                    <div className="flex-1 min-w-[240px]">
                        <label className="text-xs font-semibold text-slate-700">Pencarian</label>
                        <input
                            type="text"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Cari nama nasabah, no. nasabah, no. pinjaman, atau nama jaminan..."
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-bsm-primary focus:outline-none"
                        />
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-700">Jenis Jaminan</label>
                        <select
                            value={type}
                            onChange={(event) => setType(event.target.value)}
                            className="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-bsm-primary focus:outline-none"
                        >
                            <option value="all">Semua Jenis</option>
                            {availableTypes.map((t) => (
                                <option key={t} value={t}>
                                    {t}
                                </option>
                            ))}
                        </select>
                    </div>
                    <PrimaryButton type="submit">
                        <Search className="mr-2 h-4 w-4" />
                        Cari
                    </PrimaryButton>
                </form>

                <ReportTable
                    columns={columns}
                    rows={collaterals.data}
                    pagination={collaterals}
                    emptyMessage="Belum ada data jaminan pinjaman yang terdaftar."
                />
            </div>

            {/* Modal Detail Jaminan */}
            {selectedDetail && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-xs">
                    <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl border border-slate-200">
                        <div className="flex items-start justify-between border-b border-slate-100 pb-3">
                            <div>
                                <h3 className="text-base font-bold text-slate-900">
                                    Detail Objek Jaminan
                                </h3>
                                <p className="text-xs text-slate-500">
                                    {selectedDetail.collateral_name}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelectedDetail(null)}
                                className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                            >
                                <X className="h-5 w-5" />
                            </button>
                        </div>

                        <div className="mt-4 space-y-3 text-xs text-slate-700">
                            <div className="grid grid-cols-2 gap-2 rounded-lg bg-slate-50 p-3">
                                <div>
                                    <p className="text-slate-500">Nasabah:</p>
                                    <p className="font-semibold text-slate-900">{selectedDetail.customer?.name}</p>
                                    <p className="font-mono text-slate-600">{selectedDetail.customer?.customer_number}</p>
                                </div>
                                <div>
                                    <p className="text-slate-500">Nomor Pinjaman:</p>
                                    <p className="font-semibold text-indigo-600">{selectedDetail.loan?.loan_number}</p>
                                    <p className="text-slate-600">{selectedDetail.loan?.product_name}</p>
                                </div>
                            </div>

                            <div className="space-y-2 border-t border-slate-100 pt-3">
                                <div className="flex justify-between">
                                    <span className="text-slate-500">Jenis Jaminan:</span>
                                    <span className="font-medium">{selectedDetail.collateral_type}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">Nilai Perkiraan:</span>
                                    <span className="font-semibold font-mono">{formatRupiah(selectedDetail.estimated_value)}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">Nilai Hak Tanggungan:</span>
                                    <span className="font-semibold font-mono">{formatRupiah(selectedDetail.rights_value)}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">Status Pengikatan:</span>
                                    <span className="font-medium capitalize">{selectedDetail.binding_status || '-'}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">Tanggal Pengikatan:</span>
                                    <span>{selectedDetail.binding_date ? formatDate(selectedDetail.binding_date) : '-'}</span>
                                </div>
                                <div>
                                    <span className="text-slate-500 block mb-1">Catatan / Keterangan:</span>
                                    <p className="rounded-md bg-slate-50 p-2.5 text-slate-800 leading-relaxed">
                                        {selectedDetail.notes || 'Tidak ada catatan tambahan.'}
                                    </p>
                                </div>
                            </div>

                            {/* Foto & Dokumen Jaminan jika ada */}
                            {(selectedDetail.photo_url || selectedDetail.document_url) && (
                                <div className="border-t border-slate-100 pt-3 flex gap-2">
                                    {selectedDetail.photo_url && (
                                        <a
                                            href={selectedDetail.photo_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                        >
                                            <ImageIcon className="h-4 w-4 text-slate-500" />
                                            <span>Lihat Foto</span>
                                        </a>
                                    )}
                                    {selectedDetail.document_url && (
                                        <a
                                            href={selectedDetail.document_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                        >
                                            <FileText className="h-4 w-4 text-slate-500" />
                                            <span>Lihat Dokumen PDF</span>
                                        </a>
                                    )}
                                </div>
                            )}
                        </div>

                        <div className="mt-6 flex justify-end">
                            <button
                                type="button"
                                onClick={() => setSelectedDetail(null)}
                                className="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                            >
                                Tutup
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </ShmLayout>
    );
}

