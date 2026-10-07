import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

/**
 * Form edit Nasabah SHM.
 *
 * Nomor nasabah tidak dapat diubah. Status aktif/nonaktif dikelola dari
 * halaman detail, bukan dari form ini.
 */
export default function Edit({ customer, flash }) {
    const form = useForm({
        name: customer.name ?? '',
        nik: customer.nik ?? '',
        phone: customer.phone ?? '',
        email: customer.email ?? '',
        address: customer.address ?? '',
        latitude: customer.latitude ?? '',
        longitude: customer.longitude ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.put(route('shm.customers.update', customer.id));
    };

    return (
        <ShmLayout title={`Edit Nasabah ${customer.customer_number}`}>
            <Head title={`Edit Nasabah ${customer.customer_number}`} />

            <div className="mx-auto max-w-3xl space-y-6">
                <div>
                    <h1 className="text-xl font-semibold text-gray-800">
                        Edit Nasabah {customer.customer_number}
                    </h1>
                    <p className="text-sm text-gray-500">
                        Nomor nasabah dibuat server dan tidak dapat diubah.
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
                            />
                            <InputError message={form.errors.longitude} className="mt-1" />
                        </div>
                    </div>

                    <div className="flex items-center justify-end gap-3">
                        <Link href={route('shm.customers.show', customer.id)}>
                            <SecondaryButton>Batal</SecondaryButton>
                        </Link>
                        <PrimaryButton type="submit" disabled={form.processing}>
                            {form.processing ? 'Menyimpan...' : 'Simpan Perubahan'}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </ShmLayout>
    );
}
