import React from 'react';
import { Head, Link, router, usePage, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { formatDate, formatDateTime, formatRupiah } from '@/utils/format';
import {
    AlertTriangle,
    ArrowLeft,
    CalendarDays,
    CheckCircle2,
    ClipboardList,
    Clock,
    CreditCard,
    FileText,
    History,
    ListChecks,
    Lock,
    MapPin,
    PhoneCall,
    UserRound,
    Users,
    Workflow,
} from 'lucide-react';

/**
 * Detail monitoring Collection satu pinjaman (Ticket 90).
 *
 * READ-ONLY: menampilkan data yang SUDAH tersimpan — nasabah SHM + anggota
 * koperasi terkait (relasi existing), data pinjaman, ringkasan tunggakan,
 * jadwal angsuran dengan presentation state yang derived dari amounts,
 * riwayat pembayaran + alokasinya, dan timeline dari audit trail existing.
 *
 * Ticket 93 menambahkan pencatatan aktivitas Collection oleh PEMILIK case
 * (flag `can_record` dari server; server tetap otoritatif), dan Ticket 94
 * menambahkan transisi workflow status case — daftar aksi valid datang dari
 * server (`allowed_transitions`). Ticket 95 menambahkan progres pembayaran
 * overdue (`payment_progress`) & section Collection Resolution: seluruh angka
 * finansial dan flag kesiapan (`resolution_ready`/`can_resolve`) DIHITUNG
 * SERVER — React tidak pernah menghitung sisa tunggakan maupun kesiapan,
 * dan resolusi tidak pernah terjadi otomatis (tombol memakai endpoint
 * workflow T94 yang sama, guard finansial ada di backend). Pencatatan/
 * transisi tidak membuat PTP/pembayaran, dan tidak menawarkan pembayaran,
 * penalty, collector, escalation otomatis, maupun perubahan status pinjaman
 * — semuanya masih PENDING BUSINESS CONFIRMATION. State angsuran adalah
 * turunan tampilan, bukan status DB baru.
 *
 * Ticket 96 menambahkan section Kepemilikan & Eskalasi: pemilik Marketing
 * (dari pengajuan asal, dikirim server — bukan fallback), status case
 * otoritatif, state eskalasi (`escalation.is_escalated`), dan konteks
 * otoritas BM/PD dari contract T91. TIDAK ada assignee palsu
 * (`assigned_authority` selalu null — mekanisme assignment = PENDING), tidak
 * ada dropdown user, dan tidak ada aksi mutasi baru di sini.
 *
 * Ticket 97 menambahkan section Promise to Pay: record janji bayar
 * persisten (tanggal/nominal/catatan/pembuat — semua dikirim server),
 * riwayat multiple PTP, form Catat PTP (hanya pemilik + case
 * PROMISE_TO_PAY + tanpa PTP OPEN), dan aksi manual Tandai Terpenuhi/Tidak
 * Dipenuhi/Batalkan (graph dikirim server). TIDAK ada kalkulasi
 * terlambat/terpenuhi otomatis dari tanggal maupun pembayaran, dan
 * pencatatan PTP tidak pernah mengubah status case.
 */
function Field({ label, children }) {
    return (
        <div>
            <dt className="text-xs text-gray-500">{label}</dt>
            <dd className="font-medium text-slate-800">{children ?? '-'}</dd>
        </div>
    );
}

const INSTALLMENT_STATE = {
    paid: {
        label: 'Lunas',
        className: 'bg-emerald-50 text-emerald-700',
    },
    overdue: {
        label: 'Terlambat',
        className: 'bg-rose-50 text-rose-700',
    },
    partial: {
        label: 'Dibayar sebagian',
        className: 'bg-amber-50 text-amber-700',
    },
    scheduled: {
        label: 'Belum jatuh tempo',
        className: 'bg-slate-100 text-slate-600',
    },
};

const PAYMENT_STATUS = {
    posted: { label: 'Tersimpan', className: 'bg-emerald-50 text-emerald-700' },
    reversed: { label: 'Dibatalkan', className: 'bg-slate-100 text-slate-500' },
};

// Nilai aktivitas Collection = contract T91 (via model ShmCollectionActivity);
// label Indonesia hanya untuk tampilan. PROMISE_TO_PAY/PAID di riwayat adalah
// HASIL LAPORAN historis — bukan PTP record, pembayaran, maupun penutupan case.
const ACTIVITY_TYPE_META = {
    phone_call: { label: 'Telepon', Icon: PhoneCall },
    visit: { label: 'Kunjungan', Icon: MapPin },
    follow_up: { label: 'Follow-up', Icon: ListChecks },
    other: { label: 'Lainnya', Icon: ClipboardList },
};

const ACTIVITY_RESULT_META = {
    CONTACTED: { label: 'Terhubung', className: 'bg-emerald-50 text-emerald-700' },
    NOT_REACHED: { label: 'Tidak terhubung', className: 'bg-rose-50 text-rose-700' },
    PROMISE_TO_PAY: { label: 'Janji bayar', className: 'bg-amber-50 text-amber-700' },
    PAID: { label: 'Sudah dibayar', className: 'bg-emerald-50 text-emerald-700' },
    UNABLE_TO_PAY: { label: 'Tidak mampu bayar', className: 'bg-rose-50 text-rose-700' },
    OTHER: { label: 'Lainnya', className: 'bg-slate-100 text-slate-600' },
};

// Status lifecycle Collection Case = contract T91 (via ALLOWED_TRANSITIONS
// T92 yang dikirim server); label Indonesia hanya tampilan. PROMISE_TO_PAY di
// sini murni STATE WORKFLOW — detail janji (tanggal/nominal/catatan) berada
// di record PTP persisten terpisah (Ticket 97), bukan di status ini.
const CASE_STATUS_META = {
    OPEN: { label: 'Open', className: 'bg-slate-100 text-slate-600' },
    IN_PROGRESS: { label: 'In Progress', className: 'bg-sky-50 text-sky-700' },
    PROMISE_TO_PAY: { label: 'Promise to Pay', className: 'bg-amber-50 text-amber-700' },
    ESCALATED: { label: 'Escalated', className: 'bg-rose-50 text-rose-700' },
    RESOLVED: { label: 'Resolved', className: 'bg-emerald-50 text-emerald-700' },
};

// Status record PTP (Ticket 97) = state model minimal yang dieja sama
// dengan backend; label Indonesia hanya tampilan. MISSED/FULFILLED TIDAK
// PERNAH terjadi otomatis — seluruh perubahan status lewat aksi manual
// eksplisit pemilik pada endpoint PTP.
const PTP_STATUS_META = {
    OPEN: { label: 'Aktif (OPEN)', className: 'bg-amber-50 text-amber-700' },
    FULFILLED: { label: 'Terpenuhi', className: 'bg-emerald-50 text-emerald-700' },
    MISSED: { label: 'Tidak Dipenuhi', className: 'bg-rose-50 text-rose-700' },
    CANCELLED: { label: 'Dibatalkan', className: 'bg-slate-100 text-slate-600' },
};

/**
 * Label tombol transisi — murni presentasi; keputusan transisi valid selalu
 * datang dari `allowed_transitions` server (React tidak menghitung graph).
 */
function transitionActionLabel(fromStatus, toStatus) {
    if (toStatus === 'RESOLVED') {
        return 'Selesaikan Case';
    }

    if (toStatus === 'ESCALATED') {
        return 'Eskalasi';
    }

    if (toStatus === 'PROMISE_TO_PAY') {
        return 'Promise to Pay';
    }

    if (toStatus === 'IN_PROGRESS') {
        return fromStatus === 'OPEN' ? 'Mulai Penanganan' : 'Kembali ke Penanganan';
    }

    return toStatus;
}

/**
 * Form pencatatan aktivitas Collection (Ticket 93).
 *
 * Hanya dirender untuk PEMILIK case (flag `can_record` dihitung di server;
 * server tetap otoritatif lewat domain service). Tidak ada field
 * collector/escalation/PTP/pembayaran. Sukses = kembali ke halaman yang sama
 * dengan flash, riwayat bertambah, dan status case tidak berubah.
 */
function ActivityForm({ caseId }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        activity_type: 'phone_call',
        result: 'CONTACTED',
        activity_at: '',
        notes: '',
        next_action: '',
    });

    const selectClassName =
        'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500';

    const submit = (event) => {
        event.preventDefault();
        post(route('shm.collection.activities.store', caseId), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <form
            onSubmit={submit}
            className="mb-5 rounded-lg border border-slate-200 bg-slate-50 p-4"
        >
            <p className="mb-3 text-xs font-semibold uppercase text-slate-500">
                Tambah Aktivitas
            </p>
            <div className="grid gap-3 md:grid-cols-2">
                <div>
                    <InputLabel htmlFor="activity_type" value="Tipe Aktivitas" />
                    <select
                        id="activity_type"
                        value={data.activity_type}
                        onChange={(event) => setData('activity_type', event.target.value)}
                        className={selectClassName}
                    >
                        {Object.entries(ACTIVITY_TYPE_META).map(([value, meta]) => (
                            <option key={value} value={value}>
                                {meta.label}
                            </option>
                        ))}
                    </select>
                    <InputError
                        message={errors.activity_type || errors['collection.aktivitas.tipe']}
                        className="mt-1"
                    />
                </div>
                <div>
                    <InputLabel htmlFor="result" value="Hasil" />
                    <select
                        id="result"
                        value={data.result}
                        onChange={(event) => setData('result', event.target.value)}
                        className={selectClassName}
                    >
                        {Object.entries(ACTIVITY_RESULT_META).map(([value, meta]) => (
                            <option key={value} value={value}>
                                {meta.label}
                            </option>
                        ))}
                    </select>
                    <InputError
                        message={errors.result || errors['collection.aktivitas.hasil']}
                        className="mt-1"
                    />
                </div>
                <div>
                    <InputLabel htmlFor="activity_at" value="Waktu Aktivitas" />
                    <TextInput
                        id="activity_at"
                        type="datetime-local"
                        value={data.activity_at}
                        className="mt-1 block w-full"
                        onChange={(event) => setData('activity_at', event.target.value)}
                    />
                    <InputError message={errors.activity_at} className="mt-1" />
                </div>
                <div className="md:col-span-2">
                    <InputLabel htmlFor="notes" value="Catatan" />
                    <textarea
                        id="notes"
                        value={data.notes}
                        onChange={(event) => setData('notes', event.target.value)}
                        rows={2}
                        className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                    <InputError message={errors.notes} className="mt-1" />
                </div>
                <div className="md:col-span-2">
                    <InputLabel htmlFor="next_action" value="Tindak Lanjut (opsional)" />
                    <textarea
                        id="next_action"
                        value={data.next_action}
                        onChange={(event) => setData('next_action', event.target.value)}
                        rows={2}
                        placeholder="Contoh: Hubungi kembali setelah tanggal gajian."
                        className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                    <InputError message={errors.next_action} className="mt-1" />
                </div>
            </div>
            <div className="mt-4 flex flex-wrap items-center gap-3">
                <PrimaryButton disabled={processing}>Simpan Aktivitas</PrimaryButton>
                <p className="text-xs text-slate-500">
                    Pencatatan aktivitas tidak mengubah status case.
                </p>
            </div>
            <InputError message={errors['collection.otorisasi']} className="mt-2" />
        </form>
    );
}

