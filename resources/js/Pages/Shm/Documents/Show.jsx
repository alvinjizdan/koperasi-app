import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import StatusBadge from '@/Components/Admin/StatusBadge';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import { formatDateTime } from '@/utils/format';
import {
    ArrowLeft,
    UserRound,
    FileText,
    FolderOpen,
    ClipboardCheck,
    ClipboardList,
    CheckCircle2,
    RotateCcw,
} from 'lucide-react';

/**
 * Detail kelengkapan dokumen suatu pengajuan.
 *
 * Menampilkan konteks pengajuan/nasabah/objek, daftar checklist dokumen,
 * ringkasan administratif, referensi credit checking, dan form pencatatan
 * ulang dokumen jika pengguna punya permission `shm.document.update`.
 *
 * Status dokumen memakai terminologi existing: `pending`, `complete`,
 * `not_applicable`. Tidak ada daftar dokumen wajib dan tidak ada upload
 * berkas pada modul ini.
 */
export default function Show({
    application,
    properties,
    documents,
    documents_summary,
    documents_complete,
    credit_check,
    memorandum,
    approval,
    can_update,
    history,
    flash,
}) {
    const customer = application?.customer;
    const [updating, setUpdating] = useState(false);

    const needsReopen = application?.status === 'ready_for_disbursement' && !documents_complete;
    const canReopen = needsReopen && can_update;

    const post = (routeName) =>
        router.post(route(routeName, application.id), {}, { preserveScroll: true });

    const [form, setForm] = useState({
        document_name: '',
        status: 'pending',
        notes: '',
    });
    const [errors, setErrors] = useState({});

    const submit = (event) => {
        event.preventDefault();
        setErrors({});
        router.patch(
            route('shm.documents.update', application.id),
            form,
            { preserveScroll: true, onError: (e) => setErrors(e) },
        );
    };

    const resetForm = () => {
        setForm({ document_name: '', status: 'pending', notes: '' });
        setErrors({});
    };

    const StatusChip = ({ status }) => {
        if (status === 'complete') {
            return <StatusBadge status="COMPLETED" label="Tercatat" />;
        }
        if (status === 'not_applicable') {
            return <StatusBadge status="REVIEW" label="Tidak Berlaku" />;
        }
        return <StatusBadge status="PENDING" label="Pending" />;
    };

    return (
        <ShmLayout title={`Kelengkapan Dokumen - ${application?.application_number ?? '-'}`}>
            <Head title={`Kelengkapan Dokumen - ${application?.application_number ? String(application.application_number) : '-'}`} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link
                            href={route('shm.documents.index')}
                            className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"
                        >
                            <ArrowLeft className="h-3.5 w-3.5" />
                            Kembali ke Daftar Dokumen
                        </Link>
                        <h1 className="text-xl font-semibold text-gray-800">
                            Kelengkapan Dokumen
                        </h1>
                        <p className="mt-1 text-sm text-gray-500">
                            {application?.application_number ? String(application.application_number) : ''} — {application?.status_label}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Link href={application?.id ? route('shm.applications.show', application.id) : '#'}>
                            <SecondaryButton>Buka Pengajuan</SecondaryButton>
                        </Link>

                        {canReopen && (
                            <button
                                type="button"
                                onClick={() => post('shm.applications.reopen-document-check')}
                                className="text-xs font-medium text-amber-700 hover:text-amber-900 underline">
                                Buka Kembali ke Pemeriksaan Dokumen
                            </button>
                        )}
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        {flash.error}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <FileText className="h-4 w-4" />
                                Pengajuan
                            </h2>
                            <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt className="text-xs text-gray-500">No. Pengajuan</dt>
                                    <dd className="font-medium">{application?.application_number ?? '-'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Tahap</dt>
                                    <dd>
                                        <StatusBadge
                                            status={application?.status === 'approved' ? 'COMPLETED' :
                                                application?.status === 'document_check' ? 'IN_PROGRESS' :
                                                application?.status === 'ready_for_disbursement' ? 'COMPLETED' :
                                                'PENDING'}
                                            label={application?.status_label ?? '-'}
                                        />
                                    </dd>
                                </div>
                                {needsReopen && (
                                    <div>
                                        <dt className="text-xs text-gray-500">Status Kelengkapan</dt>
                                        <dd className="text-xs text-amber-800">Dokumen tidak lagi lengkap — perlu pembukaan kembali.</dd>
                                    </div>
                                )}
                                <div>
                                    <dt className="text-xs text-gray-500">Marketing</dt>
                                    <dd>{application?.marketing?.name ?? '-'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Dibuat</dt>
                                    <dd>{application?.created_at ? formatDateTime(application.created_at) : '-'}</dd>
                                </div>
                                {application?.submitted_at && (
                                    <div>
                                        <dt className="text-xs text-gray-500">Disubmit</dt>
                                        <dd>{formatDateTime(application.submitted_at)}</dd>
                                    </div>
                                )}
                                {application?.approved_at && (
                                    <div>
                                        <dt className="text-xs text-gray-500">Disetujui</dt>
                                        <dd>{formatDateTime(application.approved_at)}</dd>
                                    </div>
                                )}
                                {application?.disbursed_at && (
                                    <div>
                                        <dt className="text-xs text-gray-500">Dicairkan</dt>
                                        <dd>{formatDateTime(application.disbursed_at)}</dd>
                                    </div>
                                )}
                            </dl>
                        </section>

                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <UserRound className="h-4 w-4" />
                                Nasabah SHM
                            </h2>
                            {!customer ? (
                                <p className="text-sm text-gray-500">Data nasabah tidak ditemukan.</p>
                            ) : (
                                <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt className="text-xs text-gray-500">Nama</dt>
                                        <dd className="font-medium">{customer?.name ? String(customer.name) : '-'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500">Nomor Nasabah</dt>
                                        <dd>{customer?.customer_number ? String(customer.customer_number) : '-'}</dd>
                                    </div>
                                    {customer?.nik && (
                                        <div>
                                            <dt className="text-xs text-gray-500">NIK</dt>
                                            <dd>{customer?.nik ? String(customer.nik) : '-'}</dd>
                                        </div>
                                    )}
                                    {customer?.phone && (
                                        <div>
                                            <dt className="text-xs text-gray-500">Telepon</dt>
                                            <dd>{customer?.phone ? String(customer.phone) : '-'}</dd>
                                        </div>
                                    )}
                                    {customer?.email && (
                                        <div>
                                            <dt className="text-xs text-gray-500">Email</dt>
                                            <dd>{customer.email}</dd>
                                        </div>
                                    )}
                                    {customer.address && (
                                        <div className="sm:col-span-2">
                                            <dt className="text-xs text-gray-500">Alamat</dt>
                                            <dd>{customer.address}</dd>
                                        </div>
                                    )}
                                </dl>
                            )}
                            {customer && customer.is_active === false && (
                                <p className="mt-3 rounded bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    Nasabah ini sedang nonaktif. Pengajuan lama tetap tersimpan.
                                </p>
                            )}
                        </section>

                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <FolderOpen className="h-4 w-4" />
                                Objek SHM/SHGB
                            </h2>
                            {properties.length === 0 ? (
                                <p className="text-sm text-gray-500">Belum ada objek yang dicatat.</p>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {properties.map((property) => (
                                        <li key={property.id} className="rounded border border-slate-200 p-3">
                                            <p className="font-medium">
                                                {property.certificate_type}
                                                {property.certificate_number
                                                    ? ` - ${property.certificate_number}`
                                                    : ''}
                                            </p>
                                            <p className="text-gray-600">{property.address}</p>
                                            {property.owner_name && (
                                                <p className="text-xs text-gray-500">Pemilik: {property.owner_name}</p>
                                            )}
                                            {property.latitude && property.longitude ? (
                                                <p className="mt-1 flex items-center gap-1 text-xs text-gray-500">
                                                    <ClipboardCheck className="h-3 w-3" />
                                                    {property.latitude}, {property.longitude}
                                                </p>
                                            ) : (
                                                <p className="mt-1 text-xs text-gray-400">Lokasi objek belum diisi.</p>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <ClipboardList className="h-4 w-4" />
                                Checklist Dokumen
                            </h2>

                            <p className="mb-4 text-xs text-gray-500">
                                Status administratif berdasarkan data yang tersedia. Tidak ada daftar dokumen
                                wajib dari KSP dan tidak ada upload berkas pada modul ini.
                            </p>

                            {documents.length === 0 ? (
                                <div className="rounded border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                                    Belum ada data dokumen.
                                </div>
                            ) : (
                                <ul className="space-y-2">
                                    {documents.map((document) => (
                                        <li
                                            key={document.id}
                                            className="flex items-start justify-between gap-3 rounded border border-slate-200 p-3"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="truncate font-medium text-slate-800">
                                                        {document.document_name}
                                                    </span>
                                                    <StatusChip status={document.status} />
                                                </div>
                                                {document.checker && (
                                                    <p className="mt-1 text-xs text-slate-500">
                                                        Checker: {document.checker?.name}
                                                    </p>
                                                )}
                                                {document.notes && (
                                                    <p className="mt-1 text-xs text-slate-600 whitespace-pre-line">
                                                        {document.notes}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="shrink-0 text-right text-xs text-slate-400">
                                                <p>{formatDateTime(document.updated_at)}</p>
                                                {document.checked_at && (
                                                    <p className="text-slate-400">
                                                        {formatDateTime(document.checked_at)}
                                                    </p>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {documents.length > 0 && (
                                <div className="mt-4 rounded border border-slate-200 bg-slate-50 p-3 text-xs">
                                    <p className="font-medium text-slate-700">Ringkasan administratif</p>
                                    <p className="mt-1 text-slate-600">
                                        {documents_summary.total} item • {documents_summary.complete} tercatat • {' '}
                                        {documents_summary.pending} pending • {' '}
                                        {documents_complete
                                            ? 'Semua item sudah tercatat atau tidak berlaku'
                                            : 'Masih ada item pending'}
                                    </p>
                                </div>
                            )}
                        </section>
                        {credit_check && (
                            <section className="rounded-lg bg-white p-5 shadow">
                                <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                    <ClipboardCheck className="h-4 w-4" />
                                    Referensi Credit Checking
                                </h2>
                                <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt className="text-xs text-gray-500">Status</dt>
                                        <dd>{credit_check.status_label}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500">Checker</dt>
                                        <dd>{credit_check.checker ?? '-'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500">Mulai</dt>
                                        <dd>{credit_check.started_at ? formatDateTime(credit_check.started_at) : '-'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500">Selesai</dt>
                                        <dd>{credit_check.completed_at ? formatDateTime(credit_check.completed_at) : '-'}</dd>
                                    </div>
                                    {credit_check.outcome && (
                                        <div className="sm:col-span-2">
                                            <dt className="text-xs text-gray-500">Hasil</dt>
                                            <dd>
                                                <StatusBadge
                                                    status={credit_check.outcome === 'agree' ? 'PASS' :
                                                        credit_check.outcome === 'reject' ? 'FAIL' : 'REVIEW'}
                                                    label={credit_check.outcome_label ?? credit_check.outcome}
                                                />
                                            </dd>
                                        </div>
                                    )}
                                    {credit_check.result && (
                                        <div className="sm:col-span-2">
                                            <dt className="text-xs text-gray-500">Ringkasan</dt>
                                            <dd className="whitespace-pre-line">{credit_check.result}</dd>
                                        </div>
                                    )}
                                </dl>
                                <p className="mt-3 text-xs text-gray-500">
                                    Hasil credit checking hanya referensi; tidak ada aturan otomatis.
                                </p>
                            </section>
                        )}

                        {memorandum && (
                            <section className="rounded-lg bg-white p-5 shadow">
                                <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                    <FileText className="h-4 w-4" />
                                    Memorandum
                                </h2>
                                <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt className="text-xs text-gray-500">Kategori</dt>
                                        <dd className="font-medium">{memorandum.type_label ?? memorandum.type ?? '-'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500">Status</dt>
                                        <dd>{memorandum.status_label ?? memorandum.status}</dd>
                                    </div>
                                    {memorandum.submitted_at && (
                                        <div>
                                            <dt className="text-xs text-gray-500">Diajukan</dt>
                                            <dd>{formatDateTime(memorandum.submitted_at)}</dd>
                                        </div>
                                    )}
                                </dl>
                                {memorandum.notes && (
                                    <p className="mt-2 text-xs text-slate-600 whitespace-pre-line">{memorandum.notes}</p>
                                )}
                                <Link
                                    href={route('shm.memorandums.show', memorandum.id)}
                                    className="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:underline"
                                >
                                    Lihat Detail Memorandum
                                    <ArrowLeft className="h-3 w-3 rotate-180" />
                                </Link>
                            </section>
                        )}

                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <ClipboardList className="h-4 w-4" />
                                Riwayat
                            </h2>
                            {history.length === 0 ? (
                                <p className="text-sm text-gray-500">Belum ada riwayat.</p>
                            ) : (
                                <ul className="space-y-3 text-xs">
                                    {history.map((entry) => (
                                        <li key={entry.id} className="border-l-2 border-slate-200 pl-3">
                                            <p className="font-medium text-gray-700">{entry.description ?? entry.action}</p>
                                            <p className="text-gray-400">
                                                {entry.actor?.name ?? 'Sistem'} - {formatDateTime(entry.created_at)}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </div>
                    <div className="space-y-6">
                        {can_update && (
                            <section className="rounded-lg bg-white p-5 shadow">
                                <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                    <RotateCcw className="h-4 w-4" />
                                    Pencatatan Ulang Dokumen
                                </h2>

                                <p className="mb-4 text-xs text-gray-500">
                                    Tambahkan atau perbarui item checklist dokumen. Dokumen hanya dapat
                                    dicatat jika pengajuan sudah berada di tahap Approval, Kelengkapan
                                    Dokumen, atau Siap Pencairan.
                                </p>

                                <form onSubmit={submit} className="space-y-3">
                                    <div>
                                        <InputLabel htmlFor="document_name" value="Nama Dokumen" />
                                        <TextInput
                                            id="document_name"
                                            value={form.document_name}
                                            onChange={(event) => setForm({ ...form, document_name: event.target.value })}
                                            className="mt-1"
                                            placeholder="Contoh: Surat pengantar, laporan keuangan, dll."
                                        />
                                        <InputError message={errors.document_name} className="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="status" value="Status" />
                                        <select
                                            id="status"
                                            value={form.status}
                                            onChange={(event) => setForm({ ...form, status: event.target.value })}
                                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                        >
                                            <option value="pending">Pending</option>
                                            <option value="complete">Tercatat (Complete)</option>
                                            <option value="not_applicable">Tidak Berlaku (Not Applicable)</option>
                                        </select>
                                        <InputError message={errors.status} className="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="notes" value="Catatan Checker" />
                                        <textarea
                                            id="notes"
                                            rows={4}
                                            value={form.notes}
                                            onChange={(event) => setForm({ ...form, notes: event.target.value })}
                                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                            placeholder="Catatan pemeriksaan (opsional)"
                                        />
                                        <InputError message={errors.notes} className="mt-1" />
                                    </div>
                                    <div className="flex justify-end gap-2">
                                        <SecondaryButton type="button" onClick={resetForm}>
                                            Reset
                                        </SecondaryButton>
                                        <PrimaryButton type="submit" disabled={updating}>
                                            {updating ? 'Menyimpan...' : 'Simpan Dokumen'}
                                        </PrimaryButton>
                                    </div>
                                </form>
                            </section>
                        )}
                        {!can_update && (
                            <section className="rounded-lg bg-white p-5 shadow">
                                <h2 className="mb-3 text-sm font-semibold uppercase text-slate-600">
                                    Kelengkapan Dokumen
                                </h2>
                                <p className="text-sm text-gray-500">
                                    Anda tidak memiliki akses untuk mencatat atau mengubah kelengkapan
                                    dokumen.
                                </p>
                            </section>
                        )}

                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 text-sm font-semibold uppercase text-slate-600">
                                Informasi Pengajuan
                            </h2>
                            <dl className="space-y-3 text-sm">
                                <div>
                                    <dt className="text-xs text-gray-500">Nasabah</dt>
                                    <dd className="font-medium">{application?.customer_label ?? '-'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Marketing</dt>
                                    <dd>{application?.marketing?.name ?? '-'}</dd>
                                </div>
                                {application?.notes && (
                                    <div>
                                        <dt className="text-xs text-gray-500">Catatan Pengajuan</dt>
                                        <dd className="whitespace-pre-line">{application.notes}</dd>
                                    </div>
                                )}
                            </dl>
                        </section>

                        {memorandum && (
                            <section className="rounded-lg bg-white p-5 shadow">
                                <h2 className="mb-3 text-sm font-semibold uppercase text-slate-600">
                                    Memorandum
                                </h2>
                                <p className="text-sm text-slate-600">
                                    <span className="font-medium">{memorandum.type_label}</span> — {memorandum.status_label}
                                </p>
                                <Link
                                    href={route('shm.memorandums.show', memorandum.id)}
                                    className="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:underline"
                                >
                                    Lihat Detail Memorandum
                                    <ArrowLeft className="h-3 w-3 rotate-180" />
                                </Link>
                            </section>
                        )}
                    </div>
                </div>
            </div>
        </ShmLayout>
    );
}
