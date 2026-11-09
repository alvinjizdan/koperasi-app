import React, { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import { Building2, RefreshCw, FileText, Info } from 'lucide-react';

/**
 * Form pembuatan pengajuan SHM/SHGB & Buyback (Ticket 3).
 *
 * Mendukung pemilihan multi-type:
 * 1. SHGB: Menampilkan field dan petunjuk khusus SHGB (objek tanah/bangunan dilengkapi setelah draft dibuat).
 * 2. Buyback: Menampilkan field dan petunjuk khusus Buyback (kontrak & properti buyback dilengkapi setelah draft).
 *
 * Data umum (Nasabah & Catatan) digunakan bersama oleh kedua tipe pengajuan.
 */
export default function Create({ types, customers, can_create_customer, flash }) {
    const [mode, setMode] = useState('existing');

    const form = useForm({
        type: 'shgb',
        shm_customer_id: '',
        notes: '',
        shgb_start_date: '',
        shgb_end_date: '',
    });

    const submit = (event) => {
        event.preventDefault();

        // Mode "Nasabah Baru" tidak menyubmit: nasabah dibuat lewat modul Nasabah SHM
        if (mode !== 'existing') {
            return;
        }

        form.post(route('shm.applications.store'));
    };

    return (
        <ShmLayout title="Buat Pengajuan BSM Makro">
            <Head title="Buat Pengajuan BSM Makro" />

            <div className="mx-auto max-w-2xl space-y-6">
                <div>
                    <h1 className="text-xl font-semibold text-gray-800">Buat Pengajuan BSM Makro</h1>
                    <p className="text-sm text-gray-500">
                        Pilih tipe pengajuan (SHGB atau Buyback). Pengajuan akan tersimpan sebagai draft dan dapat dikirim setelah dilengkapi.
                    </p>
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}

                <form onSubmit={submit} className="space-y-6 rounded-lg bg-white p-6 shadow border border-slate-200">
                    {/* Tipe Pengajuan Selector */}
                    <div>
                        <InputLabel value="Tipe Pengajuan *" />
                        <div className="mt-2 grid grid-cols-2 gap-4">
                            <label
                                className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3.5 transition-all ${
                                    form.data.type === 'shgb'
                                        ? 'border-bsm-primary bg-blue-50/50 ring-1 ring-bsm-primary'
                                        : 'border-slate-200 hover:border-slate-300 bg-white'
                                }`}
                            >
                                <input
                                    type="radio"
                                    name="type"
                                    value="shgb"
                                    checked={form.data.type === 'shgb'}
                                    onChange={(e) => form.setData('type', e.target.value)}
                                    className="mt-0.5 text-bsm-primary focus:ring-bsm-primary"
                                />
                                <div>
                                    <div className="flex items-center gap-1.5 font-semibold text-slate-800 text-sm">
                                        <Building2 className="h-4 w-4 text-bsm-primary" />
                                        SHGB / SHM
                                    </div>
                                    <p className="mt-0.5 text-xs text-slate-500 leading-relaxed">
                                        Pengajuan pembiayaan dengan agunan sertifikat hak milik / hak guna bangunan.
                                    </p>
                                </div>
                            </label>

                            <label
                                className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3.5 transition-all ${
                                    form.data.type === 'buyback'
                                        ? 'border-bsm-primary bg-blue-50/50 ring-1 ring-bsm-primary'
                                        : 'border-slate-200 hover:border-slate-300 bg-white'
                                }`}
                            >
                                <input
                                    type="radio"
                                    name="type"
                                    value="buyback"
                                    checked={form.data.type === 'buyback'}
                                    onChange={(e) => form.setData('type', e.target.value)}
                                    className="mt-0.5 text-bsm-primary focus:ring-bsm-primary"
                                />
                                <div>
                                    <div className="flex items-center gap-1.5 font-semibold text-slate-800 text-sm">
                                        <RefreshCw className="h-4 w-4 text-indigo-600" />
                                        Buyback
                                    </div>
                                    <p className="mt-0.5 text-xs text-slate-500 leading-relaxed">
                                        Pengajuan pengalihan / pembelian kembali sertifikat dengan komitmen buyback.
                                    </p>
                                </div>
                            </label>
                        </div>
                        <InputError message={form.errors.type} className="mt-1" />
                    </div>

                    {/* Shared Data: Nasabah SHM */}
                    <div>
                        <InputLabel value="Nasabah SHM *" />
                        <div className="mt-2 flex flex-wrap gap-4 text-sm">
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="customer_mode"
                                    value="existing"
                                    checked={mode === 'existing'}
                                    onChange={() => setMode('existing')}
                                    className="text-bsm-primary focus:ring-bsm-primary"
                                />
                                Pilih nasabah terdaftar
                            </label>
                            {can_create_customer && (
                                <label className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        name="customer_mode"
                                        value="new"
                                        checked={mode === 'new'}
                                        onChange={() => setMode('new')}
                                        className="text-bsm-primary focus:ring-bsm-primary"
                                    />
                                    + Nasabah Baru
                                </label>
                            )}
                        </div>
                    </div>

                    {mode === 'existing' ? (
                        <div>
                            <InputLabel htmlFor="shm_customer_id" value="Pilih Nasabah *" />
                            <select
                                id="shm_customer_id"
                                value={form.data.shm_customer_id}
                                onChange={(e) => form.setData('shm_customer_id', e.target.value)}
                                className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary"
                                required
                            >
                                <option value="">Pilih nasabah SHM...</option>
                                {customers.map((customer) => (
                                    <option key={customer.id} value={customer.id}>
                                        {customer.name} ({customer.customer_number})
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.shm_customer_id} className="mt-1" />
                            <p className="mt-1 text-xs text-gray-500">
                                Nasabah belum terdaftar?{' '}
                                <Link
                                    href={route('shm.customers.create')}
                                    className="text-indigo-600 hover:underline"
                                >
                                    Tambah nasabah baru
                                </Link>{' '}
                                terlebih dahulu.
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-4 rounded-lg border border-slate-200 p-4 bg-slate-50">
                            <p className="text-sm text-gray-600">
                                Nasabah BSM Makro baru wajib bersumber dari anggota BSM Mikro aktif, sehingga
                                dibuat melalui modul Nasabah BSM Makro — bukan dari form pengajuan ini.
                            </p>
                            <p className="text-xs text-gray-500">
                                Setelah nasabah dibuat, kembali ke halaman ini dan pilih nasabah
                                tersebut pada pilihan "Pilih nasabah terdaftar".
                            </p>
                            <div className="flex flex-wrap items-center gap-3">
                                <Link href={route('shm.customers.create')}>
                                    <PrimaryButton type="button">Buat Nasabah BSM Makro</PrimaryButton>
                                </Link>
                                <SecondaryButton type="button" onClick={() => setMode('existing')}>
                                    Kembali memilih nasabah terdaftar
                                </SecondaryButton>
                            </div>
                        </div>
                    )}

                    {/* Type-Specific Notice & Input Box */}
                    {form.data.type === 'shgb' ? (
                        <div className="space-y-4">
                            <div className="rounded-lg border border-blue-100 bg-blue-50/70 p-3.5 text-xs text-blue-800 flex items-start gap-2.5">
                                <Info className="h-4 w-4 text-blue-600 shrink-0 mt-0.5" />
                                <div>
                                    <span className="font-semibold block mb-0.5">Pengajuan Tipe SHGB</span>
                                    Objek jaminan (sertifikat tanah/bangunan) dan checklist dokumen kelayakan akan dapat diisi pada detail pengajuan setelah berkas draft tersimpan. Anda juga dapat menentukan masa berlaku SHGB di bawah ini.
                                </div>
                            </div>

                            <div className="rounded-lg border border-slate-200 bg-slate-50/50 p-4 space-y-3">
                                <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-700">
                                    Masa Berlaku SHGB (Opsional saat Draft)
                                </h3>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <InputLabel htmlFor="shgb_start_date" value="Tanggal Mulai Berlaku SHGB" />
                                        <input
                                            type="date"
                                            id="shgb_start_date"
                                            value={form.data.shgb_start_date}
                                            onChange={(e) => form.setData('shgb_start_date', e.target.value)}
                                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary"
                                        />
                                        <InputError message={form.errors.shgb_start_date} className="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="shgb_end_date" value="Tanggal Berakhir Berlaku SHGB" />
                                        <input
                                            type="date"
                                            id="shgb_end_date"
                                            value={form.data.shgb_end_date}
                                            onChange={(e) => form.setData('shgb_end_date', e.target.value)}
                                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary"
                                        />
                                        <InputError message={form.errors.shgb_end_date} className="mt-1" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="rounded-lg border border-indigo-100 bg-indigo-50/70 p-3.5 text-xs text-indigo-800 flex items-start gap-2.5">
                            <Info className="h-4 w-4 text-indigo-600 shrink-0 mt-0.5" />
                            <div>
                                <span className="font-semibold block mb-0.5">Pengajuan Tipe Buyback</span>
                                Dokumen legalitas (SHM, Akta Buyback, Surat Kuasa, PPJB, Surat Pengosongan) diunggah dan dikelola langsung pada halaman detail pengajuan ini.
                            </div>
                        </div>
                    )}

                    {mode === 'existing' && (
                        <>
                            <div>
                                <InputLabel htmlFor="notes" value="Catatan / Keterangan Pengajuan" />
                                <textarea
                                    id="notes"
                                    rows={4}
                                    value={form.data.notes}
                                    onChange={(e) => form.setData('notes', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary"
                                    placeholder="Tambahkan catatan khusus untuk pengajuan ini jika diperlukan..."
                                />
                                <InputError message={form.errors.notes} className="mt-1" />
                            </div>

                            <div className="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                                <Link href={route('shm.applications.index')}>
                                    <SecondaryButton>Batal</SecondaryButton>
                                </Link>
                                <PrimaryButton type="submit" disabled={form.processing}>
                                    {form.processing ? 'Menyimpan...' : 'Simpan Draft'}
                                </PrimaryButton>
                            </div>
                        </>
                    )}
                </form>
            </div>
        </ShmLayout>
    );
}
