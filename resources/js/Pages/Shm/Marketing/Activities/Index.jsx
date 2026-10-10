import React, { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import useTableSort from '@/hooks/useTableSort';
import { formatDateTime } from '@/utils/format';
import {
    MapPin,
    CheckCircle2,
    AlertCircle,
    ClipboardList,
    ShieldAlert,
} from 'lucide-react';

/**
 * Aktivitas kunjungan milik pengguna sendiri.
 *
 * Check-in hanya terkirim setelah browser benar-benar memberikan koordinat.
 * Bila izin ditolak atau lokasi tidak tersedia, TIDAK ada koordinat pengganti
 * yang dibuat dan request tidak dikirim sama sekali.
 */
const LOCATION_ERRORS = {
    1: 'Izin lokasi ditolak. Aktivitas hanya dapat dicatat setelah Anda mengizinkan akses lokasi pada browser.',
    2: 'Lokasi tidak dapat ditentukan. Pastikan fitur lokasi perangkat aktif, lalu coba lagi.',
    3: 'Pencarian lokasi terlalu lama. Silakan coba lagi.',
};

function newIdempotencyKey() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
    }
    return `mkt-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export default function Index({ activities, sort, customers, filters, can_monitor, flash, shm }) {
    const handleSort = useTableSort('shm.marketing.activities.index');

    const [customerId, setCustomerId] = useState('');
    const [result, setResult] = useState('');
    const [notes, setNotes] = useState('');
    const [locating, setLocating] = useState(false);
    const [locationError, setLocationError] = useState('');

    const form = useForm({
        shm_customer_id: '',
        latitude: '',
        longitude: '',
        result: '',
        notes: '',
        idempotency_key: newIdempotencyKey(),
    });

    /**
     * Minta koordinat ke browser. Tidak ada nilai pengganti bila gagal.
     */
    const requestLocation = () =>
        new Promise((resolve, reject) => {
            if (!('geolocation' in navigator)) {
                reject(new Error('unsupported'));
                return;
            }
            navigator.geolocation.getCurrentPosition(
                (position) =>
                    resolve({
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                    }),
                (error) => reject(error),
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
            );
        });

    const handleCheckIn = async (event) => {
        event.preventDefault();

        if (!form.shm_customer_id) {
            setLocationError('Pilih nasabah SHM tujuan kunjungan terlebih dahulu.');
            return;
        }

        setLocating(true);
        setLocationError('');

        try {
            const coordinates = await requestLocation();
            form.setData({
                latitude: String(coordinates.latitude),
                longitude: String(coordinates.longitude),
                result: form.result,
                notes: form.notes,
            });

            form.post(route('shm.marketing.activities.store'), {
                preserveScroll: true,
                onSuccess: () => {
                    form.reset();
                    form.setData('idempotency_key', newIdempotencyKey());
                    setCustomerId('');
                    setResult('');
                    setNotes('');
                },
            });
        } catch (error) {
            const message =
                error.message === 'unsupported'
                    ? 'Browser Anda tidak mendukung pengambilan lokasi. Aktivitas tidak dapat dicatat.'
                    : LOCATION_ERRORS[error.code] ||
                      'Lokasi tidak dapat diperoleh. Aktivitas tidak dicatat.';
            setLocationError(message);
        } finally {
            setLocating(false);
        }
    };

    const columns = [
        {
            key: 'checked_in_at',
            label: 'Waktu Check-in',
            render: (row) => formatDateTime(row.checked_in_at),
        },
        {
            key: 'customer',
            label: 'Nasabah SHM',
            render: (row) => (
                <div>
                    <p className="font-semibold text-slate-800">{row.customer?.name ?? '-'}</p>
                    <p className="text-[11px] text-slate-400">{row.customer?.customer_number ?? '-'}</p>
                </div>
            ),
        },
        {
            key: 'location',
            label: 'Lokasi',
            sortable: false,
            render: (row) =>
                row.latitude !== null && row.longitude !== null ? (
                    <span className="inline-flex items-center gap-1 text-[11px] text-slate-600">
                        <MapPin className="h-3.5 w-3.5" />
                        {Number(row.latitude).toFixed(5)}, {Number(row.longitude).toFixed(5)}
                    </span>
                ) : (
                    <span className="text-[11px] text-slate-400">Tidak tercatat</span>
                ),
        },
        {
            key: 'result',
            label: 'Hasil',
            render: (row) => row.result || <span className="text-slate-400">Belum diisi</span>,
        },
        {
            key: 'actions',
            label: 'Aksi',
            sortable: false,
            render: (row) => (                    <Link
                        href={route('shm.marketing.activities.show', row.id)}
                        className="text-[11px] font-semibold text-emerald-700 hover:underline"
                    >
                        Detail
                    </Link>
            ),
        },
    ];

    return (
        <ShmLayout title="Aktivitas Kunjungan" pageTitle="Aktivitas Kunjungan Marketing">
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

                <form
                    onSubmit={handleCheckIn}
                    className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs"
                >
                    <h2 className="flex items-center gap-2 text-sm font-bold text-slate-900">
                        <MapPin className="h-4 w-4 text-emerald-600" />
                        Check-in Kunjungan
                    </h2>
                    <p className="mt-1 text-[11px] text-slate-500">
                        Lokasi diambil dari perangkat pada saat tombol ditekan. Jika izin lokasi
                        ditolak, aktivitas tidak akan dicatat dan tidak ada koordinat pengganti.
                    </p>

                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="shm_customer_id" value="Nasabah SHM Tujuan" />
                            <select
                                id="shm_customer_id"
                                value={customerId}
                                onChange={(event) => {
                                    setCustomerId(event.target.value);
                                    form.setData('shm_customer_id', event.target.value);
                                }}
                                className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            >
                                <option value="">-- Pilih nasabah --</option>
                                {customers.map((customer) => (
                                    <option key={customer.id} value={customer.id}>
                                        {customer.name} ({customer.customer_number})
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <InputLabel htmlFor="result" value="Hasil Kunjungan" />
                            <TextInput
                                id="result"
                                type="text"
                                value={form.result}
                                onChange={(event) => form.setData('result', event.target.value)}
                                placeholder="Catatan singkat hasil kunjungan"
                                className="mt-1"
                            />
                            <InputError message={form.errors.result} className="mt-1" />
                        </div>
                        <div className="sm:col-span-2">
                            <InputLabel htmlFor="notes" value="Catatan" />
                            <textarea
                                id="notes"
                                rows={2}
                                value={form.notes}
                                onChange={(event) => form.setData('notes', event.target.value)}
                                className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                placeholder="Catatan tambahan hasil kunjungan"
                            />
                            <InputError message={form.errors.notes} className="mt-1" />
                        </div>
                    </div>

                    {locationError && (
                        <div className="mt-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-800">
                            <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                            <span>{locationError}</span>
                        </div>
                    )}

                    <div className="mt-4 flex items-center gap-2">
                        <PrimaryButton type="submit" disabled={locating || form.processing}>
                            {locating ? 'Mengambil lokasi...' : 'Check-in'}
                        </PrimaryButton>
                        <SecondaryButton
                            type="button"
                            onClick={() => router.get(route('shm.marketing.activities.index'))}
                        >
                            Reset
                        </SecondaryButton>
                    </div>
                </form>

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <ReportTable
                        columns={columns}
                        rows={activities.data}
                        pagination={activities}
                        sort={sort}
                        onSort={handleSort}
                        routeName="shm.marketing.activities.index"
                        emptyIcon={ClipboardList}
                        emptyTitle="Belum ada aktivitas kunjungan"
                        emptyDescription="Gunakan form check-in di atas untuk mencatat kunjungan pertama."
                    />
                </div>

                {can_monitor && (
                    <Link
                        href={route('shm.marketing.monitoring.index')}
                        className="inline-flex items-center gap-2 text-xs font-semibold text-emerald-700 hover:underline"
                    >
                        Lihat monitoring seluruh aktivitas Marketing
                    </Link>
                )}
            </div>
        </ShmLayout>
    );
}
