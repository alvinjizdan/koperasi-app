import React, { useState } from "react";
import { Head, Link, useForm } from "@inertiajs/react";
import ShmLayout from "@/Layouts/ShmLayout";
import {
    UploadCloud,
    FileSpreadsheet,
    FileText,
    CheckCircle,
    AlertCircle,
    Download,
    Eye,
    Clock,
    Send,
} from "lucide-react";

export default function ImportCenter({ batches, filters, availableReportTypes }) {
    const [activeTab, setActiveTab] = useState("journal"); // "journal" | "report"
    const [previewData, setPreviewData] = useState(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [previewError, setPreviewError] = useState(null);

    // Form for Journal Import
    const journalForm = useForm({
        file: null,
    });

    // Form for Report Import
    const reportForm = useForm({
        file: null,
        report_type: availableReportTypes[0]?.value || "balance_sheet",
    });

    const handleJournalFileChange = async (e) => {
        const file = e.target.files[0];
        if (!file) return;

        journalForm.setData("file", file);
        setPreviewLoading(true);
        setPreviewError(null);
        setPreviewData(null);

        const formData = new FormData();
        formData.append("file", file);

        try {
            const res = await axios.post(route("shm.accounting-import.preview.journal"), formData, {
                headers: { "Content-Type": "multipart/form-data" },
            });
            setPreviewData(res.data);
        } catch (err) {
            setPreviewError(
                err.response?.data?.message ||
                err.response?.data?.errors?.file?.[0] ||
                "Gagal membaca berkas Excel."
            );
        } finally {
            setPreviewLoading(false);
        }
    };

    const handleReportFileChange = async (e) => {
        const file = e.target.files[0];
        if (!file) return;

        reportForm.setData("file", file);
        setPreviewLoading(true);
        setPreviewError(null);
        setPreviewData(null);

        const formData = new FormData();
        formData.append("file", file);
        formData.append("report_type", reportForm.data.report_type);

        try {
            const res = await axios.post(route("shm.accounting-import.preview.report"), formData, {
                headers: { "Content-Type": "multipart/form-data" },
            });
            setPreviewData(res.data);
        } catch (err) {
            setPreviewError(
                err.response?.data?.message ||
                err.response?.data?.errors?.file?.[0] ||
                "Gagal membaca berkas laporan Excel."
            );
        } finally {
            setPreviewLoading(false);
        }
    };

    const submitJournal = (e) => {
        e.preventDefault();
        journalForm.post(route("shm.accounting-import.store.journal"), {
            onSuccess: () => {
                setPreviewData(null);
                journalForm.reset();
            },
        });
    };

    const submitReport = (e) => {
        e.preventDefault();
        reportForm.post(route("shm.accounting-import.store.report"), {
            onSuccess: () => {
                setPreviewData(null);
                reportForm.reset();
            },
        });
    };

    const formatRupiah = (val) => {
        return new Intl.NumberFormat("id-ID", {
            style: "currency",
            currency: "IDR",
            minimumFractionDigits: 2,
        }).format(val || 0);
    };

    return (
        <ShmLayout title="Akuntansi SHM — Import Center">
            <Head title="Import Center Akuntansi SHM" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-gray-900">
                            Import Center Akuntansi SHM
                        </h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Kelola data jurnal transaksi dan laporan keuangan berbasis import Excel (.xlsx).
                            Semua jurnal diimpor dalam status <strong>DRAFT</strong> untuk verifikasi manual.
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <a
                            href={route("shm.accounting-import.template.journal")}
                            className="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-emerald-700 bg-emerald-50 rounded-lg border border-emerald-200 hover:bg-emerald-100 transition-colors shadow-sm"
                        >
                            <Download className="w-4 h-4" />
                            Template Jurnal (.xlsx)
                        </a>
                    </div>
                </div>

                {/* Import Box */}
                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <div className="flex border-b border-gray-200 mb-6">
                        <button
                            type="button"
                            onClick={() => {
                                setActiveTab("journal");
                                setPreviewData(null);
                                setPreviewError(null);
                            }}
                            className={`pb-3 px-4 font-semibold text-sm border-b-2 flex items-center gap-2 ${
                                activeTab === "journal"
                                    ? "border-emerald-600 text-emerald-600"
                                    : "border-transparent text-gray-500 hover:text-gray-700"
                            }`}
                        >
                            <FileSpreadsheet className="w-4 h-4" />
                            Import Jurnal Transaksi
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                setActiveTab("report");
                                setPreviewData(null);
                                setPreviewError(null);
                            }}
                            className={`pb-3 px-4 font-semibold text-sm border-b-2 flex items-center gap-2 ${
                                activeTab === "report"
                                    ? "border-emerald-600 text-emerald-600"
                                    : "border-transparent text-gray-500 hover:text-gray-700"
                            }`}
                        >
                            <FileText className="w-4 h-4" />
                            Import Laporan Keuangan
                        </button>
                    </div>

                    {activeTab === "journal" ? (
                        <form onSubmit={submitJournal} className="space-y-4">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">
                                    Pilih Berkas Jurnal XLSX
                                </label>
                                <input
                                    type="file"
                                    accept=".xlsx"
                                    onChange={handleJournalFileChange}
                                    className="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 border border-gray-300 rounded-lg p-2"
                                />
                                <p className="mt-1 text-xs text-gray-500">
                                    Format: .xlsx, maksimal 10 MB. Akun wajib berasal dari Chart of Accounts (COA) SHM.
                                </p>
                            </div>

                            {previewLoading && (
                                <div className="p-4 bg-gray-50 text-gray-600 rounded-lg text-sm flex items-center gap-2">
                                    <Clock className="w-4 h-4 animate-spin text-emerald-600" />
                                    Sedang membaca dan memvalidasi berkas Excel...
                                </div>
                            )}

                            {previewError && (
                                <div className="p-4 bg-rose-50 text-rose-700 border border-rose-200 rounded-lg text-sm flex items-start gap-2">
                                    <AlertCircle className="w-5 h-5 flex-shrink-0 mt-0.5" />
                                    <div>{previewError}</div>
                                </div>
                            )}

                            {previewData && (
                                <div className="mt-4 p-4 border border-emerald-200 bg-emerald-50/50 rounded-xl space-y-4">
                                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-emerald-200 pb-3">
                                        <div>
                                            <h4 className="font-semibold text-emerald-900 text-sm">
                                                Preview: {previewData.filename}
                                            </h4>
                                            <p className="text-xs text-gray-500">
                                                Checksum: <span className="font-mono">{previewData.checksum.slice(0, 16)}...</span>
                                            </p>
                                        </div>
                                        <div className="flex gap-4 text-xs font-medium">
                                            <span>Jurnal: <strong>{previewData.total_journals}</strong></span>
                                            <span>Baris: <strong>{previewData.total_lines}</strong></span>
                                            <span>Total Debit: <strong>{formatRupiah(previewData.total_debit)}</strong></span>
                                            <span>Total Kredit: <strong>{formatRupiah(previewData.total_credit)}</strong></span>
                                            <span className={`px-2 py-0.5 rounded text-xs ${previewData.is_balanced ? "bg-emerald-100 text-emerald-800" : "bg-rose-100 text-rose-800"}`}>
                                                {previewData.is_balanced ? "Seimbang (Balanced)" : "Tidak Seimbang"}
                                            </span>
                                        </div>
                                    </div>

                                    {previewData.errors && previewData.errors.length > 0 && (
                                        <div className="p-3 bg-rose-50 border border-rose-200 rounded-lg">
                                            <p className="text-xs font-bold text-rose-800 mb-1">
                                                Ditemukan {previewData.errors.length} Kesalahan Baris:
                                            </p>
                                            <ul className="text-xs text-rose-700 list-disc list-inside space-y-1 max-h-40 overflow-y-auto">
                                                {previewData.errors.map((err, idx) => (
                                                    <li key={idx}>
                                                        Baris {err.row} [{err.journal_ref}]: {err.message}
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}

                                    {previewData.errors?.length === 0 && previewData.is_balanced && (
                                        <div className="flex justify-end pt-2">
                                            <button
                                                type="submit"
                                                disabled={journalForm.processing}
                                                className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-semibold transition-colors shadow-sm disabled:opacity-50"
                                            >
                                                <UploadCloud className="w-4 h-4" />
                                                {journalForm.processing ? "Sedang Mengimpor..." : "Konfirmasi & Simpan sebagai Jurnal DRAFT"}
                                            </button>
                                        </div>
                                    )}
                                </div>
                            )}
                        </form>
                    ) : (
                        <form onSubmit={submitReport} className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        Jenis Laporan
                                    </label>
                                    <select
                                        value={reportForm.data.report_type}
                                        onChange={(e) => reportForm.setData("report_type", e.target.value)}
                                        className="block w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-emerald-500 focus:border-emerald-500"
                                    >
                                        {availableReportTypes.map((t) => (
                                            <option key={t.value} value={t.value}>
                                                {t.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        Pilih Berkas Laporan XLSX
                                    </label>
                                    <input
                                        type="file"
                                        accept=".xlsx"
                                        onChange={handleReportFileChange}
                                        className="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 border border-gray-300 rounded-lg p-2"
                                    />
                                </div>
                            </div>

                            {previewLoading && (
                                <div className="p-4 bg-gray-50 text-gray-600 rounded-lg text-sm flex items-center gap-2">
                                    <Clock className="w-4 h-4 animate-spin text-emerald-600" />
                                    Sedang membaca berkas laporan...
                                </div>
                            )}

                            {previewError && (
                                <div className="p-4 bg-rose-50 text-rose-700 border border-rose-200 rounded-lg text-sm flex items-start gap-2">
                                    <AlertCircle className="w-5 h-5 flex-shrink-0 mt-0.5" />
                                    <div>{previewError}</div>
                                </div>
                            )}

                            {previewData && (
                                <div className="mt-4 p-4 border border-emerald-200 bg-emerald-50/50 rounded-xl space-y-4">
                                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-emerald-200 pb-3">
                                        <div>
                                            <h4 className="font-semibold text-emerald-900 text-sm">
                                                Preview: {previewData.filename}
                                            </h4>
                                            <p className="text-xs text-gray-500">
                                                Tipe: <strong>{previewData.report_type}</strong> | Tanggal: <strong>{previewData.report_date}</strong>
                                            </p>
                                        </div>
                                        <div className="text-xs font-medium">
                                            Total Baris Ditemukan: <strong>{previewData.total_rows}</strong>
                                        </div>
                                    </div>

                                    {previewData.errors && previewData.errors.length > 0 ? (
                                        <div className="p-3 bg-rose-50 border border-rose-200 rounded-lg text-xs text-rose-700">
                                            {previewData.errors[0]?.message}
                                        </div>
                                    ) : (
                                        <div className="flex justify-end pt-2">
                                            <button
                                                type="submit"
                                                disabled={reportForm.processing}
                                                className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-semibold transition-colors shadow-sm disabled:opacity-50"
                                            >
                                                <UploadCloud className="w-4 h-4" />
                                                {reportForm.processing ? "Sedang Mengimpor..." : "Konfirmasi & Simpan Laporan"}
                                            </button>
                                        </div>
                                    )}
                                </div>
                            )}
                        </form>
                    )}
                </div>

                {/* Batch History List */}
                <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div className="p-4 border-b border-gray-200 flex items-center justify-between">
                        <h3 className="font-semibold text-gray-900 text-base">
                            Riwayat Batch Import
                        </h3>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50 text-gray-500 font-medium">
                                <tr>
                                    <th className="px-4 py-3 text-left">ID</th>
                                    <th className="px-4 py-3 text-left">Tipe</th>
                                    <th className="px-4 py-3 text-left">Nama Berkas</th>
                                    <th className="px-4 py-3 text-left">Pengunggah</th>
                                    <th className="px-4 py-3 text-center">Jumlah Baris</th>
                                    <th className="px-4 py-3 text-center">Status</th>
                                    <th className="px-4 py-3 text-left">Waktu Import</th>
                                    <th className="px-4 py-3 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 text-gray-700">
                                {batches.data && batches.data.length > 0 ? (
                                    batches.data.map((b) => (
                                        <tr key={b.id} className="hover:bg-gray-50/80 transition-colors">
                                            <td className="px-4 py-3 font-mono text-xs">#{b.id}</td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex px-2 py-0.5 rounded text-xs font-medium ${
                                                    b.import_type === "journal"
                                                        ? "bg-blue-50 text-blue-700 border border-blue-200"
                                                        : "bg-purple-50 text-purple-700 border border-purple-200"
                                                }`}>
                                                    {b.import_type === "journal" ? "Jurnal Transaksi" : `Laporan (${b.report_type})`}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 font-medium text-gray-900 max-w-xs truncate" title={b.filename}>
                                                {b.filename}
                                            </td>
                                            <td className="px-4 py-3 text-xs text-gray-600">
                                                {b.importer?.name || "-"}
                                            </td>
                                            <td className="px-4 py-3 text-center font-medium">
                                                {b.row_count}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                <span className="inline-flex items-center gap-1 text-emerald-700 text-xs font-medium">
                                                    <CheckCircle className="w-3.5 h-3.5" />
                                                    {b.status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">
                                                {new Date(b.created_at).toLocaleString("id-ID")}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Link
                                                    href={route("shm.accounting-import.show", b.id)}
                                                    className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded border border-gray-300 transition-colors"
                                                >
                                                    <Eye className="w-3.5 h-3.5" />
                                                    Detail
                                                </Link>
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="8" className="px-4 py-8 text-center text-gray-500">
                                            Belum ada riwayat batch import.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </ShmLayout>
    );
}

