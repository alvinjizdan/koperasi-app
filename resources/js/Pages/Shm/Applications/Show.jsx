import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import DangerButton from '@/Components/DangerButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import { formatDateTime } from '@/utils/format';
import {
    MapPin,
    CheckCircle2,
    FileText,
    ClipboardCheck,
    ScrollText,
    Gavel,
    FolderOpen,
    UserRound,
    Play,
    ExternalLink,
    ArrowRight,
    Upload,
    Download,
    Trash2,
    AlertCircle,
    Calendar,
    Lock,
} from 'lucide-react';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import StatusBadge from '@/Components/Admin/StatusBadge';

/**
 * Detail pengajuan SHM beserta seluruh tahapnya.
 *
 * Timeline hanya menampilkan event yang BENAR-BENAR terjadi, diambil dari
 * timestamp workflow dan audit yang tersimpan di server. Tahap yang belum
 * terjadi tidak dimunculkan sebagai langkah kosong.
 *
 * Nasabah pengajuan adalah Nasabah SHM (master data sendiri, bukan anggota
 * koperasi). Marketing hanya dapat menjalankan aksi pada pengajuannya sendiri;
 * server tetap memeriksa kepemilikan dan permission pada setiap request.
 */
export default function Show({
    application,
    buyback_documents,
    is_buyback_documents_complete,
    buyback_documents_ratio,
    properties,
    credit_check,
    memorandum,
    approval,
    documents,
    documents_complete,
    documents_summary,
    marketing_activity,
    timeline,
    history,
    transitions,
    permissions,
    flash,
}) {
    const can = (name) => permissions?.[name] === true;
    const allowed = transitions ?? [];
    const customer = application.customer;

    const post = (routeName, notes = '') =>
        router.post(route(routeName, application.id), { notes }, { preserveScroll: true });

    return (
        <ShmLayout title={`Pengajuan ${application.application_number}`}>
            <Head title={`Pengajuan ${application.application_number}`} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-xl font-semibold text-gray-800">
                                {application.application_number}
                            </h1>
                            <span
                                className={`rounded-full px-2.5 py-0.5 text-xs font-semibold uppercase ${
                                    application.type === 'buyback'
                                        ? 'bg-indigo-50 text-indigo-700 border border-indigo-200'
                                        : 'bg-blue-50 text-blue-700 border border-blue-200'
                                }`}
                            >
                                {application.type_label ?? (application.type === 'buyback' ? 'Buyback' : 'SHGB')}
                            </span>
                        </div>
                        <p className="text-sm text-gray-500 mt-0.5">
                            Status saat ini: {application.status_label}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {can('can_update') && (
                            <Link href={route('shm.applications.edit', application.id)}>
                                <SecondaryButton>Edit Data</SecondaryButton>
                            </Link>
                        )}
                        {allowed.includes('submitted') && can('can_submit') && (
                            <PrimaryButton onClick={() => post('shm.applications.submit')}>
                                Kirim Pengajuan
                            </PrimaryButton>
                        )}
                        {allowed.includes('cancelled') && can('can_submit') && (
                            <DangerButton onClick={() => post('shm.applications.cancel')}>
                                Batalkan
                            </DangerButton>
                        )}
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        {/* Nasabah SHM */}
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <UserRound className="h-4 w-4" />
                                Nasabah SHM
                            </h2>
                            <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt className="text-xs text-gray-500">Nama</dt>
                                    <dd className="font-medium">
                                        {customer ? (
                                            <Link
                                                href={route('shm.customers.show', customer.id)}
                                                className="text-indigo-600 hover:underline"
                                            >
                                                {customer.name}
                                            </Link>
                                        ) : (
                                            '-'
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Nomor Nasabah</dt>
                                    <dd>{customer?.customer_number ?? '-'}</dd>
                                </div>
                                {customer?.nik && (
                                    <div>
                                        <dt className="text-xs text-gray-500">NIK</dt>
                                        <dd>{customer.nik}</dd>
                                    </div>
                                )}
                                {customer?.phone && (
                                    <div>
                                        <dt className="text-xs text-gray-500">Telepon</dt>
                                        <dd>{customer.phone}</dd>
                                    </div>
                                )}
                                {customer?.address && (
                                    <div className="sm:col-span-2">
                                        <dt className="text-xs text-gray-500">Alamat</dt>
                                        <dd>{customer.address}</dd>
                                    </div>
                                )}
                            </dl>
                            {customer && customer.is_active === false && (
                                <p className="mt-3 rounded bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    Nasabah ini sedang nonaktif. Pengajuan lama tetap tersimpan.
                                </p>
                            )}
                        </section>

                        {/* Timeline */}
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-4 text-sm font-semibold uppercase text-slate-600">
                                Timeline Pengajuan
                            </h2>
                            {timeline.length === 0 ? (
                                <p className="text-sm text-gray-500">Belum ada aktivitas.</p>
                            ) : (
                                <ol className="space-y-3">
                                    {timeline.map((step) => (
                                        <li key={step.key} className="flex items-start gap-3">
                                            <span className="mt-0.5">
                                                <CheckCircle2
                                                    className={`h-5 w-5 ${
                                                        step.current
                                                            ? 'text-emerald-600'
                                                            : 'text-slate-400'
                                                    }`}
                                                />
                                            </span>
                                            <div>
                                                <p
                                                    className={
                                                        step.current
                                                            ? 'text-sm font-semibold text-gray-900'
                                                            : 'text-sm text-gray-600'
                                                    }
                                                >
                                                    {step.label}
                                                    {step.current && ' (saat ini)'}
                                                </p>
                                                <p className="text-xs text-gray-400">
                                                    {step.at ? formatDateTime(step.at) : '-'}
                                                    {step.actor ? ` \u00b7 ${step.actor}` : ''}
                                                </p>
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </section>

                        {/* Objek SHM/SHGB */}
                        <PropertySection
                            application={application}
                            properties={properties}
                            canManage={can('can_manage_property')}
                        />

                        {/* Credit checking */}
                        <CreditCheckSection
                            application={application}
                            creditCheck={credit_check}
                            canUpdate={can('can_manage_credit_check')}
                            canView={can('can_view_credit_check')}
                        />

                        {/* Memorandum */}
                        <MemorandumSection
                            application={application}
                            memorandum={memorandum}
                            canUpdate={can('can_update_memorandum')}
                        />

                        {/* Approval */}
                        <ApprovalSection
                            application={application}
                            approval={approval}
                            allowed={allowed}
                            canDecide={can('can_decide_approval')}
                        />

                        {/* Dokumen Legalitas Buyback (Ticket 14) */}
                        {application.type === 'buyback' && (
                            <BuybackDocumentsSection
                                application={application}
                                documents={buyback_documents}
                                isComplete={is_buyback_documents_complete}
                                ratio={buyback_documents_ratio}
                                canUpdate={can('can_update_document')}
                            />
                        )}

                        {/* Dokumen */}
                        <DocumentSection
                            application={application}
                            documents={documents}
                            documentsComplete={documents_complete}
                            canUpdate={can('can_update_document')}
                        />

                        {/* Pencairan (gate kelengkapan dokumen — Ticket 51) */}
                        <DisbursementSection
                            application={application}
                            documentsComplete={documents_complete}
                            documentsSummary={documents_summary}
                            allowed={allowed}
                            canUpdateDocument={can('can_update_document')}
                            canDisburse={can('can_disburse')}
                            canReopenDocumentCheck={can('can_update_document')}
                        />
                    </div>

                    <div className="space-y-6">
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 text-sm font-semibold uppercase text-slate-600">
                                Data Pengajuan
                            </h2>
                            <dl className="space-y-3 text-sm">
                                <div>
                                    <dt className="text-xs text-gray-500">Tipe Pengajuan</dt>
                                    <dd className="font-semibold text-slate-800">
                                        {application.type_label ?? (application.type === 'buyback' ? 'Buyback' : 'SHGB')}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Nasabah</dt>
                                    <dd className="font-medium">{application.customer_label}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Marketing</dt>
                                    <dd className="font-medium">
                                        {application.marketing?.name ?? '-'}
                                    </dd>
                                </div>
                                {application.type === 'shgb' && (
                                    <div className="pt-2 border-t border-slate-100">
                                        <dt className="text-xs text-gray-500">Masa Berlaku SHGB</dt>
                                        <dd className="font-medium text-slate-700">
                                            {application.shgb_start_date && application.shgb_end_date ? (
                                                <span className="flex items-center gap-1.5 text-xs text-slate-800">
                                                    <Calendar className="h-3.5 w-3.5 text-bsm-primary" />
                                                    {application.shgb_start_date} s/d {application.shgb_end_date}
                                                </span>
                                            ) : (
                                                <span className="text-slate-400 italic text-xs">Belum ditentukan</span>
                                            )}
                                        </dd>
                                    </div>
                                )}
                                {application.submitted_at && (
                                    <div>
                                        <dt className="text-xs text-gray-500">Submitted</dt>
                                        <dd>{formatDateTime(application.submitted_at)}</dd>
                                    </div>
                                )}
                                {application.approved_at && (
                                    <div>
                                        <dt className="text-xs text-gray-500">Approved</dt>
                                        <dd>{formatDateTime(application.approved_at)}</dd>
                                    </div>
                                )}
                                {application.disbursed_at && (
                                    <div>
                                        <dt className="text-xs text-gray-500">Disbursed</dt>
                                        <dd>{formatDateTime(application.disbursed_at)}</dd>
                                    </div>
                                )}
                            </dl>
                        </section>

                        {marketing_activity && (
                            <section className="rounded-lg bg-white p-5 shadow">
                                <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                    <MapPin className="h-4 w-4" />
                                    Aktivitas Marketing
                                </h2>
                                <p className="text-sm text-gray-600">
                                    Check-in {formatDateTime(marketing_activity.checked_in_at)}
                                </p>
                                {marketing_activity.latitude && (
                                    <p className="mt-1 text-xs text-gray-500">
                                        Lokasi: {marketing_activity.latitude},{' '}
                                        {marketing_activity.longitude}
                                    </p>
                                )}
                            </section>
                        )}

                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <ClipboardCheck className="h-4 w-4" />
                                Riwayat
                            </h2>
                            {history.length === 0 ? (
                                <p className="text-sm text-gray-500">Belum ada riwayat.</p>
                            ) : (
                                <ul className="space-y-3 text-xs">
                                    {history.map((entry) => (
                                        <li key={entry.id} className="border-l-2 border-slate-200 pl-3">
                                            <p className="font-medium text-gray-700">{entry.action}</p>
                                            <p className="text-gray-400">
                                                {entry.actor?.name ?? 'Sistem'} -{' '}
                                                {formatDateTime(entry.created_at)}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </div>
                </div>
            </div>
        </ShmLayout>
    );
}

function PropertySection({ application, properties, canManage }) {
    const [form, setForm] = useState({
        certificate_type: 'SHM',
        certificate_number: '',
        owner_name: '',
        address: '',
        latitude: '',
        longitude: '',
        notes: '',
    });
    const [errors, setErrors] = useState({});

    const submit = (event) => {
        event.preventDefault();
        setErrors({});
        router.put(
            route('shm.applications.property', application.id),
            form,
            { preserveScroll: true, onError: (e) => setErrors(e) },
        );
    };

    return (
        <section className="rounded-lg bg-white p-5 shadow">
            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                <FolderOpen className="h-4 w-4" />
                Objek SHM/SHGB
            </h2>

            {properties.length === 0 && !canManage && (
                <p className="text-sm text-gray-500">Belum ada objek yang dicatat.</p>
            )}

            {properties.map((property) => (
                <div key={property.id} className="mb-3 rounded border border-slate-200 p-3 text-sm">
                    <p className="font-medium">
                        {property.certificate_type}
                        {property.certificate_number ? ` - ${property.certificate_number}` : ''}
                    </p>
                    <p className="text-gray-600">{property.address}</p>
                    {property.owner_name && (
                        <p className="text-xs text-gray-500">Pemilik: {property.owner_name}</p>
                    )}
                    {property.latitude && property.longitude ? (
                        <p className="mt-1 flex items-center gap-1 text-xs text-gray-500">
                            <MapPin className="h-3 w-3" />
                            {property.latitude}, {property.longitude}
                        </p>
                    ) : (
                        <p className="mt-1 text-xs text-gray-400">Lokasi objek belum diisi.</p>
                    )}
                </div>
            ))}

            {canManage && (
                <form onSubmit={submit} className="mt-4 space-y-3 border-t border-slate-100 pt-4">
                    <p className="text-xs text-gray-500">
                        Koordinat bersifat opsional dan tidak dipakai sebagai validasi apa pun.
                    </p>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="certificate_type" value="Jenis Sertifikat" />
                            <select
                                id="certificate_type"
                                value={form.certificate_type}
                                onChange={(e) => setForm({ ...form, certificate_type: e.target.value })}
                                className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            >
                                <option value="SHM">SHM</option>
                                <option value="SHGB">SHGB</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel htmlFor="certificate_number" value="Nomor Sertifikat" />
                            <TextInput
                                id="certificate_number"
                                value={form.certificate_number}
                                onChange={(e) => setForm({ ...form, certificate_number: e.target.value })}
                                className="mt-1"
                            />
                        </div>
                        <div>
                            <InputLabel htmlFor="owner_name" value="Nama Pemilik" />
                            <TextInput
                                id="owner_name"
                                value={form.owner_name}
                                onChange={(e) => setForm({ ...form, owner_name: e.target.value })}
                                className="mt-1"
                            />
                        </div>
                        <div>
                            <InputLabel htmlFor="address" value="Alamat Objek" />
                            <TextInput
                                id="address"
                                value={form.address}
                                onChange={(e) => setForm({ ...form, address: e.target.value })}
                                className="mt-1"
                            />
                            <InputError message={errors.address} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="latitude" value="Latitude" />
                            <TextInput
                                id="latitude"
                                value={form.latitude}
                                onChange={(e) => setForm({ ...form, latitude: e.target.value })}
                                className="mt-1"
                                placeholder="-6.2"
                            />
                            <InputError message={errors.latitude} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="longitude" value="Longitude" />
                            <TextInput
                                id="longitude"
                                value={form.longitude}
                                onChange={(e) => setForm({ ...form, longitude: e.target.value })}
                                className="mt-1"
                                placeholder="106.8"
                            />
                            <InputError message={errors.longitude} className="mt-1" />
                        </div>
                    </div>
                    <div className="flex justify-end">
                        <PrimaryButton type="submit">Simpan Objek</PrimaryButton>
                    </div>
                </form>
            )}
        </section>
    );
}

function CreditCheckSection({ application, creditCheck, canUpdate, canView }) {
    const startChecking = () => {
        router.post(route('shm.applications.credit-checking.start', application.id));
    };

    const checkingRoute = creditCheck ? route('shm.credit-checks.show', creditCheck.id) : null;

    return (
        <section className="rounded-lg bg-white p-5 shadow">
            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                <ClipboardCheck className="h-4 w-4" />
                Credit Checking
            </h2>

            {creditCheck === null && (
                <div className="space-y-3">
                    <p className="text-sm text-gray-600">
                        Credit checking belum dimulai untuk pengajuan ini.
                    </p>
                    {canUpdate && (
                        <PrimaryButton onClick={startChecking}>
                            <Play className="mr-2 h-4 w-4" />
                            Mulai Credit Checking
                        </PrimaryButton>
                    )}
                </div>
            )}

            {creditCheck !== null && (
                <div className="space-y-3 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge
                            status={creditCheck.status_badge}
                            label={creditCheck.status_label}
                        />
                        {creditCheck.outcome && (
                            <StatusBadge
                                status={creditCheck.outcome_badge}
                                label={creditCheck.outcome_label}
                            />
                        )}
                    </div>

                    <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <dt className="text-xs text-gray-500">Petugas</dt>
                            <dd>{creditCheck.checker ?? '-'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">Mulai</dt>
                            <dd>
                                {creditCheck.started_at
                                    ? formatDateTime(creditCheck.started_at)
                                    : '-'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">Selesai</dt>
                            <dd>
                                {creditCheck.completed_at
                                    ? formatDateTime(creditCheck.completed_at)
                                    : '-'}
                            </dd>
                        </div>
                    </dl>

                    {creditCheck.result_summary && (
                        <div>
                            <dt className="text-xs text-gray-500">Ringkasan Hasil</dt>
                            <dd className="whitespace-pre-line">{creditCheck.result_summary}</dd>
                        </div>
                    )}

                    {canView && (
                        <Link
                            href={checkingRoute}
                            className="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:underline"
                        >
                            {creditCheck.status === 'completed'
                                ? 'Lihat Hasil Credit Checking'
                                : 'Lanjutkan Proses Credit Checking'}
                            <ExternalLink className="h-3 w-3" />
                        </Link>
                    )}
                </div>
            )}
        </section>
    );
}

function MemorandumSection({ application, memorandum, canUpdate }) {
    // Ticket 49: kategori memorandum ikut dikelola dari halaman pengajuan.
    // Kategori hanyalah pembeda naskah (SHM/SHGB vs Buyback), bukan kategori
    // legal dan bukan aturan finansial.
    const [form, setForm] = useState({
        type: memorandum?.type ?? '',
        status: memorandum?.status ?? 'draft',
        notes: memorandum?.notes ?? '',
    });
    const [errors, setErrors] = useState({});

    const submit = (event) => {
        event.preventDefault();
        setErrors({});
        router.put(
            route('shm.applications.memorandum', application.id),
            form,
            { preserveScroll: true, onError: (e) => setErrors(e) },
        );
    };

    return (
        <section className="rounded-lg bg-white p-5 shadow">
            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                <ScrollText className="h-4 w-4" />
                Memorandum
            </h2>

            {!memorandum && !canUpdate && (
                <p className="text-sm text-gray-500">Belum ada memorandum.</p>
            )}

            {memorandum && (
                <div className="mb-3 space-y-1 text-sm">
                    <p className="font-medium">
                        Kategori: {memorandum.type_label ?? memorandum.type ?? '-'}
                    </p>
                    <p>Status: {memorandum.status_label ?? memorandum.status}</p>
                    {memorandum.notes && (
                        <p className="whitespace-pre-line">{memorandum.notes}</p>
                    )}
                </div>
            )}

            {canUpdate && (
                <form onSubmit={submit} className="space-y-3 border-t border-slate-100 pt-4">
                    <p className="text-xs text-gray-500">
                        Belum ada perhitungan nominal, margin, bunga, atau formula buyback pada
                        memorandum.
                    </p>
                    <div>
                        <InputLabel htmlFor="memo_type" value="Kategori Memorandum" />
                        <select
                            id="memo_type"
                            value={form.type}
                            onChange={(e) => setForm({ ...form, type: e.target.value })}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="">Belum dikategorikan</option>
                            <option value="shm_shgb">SHM/SHGB</option>
                            <option value="buyback">Buyback</option>
                        </select>
                        <InputError message={errors.type} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="memo_status" value="Status Memorandum" />
                        <select
                            id="memo_status"
                            value={form.status}
                            onChange={(e) => setForm({ ...form, status: e.target.value })}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="draft">Draft</option>
                            <option value="submitted">Diajukan</option>
                        </select>
                        <InputError message={errors.status} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="memo_notes" value="Isi Memorandum" />
                        <textarea
                            id="memo_notes"
                            rows={4}
                            value={form.notes}
                            onChange={(e) => setForm({ ...form, notes: e.target.value })}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                        <InputError message={errors.notes} className="mt-1" />
                    </div>
                    <div className="flex justify-end">
                        <PrimaryButton type="submit">Simpan Memorandum</PrimaryButton>
                    </div>
                </form>
            )}
        </section>
    );
}

function ApprovalSection({ application, approval, allowed, canDecide }) {
    const [notes, setNotes] = useState('');

    const decide = (routeName) =>
        router.post(route(routeName, application.id), { notes }, { preserveScroll: true });

    return (
        <section className="rounded-lg bg-white p-5 shadow">
            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                <Gavel className="h-4 w-4" />
                Approval
            </h2>

            {approval ? (
                <div className="text-sm">
                    <p className="font-medium">Status: {approval.status}</p>
                    {approval.decided_at && (
                        <p className="text-xs text-gray-500">
                            {formatDateTime(approval.decided_at)}
                        </p>
                    )}
                    {approval.notes && <p className="mt-1">{approval.notes}</p>}
                </div>
            ) : (
                <p className="text-sm text-gray-500">Belum ada keputusan approval.</p>
            )}

            {canDecide && allowed.includes('approved') && (
                <div className="mt-4 space-y-3 border-t border-slate-100 pt-4">
                    <InputLabel htmlFor="approval_notes" value="Catatan Keputusan" />
                    <textarea
                        id="approval_notes"
                        rows={3}
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        className="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    />
                    <div className="flex justify-end gap-2">
                        <DangerButton onClick={() => decide('shm.applications.reject')}>
                            Tolak
                        </DangerButton>
                        <PrimaryButton onClick={() => decide('shm.applications.approve')}>
                            Setujui
                        </PrimaryButton>
                    </div>
                </div>
            )}
        </section>
    );
}

function DocumentSection({ application, documents, documentsComplete, canUpdate }) {
    const [form, setForm] = useState({
        document_name: '',
        status: 'pending',
        notes: '',
    });
    const [errors, setErrors] = useState({});

    const submit = (event) => {
        event.preventDefault();
        setErrors({});
        router.put(
            route('shm.applications.document', application.id),
            form,
            { preserveScroll: true, onError: (e) => setErrors(e) },
        );
    };

    return (
        <section className="rounded-lg bg-white p-5 shadow">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <h2 className="mb-0 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                    <FileText className="h-4 w-4" />
                    Kelengkapan Dokumen
                </h2>
                <Link
                    href={route('shm.documents.show', application.id)}
                    className="text-xs font-semibold text-indigo-600 hover:underline"
                >
                    Lihat Kelengkapan Dokumen
                </Link>
            </div>

            <p className="mb-3 text-xs text-gray-500">
                Belum ada daftar dokumen wajib dari KSP. Checklist disusun manual dan tidak ada
                upload berkas pada tahap ini.
            </p>

            {documents.length > 0 && (
                <ul className="mb-3 space-y-2 text-sm">
                    {documents.map((document) => (
                        <li
                            key={document.id}
                            className="flex items-center justify-between rounded border border-slate-200 px-3 py-2"
                        >
                            <span>{document.document_name}</span>
                            <span className="text-xs text-gray-500">{document.status}</span>
                        </li>
                    ))}
                </ul>
            )}

            <p
                className={`mb-3 rounded px-3 py-2 text-xs ${
                    documentsComplete
                        ? 'bg-emerald-50 text-emerald-800'
                        : 'bg-amber-50 text-amber-800'
                }`}
            >
                {documentsComplete
                    ? 'Dokumen lengkap.'
                    : 'Kelengkapan dokumen belum terpenuhi.'}
            </p>

            {canUpdate && (
                <form onSubmit={submit} className="space-y-3 border-t border-slate-100 pt-4">
                    <div>
                        <InputLabel htmlFor="doc_name" value="Nama Dokumen" />
                        <TextInput
                            id="doc_name"
                            value={form.document_name}
                            onChange={(e) => setForm({ ...form, document_name: e.target.value })}
                            className="mt-1"
                        />
                        <InputError message={errors.document_name} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="doc_status" value="Status" />
                        <select
                            id="doc_status"
                            value={form.status}
                            onChange={(e) => setForm({ ...form, status: e.target.value })}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="pending">Belum lengkap</option>
                            <option value="complete">Lengkap</option>
                            <option value="not_applicable">Tidak berlaku</option>
                        </select>
                        <InputError message={errors.status} className="mt-1" />
                    </div>
                    <div className="flex justify-end">
                        <PrimaryButton type="submit">Tambah Dokumen</PrimaryButton>
                    </div>
                </form>
            )}
        </section>
    );
}

/**
 * Aksi pencairan dengan gate kelengkapan dokumen (Ticket 51).
 *
 * UI hanya memberikan umpan balik: tombol dinonaktifkan selama dokumen belum
 * lengkap. Backend tetap menolak permintaan apa pun yang menembus/memalsukan
 * tombol ini, karena invariant dijaga di ShmWorkflowService.
 */
function DisbursementSection({
    application,
    documentsComplete,
    documentsSummary,
    allowed,
    canUpdateDocument,
    canDisburse,
    canReopenDocumentCheck,
}) {
    const isReadyForDisbursement = application?.status === 'ready_for_disbursement';
    const showReady = allowed.includes('ready_for_disbursement') && canUpdateDocument && !isReadyForDisbursement;
    const showDisburse = allowed.includes('disbursed') && canDisburse;

    // Reopen: termasuk bila pengajuan sudah ready tetapi dokumen tidak lengkap.
    const needsReopen = isReadyForDisbursement && !documentsComplete && canReopenDocumentCheck;

    if (!showReady && !showDisburse && !needsReopen) {
        return null;
    }

    const total = documentsSummary?.total ?? 0;
    const pending = documentsSummary?.pending ?? 0;

    const post = (routeName) =>
        router.post(route(routeName, application.id), {}, { preserveScroll: true });

    const [showReopenModal, setShowReopenModal] = useState(false);

    return (
        <section className="rounded-lg bg-white p-5 shadow">
            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                <CheckCircle2 className="h-4 w-4" />
                Pencairan
            </h2>

            <p
                className={`rounded px-3 py-2 text-xs ${
                    documentsComplete
                        ? 'bg-emerald-50 text-emerald-800'
                        : 'bg-amber-50 text-amber-800'
                }`}
            >
                {application?.status === 'ready_for_disbursement'
                    ? (documentsComplete
                        ? 'Dokumen lengkap.'
                        : 'Dokumen tidak lagi lengkap.')
                    : (documentsComplete
                        ? 'Dokumen lengkap.'
                        : 'Kelengkapan dokumen belum terpenuhi.')}
                {!documentsComplete &&
                    (total === 0
                        ? ' Belum ada item dokumen yang dicatat.'
                        : ` Masih terdapat ${pending} dokumen berstatus Pending.`)}
            </p>

            <div className="mt-4 flex flex-wrap gap-2">
                {showReady && (
                    <PrimaryButton
                        disabled={!documentsComplete}
                        onClick={() => post('shm.applications.mark-ready-for-disbursement')}
                    >
                        Tandai Siap Pencairan
                    </PrimaryButton>
                )}
                {showDisburse && (
                    <PrimaryButton
                        disabled={!documentsComplete}
                        onClick={() => post('shm.applications.disburse')}
                    >
                        Tandai Dicairkan
                    </PrimaryButton>
                )}
                {needsReopen && (
                    <PrimaryButton
                        onClick={() => setShowReopenModal(true)}
                        className="inline-flex items-center gap-2"
                    >
                        Buka Kembali ke Pemeriksaan Dokumen
                        <ArrowRight className="h-4 w-4" />
                    </PrimaryButton>
                )}
            </div>

            {!documentsComplete && (
                <p className="mt-2 text-xs text-slate-500">
                    Tombol pencairan dinonaktifkan sampai seluruh dokumen lengkap. Server tetap menolak
                    permintaan langsung yang tidak melalui halaman ini.
                </p>
            )}

            <ConfirmModal
                isOpen={showReopenModal}
                onClose={() => setShowReopenModal(false)}
                onConfirm={() => {
                    setShowReopenModal(false);
                    post('shm.applications.reopen-document-check');
                }}
                title="Konfirmasi pembatalan Siap Pencairan"
                message="Pengajuan akan dikembalikan ke tahap Pemeriksaan Dokumen karena kelengkapan dokumen sudah tidak terpenuhi. Dokumen harus dilengkapi kembali sebelum pengajuan dapat disiapkan untuk pencairan."
                confirmText="Buka Kembali"
                cancelText="Batal"
            />
        </section>
    );
}

/**
 * Section Dokumen Legalitas Buyback pada Pengajuan (Ticket 14).
 * Single Source of Truth untuk 5 dokumen legalitas buyback.
 */
function BuybackDocumentsSection({ application, documents, isComplete, ratio, canUpdate }) {
    const [uploadingType, setUploadingType] = useState(null);
    const [deletingDoc, setDeletingDoc] = useState(null);
    const [fileToUpload, setFileToUpload] = useState(null);
    const [uploadError, setUploadError] = useState(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const isLocked = application.status === 'completed';

    const handleFileSelect = (slotType, file) => {
        if (!file) return;

        if (file.type !== 'application/pdf') {
            setUploadError('Berkas harus berformat PDF (.pdf).');
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            setUploadError('Ukuran berkas maksimal adalah 10 MB.');
            return;
        }

        setUploadError(null);
        setUploadingType(slotType);
        setFileToUpload(file);

        const formData = new FormData();
        formData.append('document_type', slotType);
        formData.append('file', file);

        setIsSubmitting(true);
        router.post(route('shm.applications.buyback-documents.upload', application.id), formData, {
            preserveScroll: true,
            onFinish: () => {
                setIsSubmitting(false);
                setUploadingType(null);
                setFileToUpload(null);
            },
            onError: (errors) => {
                const firstErr = Object.values(errors)[0];
                setUploadError(firstErr || 'Gagal mengunggah berkas.');
            },
        });
    };

    const handleDelete = (doc) => {
        if (!confirm('Apakah Anda yakin ingin menghapus berkas dokumen ini?')) {
            return;
        }

        router.delete(route('shm.applications.buyback-documents.destroy', [application.id, doc.id]), {
            preserveScroll: true,
        });
    };

    return (
        <section className="rounded-lg bg-white p-5 shadow border border-slate-200">
            <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div>
                    <h2 className="text-sm font-semibold uppercase tracking-wider text-slate-700 flex items-center gap-2">
                        <FolderOpen className="h-4 w-4 text-indigo-600" />
                        Dokumen Legalitas Buyback (Wajib 5 Dokumen)
                    </h2>
                    <p className="text-xs text-slate-500 mt-0.5">
                        Dokumen legalitas diinput pada tahap Pengajuan dan menjadi prasyarat konversi ke Memorandum.
                    </p>
                </div>
                <div>
                    <span
                        className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold ${
                            isComplete
                                ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                                : 'bg-amber-50 text-amber-700 border border-amber-200'
                        }`}
                    >
                        {isComplete ? (
                            <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" />
                        ) : (
                            <AlertCircle className="h-3.5 w-3.5 text-amber-600" />
                        )}
                        {ratio ?? '0/5'} Dokumen ({isComplete ? 'Lengkap' : 'Belum Lengkap'})
                    </span>
                </div>
            </div>

            {isLocked && (
                <div className="mb-4 flex items-start gap-2.5 rounded-lg border border-slate-200 bg-slate-50 p-3.5 text-xs text-slate-700">
                    <Lock className="h-4 w-4 text-slate-500 shrink-0 mt-0.5" />
                    <div>
                        <p className="font-semibold text-slate-800">Dokumen Legalitas Terkunci</p>
                        <p className="text-slate-600 mt-0.5">
                            Pengajuan telah selesai (Completed). Pengunggahan, penggantian, dan penghapusan dokumen legalitas tidak dapat dilakukan lagi.
                        </p>
                    </div>
                </div>
            )}

            {uploadError && (
                <div className="mb-4 rounded-md bg-rose-50 p-3 text-xs text-rose-700 border border-rose-200">
                    {uploadError}
                </div>
            )}

            <div className="space-y-3">
                {(documents ?? []).map((slot, index) => (
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
                            {slot.is_uploaded && slot.document && (
                                <p className="text-slate-500 text-[11px]">
                                    Berkas: <span className="font-medium text-slate-700">{slot.document.file_name}</span> ({(slot.document.file_size / 1024).toFixed(1)} KB)
                                    {slot.document.uploader_name && (
                                        <span className="ml-2 text-slate-400">• Oleh {slot.document.uploader_name}</span>
                                    )}
                                </p>
                            )}
                        </div>

                        <div className="flex items-center gap-2">
                            {slot.is_uploaded && slot.document && (
                                <a
                                    href={route('shm.applications.buyback-documents.download', [
                                        application.id,
                                        slot.document.id,
                                    ])}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-1 rounded bg-white px-2.5 py-1.5 text-xs font-medium text-bsm-primary border border-slate-300 hover:bg-slate-50 shadow-sm"
                                    title="Lihat / Unduh Dokumen PDF"
                                >
                                    <Download className="h-3.5 w-3.5" />
                                    Lihat PDF
                                </a>
                            )}

                            {canUpdate && !isLocked && (
                                <>
                                    <label
                                        className={`inline-flex items-center gap-1 rounded px-2.5 py-1.5 text-xs font-medium cursor-pointer shadow-sm transition ${
                                            isSubmitting && uploadingType === slot.type
                                                ? 'bg-slate-200 text-slate-500 cursor-not-allowed'
                                                : slot.is_uploaded
                                                ? 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-100'
                                                : 'bg-indigo-600 text-white hover:bg-indigo-700'
                                        }`}
                                    >
                                        <Upload className="h-3.5 w-3.5" />
                                        {isSubmitting && uploadingType === slot.type
                                            ? 'Mengunggah...'
                                            : slot.is_uploaded
                                            ? 'Ganti Berkas'
                                            : 'Unggah PDF'}
                                        <input
                                            type="file"
                                            accept="application/pdf"
                                            className="hidden"
                                            disabled={isSubmitting}
                                            onChange={(e) => {
                                                const file = e.target.files?.[0];
                                                if (file) handleFileSelect(slot.type, file);
                                                e.target.value = '';
                                            }}
                                        />
                                    </label>

                                    {slot.is_uploaded && slot.document && (
                                        <button
                                            type="button"
                                            onClick={() => handleDelete(slot.document)}
                                            className="inline-flex items-center p-1.5 rounded text-rose-600 hover:bg-rose-50 border border-transparent hover:border-rose-200"
                                            title="Hapus Dokumen"
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </button>
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}
