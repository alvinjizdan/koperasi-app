import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import { formatDateTime } from '@/utils/format';
import { ArrowLeft, UserRound, FileText, ClipboardCheck } from 'lucide-react';

/**
 * Form pembuatan memorandum (Ticket 49).
 *
 * Alur: pilih pengajuan SHM dari daftar yang boleh diakses, lihat konteksnya
 * (nasabah, tahap, credit checking), lalu isi kategori, status, dan naskah.
 * Tidak ada field finansial apa pun.
 */
export default function Create({
    applications,
    selected_application,
    types,
    statuses,
    flash,
}) {
    const [selectedId, setSelectedId] = useState(
        selected_application?.id ? String(selected_application.id) : '',
    );
    const [form, setForm] = useState({
        type: 'shm_shgb',
        status: 'draft',
        notes: '',
    });
    const [errors, setErrors] = useState({});

    const pickApplication = (id) => {
        setSelectedId(id);
        // Konteks dimuat ulang dari server agar pengajuannya dibatasi otorisasi.
        router.get(
            route('shm.memorandums.create'),
            { application_id: id },
            { preserveState: true, replace: true },
        );
    };

    const submit = (event) => {
        event.preventDefault();
        setErrors({});
        router.post(route('shm.memorandums.store'), {
            shm_application_id: selectedId,
            ...form,
        }, { onError: (e) => setErrors(e) });
    };

    const context = selected_application;

    return (
        <ShmLayout title="Buat Memorandum">
            <Head title="Buat Memorandum" />

            <div className="space-y-6">
                <div>
                    <Link
                        href={route('shm.memorandums.index')}
                        className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" />
                        Kembali ke Daftar Memorandum
                    </Link>
                    <h1 className="text-xl font-semibold text-gray-800">Buat Memorandum</h1>
                    <p className="text-sm text-gray-500">
                        Pilih pengajuan SHM, lalu tentukan kategori memorandum. Tidak ada
                        perhitungan nominal, margin, bunga, atau formula buyback di sini.
                    </p>
                </div>

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
                                Pilih Pengajuan
                            </h2>

                            {applications.length === 0 ? (
                                <p className="text-sm text-gray-500">
                                    Belum ada pengajuan yang dapat Anda akses.
                                </p>
                            ) : (
                                <div className="max-h-80 space-y-2 overflow-y-auto pr-1">
                                    {applications.map((application) => (
                                        <button
                                            key={application.id}
                                            type="button"
                                            onClick={() => pickApplication(String(application.id))}
                                            className={`flex w-full items-center justify-between rounded-lg border px-4 py-2.5 text-left text-sm transition-colors ${
                                                selectedId === String(application.id)
                                                    ? 'border-indigo-500 bg-indigo-50'
                                                    : 'border-slate-200 hover:bg-slate-50'
                                            }`}
                                        >
                                            <span>
                                                <span className="font-medium text-slate-700">
                                                    {application.application_number}
                                                </span>
                                                <span className="ml-2 text-xs text-slate-500">
                                                    {application.customer_label}
                                                </span>
                                            </span>
                                            <span className="text-xs text-slate-500">
                                                {application.status_label}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            )}
                            <InputError message={errors.shm_application_id} className="mt-2" />
                        </section>

                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 text-sm font-semibold uppercase text-slate-600">
                                Isi Memorandum
                            </h2>

                            <form onSubmit={submit} className="space-y-4">
                                <div>
                                    <InputLabel htmlFor="memo_type" value="Kategori Memorandum" />
                                    <select
                                        id="memo_type"
                                        value={form.type}
                                        onChange={(e) => setForm({ ...form, type: e.target.value })}
                                        className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                    >
                                        {types.map((item) => (
                                            <option key={item.value} value={item.value}>
                                                {item.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.type} className="mt-1" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="memo_status" value="Status" />
                                    <select
                                        id="memo_status"
                                        value={form.status}
                                        onChange={(e) => setForm({ ...form, status: e.target.value })}
                                        className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                    >
                                        {statuses.map((item) => (
                                            <option key={item.value} value={item.value}>
                                                {item.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.status} className="mt-1" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="memo_notes" value="Naskah / Catatan" />
                                    <textarea
                                        id="memo_notes"
                                        rows={6}
                                        value={form.notes}
                                        onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                        className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                    />
                                    <InputError message={errors.notes} className="mt-1" />
                                </div>

                                <div className="flex justify-end">
                                    <PrimaryButton type="submit" disabled={!selectedId}>
                                        Simpan Memorandum
                                    </PrimaryButton>
                                </div>
                            </form>
                        </section>
                    </div>

                    <div className="space-y-6">
                        {/* Konteks pengajuan terpilih: agar Marketing memahami
                            dengan siapa dan untuk apa memorandum dibuat. */}
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <UserRound className="h-4 w-4" />
                                Konteks Pengajuan
                            </h2>

                            {!context ? (
                                <p className="text-sm text-gray-500">
                                    Pilih pengajuan untuk melihat konteksnya.
                                </p>
                            ) : (
                                <dl className="space-y-3 text-sm">
                                    <div>
                                        <dt className="text-xs text-gray-500">No. Pengajuan</dt>
                                        <dd>
                                            <Link
                                                href={route('shm.applications.show', context.id)}
                                                className="font-medium text-indigo-600 hover:underline"
                                            >
                                                {context.application_number}
                                            </Link>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500">Tahap</dt>
                                        <dd className="font-medium">{context.status_label}</dd>
                                    </div>
                                    {context.customer && (
                                        <>
                                            <div>
                                                <dt className="text-xs text-gray-500">Nama Nasabah</dt>
                                                <dd className="font-medium">{context.customer.name}</dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-gray-500">Nomor Nasabah</dt>
                                                <dd>{context.customer.customer_number}</dd>
                                            </div>
                                        </>
                                    )}
                                    <div>
                                        <dt className="text-xs text-gray-500">Marketing</dt>
                                        <dd>{context.marketing ?? '-'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500">Dibuat</dt>
                                        <dd>{formatDateTime(context.created_at)}</dd>
                                    </div>
                                </dl>
                            )}
                        </section>

                        {context?.credit_check && (
                            <section className="rounded-lg bg-white p-5 shadow">
                                <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                    <ClipboardCheck className="h-4 w-4" />
                                    Referensi Credit Checking
                                </h2>
                                <dl className="space-y-2 text-sm">
                                    <div>
                                        <dt className="text-xs text-gray-500">Hasil</dt>
                                        <dd className="font-medium">
                                            {context.credit_check.outcome_label ?? '-'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500">Ringkasan</dt>
                                        <dd className="whitespace-pre-line">
                                            {context.credit_check.result ?? '-'}
                                        </dd>
                                    </div>
                                </dl>
                                <p className="mt-3 text-xs text-gray-500">
                                    Hasil credit checking hanya referensi; tidak ada aturan otomatis
                                    yang mengubah memorandum.
                                </p>
                            </section>
                        )}
                    </div>
                </div>
            </div>
        </ShmLayout>
    );
}
