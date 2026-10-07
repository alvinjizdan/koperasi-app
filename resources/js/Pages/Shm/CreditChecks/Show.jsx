import React, { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import StatusBadge from '@/Components/Admin/StatusBadge';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import { formatDateTime } from '@/utils/format';
import {
    ArrowLeft,
    ClipboardCheck,
    FileText,
    Play,
    UserRound,
    ScrollText,
    CheckCircle2,
} from 'lucide-react';

/**
 * Detail Credit Checking — pusat operasional.
 *
 * Bagian: pengajuan, nasabah, data checking, aksi, dan riwayat audit. Riwayat
 * memakai event audit yang sama dengan halaman pengajuan; tidak ada timeline
 * kedua.
 *
 * Ini workflow manual, BUKAN integrasi BI Checking/SLIK dan BUKAN keputusan
 * kelayakan kredit.
 */
export default function Show({
    credit_check,
    application,
    customer,
    timeline,
    outcomes,
    permissions,
    flash,
}) {
    const canManage = permissions?.can_manage === true;
    const isCompleted = credit_check.status === 'completed';
    const isInProgress = credit_check.status === 'in_progress';
    const isPending = credit_check.status === 'pending';

    const [confirmComplete, setConfirmComplete] = useState(false);

    const form = useForm({
        outcome: credit_check.outcome ?? '',
        result: credit_check.result ?? '',
        notes: credit_check.notes ?? '',
    });

    const saveDraft = (event) => {
        event.preventDefault();
        form.put(route('shm.credit-checks.update', credit_check.id), { preserveScroll: true });
    };

    const submitComplete = () => {
        form.post(route('shm.credit-checks.complete', credit_check.id), {
            preserveScroll: true,
            onFinish: () => setConfirmComplete(false),
        });
    };

    const startChecking = () => {
        form.transform(() => ({}));
        form.post(route('shm.applications.credit-checking.start', application.id));
    };

    const fieldClass =
        'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500';

    return (
        <ShmLayout title={`Credit Checking ${application.application_number}`}>
            <Head title={`Credit Checking ${application.application_number}`} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link
                            href={route('shm.credit-checks.index')}
                            className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"
                        >
                            <ArrowLeft className="h-3.5 w-3.5" />
                            Kembali ke Proses Marketing
                        </Link>
                        <h1 className="text-xl font-semibold text-gray-800">
                            Credit Checking {application.application_number}
                        </h1>
                        <p className="mt-1 flex items-center gap-2 text-sm text-gray-500">
                            <StatusBadge
                                status={credit_check.status_badge}
                                label={credit_check.status_label}
                            />
                            {credit_check.outcome_badge && (
                                <StatusBadge
                                    status={credit_check.outcome_badge}
                                    label={credit_check.outcome_label}
                                />
                            )}
                        </p>
                    </div>
                    <Link href={route('shm.applications.show', application.id)}>
                        <SecondaryButton>Buka Pengajuan</SecondaryButton>
                    </Link>
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        {flash.error}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        {/* SECTION 3 — Credit Checking */}
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <ClipboardCheck className="h-4 w-4" />
                                Data Credit Checking
                            </h2>

                            <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt className="text-xs text-gray-500">Status</dt>
                                    <dd>
                                        <StatusBadge
                                            status={credit_check.status_badge}
                                            label={credit_check.status_label}
                                        />
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Hasil</dt>
                                    <dd>
                                        {credit_check.outcome_badge ? (
                                            <StatusBadge
                                                status={credit_check.outcome_badge}
                                                label={credit_check.outcome_label}
                                            />
                                        ) : (
                                            <span className="text-slate-400">Belum dicatat</span>
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Petugas</dt>
                                    <dd className="font-medium">{credit_check.checker ?? '-'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Mulai</dt>
                                    <dd>
                                        {credit_check.started_at
                                            ? formatDateTime(credit_check.started_at)
                                            : '-'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Selesai</dt>
                                    <dd>
                                        {credit_check.completed_at
                                            ? formatDateTime(credit_check.completed_at)
                                            : '-'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Terakhir diperbarui</dt>
                                    <dd>{formatDateTime(credit_check.updated_at)}</dd>
                                </div>
                                {credit_check.result && (
                                    <div className="sm:col-span-2">
                                        <dt className="text-xs text-gray-500">Ringkasan Hasil</dt>
                                        <dd className="whitespace-pre-line">
                                            {credit_check.result}
                                        </dd>
                                    </div>
                                )}
                                {credit_check.notes && (
                                    <div className="sm:col-span-2">
                                        <dt className="text-xs text-gray-500">Catatan</dt>
                                        <dd className="whitespace-pre-line">
                                            {credit_check.notes}
                                        </dd>
                                    </div>
                                )}
                            </dl>

                            <p className="mt-3 text-xs text-gray-500">
                                Status dan hasil di sini adalah hasil pencatatan manual, bukan
                                penilaian kelayakan kredit. Menyelesaikan checking tidak menyetujui
                                atau menolak pengajuan.
                            </p>
                        </section>

                        {/* SECTION 4 — Action */}
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 text-sm font-semibold uppercase text-slate-600">
                                Tindakan
                            </h2>

                            {isCompleted && (
                                <p className="text-sm text-gray-600">
                                    Credit checking sudah selesai dan bersifat final. Riwayat
                                    perubahan tetap tercatat pada bagian Riwayat di samping.
                                </p>
                            )}

                            {isPending && (
                                <div className="space-y-3">
                                    <p className="text-sm text-gray-600">
                                        Pengerjaan credit checking belum dimulai.
                                    </p>
                                    {canManage && (
                                        <PrimaryButton onClick={startChecking}>
                                            <Play className="mr-2 h-4 w-4" />
                                            Mulai Checking
                                        </PrimaryButton>
                                    )}
                                </div>
                            )}

                            {isInProgress && canManage && (
                                <form onSubmit={saveDraft} className="space-y-4">
                                    <div>
                                        <InputLabel htmlFor="cc_outcome" value="Hasil" />
                                        <select
                                            id="cc_outcome"
                                            value={form.data.outcome}
                                            onChange={(event) =>
                                                form.setData('outcome', event.target.value)
                                            }
                                            className={fieldClass}
                                        >
                                            <option value="">Belum ditentukan</option>
                                            {outcomes.map((item) => (
                                                <option key={item.value} value={item.value}>
                                                    {item.label} — {item.description}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={form.errors.outcome} className="mt-1" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="cc_result" value="Ringkasan Hasil" />
                                        <textarea
                                            id="cc_result"
                                            rows={4}
                                            value={form.data.result}
                                            onChange={(event) =>
                                                form.setData('result', event.target.value)
                                            }
                                            className={fieldClass}
                                        />
                                        <InputError message={form.errors.result} className="mt-1" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="cc_notes" value="Catatan" />
                                        <textarea
                                            id="cc_notes"
                                            rows={3}
                                            value={form.data.notes}
                                            onChange={(event) =>
                                                form.setData('notes', event.target.value)
                                            }
                                            className={fieldClass}
                                        />
                                        <InputError message={form.errors.notes} className="mt-1" />
                                    </div>

                                    <div className="flex flex-wrap justify-end gap-2">
                                        <SecondaryButton type="submit" disabled={form.processing}>
                                            Simpan Sementara
                                        </SecondaryButton>
                                        <PrimaryButton
                                            type="button"
                                            onClick={() => setConfirmComplete(true)}
                                            disabled={form.processing}
                                        >
                                            <CheckCircle2 className="mr-2 h-4 w-4" />
                                            Selesaikan Checking
                                        </PrimaryButton>
                                    </div>

                                    <p className="text-xs text-gray-500">
                                        Menyelesaikan checking memerlukan hasil dan ringkasan.
                                        Setelah selesai, checking tidak dapat dibuka kembali.
                                    </p>
                                </form>
                            )}

                            {isInProgress && !canManage && (
                                <p className="text-sm text-gray-600">
                                    Anda tidak memiliki kewenangan untuk mengubah credit checking.
                                </p>
                            )}
                        </section>

                        {/* SECTION 5 — Timeline */}
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <ScrollText className="h-4 w-4" />
                                Riwayat
                            </h2>
                            {timeline.length === 0 ? (
                                <p className="text-sm text-gray-500">Belum ada riwayat.</p>
                            ) : (
                                <ul className="space-y-3 text-xs">
                                    {timeline.map((entry) => (
                                        <li
                                            key={entry.id}
                                            className="border-l-2 border-slate-200 pl-3"
                                        >
                                            <p className="font-medium text-gray-700">
                                                {entry.description ?? entry.action}
                                            </p>
                                            <p className="text-gray-400">
                                                {entry.actor?.name ?? 'Sistem'} -{' '}
                                                {formatDateTime(entry.created_at)}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </div>

                    <div className="space-y-6">
                        {/* SECTION 1 — Application */}
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <FileText className="h-4 w-4" />
                                Pengajuan
                            </h2>
                            <dl className="space-y-3 text-sm">
                                <div>
                                    <dt className="text-xs text-gray-500">No. Pengajuan</dt>
                                    <dd>
                                        <Link
                                            href={route('shm.applications.show', application.id)}
                                            className="font-medium text-indigo-600 hover:underline"
                                        >
                                            {application.application_number}
                                        </Link>
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Tahap Pengajuan</dt>
                                    <dd className="font-medium">{application.status_label}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Dibuat</dt>
                                    <dd>{formatDateTime(application.created_at)}</dd>
                                </div>
                            </dl>
                        </section>

                        {/* SECTION 2 — Customer */}
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <UserRound className="h-4 w-4" />
                                Nasabah SHM
                            </h2>
                            {customer === null ? (
                                <p className="text-sm text-gray-500">
                                    Data nasabah tidak ditemukan.
                                </p>
                            ) : (
                                <dl className="space-y-3 text-sm">
                                    <div>
                                        <dt className="text-xs text-gray-500">Nama</dt>
                                        <dd className="font-medium">{customer.name}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500">Nomor Nasabah</dt>
                                        <dd>{customer.customer_number}</dd>
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
                                </dl>
                            )}
                        </section>
                    </div>
                </div>
            </div>

            <ConfirmModal
                isOpen={confirmComplete}
                onClose={() => setConfirmComplete(false)}
                onConfirm={submitComplete}
                isProcessing={form.processing}
                title="Selesaikan Credit Checking"
                message="Setelah diselesaikan, credit checking bersifat final dan tidak dapat dibuka kembali. Status pengajuan tidak ikut berubah."
                confirmText="Ya, Selesaikan"
            />
        </ShmLayout>
    );
}