/**
 * Kepemilikan & eskalasi Collection (Ticket 96).
 *
 * Seluruh datang dari server: `owner` (Marketing pemilik dari pengajuan
 * asal — tanpa fallback user), `status` (state otoritatif lifecycle T92),
 * dan `escalation` (state + konteks otoritas BM/PD dari contract T91).
 * TIDAK ada assignee palsu: `assigned_authority` selalu null karena
 * mekanisme assignment/reassignment BM/PD masih PENDING BUSINESS
 * CONFIRMATION. Tidak ada dropdown user maupun form assignment di sini —
 * section ini murni representasi baca.
 */
function CollectionOwnership({ collectionCase }) {
    const { owner, status, escalation } = collectionCase;
    const statusMeta = CASE_STATUS_META[status] ?? {
        label: status,
        className: 'bg-slate-100 text-slate-600',
    };
    const authorityLabels = {
        branch_manager: 'Branch Manager',
        president_director: 'President Director',
    };
    const isEscalated = escalation?.is_escalated ?? false;

    return (
        <section className="rounded-lg bg-white p-5 shadow">
            <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                <Users className="h-4 w-4 text-slate-400" />
                Kepemilikan & Eskalasi
            </h2>

            <dl className="grid grid-cols-2 gap-4 md:grid-cols-3">
                <Field label="Pemilik">
                    <span className="text-slate-800">
                        Marketing — {owner?.name ?? `#${owner?.user_id}`}
                    </span>
                </Field>
                <Field label="Status">
                    <span
                        className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusMeta.className}`}
                    >
                        {statusMeta.label}
                    </span>
                </Field>
                <Field label="Eskalasi">
                    <span
                        className={
                            isEscalated ? 'font-semibold text-rose-700' : 'text-slate-700'
                        }
                    >
                        {isEscalated ? 'Dieskalasi' : 'Belum dieskalasi'}
                    </span>
                </Field>
            </dl>

            {isEscalated && (
                <p className="mt-3 text-xs text-slate-500">
                    Otoritas eskalasi (contract T91):{' '}
                    {(escalation?.authorities ?? [])
                        .map((authority) => authorityLabels[authority] ?? authority)
                        .join(' / ')}
                    . Penanganan operasional tetap oleh Marketing pemilik case
                    — penugasan spesifik otoritas (assignment BM/PD) belum
                    ditentukan.
                </p>
            )}
        </section>
    );
}

/**
 * Status workflow Collection Case + aksi transisi valid (Ticket 94).
 *
 * Daftar aksi MURNI datang dari server (`allowed_transitions` = tabel
 * ALLOWED_TRANSITIONS T92 yang sudah difilter pemilik) — React tidak
 * menghitung ulang graph transisi. PROMISE_TO_PAY hanya merubah status;
 * TIDAK ada form/field detail PTP. RESOLVED TIDAK dirender di sini: aksi
 * Selesaikan pindah ke section Collection Resolution (Ticket 95) yang
 * di-gate flag server `can_resolve` (readiness finansial) — tanpa itu
 * resolusi bisa ditawarkan saat tunggakan masih ada. RESOLVED sendiri
 * tetap terminal (tanpa reopen). Otorisasi tetap mutlak di backend.
 */
function CaseWorkflow({ collectionCase, flash }) {
    const { status, opened_at, resolved_at, allowed_transitions } = collectionCase;
    const { errors } = usePage().props;
    const statusMeta = CASE_STATUS_META[status] ?? {
        label: status,
        className: 'bg-slate-100 text-slate-600',
    };

    // RESOLVED disaring dari sini — aksi Selesaikan digantikan section
    // Collection Resolution dengan gate finansial `can_resolve` (T95).
    const workflowTransitions = allowed_transitions.filter(
        (toStatus) => toStatus !== 'RESOLVED',
    );

    const transitionTo = (toStatus) => {
        router.post(
            route('shm.collection.cases.transition', collectionCase.id),
            { status: toStatus },
            { preserveScroll: true },
        );
    };

    return (
        <section className="rounded-lg bg-white p-5 shadow">
            <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                <Workflow className="h-4 w-4 text-slate-400" />
                Status Penanganan Collection
            </h2>

            {flash?.success && (
                <div className="mb-4 flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-700">
                    <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                    <p>{flash.success}</p>
                </div>
            )}
            {flash?.error && (
                <div className="mb-4 flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                    <p>{flash.error}</p>
                </div>
            )}

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <span className="text-sm text-slate-500">Status:</span>
                <span
                    className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusMeta.className}`}
                >
                    {statusMeta.label}
                </span>
                <span className="text-xs text-slate-400">
                    Dibuka {formatDateTime(opened_at)}
                    {resolved_at
                        ? ` • Diselesaikan ${formatDateTime(resolved_at)}`
                        : ''}
                </span>
            </div>

            {workflowTransitions.length > 0 ? (
                <div className="flex flex-wrap gap-2">
                    {workflowTransitions.map((toStatus) => (
                        <button
                            key={toStatus}
                            type="button"
                            onClick={() => transitionTo(toStatus)}
                            className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1"
                        >
                            {transitionActionLabel(status, toStatus)}
                        </button>
                    ))}
                </div>
            ) : status === 'RESOLVED' ? (
                <p className="text-xs text-slate-500">
                    Case sudah RESOLVED — status terminal, tidak dapat dibuka
                    kembali melalui workflow ini.
                </p>
            ) : (
                <p className="text-xs text-slate-500">
                    Transisi status hanya tersedia bagi Marketing pemilik case
                    ini.
                </p>
            )}

            {errors?.['collection.status'] && (
                <p className="mt-3 text-xs text-rose-600">
                    {errors['collection.status']}
                </p>
            )}
            {errors?.['collection.otorisasi'] && (
                <p className="mt-3 text-xs text-rose-600">
                    {errors['collection.otorisasi']}
                </p>
            )}
        </section>
    );
}

