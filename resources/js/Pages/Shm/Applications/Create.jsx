import React, { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

/**
 * Form pembuatan pengajuan SHM.
 *
 * Nasabah pengajuan adalah Nasabah SHM, BUKAN anggota koperasi. Pengguna
 * memilih nasabah yang sudah terdaftar, atau membuat nasabah baru langsung
 * dari flow ini. Nasabah baru dibuat sebagai master nasabah SHM dan tidak
 * pernah otomatis menjadi anggota koperasi.
 *
 * Nomor pengajuan maupun nomor nasabah tidak ada di form ini karena selalu
 * dibuat server.
 */
export default function Create({ customers, can_create_customer, flash }) {
    const [mode, setMode] = useState('existing');

    const form = useForm({
        shm_customer_id: '',
        new_customer: {
            name: '',
            nik: '',
            phone: '',
        },
        notes: '',
    });

    const submit = (event) => {
        event.preventDefault();

        if (mode === 'existing') {
            form.transform((data) => ({
                ...data,
                new_customer: { name: '', nik: '', phone: '' },
            })).post(route('shm.applications.store'));

            return;
        }

        form.transform((data) => ({
            ...data,
            shm_customer_id: '',
        })).post(route('shm.applications.store'));
    };

    return (
        <ShmLayout title="Buat Pengajuan SHM">
            <Head title="Buat Pengajuan SHM" />

            <div className="mx-auto max-w-2xl space-y-6">
                <div>
                    <h1 className="text-xl font-semibold text-gray-800">Buat Pengajuan SHM</h1>
                    <p className="text-sm text-gray-500">
                        Pengajuan akan tersimpan sebagai draft dan dapat dikirim setelah dilengkapi.
                    </p>
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}

                <form onSubmit={submit} className="space-y-5 rounded-lg bg-white p-6 shadow">
                    <div>
                        <InputLabel value="Nasabah SHM" />
                        <div className="mt-2 flex flex-wrap gap-4 text-sm">
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="customer_mode"
                                    value="existing"
                                    checked={mode === 'existing'}
                                    onChange={() => setMode('existing')}
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
                                    />
                                    + Nasabah Baru
                                </label>
                            )}
                        </div>
                    </div>

                    {mode === 'existing' ? (
                        <div>
                            <InputLabel htmlFor="shm_customer_id" value="Pilih Nasabah" />
                            <select
                                id="shm_customer_id"
                                value={form.data.shm_customer_id}
                                onChange={(e) => form.setData('shm_customer_id', e.target.value)}
                                className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
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
                        <div className="space-y-4 rounded-lg border border-slate-200 p-4">
                            <p className="text-xs text-gray-500">
                                Nasabah baru akan dibuat di master Nasabah SHM. Ini tidak membuat
                                anggota koperasi.
                            </p>
                            <div>
                                <InputLabel htmlFor="new_customer_name" value="Nama Nasabah" />
                                <TextInput
                                    id="new_customer_name"
                                    value={form.data.new_customer.name}
                                    onChange={(e) =>
                                        form.setData('new_customer.name', e.target.value)
                                    }
                                    className="mt-1"
                                />
                                <InputError
                                    message={
                                        form.errors['new_customer.name'] || form.errors.shm_customer_id
                                    }
                                    className="mt-1"
                                />
                            </div>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <InputLabel htmlFor="new_customer_nik" value="NIK" />
                                    <TextInput
                                        id="new_customer_nik"
                                        value={form.data.new_customer.nik}
                                        onChange={(e) =>
                                            form.setData('new_customer.nik', e.target.value)
                                        }
                                        className="mt-1"
                                        placeholder="Opsional"
                                    />
                                    <InputError
                                        message={form.errors['new_customer.nik']}
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <InputLabel htmlFor="new_customer_phone" value="Telepon" />
                                    <TextInput
                                        id="new_customer_phone"
                                        value={form.data.new_customer.phone}
                                        onChange={(e) =>
                                            form.setData('new_customer.phone', e.target.value)
                                        }
                                        className="mt-1"
                                        placeholder="Opsional"
                                    />
                                    <InputError
                                        message={form.errors['new_customer.phone']}
                                        className="mt-1"
                                    />
                                </div>
                            </div>
                        </div>
                    )}

                    <div>
                        <InputLabel htmlFor="notes" value="Catatan" />
                        <textarea
                            id="notes"
                            rows={4}
                            value={form.data.notes}
                            onChange={(e) => form.setData('notes', e.target.value)}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                        <InputError message={form.errors.notes} className="mt-1" />
                    </div>

                    <div className="flex items-center justify-end gap-3">
                        <Link href={route('shm.applications.index')}>
                            <SecondaryButton>Batal</SecondaryButton>
                        </Link>
                        <PrimaryButton type="submit" disabled={form.processing}>
                            {form.processing ? 'Menyimpan...' : 'Simpan Draft'}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </ShmLayout>
    );
}
