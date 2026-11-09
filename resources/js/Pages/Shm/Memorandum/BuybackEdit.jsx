import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import {
    ArrowLeft,
    FileText,
    UserRound,
    Building2,
    RefreshCw,
    FileCheck,
    Upload,
    Trash2,
    Download,
    CheckCircle2,
    AlertCircle,
} from 'lucide-react';

/**
 * Form Edit data Memorandum Buyback (Ticket 4 & 7).
 *
 * Rules:
 * - Pengajuan sumber bersifat READ-ONLY dan tidak dapat diganti (integrity protection).
 * - Tipe memorandum terkunci pada 'buyback'.
 * - Nasabah & Objek Jaminan ditampilkan sebagai context preview read-only.
 * - Form mengizinkan perubahan status memorandum (draft/submitted), catatan/notes, dan upload/ganti/hapus dokumen legalitas PDF.
 */
export default function BuybackEdit({ memorandum, documents = [], statuses, flash }) {
    const [form, setForm] = useState({
        status: memorandum.status ?? 'draft',
        notes: memorandum.notes ?? '',
    });
    const [errors, setErrors] = useState({});
    const [uploadingSlot, setUploadingSlot] = useState(null);
    const [uploadError, setUploadError] = useState(null);

    const submit = (event) => {
        event.preventDefault();
        setErrors({});

        // Validasi frontend tambahan untuk keramahan pengguna
        if (form.status === 'submitted' && !memorandum.is_documents_complete) {
            if (
                !confirm(
                    'Peringatan: Dokumen legalitas belum lengkap 5/5. Perubahan status menjadi Diajukan (Submitted) akan ditolak oleh sistem. Tetap lanjutkan?',
                )
            ) {
                return;
            }
        }

        router.put(
            route('shm.memorandums.buyback.update', memorandum.id),
            {
                status: form.status,
                notes: form.notes,
            },
            {
                onError: (e) => setErrors(e),
            },
        );
    };

    const handleFileUpload = (documentType, file) => {
        if (!file) return;

        if (file.type !== 'application/pdf') {
            setUploadError(`Berkas untuk ${documentType} harus berupa file PDF.`);
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            setUploadError(`Ukuran file PDF tidak boleh melebihi 10 MB.`);
            return;
        }

        setUploadingSlot(documentType);
        setUploadError(null);

        const formData = new FormData();
        formData.append('document_type', documentType);
        formData.append('file', file);

        router.post(route('shm.memorandums.buyback.documents.upload', memorandum.id), formData, {
            onFinish: () => setUploadingSlot(null),
            onError: (errs) => {
                setUploadError(errs.file || errs.document_type || 'Gagal mengunggah dokumen.');
            },
        });
    };

    const handleDeleteDocument = (documentId, documentLabel) => {
        if (
            confirm(
                `Apakah Anda yakin ingin menghapus berkas dokumen ${documentLabel}?`,
            )
        ) {
            router.delete(
                route('shm.memorandums.buyback.documents.destroy', [
                    memorandum.id,
                    documentId,
                ]),
            );
        }
    };

    return (
        <ShmLayout title="Edit Memorandum Buyback">
            <Head title="Edit Memorandum Buyback" />

            <div className="space-y-6">
                <div>
                    <Link
                        href={route('shm.memorandums.buyback.show', memorandum.id)}
                        className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" />
                        Kembali ke Detail Buyback
                    </Link>
                    <h1 className="text-xl font-semibold text-gray-800">Edit Memorandum Buyback</h1>
                    <p className="text-sm text-gray-500">
                        Perbarui status dokumen dan catatan pada berkas memorandum Buyback.
                    </p>
                </div>

                {flash?.error && (
                    <div className="rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        {flash.error}
                    </div>
                )}

                {flash?.success && (
                    <div className="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        {flash.success}
                    </div>
                )}

                {uploadError && (
                    <div className="rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-800 flex items-center justify-between">
                        <span>{uploadError}</span>
                        <button type="button" onClick={() => setUploadError(null)} className="text-xs underline font-medium">Tutup</button>
                    </div>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Form Section */}
                    <div className="lg:col-span-2 space-y-6">
                        <section className="rounded-lg bg-white p-6 shadow-sm border border-slate-200">
                            <form onSubmit={submit} className="space-y-5">
                                <div>
                                    <InputLabel htmlFor="status" value="Status Dokumen Memorandum *" />
                                    <select
                                        id="status"
                                        value={form.status}
                                        onChange={(e) => setForm({ ...form, status: e.target.value })}
                                        className="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary"
                                        required
                                    >
                                        {statuses.map((opt) => (
                                            <option key={opt.value} value={opt.value}>
                                                {opt.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.status} className="mt-1" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="notes" value="Catatan / Perjanjian Buyback" />
                                    <textarea
                                        id="notes"
                                        rows={5}
                                        value={form.notes}
                                        onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                        className="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary leading-relaxed"
                                        placeholder="Tambahkan catatan khusus memorandum jika diperlukan..."
                                    />
                                    <InputError message={errors.notes} className="mt-1" />
                                </div>

                                <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                                    <Link href={route('shm.memorandums.buyback.show', memorandum.id)}>
                                        <SecondaryButton type="button">Batal</SecondaryButton>
                                    </Link>
                                    <PrimaryButton type="submit">
                                        Simpan Perubahan
                                    </PrimaryButton>
                                </div>
                            </form>
                        </section>

                        {/* Dokumen Legalitas Buyback Upload Section (Ticket 7) */}
                        <section className="rounded-lg bg-white p-6 shadow-sm border border-slate-200">
                            <div className="flex items-center justify-between mb-4 border-b border-slate-100 pb-3">
                                <div>
                                    <h2 className="text-sm font-semibold uppercase tracking-wider text-slate-700 flex items-center gap-1.5">
                                        <FileCheck className="h-4 w-4 text-bsm-primary" />
                                        Kelola Dokumen Legalitas Buyback
                                    </h2>
                                    <p className="text-xs text-slate-500 mt-0.5">
                                        Unggah atau perbarui 5 dokumen legalitas format PDF (maks. 10 MB per file).
                                    </p>
                                </div>
                                <div>
                                    <span
                                        className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold ${
                                            memorandum.is_documents_complete
                                                ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                                                : 'bg-amber-50 text-amber-700 border border-amber-200'
                                        }`}
                                    >
                                        {memorandum.is_documents_complete ? (
                                            <CheckCircle2 className="h-3.5 w-3.5" />
                                        ) : (
                                            <AlertCircle className="h-3.5 w-3.5" />
                                        )}
                                        {memorandum.documents_ratio} ({memorandum.is_documents_complete ? 'Lengkap' : 'Belum Lengkap'})
                                    </span>
                                </div>
                            </div>

                            <div className="space-y-4">
                                {documents.map((slot, index) => (
                                    <div
                                        key={slot.type}
                                        className={`p-4 rounded-lg border text-xs space-y-3 ${
                                            slot.is_uploaded
                                                ? 'bg-slate-50/70 border-slate-200'
                                                : 'bg-rose-50/30 border-rose-200'
                                        }`}
                                    >
                                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                            <div className="flex items-center gap-2">
                                                <span className="font-semibold text-slate-800 text-sm">
                                                    {index + 1}. {slot.label}
                                                </span>
                                                <span
                                                    className={`px-2 py-0.5 rounded text-[10px] font-medium ${
                                                        slot.is_uploaded
                                                            ? 'bg-emerald-100 text-emerald-800'
                                                            : 'bg-rose-100 text-rose-800'
                                                    }`}
                                                >
                                                    {slot.is_uploaded ? 'Sudah Diunggah' : 'Belum Diunggah'}
                                                </span>
                                            </div>

                                            {slot.is_uploaded && (
                                                <div className="flex items-center gap-2">
                                                    <a
                                                        href={route('shm.memorandums.buyback.documents.download', [
                                                            memorandum.id,
                                                            slot.document.id,
                                                        ])}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="inline-flex items-center gap-1 rounded bg-white px-2 py-1 text-xs font-medium text-bsm-primary border border-slate-300 hover:bg-slate-50 shadow-sm"
                                                    >
                                                        <Download className="h-3 w-3" />
                                                        Unduh PDF
                                                    </a>
                                                    {memorandum.status !== 'submitted' && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleDeleteDocument(slot.document.id, slot.label)}
                                                            className="inline-flex items-center gap-1 rounded bg-white px-2 py-1 text-xs font-medium text-rose-600 border border-rose-200 hover:bg-rose-50 shadow-sm"
                                                        >
                                                            <Trash2 className="h-3 w-3" />
                                                            Hapus
                                                        </button>
                                                    )}
                                                </div>
                                            )}
                                        </div>

                                        {slot.is_uploaded && (
                                            <p className="text-slate-500 text-[11px]">
                                                Berkas saat ini: <span className="font-medium text-slate-700">{slot.document.file_name}</span> ({(slot.document.file_size / 1024).toFixed(1)} KB)
                                            </p>
                                        )}

                                        {/* Upload / Ganti file input */}
                                        <div className="flex items-center gap-3 pt-2 border-t border-slate-100">
                                            <label className="inline-flex items-center gap-1.5 cursor-pointer rounded bg-white px-3 py-1.5 text-xs font-medium text-slate-700 border border-slate-300 hover:bg-slate-50 shadow-sm">
                                                <Upload className="h-3.5 w-3.5 text-slate-500" />
                                                <span>{slot.is_uploaded ? 'Ganti File PDF' : 'Pilih File PDF'}</span>
                                                <input
                                                    type="file"
                                                    accept=".pdf,application/pdf"
                                                    disabled={uploadingSlot === slot.type}
                                                    onChange={(e) => {
                                                        const file = e.target.files?.[0];
                                                        if (file) {
                                                            handleFileUpload(slot.type, file);
                                                        }
                                                        e.target.value = '';
                                                    }}
                                                    className="hidden"
                                                />
                                            </label>
                                            {uploadingSlot === slot.type && (
                                                <span className="text-xs text-indigo-600 font-medium animate-pulse">
                                                    Sedang mengunggah berkas...
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    </div>

                    {/* Read-only Context Section */}
                    <div className="space-y-6">
                        <div className="rounded-lg bg-white p-5 shadow-sm border border-slate-200 text-xs space-y-4">
                            <h2 className="text-sm font-semibold uppercase tracking-wider text-slate-500 mb-2 flex items-center gap-1.5">
                                <FileText className="h-4 w-4 text-bsm-primary" />
                                Sumber Pengajuan (Terkunci)
                            </h2>

                            <div>
                                <span className="text-slate-400 block mb-0.5">Nomor Pengajuan</span>
                                <span className="font-semibold text-slate-800 text-sm">
                                    {memorandum.application_number}
                                </span>
                            </div>

                            <div>
                                <span className="text-slate-400 block mb-0.5">Tipe Pengajuan</span>
                                <span className="inline-flex items-center gap-1 font-semibold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded border border-indigo-200">
                                    <RefreshCw className="h-3 w-3" /> Buyback
                                </span>
                            </div>

                            <div>
                                <span className="text-slate-400 block mb-0.5">Status Pengajuan</span>
                                <span className="font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-100">
                                    {memorandum.application_status_label}
                                </span>
                            </div>

                            <div className="pt-3 border-t border-slate-100">
                                <span className="text-slate-400 block mb-1 font-medium flex items-center gap-1">
                                    <UserRound className="h-3 w-3" /> Data Nasabah
                                </span>
                                <div className="bg-slate-50 p-2.5 rounded border border-slate-100 space-y-1">
                                    <div className="font-semibold text-slate-700">{memorandum.customer_name ?? '-'}</div>
                                    <div className="text-slate-500">No: {memorandum.customer_number ?? '-'}</div>
                                </div>
                            </div>

                            <div className="pt-3 border-t border-slate-100">
                                <span className="text-slate-400 block mb-1 font-medium flex items-center gap-1">
                                    <Building2 className="h-3 w-3" /> Objek Terdaftar
                                </span>
                                <div className="bg-slate-50 p-2.5 rounded border border-slate-100 text-slate-600">
                                    Total Objek: <span className="font-semibold">{memorandum.properties_count ?? 0}</span> sertifikat
                                </div>
                            </div>

                            <div className="rounded bg-amber-50 p-2.5 text-amber-800 text-[11px] leading-relaxed border border-amber-200">
                                Catatan: Pengajuan asal dan tipe memorandum terkunci secara permanen untuk menjaga integritas data riwayat.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </ShmLayout>
    );
}

