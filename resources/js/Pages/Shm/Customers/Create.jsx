import React, { useState } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import { Search, UserCheck, AlertCircle, ArrowLeft } from 'lucide-react';

/**
 * Form tambah Nasabah SHM — T89C Flow Resmi.
 *
 * Sesuai aturan KSP:
 * 1. Nasabah SHM TIDAK boleh dibuat bebas/manual tanpa anggota sumber.
 * 2. User mencari dan memilih Anggota Koperasi aktif.
 * 3. Preview data anggota ditampilkan.
 * 4. Submit membuat record shm_customers baru yang menyimpan member_id.
 */
export default function Create({ members = [], filters = {}, flash }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [selectedMember, setSelectedMember] = useState(null);

    const form = useForm({
        member_id: '',
        name: '',
        nik: '',
        phone: '',
        email: '',
        address: '',
        latitude: '',
        longitude: '',
    });

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(
            route('shm.customers.create'),
            { search: searchQuery },
            { preserveState: true, replace: true }
        );
    };

    const handleSelectMember = (member) => {
        if (member.has_active_shm_customer) {
            return;
        }

        setSelectedMember(member);
        form.setData({
            member_id: member.id,
            name: member.full_name || '',
            nik: member.identity_number || '',
            phone: member.phone || '',
            email: '',
            address: member.address || '',
            latitude: '',
            longitude: '',
        });
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route('shm.customers.store'));
    };

    return (
        <ShmLayout title="Tambah Nasabah SHM">
            <Head title="Tambah Nasabah SHM" />

            <div className="mx-auto max-w-4xl space-y-6">
                <div>
                    <Link
                        href={route('shm.customers.index')}
                        className="mb-2 inline-flex items-center gap-1 text-sm text-indigo-600 hover:underline"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Kembali ke daftar nasabah
                    </Link>
                    <h1 className="text-xl font-semibold text-gray-800">Tambah Nasabah SHM</h1>
                    <p className="text-sm text-gray-500">
                        Nasabah SHM harus bersumber dari Anggota Koperasi aktif. Pilih anggota sumber di bawah.
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

                {/* LANGKAH 1: CARI & PILIH ANGGOTA KOPERASI */}
                <div className="rounded-lg bg-white p-6 shadow">
                    <h2 className="text-base font-medium text-gray-900 mb-2">1. Pilih Anggota Koperasi</h2>
                    <p className="text-xs text-gray-500 mb-4">
                        Cari anggota berdasarkan nama, nomor anggota, NIK, atau nomor telepon.
                    </p>

                    <form onSubmit={handleSearch} className="mb-4 flex gap-2">
                        <div className="relative flex-1">
                            <TextInput
                                type="text"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                placeholder="Cari nama, No. Anggota, NIK, dsb..."
                                className="w-full pl-9"
                            />
                            <Search className="absolute left-3 top-2.5 h-4 w-4 text-gray-400" />
                        </div>
                        <SecondaryButton type="submit">Cari</SecondaryButton>
                    </form>

                    <div className="max-h-60 overflow-y-auto divide-y rounded-md border border-slate-200">
                        {members.length === 0 ? (
                            <div className="p-4 text-center text-sm text-gray-500">
                                Tidak ada data anggota aktif yang ditemukan.
                            </div>
                        ) : (
                            members.map((member) => (
                                <div
                                    key={member.id}
                                    className={`flex items-center justify-between p-3 transition-colors ${
                                        selectedMember?.id === member.id
                                            ? 'bg-indigo-50 border-l-4 border-indigo-600'
                                            : member.has_active_shm_customer
                                            ? 'bg-slate-50 opacity-60'
                                            : 'hover:bg-slate-50 cursor-pointer'
                                    }`}
                                    onClick={() => handleSelectMember(member)}
                                >
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <span className="font-semibold text-slate-800 text-sm">
                                                {member.full_name}
                                            </span>
                                            <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                                                {member.member_number}
                                            </span>
                                            {member.has_active_shm_customer && (
                                                <span className="rounded bg-amber-100 px-2 py-0.5 text-[10px] text-amber-700 font-medium">
                                                    Sudah ada Nasabah SHM
                                                </span>
                                            )}
                                        </div>
                                        <div className="mt-1 text-xs text-slate-500 flex gap-4">
                                            <span>NIK: {member.identity_number || '-'}</span>
                                            <span>Telp: {member.phone || '-'}</span>
                                        </div>
                                    </div>
                                    <div>
                                        <button
                                            type="button"
                                            disabled={member.has_active_shm_customer}
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                handleSelectMember(member);
                                            }}
                                            className={`rounded-md px-3 py-1 text-xs font-semibold ${
                                                selectedMember?.id === member.id
                                                    ? 'bg-indigo-600 text-white'
                                                    : member.has_active_shm_customer
                                                    ? 'bg-slate-200 text-slate-400 cursor-not-allowed'
                                                    : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100'
                                            }`}
                                        >
                                            {selectedMember?.id === member.id ? 'Terpilih' : 'Pilih'}
                                        </button>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                    {form.errors.member_id && (
                        <InputError message={form.errors.member_id} className="mt-2" />
                    )}
                </div>

                {/* LANGKAH 2: PREVIEW & DETAIL NASABAH SHM */}
                {selectedMember && (
                    <form onSubmit={submit} className="space-y-5 rounded-lg bg-white p-6 shadow">
                        <div className="flex items-center justify-between border-b pb-3">
                            <div className="flex items-center gap-2">
                                <UserCheck className="h-5 w-5 text-indigo-600" />
                                <h2 className="text-base font-medium text-gray-900">
                                    2. Data Nasabah SHM (Sumber: {selectedMember.full_name})
                                </h2>
                            </div>
                            <span className="text-xs text-slate-500">
                                Member: {selectedMember.member_number} - {selectedMember.full_name}
                            </span>
                        </div>

                        <div className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                            Kode Nasabah SHM dibuat otomatis oleh sistem dengan sequence sendiri
                            (format NNNNN-ANG, mis. 00001-ANG) — TERPISAH dari nomor anggota di atas.
                            Kode tidak diketik manual.
                        </div>

                        <div>
                            <InputLabel htmlFor="name" value="Nama Nasabah SHM" />
                            <TextInput
                                id="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                className="mt-1"
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
                            <InputLabel htmlFor="address" value="Alamat" />
                            <textarea
                                id="address"
                                rows={3}
                                value={form.data.address}
                                onChange={(e) => form.setData('address', e.target.value)}
                                className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                            <InputError message={form.errors.address} className="mt-1" />
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t">
                            <SecondaryButton
                                type="button"
                                onClick={() => setSelectedMember(null)}
                            >
                                Ganti Anggota
                            </SecondaryButton>
                            <PrimaryButton disabled={form.processing}>
                                Simpan Nasabah SHM
                            </PrimaryButton>
                        </div>
                    </form>
                )}
            </div>
        </ShmLayout>
    );
}
