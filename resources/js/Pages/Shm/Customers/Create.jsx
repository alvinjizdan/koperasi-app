import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

/**
 * Form tambah Nasabah SHM.
 *
 * Nomor nasabah tidak ada di form karena selalu dibuat server. Nasabah baru
 * selalu dimulai sebagai aktif; penonaktifan dilakukan dari halaman detail.
 */
export default function Create({ flash }) {
    const form = useForm({
        name: '',
        nik: '',
        phone: '',
        email: '',
        address: '',
        latitude: '',
        longitude: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('shm.customers.store'));
    };

    return (
        <ShmLayout title="Tambah Nasabah SHM">
            <Head title="Tambah Nasabah SHM" />

            <div className="mx-auto max-w-3xl space-y-6">
                <div>
                    <h1 className="text-xl font-semibold text-gray-800">Tambah Nasabah SHM</h1>
                    <p className="text-sm text-gray-500">
                        Nasabah SHM tidak dianggap sebagai anggota koperasi.
                    </p>
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}

                <form onSubmit={submit} className="space-y-5 rounded-lg bg-white p-6 shadow">
                    <div>
                        <InputLabel htmlFor="name" value="Nama Nasabah" />
                        <TextInput
                            id="name"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            className="mt-1"
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
                                placeholder="Opsional"
                            />
                            <InputError message={form.errors.nik} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="phone" value="Telepon" />
                            <TextInput
                                id="phone"
                                value={form.data.phone}
                                onChange={(e) => form.setData('phone', e.target.value)}
                                className="mt-1"
                                placeholder="Opsional"
                            />
                            <InputError message={form.errors.phone} className="mt-1" />
                        </div>
                    </div>

                    <div>
                        <InputLabel htmlFor="email" value="Email" />
                        <TextInput
                            id="email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            className="mt-1"
                            placeholder="Opsional"
                        />
                        <InputError message={form.errors.email} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="address" value="Alamat" />
                        <textarea
                            id="address"
                            rows={3}
                            value={form.data.address}
                            onChange={(e) => form.setData('address', e.target.value)}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                        <InputError message={form.errors.address} className="mt-1" />
                    </div>

                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="latitude" value="Latitude" />
                            <TextInput
                                id="latitude"
                                value={form.data.latitude}
                                onChange={(e) => form.setData('latitude', e.target.value)}
                                className="mt-1"
                                placeholder="-6.2"
                            />
                            <InputError message={form.errors.latitude} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="longitude" value="Longitude" />
                            <TextInput
                                id="longitude"
                                value={form.data.longitude}
                                onChange={(e) => form.setData('longitude', e.target.value)}
                                className="mt-1"
                                placeholder="106.8"
                            />
                            <InputError message={form.errors.longitude} className="mt-1" />
                        </div>
                    </div>
                    <p className="text-xs text-gray-500">
                        Koordinat bersifat opsional dan tidak dipakai sebagai validasi apa pun.
                    </p>

                    <div className="flex items-center justify-end gap-3">
                        <Link href={route('shm.customers.index')}>
                            <SecondaryButton>Batal</SecondaryButton>
                        </Link>
                        <PrimaryButton type="submit" disabled={form.processing}>
                            {form.processing ? 'Menyimpan...' : 'Simpan Nasabah'}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </ShmLayout>
    );
}
