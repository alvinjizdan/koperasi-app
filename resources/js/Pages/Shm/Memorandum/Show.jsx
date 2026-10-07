import React from 'react';
import { Head, Link } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import StatusBadge from '@/Components/Admin/StatusBadge';
import SecondaryButton from '@/Components/SecondaryButton';
import { formatDateTime } from '@/utils/format';
import { ArrowLeft, UserRound, FileText, ClipboardCheck, ScrollText, FolderOpen } from 'lucide-react';

/**
 * Detail memorandum (Ticket 49).
 *
 * Menjawab dalam satu halaman: milik siapa, pengajuan apa, objek mana,
 * kategori apa, statusnya apa, siapa yang membuat, dan apa aktivitas
 * terakhirnya — tanpa membuat sistem timeline baru (riwayat memakai
 * audit_logs yang sudah ada).
 */
export default function Show({
    memorandum,
    application,
    properties,
    credit_check,
    history,
    can_edit,
    flash,
}) {
    const customer = application?.customer;

    return (
        <ShmLayout title={`Memorandum ${application?.application_number ?? memorandum.id}`}>
            <Head title={`Memorandum ${application?.application_number ?? memorandum.id}`} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link
                            href={route('shm.memorandums.index')}
                            className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"
                        >
                            <ArrowLeft className="h-3.5 w-3.5" />
                            Kembali ke Daftar Memorandum
                        </Link>
                        <h1 className="text-xl font-semibold text-gray-800">
                            Memorandum — {application?.application_number ?? '-'}
                        </h1>
                        <p className="mt-1 flex items-center gap-2 text-sm text-gray-500">
                            <span className="rounded-full border border-slate-200 bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600">
                                {memorandum.type_label}
                            </span>
                            <StatusBadge
                                status={memorandum.status === 'submitted' ? 'COMPLETED' : 'PENDING'}
                                label={memorandum.status_label}
                            />
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Link href={route('shm.applications.show', application.id)}>
                            <SecondaryButton>Buka Pengajuan</SecondaryButton>
                        </Link>
                        {can_edit && (
                            <Link href={route('shm.memorandums.edit', memorandum.id)}>
                                <SecondaryButton>Edit Memorandum</SecondaryButton>
                            </Link>
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
                        {/* SECTION — Memorandum */}
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <ScrollText className="h-4 w-4" />
                                Data Memorandum
                            </h2>
                            <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt className="text-xs text-gray-500">Kategori</dt>
                                    <dd className="font-medium">{memorandum.type_label}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Status</dt>
                                    <dd className="font-medium">{memorandum.status_label}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Dibuat Oleh</dt>
                                    <dd>{memorandum.creator ?? '-'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Dibuat</dt>
                                    <dd>{formatDateTime(memorandum.created_at)}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Diperbarui</dt>
                                    <dd>{formatDateTime(memorandum.updated_at)}</dd>
                                </div>
                                {memorandum.submitted_at && (
                                    <div>
                                        <dt className="text-xs text-gray-500">Diajukan</dt>
                                        <dd>{formatDateTime(memorandum.submitted_at)}</dd>
                                    </div>
                                )}
                                <div className="sm:col-span-2">
                                    <dt className="text-xs text-gray-500">Naskah / Catatan</dt>
                                    <dd className="whitespace-pre-line">
                                        {memorandum.notes ?? '-'}
                                    </dd>
                                </div>
                            </dl>
                            <p className="mt-3 text-xs text-gray-500">
                                Tidak ada nominal, margin, bunga, atau formula buyback pada
                                memorandum. Seluruh aturan finansial masih menunggu keputusan KSP.
                            </p>
                        </section>

                        {/* SECTION — Riwayat (audit existing, bukan timeline baru) */}
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
                                            <p className="font-medium text-gray-700">
                                                {entry.description ?? entry.action}
                                            </p>
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

                    <div className="space-y-6">
                        {/* SECTION — Pengajuan */}
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <FileText className="h-4 w-4" />
                                Pengajuan
                            </h2>
                            <dl className="space-y-3 text-sm">
                                <div>
                                    <dt className="text-xs text-gray-500">No. Pengajuan</dt>
                                    <dd>
                                        <Link
                                            href={route('shm.applications.show', application.id)}
                                            className="font-medium text-indigo-600 hover:underline"
                                        >
                                            {application.application_number}
                                        </Link>
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Tahap</dt>
                                    <dd className="font-medium">{application.status_label}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Marketing</dt>
                                    <dd>{application.marketing ?? '-'}</dd>
                                </div>
                            </dl>
                        </section>

                        {/* SECTION — Nasabah */}
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <UserRound className="h-4 w-4" />
                                Nasabah SHM
                            </h2>
                            {!customer ? (
                                <p className="text-sm text-gray-500">Data nasabah tidak ditemukan.</p>
                            ) : (
                                <dl className="space-y-3 text-sm">
                                    <div>
                                        <dt className="text-xs text-gray-500">Nama</dt>
                                        <dd className="font-medium">{customer.name}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500">Nomor Nasabah</dt>
                                        <dd>{customer.customer_number}</dd>
                                    </div>
                                    {customer.nik && (
                                        <div>
                                            <dt className="text-xs text-gray-500">NIK</dt>
                                            <dd>{customer.nik}</dd>
                                        </div>
                                    )}
                                    {customer.phone && (
                                        <div>
                                            <dt className="text-xs text-gray-500">Telepon</dt>
                                            <dd>{customer.phone}</dd>
                                        </div>
                                    )}
                                </dl>
                            )}
                        </section>

                        {/* SECTION — Objek */}
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
                                        <li
                                            key={property.id}
                                            className="rounded border border-slate-200 p-3"
                                        >
                                            <p className="font-medium">
                                                {property.certificate_type}
                                                {property.certificate_number
                                                    ? ` - ${property.certificate_number}`
                                                    : ''}
                                            </p>
                                            <p className="text-gray-600">{property.address}</p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        {/* SECTION — Referensi credit checking */}
                        {credit_check && (
                            <section className="rounded-lg bg-white p-5 shadow">
                                <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                    <ClipboardCheck className="h-4 w-4" />
                                    Referensi Credit Checking
                                </h2>
                                <dl className="space-y-2 text-sm">
                                    <div>
                                        <dt className="text-xs text-gray-500">Status</dt>
                                        <dd className="font-medium">{credit_check.status_label}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500">Hasil</dt>
                                        <dd>
                                            {credit_check.outcome_badge ? (
                                                <StatusBadge
                                                    status={credit_check.outcome_badge}
                                                    label={credit_check.outcome_label}
                                                />
                                            ) : (
                                                '-'
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500">Ringkasan</dt>
                                        <dd className="whitespace-pre-line">
                                            {credit_check.result ?? '-'}
                                        </dd>
                                    </div>
                                </dl>
                                <p className="mt-3 text-xs text-gray-500">
                                    Hasil credit checking hanya referensi; tidak ada aturan otomatis.
                                </p>
                            </section>
                        )}
                    </div>
                </div>
            </div>
        </ShmLayout>
    );
}
