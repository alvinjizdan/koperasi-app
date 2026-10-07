import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import { ArrowLeft } from 'lucide-react';

/**
 * Form perubahan memorandum (Ticket 49).
 *
 * Pengajuan TIDAK dapat diganti: memorandum menempel pada pengajuannya.
 * Yang dapat diubah hanya kategori, status workflow (draft/submitted), dan
 * naskah. Tidak ada field finansial apa pun.
 */
export default function Edit({ memorandum, types, statuses, flash }) {
    const [form, setForm] = useState({
        type: memorandum.type ?? '',
        status: memorandum.status ?? 'draft',
        notes: memorandum.notes ?? '',
    });
    const [errors, setErrors] = useState({});

    const submit = (event) => {
        event.preventDefault();
        setErrors({});
        router.put(
            route('shm.memorandums.update', memorandum.id),
            { ...form, shm_application_id: memorandum.shm_application_id },
            { onError: (e) => setErrors(e) },
        );
    };

    return (
        <ShmLayout title="Edit Memorandum">
            <Head title="Edit Memorandum" />

            <div className="space-y-6">
                <div>
                    <Link
                        href={route('shm.memorandums.show', memorandum.id)}
                        className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" />
                        Kembali ke Detail Memorandum
                    </Link>
                    <h1 className="text-xl font-semibold text-gray-800">Edit Memorandum</h1>
                    <p className="text-sm text-gray-500">
                        Pengajuan: <span className="font-medium">{memorandum.application_number}</span>{' '}
                        (tahap {memorandum.application_status_label})
                    </p>
                </div>

                {flash?.error && (
                    <div className="rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        {flash.error}
                    </div>
                )}

                <section className="max-w-2xl rounded-lg bg-white p-5 shadow">
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
                            <PrimaryButton type="submit">Simpan Perubahan</PrimaryButton>
                        </div>
                    </form>
                </section>
            </div>
        </ShmLayout>
    );
}
