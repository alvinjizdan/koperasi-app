import React, { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

/**
 * Form pembuatan pengajuan SHM.
 *
 * Nasabah pengajuan adalah Nasabah SHM, BUKAN anggota koperasi. Form ini hanya
 * memilih nasabah yang sudah terdaftar pada master Nasabah SHM. Nasabah baru
 * dibuat melalui modul Nasabah SHM (`/shm/nasabah/create`) karena sejak T89C
 * wajib menunjuk anggota koperasi sumber secara eksplisit (`member_id`);
 * form ini TIDAK membuat nasabah secara inline sehingga tidak pernah
 * mengirim `new_customer` dan tidak pernah memilih anggota secara otomatis.
 *
 * Nomor pengajuan maupun nomor nasabah tidak ada di form ini karena selalu
 * dibuat server.
 */
export default function Create({ customers, can_create_customer, flash }) {
    const [mode, setMode] = useState('existing');

    const form = useForm({
        shm_customer_id: '',
        notes: '',
    });

    // T89F-5 — submit chain.
    //
    // `useForm().transform()` pada @inertiajs/react 2.3.28 hanya MENDAFTARKAN
    // callback transformasi dan tidak mengembalikan objek form, sehingga pola
    // lama `form.transform(...).post(...)` melempar
    // "TypeError: Cannot read properties of undefined (reading 'post')" dan
    // SIMPAN DRAFT tidak pernah mengirim request. Payload yang dikirim sudah
    // sama dengan state form (hanya nasabah terdaftar + catatan), jadi cukup
    // dipanggil sebagai method: `form.post(...)`.
    const submit = (event) => {
        event.preventDefault();

        // Mode "Nasabah Baru" tidak menyubmit apa pun: nasabah dibuat lewat
        // modul Nasabah SHM, lalu dipilih kembali pada mode ini.
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

                    {mode === 'existing' && (
                        <>
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
                        </>
                    )}
                </form>
            </div>
        </ShmLayout>
    );
}