/**
 * Kesiapan resolusi Collection (Ticket 95).
 *
 * Seluruh angka & flag datang dari server: `remaining_overdue`,
 * `resolution_ready`, dan `can_resolve` dihitung dari agregat SQL yang SAMA
 * dengan guard finansial backend — React TIDAK menghitung sisa tunggakan
 * maupun kesiapan (presentasi warna saja dari angka yang tampil). Tombol
 * Selesaikan memakai endpoint workflow T94 yang sama (status = RESOLVED);
 * backend tetap otoritatif. Resolusi SELALU aksi eksplisit pemilik — tidak
 * pernah terjadi otomatis dari pembayaran maupun aktivitas.
 */
function CollectionResolution({ collectionCase }) {
    const { id, status, resolution_ready, can_resolve, remaining_overdue } =
        collectionCase;
    const { errors } = usePage().props;
    const isResolved = status === 'RESOLVED';

    const readiness = isResolved
        ? { label: 'SELESAI', className: 'bg-slate-100 text-slate-600' }
        : resolution_ready
          ? { label: 'SIAP DISELESAIKAN', className: 'bg-emerald-50 text-emerald-700' }
          : { label: 'BELUM SIAP DISELESAIKAN', className: 'bg-rose-50 text-rose-700' };

    const reason = isResolved
        ? 'Case sudah RESOLVED — status terminal, tidak dapat dibuka kembali.'
        : resolution_ready
          ? 'Seluruh kewajiban overdue telah terbayar. Case dapat diselesaikan melalui aksi eksplisit — resolusi tidak pernah otomatis.'
          : 'Masih terdapat kewajiban overdue.';

    const resolve = () => {
        router.post(
            route('shm.collection.cases.transition', id),
            { status: 'RESOLVED' },
            { preserveScroll: true },
        );
    };

    return (
        <section className="rounded-lg bg-white p-5 shadow">
            <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                <CheckCircle2 className="h-4 w-4 text-slate-400" />
                Collection Resolution
            </h2>

            <dl className="grid grid-cols-2 gap-4 md:grid-cols-3">
                <Field label="Status">
                    <span
                        className={`rounded-full px-2.5 py-1 text-xs font-semibold ${readiness.className}`}
                    >
                        {readiness.label}
                    </span>
                </Field>
                <Field label="Sisa tunggakan overdue">
                    <span
                        className={
                            remaining_overdue > 0 ? 'text-rose-700' : 'text-emerald-700'
                        }
                    >
                        {formatRupiah(remaining_overdue)}
                    </span>
                </Field>
                <Field label="Keterangan">{reason}</Field>
            </dl>

            {can_resolve ? (
                <div className="mt-4 flex flex-wrap items-center gap-3">
                    <PrimaryButton type="button" onClick={resolve}>
                        Selesaikan Collection
                    </PrimaryButton>
                    <p className="text-xs text-slate-500">
                        Aksi eksplisit pemilik case — status berubah melalui
                        workflow Collection dengan guard finansial di backend.
                    </p>
                </div>
            ) : (
                !isResolved &&
                resolution_ready && (
                    <p className="mt-4 text-xs text-slate-500">
                        Transisi resolusi hanya tersedia bagi Marketing pemilik
                        case ini.
                    </p>
                )
            )}

            {errors?.['collection.resolusi'] && (
                <p className="mt-3 text-xs text-rose-600">
                    {errors['collection.resolusi']}
                </p>
            )}
        </section>
    );
}

