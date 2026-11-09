import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import StatusBadge from '@/Components/Admin/StatusBadge';
import { formatDateTime } from '@/utils/format';
import { ArrowLeft, UserRound, FileText, MapPin, Building2, CheckCircle2 } from 'lucide-react';

/**
 * Form pembuatan data Memorandum SHM/SHGB (Ticket 2).
 *
 * Persyaratan:
 * 1. Data sumber HARUS berasal dari pengajuan SHM (ShmApplication) yang berstatus Selesai (Completed).
 * 2. Menampilkan read-only preview dari Pengajuan terpilih, Nasabah, dan Objek Jaminan (Property SHM/SHGB).
 * 3. Catatan dan status operasional (draft/submitted) diinput oleh user.
 */
export default function ShmShgbCreate({
    applications,
    selected_application,
    statuses,
    flash,
}) {
    const [selectedId, setSelectedId] = useState(
        selected_application?.id ? String(selected_application.id) : '',
    );
    const [form, setForm] = useState({
        status: 'draft',
        notes: '',
    });
    const [errors, setErrors] = useState({});

    const pickApplication = (id) => {
        setSelectedId(id);
        if (id) {
            router.get(
                route('shm.memorandums.shm-shgb.create'),
                { application_id: id },
                { preserveState: true, replace: true },
            );
        }
    };

    const submit = (event) => {
        event.preventDefault();
        setErrors({});
        router.post(
            route('shm.memorandums.shm-shgb.store'),
            {
                shm_application_id: selectedId,
                status: form.status,
                notes: form.notes,
            },
            {
                onError: (e) => setErrors(e),
            },
        );
    };

    const app = selected_application;
    const customer = app?.customer;
    const properties = app?.properties || [];

    return (
        <ShmLayout title="Buat Data Memorandum SHM/SHGB">
            <Head title="Buat Data Memorandum SHM/SHGB" />

            <div className="space-y-6">
                <div>
                    <Link
                        href={route('shm.memorandums.shm-shgb.index')}
                        className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" />
                        Kembali ke Daftar SHM/SHGB
                    </Link>
                    <h1 className="text-xl font-semibold text-gray-800">Buat Data SHM/SHGB Baru</h1>
                    <p className="text-sm text-gray-500">
                        Pilih pengajuan yang telah selesai (Completed) untuk dikonversikan menjadi data memorandum SHM/SHGB.
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
                                    <InputLabel htmlFor="application_id" value="Pilih Pengajuan Selesai (Completed) *" />
                                    <p className="mb-2 text-xs text-slate-500">
                                        Hanya pengajuan dengan status Selesai yang belum terdaftar di SHM/SHGB yang ditampilkan.
                                    </p>
                                    <select
                                        id="application_id"
                                        value={selectedId}
                                        onChange={(e) => pickApplication(e.target.value)}
                                        className="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary"
                                        required
                                    >
                                        <option value="">-- Pilih Pengajuan Selesai --</option>
                                        {applications.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.application_number} — {item.customer_label} ({item.status_label})
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.shm_application_id} className="mt-1" />
                                </div>

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
                                    <InputLabel htmlFor="notes" value="Catatan / Keterangan Tambahan" />
                                    <textarea
                                        id="notes"
                                        rows={4}
                                        value={form.notes}
                                        onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                        className="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary"
                                        placeholder="Tambahkan catatan khusus memorandum jika diperlukan..."
                                    />
                                    <InputError message={errors.notes} className="mt-1" />
                                </div>

                                <div className="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                                    <Link href={route('shm.memorandums.shm-shgb.index')}>
                                        <SecondaryButton type="button">Batal</SecondaryButton>
                                    </Link>
                                    <PrimaryButton type="submit" disabled={!selectedId}>
                                        Simpan Data SHM/SHGB
                                    </PrimaryButton>
                                </div>
                            </form>
                        </section>
                    </div>

                    {/* Preview Context Section */}
                    <div className="space-y-6">
                        <div className="rounded-lg bg-white p-5 shadow-sm border border-slate-200">
                            <h2 className="text-sm font-semibold uppercase tracking-wider text-slate-500 mb-4 flex items-center gap-1.5">
                                <FileText className="h-4 w-4 text-bsm-primary" />
                                Data Sumber Pengajuan
                            </h2>

                            {app ? (
                                <div className="space-y-4 text-xs">
                                    <div>
                                        <span className="text-slate-400 block mb-0.5">Nomor Pengajuan</span>
                                        <span className="font-semibold text-slate-800 text-sm">{app.application_number}</span>
                                    </div>

                                    <div>
                                        <span className="text-slate-400 block mb-0.5">Status Pengajuan</span>
                                        <StatusBadge status="COMPLETED" label={app.status_label} />
                                    </div>

                                    {app.shgb_start_date && app.shgb_end_date && (
                                        <div className="pt-2 border-t border-slate-100">
                                            <span className="text-slate-400 block mb-0.5">Masa Berlaku SHGB (dari Pengajuan)</span>
                                            <span className="font-semibold text-slate-700">
                                                {app.shgb_start_date} s/d {app.shgb_end_date}
                                            </span>
                                        </div>
                                    )}

                                    {customer && (
                                        <div className="pt-3 border-t border-slate-100">
                                            <span className="text-slate-400 block mb-1 font-medium flex items-center gap-1">
                                                <UserRound className="h-3 w-3" /> Data Nasabah
                                            </span>
                                            <div className="bg-slate-50 p-2.5 rounded border border-slate-100 space-y-1">
                                                <div className="font-semibold text-slate-700">{customer.name}</div>
                                                <div className="text-slate-500">NIK: {customer.nik || '-'}</div>
                                                <div className="text-slate-500">Telp: {customer.phone || '-'}</div>
                                            </div>
                                        </div>
                                    )}

                                    <div className="pt-3 border-t border-slate-100">
                                        <span className="text-slate-400 block mb-1 font-medium flex items-center gap-1">
                                            <Building2 className="h-3 w-3" /> Objek Jaminan (SHM/SHGB)
                                        </span>
                                        {properties.length > 0 ? (
                                            <div className="space-y-2">
                                                {properties.map((prop, idx) => (
                                                    <div key={prop.id || idx} className="bg-slate-50 p-2.5 rounded border border-slate-100 space-y-1">
                                                        <div className="flex items-center justify-between">
                                                            <span className="font-semibold text-slate-700">
                                                                {prop.certificate_type || 'SHM'}
                                                            </span>
                                                            <span className="text-slate-500 font-mono text-[11px]">
                                                                No: {prop.certificate_number || '-'}
                                                            </span>
                                                        </div>
                                                        <div className="text-slate-600">Atas Nama: {prop.owner_name || '-'}</div>
                                                        <div className="text-slate-500 flex items-start gap-1">
                                                            <MapPin className="h-3 w-3 mt-0.5 shrink-0" />
                                                            <span>{prop.address || '-'}</span>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        ) : (
                                            <div className="text-slate-400 italic">Tidak ada rincian properti terdaftar</div>
                                        )}
                                    </div>
                                </div>
                            ) : (
                                <div className="text-center py-8 text-slate-400">
                                    <FileText className="h-8 w-8 mx-auto mb-2 stroke-[1.5] text-slate-300" />
                                    <p className="text-xs">Pilih pengajuan terlebih dahulu untuk melihat pratinjau data.</p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </ShmLayout>
    );
}

