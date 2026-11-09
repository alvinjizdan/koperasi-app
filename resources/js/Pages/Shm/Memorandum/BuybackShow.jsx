import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import StatusBadge from '@/Components/Admin/StatusBadge';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import MemorandumApprovalActions from '@/Components/Shm/MemorandumApprovalActions';
import MemorandumApprovalCard from '@/Components/Shm/MemorandumApprovalCard';
import MemorandumApprovalTimeline from '@/Components/Shm/MemorandumApprovalTimeline';
import { formatDateTime } from '@/utils/format';
import {
    ArrowLeft,
    UserRound,
    FileText,
    ClipboardCheck,
    ScrollText,
    MapPin,
    Building2,
    RefreshCw,
    Pencil,
    Trash2,
    FileCheck,
    Download,
    AlertCircle,
    CheckCircle2,
} from 'lucide-react';

/**
 * Detail data Memorandum Buyback (Ticket 4 & 7).
 *
 * Menampilkan:
 * 1. Data Memorandum: ID, tipe (Buyback), status (draft/submitted), naskah/catatan, waktu submit.
 * 2. Dokumen Legalitas Buyback: 5 slot (SHM, Akta Buyback, Kuasa Awal, PPJB, Pengosongan).
 * 3. Data Pengajuan Asal (Read-only): No Pengajuan (type Buyback), Marketing, Tanggal.
 * 4. Data Nasabah Asal (Read-only): Nama, NIK, No Telepon, Alamat.
 * 5. Data Objek Asal (Read-only): Tipe Sertifikat, Nomor Sertifikat, Pemilik, Alamat.
 * 6. Riwayat Audit & Tombol Aksi (Edit, Hapus).
 */
