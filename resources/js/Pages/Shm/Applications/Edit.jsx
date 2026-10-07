import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

/**
 * Edit pengajuan yang masih berstatus draft.
 *
 * Server menolak perubahan bila pengajuan sudah keluar dari draft, sehingga
 * halaman ini hanya menampilkan form untuk pengajuan draft.
 *
 * Nasabah pengajuan adalah Nasabah SHM, bukan anggota koperasi. Nasabah baru
 * tidak dibuat dari halaman edit; gunakan halaman Nasabah SHM.
 */
export default function Edit({ application, customers, flash }) {
    const form = useForm({
        shm_customer_id: application.shm_customer_id ?? '',
        notes: application.notes ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.put(route('shm.applications.update', application.id));
    };

    return (
        <ShmLayout title="Edit Pengajuan SHM">
            <Head title="Edit Pengajuan SHM" />

            <div className="mx-auto max-w-2xl space-y-6">
                <div>
                    <h1 className="text-xl font-semibold text-gray-800">
                        Edit Pengajuan {application.application_number}
                    </h1>
                    <p className="text-sm text-gray-500">
                        Pengajuan hanya dapat diubah selama masih berstatus draft.
                    </p>
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}

                <form onSubmit={submit} className="space-y-5 rounded-lg bg-white p-6 shadow">
                    <div>
                        <InputLabel htmlFor="shm_customer_id" value="Nasabah SHM" />
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
                            Nasabah baru ditambahkan dari halaman{' '}
                            <Link
                                href={route('shm.customers.create')}
                                className="text-indigo-600 hover:underline"
                            >
                                Nasabah SHM
                            </Link>
                            .
                        </p>
                    </div>

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
                        <Link href={route('shm.applications.show', application.id)}>
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
