import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import { UserCheck, ArrowLeft } from 'lucide-react';

/**
 * Form tambah Nasabah BSM Makro — Pendaftaran Mandiri.
 *
 * Nasabah BSM Makro beroperasi secara mandiri tanpa ketergantungan pada data Anggota Mikro.
 */
export default function Create({ flash }) {
    const form = useForm({
        name: '',
        nik: '',
        phone: '',
        email: '',
        address: '',
        work_unit: '',
        pension_id: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('shm.customers.store'));
    };

    return (
        <ShmLayout title="Tambah Nasabah BSM Makro">
            <Head title="Tambah Nasabah BSM Makro" />

            <div className="mx-auto max-w-4xl space-y-6">
                <div>
                    <Link
                        href={route('shm.customers.index')}
                        className="mb-2 inline-flex items-center gap-1 text-sm text-indigo-600 hover:underline"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Kembali ke daftar nasabah
                    </Link>
                    <h1 className="text-xl font-semibold text-gray-800">Tambah Nasabah BSM Makro</h1>
                    <p className="text-sm text-gray-500">
                        Nasabah BSM Makro beroperasi secara mandiri. Isi formulir data diri nasabah di bawah ini.
                    </p>
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
                        {flash.error}
                    </div>
                )}

                {/* FORM DATA NASABAH MANDIRI */}
                <form onSubmit={submit} className="space-y-5 rounded-lg bg-white p-6 shadow">
                    <div className="flex items-center justify-between border-b pb-3">
                        <div className="flex items-center gap-2">
                            <UserCheck className="h-5 w-5 text-indigo-600" />
                            <h2 className="text-base font-medium text-gray-900">
                                Formulir Pendaftaran Nasabah Mandiri
                            </h2>
                        </div>
                    </div>

                    <div className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        Nomor Kode Nasabah BSM Makro dibuat otomatis oleh sistem dengan sequence mandiri
                        (format NNNNN-ANG, mis. 00001-ANG).
                    </div>

                    <div>
                        <InputLabel htmlFor="name" value="Nama Nasabah BSM Makro *" />
                        <TextInput
                            id="name"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            className="mt-1"
                            placeholder="Masukkan nama lengkap nasabah"
                            required
                        />
                        <InputError message={form.errors.name} className="mt-1" />
                    </div>

                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="nik" value="NIK" />
                            <TextInput
                                id="nik"
                                value={form.data.nik}
                                onChange={(e) => form.setData('nik', e.target.value)}
                                className="mt-1"
                                placeholder="16 digit NIK"
                            />
                            <InputError message={form.errors.nik} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="phone" value="Nomor Telepon" />
                            <TextInput
                                id="phone"
                                value={form.data.phone}
                                onChange={(e) => form.setData('phone', e.target.value)}
                                className="mt-1"
                                placeholder="08xxxxxxxxxx"
                            />
                            <InputError message={form.errors.phone} className="mt-1" />
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="email" value="Email (Opsional)" />
                            <TextInput
                                id="email"
                                type="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                className="mt-1"
                                placeholder="email@contoh.com"
                            />
                            <InputError message={form.errors.email} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="pension_id" value="Nomor Pensiun / Nopen (Opsional)" />
                            <TextInput
                                id="pension_id"
                                value={form.data.pension_id}
                                onChange={(e) => form.setData('pension_id', e.target.value)}
                                className="mt-1"
                                placeholder="Nomor pensiun atau identitas dinas"
                            />
                            <InputError message={form.errors.pension_id} className="mt-1" />
                        </div>
                    </div>

                    <div>
                        <InputLabel htmlFor="work_unit" value="Instansi / Unit Kerja (Opsional)" />
                        <TextInput
                            id="work_unit"
                            value={form.data.work_unit}
                            onChange={(e) => form.setData('work_unit', e.target.value)}
                            className="mt-1"
                            placeholder="Unit kerja atau instansi asal"
                        />
                        <InputError message={form.errors.work_unit} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="address" value="Alamat Lengkap" />
                        <textarea
                            id="address"
                            rows={3}
                            value={form.data.address}
                            onChange={(e) => form.setData('address', e.target.value)}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="Alamat domisili lengkap nasabah"
                        />
                        <InputError message={form.errors.address} className="mt-1" />
                    </div>

                    <div className="flex items-center justify-end gap-3 pt-4 border-t">
                        <Link href={route('shm.customers.index')}>
                            <SecondaryButton type="button">
                                Batal
                            </SecondaryButton>
                        </Link>
                        <PrimaryButton disabled={form.processing}>
                            Simpan Nasabah BSM Makro
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </ShmLayout>
    );
}
