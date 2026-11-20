import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import StatusBadge from '@/Components/Admin/StatusBadge';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import { formatDateTime } from '@/utils/format';
import {
    ArrowLeft,
    ClipboardCheck,
    FileText,
    UserRound,
    ScrollText,
    CheckCircle2,
    XCircle,
    RotateCcw,
    Loader2,
} from 'lucide-react';

/**
 * Detail Credit Checking — pusat operasional CC-09 / CC-11.
 *
 * Menampilkan: pengajuan, nasabah, status alur kerja 4-status, hasil penilaian,
 * aksi transisi status sesuai kewenangan peran, dan riwayat audit.
 */
export default function Show({
    credit_check,
    application,
    customer,
    timeline,
    flash,
}) {
    const isCompleted = credit_check.status === 'completed';
    const availableTransitions = credit_check.available_transitions || [];

    const [transitioning, setTransitioning] = useState(false);
    const [transitionNotes, setTransitionNotes] = useState('');
    const [selectedTransition, setSelectedTransition] = useState(null);

    const handleExecuteTransition = (targetStatus) => {
        if (!targetStatus || transitioning) return;

        setTransitioning(true);
        router.post(
            route('shm.credit-checks.transition', credit_check.id),
            {
                status: targetStatus,
                notes: transitionNotes || undefined,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setTransitioning(false);
                    setSelectedTransition(null);
                    setTransitionNotes('');
                },
                onError: () => {
                    setTransitioning(false);
                },
            }
        );
    };

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

                        {/* SECTION 4 — Action (CC-09 & CC-11 Workflow Reconciliation) */}
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 text-sm font-semibold uppercase text-slate-600">
                                Tindakan
                            </h2>

                            {isCompleted && (
                                <p className="text-sm text-gray-600">
                                    Credit checking sudah selesai (Completed) dan bersifat final. Riwayat
                                    perubahan tetap tercatat pada bagian Riwayat di samping.
                                </p>
                            )}

                            {!isCompleted && availableTransitions.length === 0 && (
                                <p className="text-sm text-gray-500">
                                    Tidak ada aksi transisi status alur kerja yang tersedia untuk akun Anda saat ini.
                                </p>
                            )}

                            {!isCompleted && availableTransitions.length > 0 && (
                                <div className="space-y-4">
                                    <p className="text-sm text-gray-700">
                                        Pilih aksi untuk mengubah status Credit Checking pengajuan ini:
                                    </p>

                                    <div className="space-y-2">
                                        <label htmlFor="transition_notes" className="block text-xs font-medium text-slate-600">
                                            Catatan Transisi (Opsional):
                                        </label>
                                        <textarea
                                            id="transition_notes"
                                            rows={2}
                                            value={transitionNotes}
                                            onChange={(e) => setTransitionNotes(e.target.value)}
                                            placeholder="Tulis catatan jika diperlukan..."
                                            disabled={transitioning}
                                            className="block w-full rounded-md border border-slate-300 p-2 text-xs shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 disabled:opacity-50"
                                        />
                                    </div>

                                    <div className="flex flex-wrap items-center gap-2">
                                        {availableTransitions.map((t) => {
                                            if (t.value === 'approve') {
                                                return (
                                                    <PrimaryButton
                                                        key={t.value}
                                                        type="button"
                                                        onClick={() => handleExecuteTransition('approve')}
                                                        disabled={transitioning}
                                                        className="inline-flex items-center gap-1.5 bg-emerald-600 text-xs hover:bg-emerald-700"
                                                    >
                                                        {transitioning ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <CheckCircle2 className="h-3.5 w-3.5" />}
                                                        Setujui (Approve)
                                                    </PrimaryButton>
                                                );
                                            }
                                            if (t.value === 'rejected') {
                                                return (
                                                    <DangerButton
                                                        key={t.value}
                                                        type="button"
                                                        onClick={() => handleExecuteTransition('rejected')}
                                                        disabled={transitioning}
                                                        className="inline-flex items-center gap-1.5 text-xs"
                                                    >
                                                        {transitioning ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <XCircle className="h-3.5 w-3.5" />}
                                                        Tolak (Rejected)
                                                    </DangerButton>
                                                );
                                            }
                                            if (t.value === 'completed') {
                                                return (
                                                    <PrimaryButton
                                                        key={t.value}
                                                        type="button"
                                                        onClick={() => handleExecuteTransition('completed')}
                                                        disabled={transitioning}
                                                        className="inline-flex items-center gap-1.5 bg-indigo-600 text-xs hover:bg-indigo-700"
                                                    >
                                                        {transitioning ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <CheckCircle2 className="h-3.5 w-3.5" />}
                                                        Selesaikan (Completed)
                                                    </PrimaryButton>
                                                );
                                            }
                                            if (t.value === 'drafted') {
                                                return (
                                                    <SecondaryButton
                                                        key={t.value}
                                                        type="button"
                                                        onClick={() => handleExecuteTransition('drafted')}
                                                        disabled={transitioning}
                                                        className="inline-flex items-center gap-1.5 text-xs"
                                                    >
                                                        {transitioning ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RotateCcw className="h-3.5 w-3.5" />}
                                                        Kembalikan ke Drafted
                                                    </SecondaryButton>
                                                );
                                            }
                                            return null;
                                        })}
                                    </div>
                                </div>
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
        </ShmLayout>
    );
}
