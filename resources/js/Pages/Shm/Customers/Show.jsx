import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import { formatDateTime } from '@/utils/format';
import { MapPin, UserRound } from 'lucide-react';

/**
 * Detail Nasabah SHM beserta riwayat pengajuannya.
 *
 * Riwayat pengajuan sudah dibatasi kepemilikannya di server: Marketing tanpa
 * shm.view_all hanya melihat pengajuan miliknya sendiri.
 */
export default function Show({ customer, applications, permissions, flash }) {
    const toggleActive = () =>
        router.patch(
            route('shm.customers.toggle-active', customer.id),
            { is_active: !customer.is_active },
            { preserveScroll: true },
        );

    const columns = [
        {
            key: 'application_number',
            label: 'No. Pengajuan',
            render: (row) => (
                <Link
                    href={route('shm.applications.show', row.id)}
                    className="font-medium text-indigo-600 hover:underline"
                >
                    {row.application_number}
                </Link>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => (
                <span className="rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">
                    {row.status_label}
                </span>
            ),
        },
        { key: 'marketing', label: 'Marketing', render: (row) => row.marketing || '-' },
        {
            key: 'submitted_at',
            label: 'Tanggal Submit',
            render: (row) => (row.submitted_at ? formatDateTime(row.submitted_at) : '-'),
        },
        {
            key: 'created_at',
            label: 'Dibuat',
            render: (row) => formatDateTime(row.created_at),
        },
    ];

    return (
        <ShmLayout title={`Nasabah ${customer.customer_number}`}>
            <Head title={`Nasabah ${customer.customer_number}`} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
                            <UserRound className="h-5 w-5 text-slate-400" />
                            {customer.name}
                        </h1>
                        <p className="text-sm text-gray-500">
                            {customer.customer_number} &middot;{' '}
                            {customer.is_active ? 'Aktif' : 'Nonaktif'}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {permissions?.can_update && (
                            <Link href={route('shm.customers.edit', customer.id)}>
                                <SecondaryButton>Edit Data</SecondaryButton>
                            </Link>
                        )}
                        {permissions?.can_update && (
                            <DangerButton onClick={toggleActive}>
                                {customer.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                            </DangerButton>
                        )}
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <section className="rounded-lg bg-white p-5 shadow lg:col-span-1">
                        <h2 className="mb-3 text-sm font-semibold uppercase text-slate-600">
                            Informasi Nasabah
                        </h2>
                        <dl className="space-y-3 text-sm">
                            <div>
                                <dt className="text-xs text-gray-500">Nomor Nasabah</dt>
                                <dd className="font-medium">{customer.customer_number}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Nama</dt>
                                <dd className="font-medium">{customer.name}</dd>
                            </div>
                            {customer.nik && (
                                <div>
                                    <dt className="text-xs text-gray-500">NIK</dt>
                                    <dd>{customer.nik}</dd>
                                </div>
                            )}
                            {customer.phone && (
                                <div>
                                    <dt className="text-xs text-gray-500">Telepon</dt>
                                    <dd>{customer.phone}</dd>
                                </div>
                            )}
                            {customer.email && (
                                <div>
                                    <dt className="text-xs text-gray-500">Email</dt>
                                    <dd>{customer.email}</dd>
                                </div>
                            )}
                            {customer.address && (
                                <div>
                                    <dt className="text-xs text-gray-500">Alamat</dt>
                                    <dd>{customer.address}</dd>
                                </div>
                            )}
                            {customer.has_location ? (
                                <div>
                                    <dt className="text-xs text-gray-500">Lokasi</dt>
                                    <dd className="flex items-center gap-1">
                                        <MapPin className="h-3 w-3" />
                                        {customer.latitude}, {customer.longitude}
                                    </dd>
                                </div>
                            ) : (
                                <div>
                                    <dt className="text-xs text-gray-500">Lokasi</dt>
                                    <dd className="text-xs text-gray-400">Belum diisi.</dd>
                                </div>
                            )}
                            <div>
                                <dt className="text-xs text-gray-500">Terdaftar</dt>
                                <dd>{formatDateTime(customer.created_at)}</dd>
                            </div>
                        </dl>
                    </section>

                    <section className="space-y-4 lg:col-span-2">
                        <h2 className="text-sm font-semibold uppercase text-slate-600">
                            Riwayat Pengajuan
                        </h2>
                        <ReportTable
                            columns={columns}
                            rows={applications}
                            emptyTitle="Belum ada pengajuan"
                            emptyDescription="Pengajuan SHM untuk nasabah ini akan tampil di sini."
                        />
                    </section>
                </div>
            </div>
        </ShmLayout>
    );
}