export default function BuybackShow({
    memorandum,
    documents = [],
    application,
    properties,
    credit_check,
    history,
    approval_histories = [],
    can_edit,
    can_delete,
    can_submit,
    can_start_review,
    can_approve,
    can_reject,
    flash,
}) {
    const customer = application?.customer;

    const handleDelete = () => {
        if (
            confirm(
                `Apakah Anda yakin ingin menghapus data Memorandum Buyback ini? Data pengajuan ${application?.application_number ?? ''} tidak akan terhapus.`,
            )
        ) {
            router.delete(route('shm.memorandums.buyback.destroy', memorandum.id));
        }
    };

    return (
        <ShmLayout title={`Memorandum Buyback — ${application?.application_number ?? memorandum.id}`}>
            <Head title={`Memorandum Buyback — ${application?.application_number ?? memorandum.id}`} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link
                            href={route('shm.memorandums.buyback.index')}
                            className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"
                        >
                            <ArrowLeft className="h-3.5 w-3.5" />
                            Kembali ke Daftar Buyback
                        </Link>
                        <h1 className="text-xl font-semibold text-gray-800">
                            Memorandum Buyback — {application?.application_number ?? '-'}
                        </h1>
                        <p className="mt-1 flex items-center gap-2 text-sm text-gray-500">
                            <span className="rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-0.5 text-[11px] font-semibold text-indigo-700 uppercase flex items-center gap-1">
                                <RefreshCw className="h-3 w-3" />
                                {memorandum.type_label}
                            </span>
                            <StatusBadge
                                status={
                                    memorandum.status === 'approved'
                                        ? 'COMPLETED'
                                        : memorandum.status === 'under_review'
                                        ? 'PROCESSING'
                                        : memorandum.status === 'submitted'
                                        ? 'PENDING'
                                        : 'DRAFT'
                                }
                                label={memorandum.status_label}
                            />
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <MemorandumApprovalActions
                            memorandum={memorandum}
                            can_submit={can_submit}
                            can_start_review={can_start_review}
                            can_approve={can_approve}
                            can_reject={can_reject}
                        />
                        {can_edit && (
                            <Link href={route('shm.memorandums.buyback.edit', memorandum.id)}>
                                <PrimaryButton className="inline-flex items-center gap-1.5 text-xs">
                                    <Pencil className="h-3.5 w-3.5" />
                                    Edit Data
                                </PrimaryButton>
                            </Link>
                        )}
                        {can_delete && (
                            <SecondaryButton
                                onClick={handleDelete}
                                className="inline-flex items-center gap-1.5 text-xs text-rose-600 hover:text-rose-700 border-rose-200 hover:bg-rose-50"
                            >
                                <Trash2 className="h-3.5 w-3.5 text-rose-500" />
                                Hapus
                            </SecondaryButton>
                        )}
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                        {flash.success}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Main Content (Memorandum Data) */}
                    <div className="lg:col-span-2 space-y-6">
                        <section className="rounded-lg bg-white p-5 shadow-sm border border-slate-200">
                            <h2 className="text-sm font-semibold uppercase tracking-wider text-slate-600 mb-3 flex items-center gap-1.5">
                                <ScrollText className="h-4 w-4 text-bsm-primary" />
                                Catatan & Rincian Perjanjian Buyback
                            </h2>

                            <div className="rounded-md border border-slate-100 bg-slate-50 p-4 min-h-[120px] text-sm text-slate-800 whitespace-pre-wrap leading-relaxed">
                                {memorandum.notes ? (
                                    memorandum.notes
                                ) : (
                                    <span className="text-slate-400 italic">Tidak ada catatan memorandum khusus.</span>
                                )}
                            </div>

                            <dl className="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4 border-t border-slate-100 pt-4 text-xs">
                                <div>
                                    <dt className="text-slate-400">Dibuat Oleh</dt>
                                    <dd className="font-medium text-slate-800 mt-0.5">{memorandum.creator ?? '-'}</dd>
                                </div>
                                <div>
                                    <dt className="text-slate-400">Tanggal Dibuat</dt>
                                    <dd className="font-medium text-slate-800 mt-0.5">{formatDateTime(memorandum.created_at)}</dd>
                                </div>
                                <div>
                                    <dt className="text-slate-400">Tanggal Disubmit</dt>
                                    <dd className="font-medium text-slate-800 mt-0.5">
                                        {memorandum.submitted_at ? formatDateTime(memorandum.submitted_at) : '-'}
                                    </dd>
                                </div>
                            </dl>
                        </section>

                        {/* Dokumen Legalitas Buyback Section (Ticket 7) */}
                        <section className="rounded-lg bg-white p-5 shadow-sm border border-slate-200">
                            <div className="flex items-center justify-between mb-4 border-b border-slate-100 pb-3">
                                <div>
                                    <h2 className="text-sm font-semibold uppercase tracking-wider text-slate-600 flex items-center gap-1.5">
                                        <FileCheck className="h-4 w-4 text-bsm-primary" />
                                        Dokumen Legalitas Buyback
                                    </h2>
                                    <p className="text-xs text-slate-500 mt-0.5">
                                        Kelima dokumen wajib lengkap dan terverifikasi untuk proses Buyback.
                                    </p>
                                </div>
                                <div className="text-right">
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

                            <div className="space-y-3">
                                {documents.map((slot, index) => (
                                    <div
                                        key={slot.type}
                                        className={`flex flex-col sm:flex-row sm:items-center justify-between p-3.5 rounded-lg border text-xs gap-3 ${
                                            slot.is_uploaded
                                                ? 'bg-slate-50 border-slate-200'
                                                : 'bg-rose-50/40 border-rose-200'
                                        }`}
                                    >
                                        <div className="space-y-1">
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
                                                <p className="text-slate-500 text-[11px]">
                                                    Berkas: <span className="font-medium text-slate-700">{slot.document.file_name}</span> ({(slot.document.file_size / 1024).toFixed(1)} KB)
                                                    {slot.document.uploader_name && (
                                                        <span className="ml-2 text-slate-400">• Diunggah oleh {slot.document.uploader_name}</span>
                                                    )}
                                                </p>
                                            )}
                                        </div>

                                        <div className="flex items-center gap-2">
                                            {slot.is_uploaded ? (
                                                <a
                                                    href={route('shm.memorandums.buyback.documents.download', [
                                                        memorandum.id,
                                                        slot.document.id,
                                                    ])}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex items-center gap-1 rounded bg-white px-2.5 py-1.5 text-xs font-medium text-bsm-primary border border-slate-300 hover:bg-slate-50 shadow-sm"
                                                    title="Lihat / Unduh Dokumen PDF"
                                                >
                                                    <Download className="h-3.5 w-3.5" />
                                                    Unduh PDF
                                                </a>
                                            ) : (
                                                <Link
                                                    href={route('shm.memorandums.buyback.edit', memorandum.id)}
                                                    className="inline-flex items-center gap-1 rounded bg-indigo-50 px-2.5 py-1.5 text-xs font-medium text-indigo-700 border border-indigo-200 hover:bg-indigo-100"
                                                >
                                                    <Pencil className="h-3.5 w-3.5" />
                                                    Unggah Berkas
                                                </Link>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>

                        {/* Objek Jaminan / Aset Section */}
                        <section className="rounded-lg bg-white p-5 shadow-sm border border-slate-200">
                            <h2 className="text-sm font-semibold uppercase tracking-wider text-slate-600 mb-3 flex items-center gap-1.5">
                                <Building2 className="h-4 w-4 text-bsm-primary" />
                                Rincian Objek Agunan / Aset Terkait
                            </h2>

                            {properties && properties.length > 0 ? (
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    {properties.map((prop, idx) => (
                                        <div key={prop.id || idx} className="rounded-lg border border-slate-200 p-3.5 bg-slate-50 text-xs space-y-1.5">
                                            <div className="flex items-center justify-between">
                                                <span className="font-bold text-slate-800 text-sm">
                                                    {prop.certificate_type || 'SHM'}
                                                </span>
                                                <span className="font-mono text-slate-600 bg-white px-2 py-0.5 rounded border border-slate-200">
                                                    {prop.certificate_number || '-'}
                                                </span>
                                            </div>
                                            <div>
                                                <span className="text-slate-400">Atas Nama: </span>
                                                <span className="font-medium text-slate-700">{prop.owner_name || '-'}</span>
                                            </div>
                                            <div className="flex items-start gap-1 text-slate-600 pt-1">
                                                <MapPin className="h-3.5 w-3.5 mt-0.5 shrink-0 text-slate-400" />
                                                <span>{prop.address || '-'}</span>
                                            </div>
                                            {prop.notes && (
                                                <p className="text-[11px] text-slate-500 italic pt-1 border-t border-slate-200">
                                                    {prop.notes}
                                                </p>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-xs text-slate-400 italic">Tidak ada rincian objek terdaftar pada pengajuan ini.</p>
                            )}
                        </section>

                        {/* Informasi Approval (Ticket 8) */}
                        <MemorandumApprovalCard memorandum={memorandum} />

                        {/* Workflow Approval Timeline (Ticket 8) */}
                        <MemorandumApprovalTimeline histories={approval_histories} />

                        {/* Audit History */}
                        {history && history.length > 0 && (
                            <section className="rounded-lg bg-white p-5 shadow-sm border border-slate-200">
                                <h2 className="text-sm font-semibold uppercase tracking-wider text-slate-600 mb-3 flex items-center gap-1.5">
                                    <ClipboardCheck className="h-4 w-4 text-bsm-primary" />
                                    Riwayat Audit
                                </h2>
                                <div className="space-y-2 text-xs">
                                    {history.map((h, i) => (
                                        <div key={i} className="flex items-center justify-between border-b border-slate-100 pb-2">
                                            <div>
                                                <span className="font-medium text-slate-800">{h.action}</span>
                                                <span className="text-slate-500 block text-[11px]">{h.user}</span>
                                            </div>
                                            <span className="text-slate-400">{formatDateTime(h.created_at)}</span>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        )}
                    </div>

                    {/* Side Context (Application & Customer) */}
                    <div className="space-y-6">
                        <div className="rounded-lg bg-white p-5 shadow-sm border border-slate-200">
                            <h2 className="text-sm font-semibold uppercase tracking-wider text-slate-500 mb-4 flex items-center gap-1.5">
                                <FileText className="h-4 w-4 text-bsm-primary" />
                                Konteks Pengajuan Asal
                            </h2>

                            <dl className="space-y-3 text-xs">
                                <div>
                                    <dt className="text-slate-400">Nomor Pengajuan</dt>
                                    <dd className="font-semibold text-slate-800 text-sm mt-0.5">
                                        <Link
                                            href={route('shm.applications.show', application.id)}
                                            className="text-bsm-primary hover:underline"
                                        >
                                            {application.application_number}
                                        </Link>
                                    </dd>
                                </div>

                                <div>
                                    <dt className="text-slate-400">Tipe Pengajuan</dt>
                                    <dd className="mt-1">
                                        <span className="inline-flex items-center gap-1 font-semibold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded border border-indigo-200">
                                            <RefreshCw className="h-3 w-3" /> Buyback
                                        </span>
                                    </dd>
                                </div>

                                <div>
                                    <dt className="text-slate-400">Status Pengajuan</dt>
                                    <dd className="mt-1">
                                        <StatusBadge status="COMPLETED" label={application.status_label} />
                                    </dd>
                                </div>

                                <div>
                                    <dt className="text-slate-400">Marketing</dt>
                                    <dd className="font-medium text-slate-700 mt-0.5">{application.marketing ?? '-'}</dd>
                                </div>

                                <div>
                                    <dt className="text-slate-400">Tanggal Pengajuan</dt>
                                    <dd className="font-medium text-slate-700 mt-0.5">{formatDateTime(application.created_at)}</dd>
                                </div>
                            </dl>
                        </div>

                        {customer && (
                            <div className="rounded-lg bg-white p-5 shadow-sm border border-slate-200">
                                <h2 className="text-sm font-semibold uppercase tracking-wider text-slate-500 mb-4 flex items-center gap-1.5">
                                    <UserRound className="h-4 w-4 text-bsm-primary" />
                                    Data Nasabah
                                </h2>

                                <dl className="space-y-3 text-xs">
                                    <div>
                                        <dt className="text-slate-400">Nama Lengkap</dt>
                                        <dd className="font-semibold text-slate-800 mt-0.5">{customer.name}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-slate-400">Nomor Nasabah</dt>
                                        <dd className="font-medium text-slate-700 mt-0.5">{customer.customer_number ?? '-'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-slate-400">NIK</dt>
                                        <dd className="font-medium text-slate-700 mt-0.5">{customer.nik ?? '-'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-slate-400">No. Telepon</dt>
                                        <dd className="font-medium text-slate-700 mt-0.5">{customer.phone ?? '-'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-slate-400">Alamat</dt>
                                        <dd className="text-slate-600 mt-0.5 leading-relaxed">{customer.address ?? '-'}</dd>
                                    </div>
                                </dl>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </ShmLayout>
    );
}

