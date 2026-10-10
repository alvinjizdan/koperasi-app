import React, { useEffect } from 'react';
import { Link, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import { formatDateTime } from '@/utils/format';
import { MapPin, ArrowLeft, CheckCircle2, AlertCircle, Info } from 'lucide-react';

/**
 * Detail satu aktivitas kunjungan.
 *
 * Kolom hasil sengaja berupa teks bebas: KSP belum menetapkan daftar hasil
 * kunjungan yang sah, sehingga sistem tidak improvised daftar status.
 */
export default function Show({ activity, can_update_result, flash, shm }) {
    const form = useForm({
        result: activity.result ?? '',
        notes: activity.notes ?? '',
    });

    useEffect(() => {
        form.setData({
            result: activity.result ?? '',
            notes: activity.notes ?? '',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activity.id, activity.result, activity.notes]);

    const handleSubmit = (event) => {
        event.preventDefault();
        form.patch(route('shm.marketing.activities.result.update', activity.id));
    };

    return (
        <ShmLayout title="Detail Aktivitas" pageTitle="Detail Aktivitas Kunjungan">
            <div className="space-y-6">
                {flash?.success && (
                    <div className="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs text-emerald-800">
                        <CheckCircle2 className="h-5 w-5 shrink-0 text-emerald-600" />
                        <span className="font-medium">{flash.success}</span>
                    </div>
                )}
                {flash?.error && (
                    <div className="flex items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-800">
                        <AlertCircle className="h-5 w-5 shrink-0 text-rose-600" />
                        <span className="font-medium">{flash.error}</span>
                    </div>
                )}

                <Link
                    href={route('shm.marketing.activities.index')}
                    className="inline-flex items-center gap-1 text-xs font-semibold text-slate-600 hover:text-slate-900"
                >
                    <ArrowLeft className="h-4 w-4" />
                    Kembali ke daftar aktivitas
                </Link>

                <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                    <h2 className="text-sm font-bold text-slate-900">Informasi Aktivitas</h2>
                    <dl className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <dt className="text-[11px] uppercase tracking-wider text-slate-400">
                                Nasabah SHM
                            </dt>
                            <dd className="mt-0.5 text-sm font-semibold text-slate-800">
                                {activity.customer.name}
                            </dd>
                            <dd className="text-[11px] text-slate-400">
                                {activity.customer.customer_number}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[11px] uppercase tracking-wider text-slate-400">
                                Marketing / PIC
                            </dt>
                            <dd className="mt-0.5 text-sm font-semibold text-slate-800">
                                {activity.marketing?.name || '-'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[11px] uppercase tracking-wider text-slate-400">
                                Waktu Check-in
                            </dt>
                            <dd className="mt-0.5 text-sm text-slate-700">
                                {formatDateTime(activity.checked_in_at)}
                            </dd>
                        </div>
                        <div className="sm:col-span-2">
                            <dt className="text-[11px] uppercase tracking-wider text-slate-400">
                                Alamat (tetap terpisah dari titik lokasi)
                            </dt>
                            <dd className="mt-0.5 text-sm text-slate-700">
                                {activity.customer.address || 'Belum diisi'}
                            </dd>
                        </div>
                        <div className="sm:col-span-2">
                            <dt className="text-[11px] uppercase tracking-wider text-slate-400">
                                Lokasi saat Check-in
                            </dt>
                            <dd className="mt-0.5 flex items-center gap-1 text-sm text-slate-700">
                                <MapPin className="h-4 w-4 text-emerald-600" />
                                {activity.latitude !== null && activity.longitude !== null
                                    ? `${Number(activity.latitude).toFixed(5)}, ${Number(
                                          activity.longitude,
                                      ).toFixed(5)}`
                                    : 'Tidak tercatat'}
                            </dd>
                        </div>
                        {activity.distance_meters !== null && (
                            <div className="sm:col-span-2">
                                <dt className="text-[11px] uppercase tracking-wider text-slate-400">
                                    Jarak dari titik lokasi nasabah
                                </dt>
                                <dd className="mt-0.5 flex items-center gap-1 text-sm text-slate-700">
                                    <Info className="h-4 w-4 text-slate-400" />
                                    {Number(activity.distance_meters).toLocaleString('id-ID')} meter
                                    <span className="text-[11px] text-slate-400">
                                        (informasi saja, bukan syarat check-in)
                                    </span>
                                </dd>
                            </div>
                        )}
                    </dl>
                </div>

                {can_update_result ? (
                    <form
                        onSubmit={handleSubmit}
                        className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs"
                    >
                        <h2 className="text-sm font-bold text-slate-900">Hasil Kunjungan</h2>
                        <p className="mt-1 text-[11px] text-slate-500">
                            Belum ada daftar hasil kunjungan resmi dari KSP, sehingga isian berupa
                            catatan bebas yang dapat diubah sewaktu-waktu.
                        </p>
                        <div className="mt-4 space-y-4">
                            <div>
                                <InputLabel htmlFor="result" value="Hasil" />
                                <TextInput
                                    id="result"
                                    type="text"
                                    value={form.data.result}
                                    onChange={(event) => form.setData('result', event.target.value)}
                                    className="mt-1"
                                />
                                <InputError message={form.errors.result} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="notes" value="Catatan" />
                                <textarea
                                    id="notes"
                                    rows={3}
                                    value={form.data.notes}
                                    onChange={(event) => form.setData('notes', event.target.value)}
                                    className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                />
                                <InputError message={form.errors.notes} className="mt-1" />
                            </div>
                        </div>
                        <div className="mt-4">
                            <PrimaryButton type="submit" disabled={form.processing}>
                                Simpan Hasil
                            </PrimaryButton>
                        </div>
                    </form>
                ) : (
                    <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs text-slate-500">
                        Anda tidak memiliki izin untuk mengubah hasil kunjungan.
                    </div>
                )}
            </div>
        </ShmLayout>
    );
}
