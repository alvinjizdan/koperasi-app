import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import { Building2, RefreshCw, Lock } from 'lucide-react';

/**
 * Edit pengajuan yang masih berstatus draft (Ticket 3).
 *
 * Rules:
 * - Tipe pengajuan (SHGB / Buyback) bersifat read-only dan terkunci setelah dibuat untuk menjaga integritas workflow.
 * - Nasabah SHM dan Catatan dapat diperbarui selama status masih Draft.
 */
export default function Edit({ application, customers, flash }) {
    const form = useForm({
        shm_customer_id: application.shm_customer_id ?? '',
        notes: application.notes ?? '',
        shgb_start_date: application.shgb_start_date ?? '',
        shgb_end_date: application.shgb_end_date ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.put(route('shm.applications.update', application.id));
    };

    const isBuyback = application.type === 'buyback';

    return (
        <ShmLayout title="Edit Pengajuan BSM Makro">
            <Head title="Edit Pengajuan BSM Makro" />

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

                <form onSubmit={submit} className="space-y-5 rounded-lg bg-white p-6 shadow border border-slate-200">
                    {/* Tipe Pengajuan (Read-only / Locked) */}
                    <div>
                        <InputLabel value="Tipe Pengajuan (Terkunci)" />
                        <div className="mt-1 flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                            <div className="flex items-center gap-2">
                                {isBuyback ? (
                                    <RefreshCw className="h-4 w-4 text-indigo-600" />
                                ) : (
                                    <Building2 className="h-4 w-4 text-bsm-primary" />
                                )}
                                <span className="font-semibold text-slate-800">
                                    {application.type_label ?? (isBuyback ? 'Buyback' : 'SHGB')}
                                </span>
                            </div>
                            <span className="flex items-center gap-1 text-xs text-slate-400">
                                <Lock className="h-3 w-3" /> Tipe terkunci
                            </span>
                        </div>
                    </div>

                    <div>
                        <InputLabel htmlFor="shm_customer_id" value="Nasabah SHM *" />
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
                    </div>

                    {!isBuyback && (
                        <div className="rounded-lg border border-slate-200 bg-slate-50/50 p-4 space-y-3">
                            <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-700">
                                Masa Berlaku SHGB
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
                    )}

                    <div>
                        <InputLabel htmlFor="notes" value="Catatan / Keterangan Pengajuan" />
                        <textarea
                            id="notes"
                            rows={4}
                            value={form.data.notes}
                            onChange={(e) => form.setData('notes', e.target.value)}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary"
                        />
                        <InputError message={form.errors.notes} className="mt-1" />
                    </div>

                    <div className="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
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
