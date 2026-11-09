import React from "react";
import { Head, Link, useForm } from "@inertiajs/react";
import ShmLayout from "@/Layouts/ShmLayout";
import {
    ArrowLeft,
    CheckCircle,
    Clock,
    FileSpreadsheet,
    FileText,
    Send,
} from "lucide-react";

export default function ImportDetail({ batch, journalEntries, reportRows }) {
    const postForm = useForm();

    const handlePost = (entryId) => {
        if (!confirm("Apakah Anda yakin ingin memposting jurnal ini ke Buku Besar?")) {
            return;
        }
        postForm.post(route("shm.accounting-import.post.journal", entryId));
    };

    const formatRupiah = (val) => {
        return new Intl.NumberFormat("id-ID", {
            style: "currency",
            currency: "IDR",
            minimumFractionDigits: 2,
        }).format(val || 0);
    };

    return (
        <ShmLayout title={`Detail Batch #${batch.id} — Akuntansi SHM`}>
            <Head title={`Detail Batch #${batch.id}`} />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Link
                            href={route("shm.accounting-import.index")}
                            className="p-2 text-gray-500 hover:text-gray-700 bg-white border border-gray-300 rounded-lg shadow-sm transition-colors"
                        >
                            <ArrowLeft className="w-5 h-5" />
                        </Link>
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight text-gray-900">
                                Detail Batch Import #{batch.id}
                            </h1>
                            <p className="text-sm text-gray-500">
                                Berkas: <strong>{batch.filename}</strong> | Diimpor oleh: <strong>{batch.importer?.name || "-"}</strong> pada {new Date(batch.created_at).toLocaleString("id-ID")}
                            </p>
                        </div>
                    </div>

                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                        <CheckCircle className="w-4 h-4" />
                        {batch.status}
                    </span>
                </div>

                {/* Metadata Card */}
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4 bg-white p-4 rounded-xl border border-gray-200 shadow-sm text-sm">
                    <div>
                        <span className="text-gray-500 text-xs block">Tipe Import</span>
                        <strong className="text-gray-900 uppercase">
                            {batch.import_type === "journal" ? "Jurnal Transaksi" : `Laporan (${batch.report_type})`}
                        </strong>
                    </div>
                    <div>
                        <span className="text-gray-500 text-xs block">Total Baris / Record</span>
                        <strong className="text-gray-900">{batch.row_count} baris</strong>
                    </div>
                    <div>
                        <span className="text-gray-500 text-xs block">Konteks Akuntansi</span>
                        <strong className="text-emerald-700 font-mono">{batch.accounting_context}</strong>
                    </div>
                    <div>
                        <span className="text-gray-500 text-xs block">SHA-256 Checksum</span>
                        <span className="font-mono text-xs text-gray-600 truncate block" title={batch.checksum_hash}>
                            {batch.checksum_hash.slice(0, 20)}...
                        </span>
                    </div>
                </div>

                {/* Content table: Journals */}
                {batch.import_type === "journal" && journalEntries && (
                    <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden space-y-4">
                        <div className="p-4 border-b border-gray-200 flex items-center justify-between">
                            <h3 className="font-semibold text-gray-900 text-base">
                                Daftar Jurnal Hasil Import
                            </h3>
                            <span className="text-xs text-gray-500">
                                Status DRAFT membutuhkan aksi Posting manual.
                            </span>
                        </div>

                        <div className="divide-y divide-gray-200">
                            {journalEntries.data && journalEntries.data.length > 0 ? (
                                journalEntries.data.map((j) => (
                                    <div key={j.id} className="p-4 space-y-3 hover:bg-gray-50/50">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="flex items-center gap-3">
                                                <span className="font-mono font-bold text-gray-900 text-sm">
                                                    {j.journal_number}
                                                </span>
                                                <span className="text-xs text-gray-500">
                                                    Tanggal: {j.transaction_date}
                                                </span>
                                                <span className={`px-2 py-0.5 rounded text-xs font-semibold ${
                                                    j.status === "DRAFT"
                                                        ? "bg-amber-50 text-amber-700 border border-amber-200"
                                                        : "bg-emerald-50 text-emerald-700 border border-emerald-200"
                                                }`}>
                                                    {j.status}
                                                </span>
                                            </div>

                                            {j.status === "DRAFT" && (
                                                <button
                                                    type="button"
                                                    disabled={postForm.processing}
                                                    onClick={() => handlePost(j.id)}
                                                    className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-semibold shadow-sm transition-colors"
                                                >
                                                    <Send className="w-3.5 h-3.5" />
                                                    Posting ke Buku Besar
                                                </button>
                                            )}
                                        </div>

                                        <p className="text-xs text-gray-600 italic">
                                            {j.description}
                                        </p>

                                        {/* Lines */}
                                        <div className="bg-gray-50 rounded-lg p-2 overflow-x-auto border border-gray-200">
                                            <table className="min-w-full text-xs">
                                                <thead>
                                                    <tr className="text-gray-500 border-b border-gray-200">
                                                        <th className="text-left py-1 px-2">Kode Akun</th>
                                                        <th className="text-left py-1 px-2">Nama Akun</th>
                                                        <th className="text-left py-1 px-2">Keterangan</th>
                                                        <th className="text-right py-1 px-2">Debit</th>
                                                        <th className="text-right py-1 px-2">Kredit</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-gray-100">
                                                    {j.lines?.map((line) => (
                                                        <tr key={line.id}>
                                                            <td className="py-1 px-2 font-mono font-medium text-gray-900">{line.account?.code}</td>
                                                            <td className="py-1 px-2 text-gray-700">{line.account?.name}</td>
                                                            <td className="py-1 px-2 text-gray-500">{line.description || "-"}</td>
                                                            <td className="py-1 px-2 text-right font-medium text-gray-900">{formatRupiah(line.debit)}</td>
                                                            <td className="py-1 px-2 text-right font-medium text-gray-900">{formatRupiah(line.credit)}</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                ))
                            ) : (
                                <div className="p-8 text-center text-gray-500 text-sm">
                                    Tidak ada data jurnal.
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* Content table: Reports */}
                {batch.import_type === "report" && reportRows && (
                    <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                        <div className="p-4 border-b border-gray-200 flex items-center justify-between">
                            <h3 className="font-semibold text-gray-900 text-base">
                                Data Laporan Hasil Import
                            </h3>
                            <span className="text-xs text-purple-700 bg-purple-50 px-2.5 py-1 rounded-full border border-purple-200 font-medium">
                                Data Laporan Terisolasi (Non-Ledger)
                            </span>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 text-xs">
                                <thead className="bg-gray-50 text-gray-500 font-medium">
                                    <tr>
                                        <th className="px-4 py-2.5 text-left">Section</th>
                                        <th className="px-4 py-2.5 text-left">Kode Akun</th>
                                        <th className="px-4 py-2.5 text-left">Nama Akun / Uraian</th>
                                        <th className="px-4 py-2.5 text-right">Debit</th>
                                        <th className="px-4 py-2.5 text-right">Kredit</th>
                                        <th className="px-4 py-2.5 text-right">Saldo / Nominal</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 text-gray-700">
                                    {reportRows.data?.map((row) => (
                                        <tr key={row.id} className="hover:bg-gray-50/60">
                                            <td className="px-4 py-2 font-semibold text-gray-900">{row.section || "-"}</td>
                                            <td className="px-4 py-2 font-mono">{row.account_code || "-"}</td>
                                            <td className="px-4 py-2">{row.account_name || row.description || "-"}</td>
                                            <td className="px-4 py-2 text-right">{formatRupiah(row.debit)}</td>
                                            <td className="px-4 py-2 text-right">{formatRupiah(row.credit)}</td>
                                            <td className="px-4 py-2 text-right font-medium text-gray-900">{formatRupiah(row.amount)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </ShmLayout>
    );
}

