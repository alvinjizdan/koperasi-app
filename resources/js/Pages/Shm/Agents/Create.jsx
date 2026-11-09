import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import Checkbox from '@/Components/Checkbox';
import { ArrowLeft, Save } from 'lucide-react';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        nik: '',
        is_active: true,
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('shm.agents.store'));
    };

    return (
        <ShmLayout title="Tambah Agen SHM" pageTitle="Tambah Agen BSM Makro">
            <div className="mx-auto max-w-2xl space-y-6">
                <div>
                    <Link
                        href={route('shm.agents.index')}
                        className="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-700"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Kembali ke Daftar Agen
                    </Link>
                    <h1 className="mt-2 text-xl font-bold text-slate-900">
                        Tambah Agen Baru
                    </h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Kode agen (AGEN-NNN) akan dibangkitkan secara otomatis oleh sistem.
                    </p>
                </div>

                <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel htmlFor="code" value="Kode Agen" />
                            <TextInput
                                id="code"
                                type="text"
                                value="Otomatis (AGEN-NNN)"
                                disabled
                                className="mt-1 block w-full bg-slate-50 text-slate-400"
                            />
                            <p className="mt-1 text-xs text-slate-400">
                                Sistem akan menstempel kode unik urut setelah form disimpan.
                            </p>
                        </div>

                        <div>
                            <InputLabel htmlFor="name" value="Nama Lengkap *" />
                            <TextInput
                                id="name"
                                type="text"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="mis. Budi Santoso"
                                required
                                className="mt-1 block w-full"
                            />
                            <InputError message={errors.name} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="nik" value="NIK (16 Digit) *" />
                            <TextInput
                                id="nik"
                                type="text"
                                maxLength={16}
                                value={data.nik}
                                onChange={(e) => setData('nik', e.target.value.replace(/\D/g, ''))}
                                placeholder="3201234567890001"
                                required
                                className="mt-1 block w-full font-mono"
                            />
                            <InputError message={errors.nik} className="mt-1" />
                        </div>

                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="is_active"
                                checked={data.is_active}
                                onChange={(e) => setData('is_active', e.target.checked)}
                            />
                            <InputLabel
                                htmlFor="is_active"
                                value="Status Aktif (dapat dipilih pada pengajuan baru)"
                                className="cursor-pointer font-normal text-slate-700"
                            />
                        </div>

                        <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-5">
                            <Link href={route('shm.agents.index')}>
                                <SecondaryButton type="button">
                                    Batal
                                </SecondaryButton>
                            </Link>
                            <PrimaryButton
                                type="submit"
                                disabled={processing}
                                className="gap-2"
                            >
                                <Save className="h-4 w-4" />
                                {processing ? 'Menyimpan...' : 'Simpan Agen'}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </ShmLayout>
    );
}

