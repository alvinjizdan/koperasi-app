import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import { ArrowLeft, FileText, UserRound, Building2 } from 'lucide-react';

/**
 * Form Edit data Memorandum SHM/SHGB (Ticket 2).
 *
 * Rules:
 * - Pengajuan sumber bersifat READ-ONLY dan tidak dapat diganti (integrity protection).
 * - Nasabah & Objek Jaminan ditampilkan sebagai context preview read-only.
 * - Form hanya mengizinkan perubahan status memorandum (draft/submitted) dan catatan/notes.
 */
export default function ShmShgbEdit({ memorandum, statuses, flash }) {
    const [form, setForm] = useState({
        status: memorandum.status ?? 'draft',
        notes: memorandum.notes ?? '',
        shgb_start_date: memorandum.shgb_start_date ?? '',
        shgb_end_date: memorandum.shgb_end_date ?? '',
    });
    const [errors, setErrors] = useState({});

    const submit = (event) => {
        event.preventDefault();
        setErrors({});
        router.put(
            route('shm.memorandums.shm-shgb.update', memorandum.id),
            {
                status: form.status,
                notes: form.notes,
                shgb_start_date: form.shgb_start_date || undefined,
                shgb_end_date: form.shgb_end_date || undefined,
            },
            {
                onError: (e) => setErrors(e),
            },
        );
    };

    return (
        <ShmLayout title="Edit Data SHM/SHGB">
            <Head title="Edit Data SHM/SHGB" />

            <div className="space-y-6">
                <div>
                    <Link
                        href={route('shm.memorandums.shm-shgb.show', memorandum.id)}
                        className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" />
                        Kembali ke Detail SHM/SHGB
                    </Link>
                    <h1 className="text-xl font-semibold text-gray-800">Edit Data SHM/SHGB</h1>
                    <p className="text-sm text-gray-500">
                        Perbarui status dan catatan data memorandum SHM/SHGB.
                    </p>
                </div>

                {flash?.error && (
                    <div className="rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        {flash.error}
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

                                {/* Section Masa Berlaku SHGB (Ticket 7) */}
                                <div className="rounded-lg border border-slate-200 bg-slate-50/50 p-4 space-y-4">
                                    <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-700">
                                        Masa Berlaku SHGB
                                    </h3>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <InputLabel htmlFor="shgb_start_date" value="Tanggal Mulai Berlaku" />
                                            <input
                                                type="date"
                                                id="shgb_start_date"
                                                value={form.shgb_start_date}
                                                onChange={(e) => setForm({ ...form, shgb_start_date: e.target.value })}
                                                className="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary"
                                            />
                                            <InputError message={errors.shgb_start_date} className="mt-1" />
                                        </div>
                                        <div>
                                            <InputLabel htmlFor="shgb_end_date" value="Tanggal Berakhir" />
                                            <input
                                                type="date"
                                                id="shgb_end_date"
                                                value={form.shgb_end_date}
                                                onChange={(e) => setForm({ ...form, shgb_end_date: e.target.value })}
                                                className="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary"
                                            />
                                            <InputError message={errors.shgb_end_date} className="mt-1" />
                                        </div>
                                    </div>
                                    <p className="text-xs text-slate-500">
                                        Informasi pencatatan tanggal masa berlaku SHGB untuk keperluan legalitas.
                                    </p>
                                </div>

                                <div>
                                    <InputLabel htmlFor="notes" value="Catatan / Keterangan Tambahan" />
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
                                    <Link href={route('shm.memorandums.shm-shgb.show', memorandum.id)}>
                                        <SecondaryButton type="button">Batal</SecondaryButton>
                                    </Link>
                                    <PrimaryButton type="submit">
                                        Simpan Perubahan
                                    </PrimaryButton>
                                </div>
                            </form>
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
                                    <Building2 className="h-3 w-3" /> Objek Jaminan Terdaftar
                                </span>
                                <div className="bg-slate-50 p-2.5 rounded border border-slate-100 text-slate-600">
                                    Total Objek: <span className="font-semibold">{memorandum.properties_count ?? 0}</span> sertifikat
                                </div>
                            </div>

                            <div className="rounded bg-amber-50 p-2.5 text-amber-800 text-[11px] leading-relaxed border border-amber-200">
                                Catatan: Pengajuan asal terkunci secara permanen untuk menjaga integritas data riwayat.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </ShmLayout>
    );
}