/**
 * Record Promise to Pay (Ticket 97).
 *
 * Seluruh datang dari server: riwayat record (`ptp.history`), penanda PTP
 * aktif (`ptp.has_open`), hak pembuatan (`ptp.can_create`), dan graph
 * transisi per record (`allowed_transitions` — KOSONG untuk non-pemilik,
 * sehingga React tidak pernah menawarkan aksi ke non-owner). TIDAK ada
 * perhitungan "terlambat/terpenuhi/gagal" dari tanggal hari ini maupun
 * pembayaran: FULFILLED/MISSED/CANCELLED semuanya aksi manual eksplisit
 * pemilik yang dikirim ke endpoint PTP (backend otoritatif).
 *
 * Pencatatan PTP hanya tersedia saat case berstatus PROMISE_TO_PAY dan
 * tanpa PTP OPEN (guard di backend); pembuatan PTP TIDAK mengubah status
 * case — transisi case tetap melalui workflow Collection (Ticket 94).
 */
function PromiseToPay({ collectionCase }) {
    const { id: caseId, status: caseStatus, can_record, ptp } = collectionCase;
    const { errors } = usePage().props;
    const history = ptp?.history ?? [];
    const currentOpen = history.find((item) => item.status === 'OPEN') ?? null;
    const latest = currentOpen ?? history[0] ?? null;

    const { data, setData, post, processing, reset } = useForm({
        promised_date: '',
        promised_amount: '',
        notes: '',
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('shm.collection.ptp.store', caseId), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    const transitionTo = (ptpId, toStatus) => {
        router.post(
            route('shm.collection.ptp.transition', ptpId),
            { status: toStatus },
            { preserveScroll: true },
        );
    };

    // Label aksi — murni presentasi; target valid selalu datang dari
    // `allowed_transitions` server (React tidak menghitung graph PTP).
    const transitionLabels = {
        FULFILLED: 'Tandai Terpenuhi',
        MISSED: 'Tandai Tidak Dipenuhi',
        CANCELLED: 'Batalkan PTP',
    };

    const statusMeta = (status) =>
        PTP_STATUS_META[status] ?? { label: status, className: 'bg-slate-100 text-slate-600' };

    return (
        <section className="rounded-lg bg-white p-5 shadow">
            <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                <CalendarDays className="h-4 w-4 text-slate-400" />
                Promise to Pay
            </h2>

            {latest ? (
                <div className="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <dl className="grid grid-cols-2 gap-4 md:grid-cols-3">
                        <Field label="Tanggal Janji">
                            <span className="text-slate-800">
                                {formatDate(latest.promised_date)}
                            </span>
                        </Field>
                        <Field label="Nominal Janji">
                            <span className="text-slate-800">
                                {formatRupiah(latest.promised_amount)}
                            </span>
                        </Field>
                        <Field label="Status">
                            <span
                                className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusMeta(latest.status).className}`}
                            >
                                {statusMeta(latest.status).label}
                            </span>
                        </Field>
                        <Field label="Catatan">
                            <span className="text-slate-800">
                                {latest.notes ?? '—'}
                            </span>
                        </Field>
                        <Field label="Dibuat oleh">
                            <span className="text-slate-800">
                                Marketing — {latest.creator?.name ?? `#${latest.creator?.id}`}
                            </span>
                        </Field>
                        <Field label="Dibuat pada">
                            <span className="text-slate-800">
                                {formatDateTime(latest.created_at)}
                            </span>
                        </Field>
                    </dl>

                    {currentOpen && currentOpen.allowed_transitions.length > 0 && (
                        <div className="mt-3 flex flex-wrap gap-2">
                            {currentOpen.allowed_transitions.map((toStatus) => (
                                <button
                                    key={toStatus}
                                    type="button"
                                    onClick={() => transitionTo(currentOpen.id, toStatus)}
                                    className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1"
                                >
                                    {transitionLabels[toStatus] ?? toStatus}
                                </button>
                            ))}
                            <p className="self-center text-xs text-slate-500">
                                Penyelesaian PTP = aksi manual eksplisit —
                                tidak dihitung otomatis dari pembayaran.
                            </p>
                        </div>
                    )}
                </div>
            ) : (
                <p className="mb-4 text-sm text-slate-500">
                    Belum ada record Promise to Pay pada case ini.
                </p>
            )}

            {ptp?.can_create ? (
                <form
                    onSubmit={submit}
                    className="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-4"
                >
                    <p className="mb-3 text-xs font-semibold uppercase text-slate-500">
                        Catat PTP
                    </p>
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="promised_date" value="Tanggal Janji" />
                            <TextInput
                                id="promised_date"
                                type="date"
                                value={data.promised_date}
                                className="mt-1 block w-full"
                                onChange={(event) =>
                                    setData('promised_date', event.target.value)
                                }
                            />
                            <InputError
                                message={errors.promised_date ?? errors['collection.ptp.tanggal']}
                                className="mt-1"
                            />
                        </div>
                        <div>
                            <InputLabel htmlFor="promised_amount" value="Nominal Janji" />
                            <TextInput
                                id="promised_amount"
                                type="number"
                                min="0.01"
                                step="0.01"
                                value={data.promised_amount}
                                className="mt-1 block w-full"
                                onChange={(event) =>
                                    setData('promised_amount', event.target.value)
                                }
                            />
                            <InputError
                                message={
                                    errors.promised_amount ?? errors['collection.ptp.nominal']
                                }
                                className="mt-1"
                            />
                        </div>
                        <div className="md:col-span-2">
                            <InputLabel htmlFor="ptp_notes" value="Catatan (opsional)" />
                            <textarea
                                id="ptp_notes"
                                value={data.notes}
                                onChange={(event) => setData('notes', event.target.value)}
                                rows={2}
                                placeholder="Contoh: Pembayaran setelah tanggal gajian."
                                className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                            <InputError message={errors.notes} className="mt-1" />
                        </div>
                    </div>
                    <div className="mt-4 flex flex-wrap items-center gap-3">
                        <PrimaryButton disabled={processing}>Catat PTP</PrimaryButton>
                        <p className="text-xs text-slate-500">
                            Pencatatan PTP tidak mengubah status case dan tidak
                            membuat pembayaran — transisi status case tetap
                            melalui workflow Collection.
                        </p>
                    </div>
                    <InputError
                        message={errors['collection.ptp.duplikat']}
                        className="mt-2"
                    />
                </form>
            ) : (
                can_record &&
                caseStatus !== 'PROMISE_TO_PAY' && (
                    <p className="mb-4 text-xs text-slate-500">
                        PTP dapat dicatat setelah case berstatus Promise to
                        Pay — ubah status lewat workflow Collection terlebih
                        dahulu. Pembuatan PTP tidak mengubah status case
                        otomatis.
                    </p>
                )
            )}

            {!can_record && (
                <p className="mb-4 text-xs text-slate-500">
                    Pencatatan &amp; mutasi PTP hanya tersedia bagi Marketing
                    pemilik case ini.
                </p>
            )}

            {history.length > 0 && (
                <div>
                    <h3 className="mb-2 text-xs font-semibold uppercase text-slate-500">
                        Riwayat PTP
                    </h3>
                    <table className="w-full text-left text-xs">
                        <thead className="border-b border-slate-200 text-slate-500">
                            <tr>
                                <th className="py-2 pr-3 font-semibold">Tanggal Janji</th>
                                <th className="py-2 pr-3 font-semibold">Nominal</th>
                                <th className="py-2 pr-3 font-semibold">Status</th>
                                <th className="py-2 pr-3 font-semibold">Dibuat Oleh</th>
                            </tr>
                        </thead>
                        <tbody>
                            {history.map((item) => (
                                <tr key={item.id} className="border-b border-slate-100">
                                    <td className="py-2 pr-3 text-slate-700">
                                        {formatDate(item.promised_date)}
                                    </td>
                                    <td className="py-2 pr-3 text-slate-700">
                                        {formatRupiah(item.promised_amount)}
                                    </td>
                                    <td className="py-2 pr-3">
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${statusMeta(item.status).className}`}
                                        >
                                            {statusMeta(item.status).label}
                                        </span>
                                    </td>
                                    <td className="py-2 pr-3 text-slate-700">
                                        Marketing —{' '}
                                        {item.creator?.name ?? `#${item.creator?.id}`}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {errors?.['collection.ptp.status'] && (
                <p className="mt-3 text-xs text-rose-600">
                    {errors['collection.ptp.status']}
                </p>
            )}
            {errors?.['collection.otorisasi'] && (
                <p className="mt-3 text-xs text-rose-600">
                    {errors['collection.otorisasi']}
                </p>
            )}
        </section>
    );
}

export default function Show({ collection, collection_case, timeline, today, flash }) {
    const { loan, overdue, schedule, payments, payment_progress } = collection;

    const scheduleColumns = [
        {
            key: 'installment_number',
            label: 'No.',
            align: 'right',
            sortable: false,
            render: (row) => row.installment_number,
        },
        {
            key: 'due_date',
            label: 'Jatuh Tempo',
            sortable: false,
            render: (row) => (row.due_date ? formatDate(row.due_date) : '-'),
        },
        {
            key: 'principal_amount',
            label: 'Pokok',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.principal_amount),
        },
        {
            key: 'interest_amount',
            label: 'Bunga',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.interest_amount),
        },
        {
            key: 'paid_principal_amount',
            label: 'Terbayar Pokok',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.paid_principal_amount),
        },
        {
            key: 'paid_interest_amount',
            label: 'Terbayar Bunga',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.paid_interest_amount),
        },
        {
            key: 'outstanding_principal',
            label: 'Sisa Pokok',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.outstanding_principal),
        },
        {
            key: 'outstanding_interest',
            label: 'Sisa Bunga',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.outstanding_interest),
        },
        {
            key: 'outstanding_total',
            label: 'Sisa Tagihan',
            align: 'right',
            sortable: false,
            render: (row) => (
                <span className="font-semibold">
                    {formatRupiah(row.outstanding_total)}
                </span>
            ),
        },
        {
            key: 'state',
            label: 'Status',
            sortable: false,
            render: (row) => {
                const state = INSTALLMENT_STATE[row.state] || INSTALLMENT_STATE.scheduled;
                return (
                    <div className="flex items-center justify-end gap-1">
                        <span
                            className={`rounded-full px-2 py-0.5 text-xs font-medium ${state.className}`}
                        >
                            {state.label}
                        </span>
                        {row.overdue_days !== null && row.overdue_days !== undefined && (
                            <span className="text-xs text-rose-600">
                                {row.overdue_days} hari
                            </span>
                        )}
                    </div>
                );
            },
        },
    ];

    const paymentColumns = [
        {
            key: 'payment_reference',
            label: 'Referensi',
            sortable: false,
            render: (row) => (
                <span className="font-medium text-slate-700">
                    {row.payment_reference}
                </span>
            ),
        },
        {
            key: 'payment_date',
            label: 'Tanggal',
            sortable: false,
            render: (row) => (row.payment_date ? formatDate(row.payment_date) : '-'),
        },
        {
            key: 'amount',
            label: 'Nominal',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.amount),
        },
        {
            key: 'allocated_interest',
            label: 'Alokasi Bunga',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.allocated_interest),
        },
        {
            key: 'allocated_principal',
            label: 'Alokasi Pokok',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.allocated_principal),
        },
        {
            key: 'allocated_penalty',
            label: 'Alokasi Denda',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.allocated_penalty),
        },
        {
            key: 'allocated_total',
            label: 'Total Teralokasi',
            align: 'right',
            sortable: false,
            render: (row) => (
                <span className="font-semibold">
                    {formatRupiah(row.allocated_total)}
                </span>
            ),
        },
        {
            key: 'unallocated',
            label: 'Belum Teralokasi',
            align: 'right',
            sortable: false,
            render: (row) => formatRupiah(row.unallocated),
        },
        {
            key: 'status',
            label: 'Status',
            sortable: false,
            render: (row) => {
                const status = PAYMENT_STATUS[row.status] || PAYMENT_STATUS.posted;
                return (
                    <span
                        className={`rounded-full px-2 py-0.5 text-xs font-medium ${status.className}`}
                    >
                        {status.label}
                    </span>
                );
            },
        },
    ];

    return (
        <ShmLayout title={`Collection ${loan.loan_number}`}>
            <Head title={`Collection — ${loan.loan_number}`} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link
                            href={route('shm.collection.index')}
                            className="mb-2 inline-flex items-center gap-1 text-sm text-indigo-600 hover:underline"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Kembali ke daftar Collection
                        </Link>
                        <h1 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
                            <Clock className="h-5 w-5 text-slate-400" />
                            {loan.loan_number}
                        </h1>
                        <p className="text-sm text-gray-500">
                            Detail monitoring tunggakan — data aktual dari
                            jadwal angsuran dan pembayaran tersimpan.
                        </p>
                    </div>
                </div>

                <div className="flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                    <Lock className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                    <p>
                        Halaman <strong>read-only</strong> kecuali dua aksi
                        terbatas bagi pemilik case: pencatatan aktivitas
                        Collection (Ticket 93) dan transisi status workflow
                        Collection Case (Ticket 94). Tidak ada aksi pembayaran,
                        penalty, collector, maupun perubahan status pinjaman
                        dari halaman ini — semuanya masih{' '}
                        <strong>PENDING BUSINESS CONFIRMATION</strong>.
                    </p>
                </div>

                {/* Overdue summary */}
                <section className="rounded-lg bg-white p-5 shadow">
                    <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                        <AlertTriangle className="h-4 w-4 text-rose-500" />
                        Ringkasan Tunggakan
                    </h2>
                    <dl className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
                        <Field label="Jatuh tempo terlambat">
                            {overdue.earliest_overdue_due_date
                                ? formatDate(overdue.earliest_overdue_due_date)
                                : '-'}
                        </Field>
                        <Field label="Hari tunggakan">
                            <span className="text-rose-700">
                                {overdue.days_overdue} hari
                            </span>
                        </Field>
                        <Field label="Angsuran terlambat">
                            {overdue.installments_count}x
                        </Field>
                        <Field label="Sisa pokok">
                            {formatRupiah(overdue.outstanding_principal)}
                        </Field>
                        <Field label="Sisa bunga">
                            {formatRupiah(overdue.outstanding_interest)}
                        </Field>
                        <Field label="Total tunggakan">
                            <span className="text-rose-700">
                                {formatRupiah(overdue.total_outstanding)}
                            </span>
                        </Field>
                    </dl>
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Customer */}
                    <section className="rounded-lg bg-white p-5 shadow">
                        <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                            <UserRound className="h-4 w-4 text-slate-400" />
                            Nasabah SHM
                        </h2>
                        <dl className="grid grid-cols-2 gap-4">
                            <Field label="Nama">{loan.customer?.name}</Field>
                            <Field label="No. Nasabah">
                                {loan.customer?.customer_number}
                            </Field>
                            <Field label="NIK">{loan.customer?.nik}</Field>
                            <Field label="Telepon">{loan.customer?.phone}</Field>
                            <Field label="Alamat">{loan.customer?.address}</Field>
                            <Field label="Status nasabah">
                                <span
                                    className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                                        loan.customer?.is_active
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : 'bg-slate-100 text-slate-500'
                                    }`}
                                >
                                    {loan.customer?.is_active ? 'Aktif' : 'Nonaktif'}
                                </span>
                            </Field>
                        </dl>
                        <div className="mt-4 rounded-lg border border-slate-100 bg-slate-50 p-3">
                            <p className="mb-1 flex items-center gap-1 text-xs font-semibold uppercase text-slate-500">
                                <Users className="h-3.5 w-3.5" />
                                Anggota koperasi terkait
                            </p>
                            {loan.customer?.member ? (
                                <p className="text-sm text-slate-700">
                                    {loan.customer.member.name}{' '}
                                    <span className="text-xs text-slate-400">
                                        ({loan.customer.member.member_number})
                                    </span>
                                </p>
                            ) : (
                                <p className="text-sm text-slate-500">
                                    Tidak terhubung ke anggota koperasi.
                                </p>
                            )}
                        </div>
                    </section>

                    {/* Loan */}
                    <section className="rounded-lg bg-white p-5 shadow">
                        <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                            <FileText className="h-4 w-4 text-slate-400" />
                            Pinjaman
                        </h2>
                        <dl className="grid grid-cols-2 gap-4">
                            <Field label="No. Pinjaman">{loan.loan_number}</Field>
                            <Field label="Produk">
                                {loan.product
                                    ? `${loan.product.name}${loan.product.code ? ` (${loan.product.code})` : ''}`
                                    : '-'}
                            </Field>
                            <Field label="Plafon">
                                {formatRupiah(loan.principal)}
                            </Field>
                            <Field label="Tenor">{loan.tenor_months} bulan</Field>
                            <Field label="Bunga/Jasa">
                                {Number(loan.rate_percent)}%
                                {loan.rate_period
                                    ? ` / ${loan.rate_period === 'monthly' ? 'bulan' : 'tahun'}`
                                    : ''}
                            </Field>
                            <Field label="Metode hitung">
                                {loan.calculation_method || '-'}
                            </Field>
                            <Field label="Tanggal pencairan">
                                {loan.disbursed_at
                                    ? formatDate(loan.disbursed_at)
                                    : '-'}
                            </Field>
                            <Field label="Jatuh tempo pertama">
                                {loan.first_due_date
                                    ? formatDate(loan.first_due_date)
                                    : '-'}
                            </Field>
                            <Field label="Tujuan">{loan.purpose}</Field>
                            <Field label="Lokasi">{loan.location}</Field>
                        </dl>
                    </section>
                </div>

                {/* Installment schedule */}
                <section className="rounded-lg bg-white p-5 shadow">
                    <h2 className="mb-1 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                        <CalendarDays className="h-4 w-4 text-slate-400" />
                        Jadwal Angsuran
                    </h2>
                    <p className="mb-4 text-xs text-slate-500">
                        Status adalah turunan tampilan dari jumlah terbayar dan
                        tanggal jatuh tempo — bukan status database baru.
                        Hari ini: {today ? formatDate(today) : '-'}.
                    </p>
                    <ReportTable
                        columns={scheduleColumns}
                        rows={schedule}
                        emptyTitle="Jadwal angsuran belum tersedia"
                        emptyDescription="Pinjaman ini tidak memiliki baris jadwal angsuran."
                    />
                </section>

                {/* Payment history */}
                <section className="rounded-lg bg-white p-5 shadow">
                    <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                        <CreditCard className="h-4 w-4 text-slate-400" />
                        Riwayat Pembayaran
                    </h2>

                    {/* Progres kewajiban → alokasi → sisa (Ticket 95) — angka
                        server-derived dari data payment/allocation tersimpan. */}
                    {payment_progress && (
                        <div className="mb-4 grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 md:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <p className="text-xs uppercase text-slate-500">
                                    Kewajiban overdue
                                </p>
                                <p className="text-sm font-semibold text-slate-800">
                                    {formatRupiah(payment_progress.overdue_total)}
                                </p>
                                <p className="text-xs text-slate-500">
                                    Pokok {formatRupiah(payment_progress.overdue_principal)}{' '}
                                    • Bunga {formatRupiah(payment_progress.overdue_interest)}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs uppercase text-slate-500">
                                    Teralokasi
                                </p>
                                <p className="text-sm font-semibold text-slate-800">
                                    {formatRupiah(payment_progress.allocated_total)}
                                </p>
                                <p className="text-xs text-slate-500">
                                    Pokok {formatRupiah(payment_progress.allocated_principal)}{' '}
                                    • Bunga {formatRupiah(payment_progress.allocated_interest)}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs uppercase text-slate-500">
                                    Sisa overdue
                                </p>
                                <p
                                    className={`text-sm font-semibold ${
                                        payment_progress.remaining_overdue > 0
                                            ? 'text-rose-700'
                                            : 'text-emerald-700'
                                    }`}
                                >
                                    {formatRupiah(payment_progress.remaining_overdue)}
                                </p>
                                <p className="text-xs text-slate-500">
                                    Kewajiban − teralokasi (server)
                                </p>
                            </div>
                            <div>
                                <p className="text-xs uppercase text-slate-500">
                                    Pembayaran terakhir
                                </p>
                                <p className="text-sm font-semibold text-slate-800">
                                    {payment_progress.latest_payment
                                        ? formatDate(payment_progress.latest_payment.payment_date)
                                        : '-'}
                                </p>
                                <p className="text-xs text-slate-500">
                                    {payment_progress.latest_payment?.payment_reference || '-'}
                                </p>
                            </div>
                        </div>
                    )}

                    <ReportTable
                        columns={paymentColumns}
                        rows={payments}
                        emptyTitle="Belum ada pembayaran"
                        emptyDescription="Riwayat pembayaran pinjaman ini akan tampil di sini."
                    />
                </section>

                {/* Ownership & escalation context (Ticket 96) + case workflow
                    (Ticket 94) + resolution readiness (Ticket 95) + PTP
                    record (Ticket 97) */}
                {collection_case && (
                    <>
                        <CollectionOwnership collectionCase={collection_case} />
                        <CaseWorkflow
                            collectionCase={collection_case}
                            flash={flash}
                        />
                        <CollectionResolution collectionCase={collection_case} />
                        <PromiseToPay collectionCase={collection_case} />
                    </>
                )}

                {/* Collection activities (Ticket 93) */}
                <section className="rounded-lg bg-white p-5 shadow">
                    <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                        <ClipboardList className="h-4 w-4 text-slate-400" />
                        Riwayat Aktivitas Collection
                    </h2>

                    {!collection_case ? (
                        <p className="text-sm text-slate-500">
                            Belum ada Collection Case aktif untuk pinjaman ini.
                            Aktivitas Collection hanya dapat dicatat pada case
                            yang sudah ada — pembuatan case dilakukan melalui
                            layanan Collection (Ticket 92).
                        </p>
                    ) : (
                        <>
                            {collection_case.can_record ? (
                                <ActivityForm caseId={collection_case.id} />
                            ) : (
                                <p className="mb-4 text-xs text-slate-500">
                                    Pencatatan aktivitas hanya tersedia bagi
                                    Marketing pemilik case ini.
                                </p>
                            )}

                            {collection_case.activities.length === 0 ? (
                                <p className="text-sm text-slate-500">
                                    Belum ada aktivitas tercatat pada case ini.
                                </p>
                            ) : (
                                <ol className="space-y-3">
                                    {collection_case.activities.map((activity) => {
                                        const typeMeta =
                                            ACTIVITY_TYPE_META[activity.activity_type] ?? {
                                                label: activity.activity_type,
                                                Icon: ClipboardList,
                                            };
                                        const resultMeta =
                                            ACTIVITY_RESULT_META[activity.result] ?? {
                                                label: activity.result,
                                                className:
                                                    'bg-slate-100 text-slate-600',
                                            };
                                        const Icon = typeMeta.Icon;

                                        return (
                                            <li
                                                key={activity.id}
                                                className="flex items-start gap-3 border-b border-slate-50 pb-3 last:border-b-0 last:pb-0"
                                            >
                                                <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-indigo-500">
                                                    <Icon className="h-3.5 w-3.5" />
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <p className="text-sm font-medium text-slate-700">
                                                            {typeMeta.label}
                                                        </p>
                                                        <span
                                                            className={`rounded-full px-2 py-0.5 text-xs font-medium ${resultMeta.className}`}
                                                        >
                                                            {resultMeta.label}
                                                        </span>
                                                        <span className="text-xs text-slate-400">
                                                            {formatDateTime(
                                                                activity.activity_at,
                                                            )}
                                                        </span>
                                                    </div>
                                                    <p className="text-xs text-slate-500">
                                                        Dicatat oleh{' '}
                                                        {activity.actor?.name ??
                                                            '-'}{' '}
                                                        •{' '}
                                                        {formatDateTime(
                                                            activity.created_at,
                                                        )}
                                                    </p>
                                                    {activity.notes && (
                                                        <p className="mt-1 text-sm text-slate-600">
                                                            {activity.notes}
                                                        </p>
                                                    )}
                                                    {activity.next_action && (
                                                        <p className="mt-1 text-xs text-slate-500">
                                                            <span className="font-medium text-slate-600">
                                                                Tindak lanjut:
                                                            </span>{' '}
                                                            {activity.next_action}
                                                        </p>
                                                    )}
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ol>
                            )}
                        </>
                    )}
                </section>

                {/* Timeline */}
                <section className="rounded-lg bg-white p-5 shadow">
                    <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                        <History className="h-4 w-4 text-slate-400" />
                        Timeline (Audit Trail)
                    </h2>
                    {!timeline || timeline.length === 0 ? (
                        <p className="text-sm text-gray-500">
                            Belum ada aktivitas tercatat.
                        </p>
                    ) : (
                        <ol className="space-y-3">
                            {timeline.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="flex items-start gap-3 border-b border-slate-50 pb-3 last:border-b-0 last:pb-0"
                                >
                                    <span className="mt-0.5 h-2 w-2 shrink-0 rounded-full bg-indigo-400" />
                                    <div className="min-w-0">
                                        <p className="text-sm text-slate-700">
                                            {entry.description || entry.action}
                                        </p>
                                        <p className="text-xs text-slate-400">
                                            {entry.action}
                                            {entry.actor ? ` • ${entry.actor}` : ''}
                                            {entry.created_at
                                                ? ` • ${formatDateTime(entry.created_at)}`
                                                : ''}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    )}
                </section>
            </div>
        </ShmLayout>
    );
}
