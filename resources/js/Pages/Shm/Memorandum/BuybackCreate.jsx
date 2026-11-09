import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import StatusBadge from '@/Components/Admin/StatusBadge';
import { ArrowLeft, UserRound, FileText, MapPin, Building2, RefreshCw, FolderOpen, CheckCircle2, AlertCircle } from 'lucide-react';

/**
 * Form pembuatan data Memorandum Buyback (Ticket 4).
 *
 * Persyaratan:
 * 1. Data sumber HARUS berasal dari pengajuan SHM (ShmApplication) yang bertipe Buyback (type = 'buyback')
 *    dan berstatus Selesai (Completed). Pengajuan SHGB ditolak.
 * 2. Menampilkan read-only preview dari Pengajuan terpilih, Nasabah, dan Objek Jaminan/Aset.
 * 3. Catatan dan status operasional (draft/submitted) diinput oleh user.
 * 4. Tidak ada perhitungan finansial spekulatif.
 */
export default function BuybackCreate({
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
                route('shm.memorandums.buyback.create'),
                { application_id: id },
                { preserveState: true, replace: true },
            );
        }
    };

    const submit = (event) => {
        event.preventDefault();
        setErrors({});
        router.post(
            route('shm.memorandums.buyback.store'),
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
        <ShmLayout title="Tambah Memorandum Buyback">
            <Head title="Tambah Memorandum Buyback" />

            <div className="space-y-6">
                <div>
                    <Link
                        href={route('shm.memorandums.buyback.index')}
                        className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" />
                        Kembali ke Daftar Buyback
                    </Link>
                    <h1 className="text-xl font-semibold text-gray-800">Tambah Data Memorandum Buyback</h1>
                    <p className="text-sm text-gray-500">
                        Pilih pengajuan bertipe Buyback yang telah selesai (Completed) untuk dikonversikan menjadi berkas Memorandum Buyback.
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
                                    <InputLabel htmlFor="application_id" value="Pilih Pengajuan Buyback Selesai (Completed) *" />
                                    <p className="mb-2 text-xs text-slate-500">
                                        Hanya pengajuan dengan tipe Buyback dan status Selesai yang belum terdaftar di Memorandum Buyback yang ditampilkan.
                                    </p>
                                    <select
                                        id="application_id"
                                        value={selectedId}
                                        onChange={(e) => pickApplication(e.target.value)}
                                        className="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary"
                                        required
                                    >
                                        <option value="">-- Pilih Pengajuan Buyback Selesai --</option>
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
                                    <InputLabel htmlFor="notes" value="Catatan / Perjanjian Buyback" />
                                    <textarea
                                        id="notes"
                                        rows={5}
                                        value={form.notes}
                                        onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                        className="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary leading-relaxed"
                                        placeholder="Tambahkan rincian naskah atau catatan khusus memorandum Buyback..."
                                    />
                                    <InputError message={errors.notes} className="mt-1" />
                                </div>

                                <div className="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                                    <Link href={route('shm.memorandums.buyback.index')}>
                                        <SecondaryButton type="button">Batal</SecondaryButton>
                                    </Link>
                                    <PrimaryButton type="submit" disabled={!selectedId}>
                                        Simpan Data Buyback
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
                                Data Sumber Pengajuan Buyback
                            </h2>

                            {app ? (
                                <div className="space-y-4 text-xs">
                                    <div>
                                        <span className="text-slate-400 block mb-0.5">Nomor Pengajuan</span>
                                        <span className="font-semibold text-slate-800 text-sm">{app.application_number}</span>
                                    </div>

                                    <div>
                                        <span className="text-slate-400 block mb-0.5">Tipe Pengajuan</span>
                                        <span className="inline-flex items-center gap-1 font-semibold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded border border-indigo-200">
                                            <RefreshCw className="h-3 w-3" /> Buyback
                                        </span>
                                    </div>

                                    <div>
                                        <span className="text-slate-400 block mb-0.5">Status Pengajuan</span>
                                        <StatusBadge status="COMPLETED" label={app.status_label} />
                                    </div>

                                    {app.buyback_documents && (
                                        <div className="pt-3 border-t border-slate-100">
                                            <div className="flex items-center justify-between mb-1.5">
                                                <span className="text-slate-400 font-medium flex items-center gap-1">
                                                    <FolderOpen className="h-3 w-3" /> Dokumen Legalitas (dari Pengajuan)
                                                </span>
                                                <span className={`text-[10px] font-semibold px-1.5 py-0.5 rounded ${
                                                    app.is_buyback_documents_complete ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'
                                                }`}>
                                                    {app.buyback_documents_ratio}
                                                </span>
                                            </div>
                                            <div className="space-y-1">
                                                {app.buyback_documents.map((slot) => (
                                                    <div key={slot.type} className="flex items-center justify-between text-[11px] p-1.5 rounded bg-slate-50 border border-slate-100">
                                                        <span className="text-slate-700">{slot.label}</span>
                                                        {slot.is_uploaded ? (
                                                            <span className="text-emerald-600 font-medium flex items-center gap-0.5">
                                                                <CheckCircle2 className="h-3 w-3" /> Ada
                                                            </span>
                                                        ) : (
                                                            <span className="text-rose-500 font-medium flex items-center gap-0.5">
                                                                <AlertCircle className="h-3 w-3" /> Belum
                                                            </span>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
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
                                            <Building2 className="h-3 w-3" /> Objek Agunan / Aset Terkait
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

