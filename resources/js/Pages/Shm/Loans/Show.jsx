import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import { formatDate, formatDateTime, formatRupiah } from '@/utils/format';
import {
    ArrowLeft,
    Building2,
    Calculator,
    CalendarCheck,
    CalendarDays,
    FileText,
    History,
    Landmark,
    User,
} from 'lucide-react';

const PERIOD_LABEL = { yearly: 'tahun', monthly: 'bulan' };

/** Label status pengajuan asal (vocabulary existing, tanpa status baru). */
const APPLICATION_STATUS_LABEL = {
    pending: 'Menunggu',
    approved: 'Disetujui',
    rejected: 'Ditolak',
};

/** Baris definisi konsisten untuk seluruh section. */
function Field({ label, children }) {
    return (
        <div>
            <dt className="text-xs text-gray-500">{label}</dt>
            <dd className="font-medium">{children ?? '-'}</dd>
        </div>
    );
}

/**
 * Detail record Pinjaman SHM (Ticket 64, diperluas Ticket 70).
 *
 * Ticket 70 menambahkan layer MONITORING setelah pencairan: data nasabah SHM
 * lengkap, pengajuan asal, ringkasan pencairan, jaminan/barang agunan, dan
 * garis waktu dari audit trail existing.
 *
 * SELURUH angka berasal dari record yang sudah tersimpan — tidak ada
 * perhitungan bunga maupun pelunasan di ticket ini.
 *
 * Ticket 83 — JADWAL ANGSURAN TERSIMPAN (shm_loan_installments):
 * - Jadwal dibuat HANYA melalui action eksplisit "Generate Jadwal Angsuran"
 *   (POST shm.loans.schedule.generate) — tidak pernah otomatis saat
 *   pencairan/approval/membuka halaman ini.
 * - Anchor jatuh tempo = first_due_date (dipilih Marketing); jadwal tampil
 *   dari database (bukan simulasi). Blok simulasi T64 dipertahankan sebagai
 *   pratinjau SAAT JADWAL BELUM ADA.
 * - Setelah jadwal tersedia, tombol generate tidak lagi ditawarkan (tidak
 *   ada regenerasi/overwrite dari UI).
 */
export default function Show({
    loan,
    result,
    schedule = null,
    can_generate_schedule = false,
}) {
    const rows = result?.rows ?? [];
    const installments = schedule?.installments ?? [];
    const scheduleGenerated = schedule?.generated ?? false;
    const application = loan.application ?? null;
    const timeline = loan.timeline ?? [];
    const jaminan = loan.jaminan ?? [];
    const agunan = loan.agunan ?? [];

    // T83 — action EKSPLISIT; tidak pernah dipanggil otomatis oleh UI.
    const generateSchedule = () => {
        if (
            !window.confirm(
                [
                    'Buat jadwal angsuran untuk pinjaman ini?',
                    `No. Pinjaman: ${loan.loan_number}`,
                    `Jatuh tempo pertama: ${
                        loan.first_due_date
                            ? formatDate(loan.first_due_date)
                            : '—'
                    }`,
                    'Jadwal bersifat tetap setelah dibuat (tidak dapat dibuat ulang dari halaman ini).',
                ].join('\n'),
            )
        ) {
            return;
        }

        router.post(
            route('shm.loans.schedule.generate', loan.id),
            {},
            { preserveScroll: true },
        );
    };

    const columns = [
        { key: 'period', label: 'No', align: 'center', render: (row) => row.period },
        {
            key: 'principal',
            label: 'Pokok',
            align: 'right',
            render: (row) => formatRupiah(row.principal),
        },
        {
            key: 'interest',
            label: 'Jasa/Bunga',
            align: 'right',
            render: (row) => formatRupiah(row.interest),
        },
        {
            key: 'total',
            label: 'Total',
            align: 'right',
            render: (row) => formatRupiah(row.total),
        },
        {
            key: 'balance',
            label: 'Saldo',
            align: 'right',
            render: (row) => formatRupiah(row.balance),
        },
    ];

    // T83 — kolom tabel jadwal angsuran TERSIMPAN (paid/status mengikuti
    // kolom/schema existing; pembayaran BELUM diimplementasikan).
    const scheduleColumns = [
        {
            key: 'installment_number',
            label: 'No',
            align: 'center',
            render: (row) => row.installment_number,
        },
        {
            key: 'due_date',
            label: 'Jatuh Tempo',
            render: (row) => formatDate(row.due_date),
        },
        {
            key: 'principal_amount',
            label: 'Pokok',
            align: 'right',
            render: (row) => formatRupiah(row.principal_amount),
        },
        {
            key: 'interest_amount',
            label: 'Bunga',
            align: 'right',
            render: (row) => formatRupiah(row.interest_amount),
        },
        {
            key: 'total_amount',
            label: 'Total',
            align: 'right',
            render: (row) => formatRupiah(row.total_amount),
        },
        {
            key: 'paid_amount',
            label: 'Dibayar',
            align: 'right',
            render: (row) => formatRupiah(row.paid_amount),
        },
        {
            key: 'remaining_amount',
            label: 'Sisa',
            align: 'right',
            render: (row) => formatRupiah(row.remaining_amount),
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => (
                <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium capitalize text-slate-700">
                    {row.status}
                </span>
            ),
        },
    ];

    const jaminanColumns = [
        {
            key: 'jenis',
            label: 'Jenis',
            sortable: false,
            render: (row) => row.jenis,
        },
        {
            key: 'nama',
            label: 'Nama',
            sortable: false,
            render: (row) => row.nama,
        },
        {
            key: 'tanggal',
            label: 'Tanggal',
            sortable: false,
            render: (row) => (row.tanggal ? formatDate(row.tanggal) : '-'),
        },
        {
            key: 'nilai_perkiraan',
            label: 'Nilai Perkiraan',
            sortable: false,
            align: 'right',
            render: (row) => formatRupiah(row.nilai_perkiraan),
        },
        {
            key: 'status_pengikatan',
            label: 'Status Pengikatan',
            sortable: false,
            render: (row) => row.status_pengikatan || '-',
        },
        {
            key: 'nilai_hak_tanggungan',
            label: 'Hak Tanggungan',
            sortable: false,
            align: 'right',
            render: (row) => formatRupiah(row.nilai_hak_tanggungan),
        },
    ];

    const agunanColumns = [
        { key: 'jenis', label: 'Jenis Barang', sortable: false, render: (row) => row.jenis },
        { key: 'nomor', label: 'No. Barang', sortable: false, render: (row) => row.nomor || '-' },
        { key: 'tahun', label: 'Tahun', sortable: false, render: (row) => row.tahun || '-' },
        {
            key: 'nilai',
            label: 'Nilai Perkiraan',
            sortable: false,
            align: 'right',
            render: (row) => formatRupiah(row.nilai),
        },
    ];

    return (
        <ShmLayout title={`Pinjaman ${loan.loan_number}`}>
            <Head title={`Pinjaman ${loan.loan_number}`} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link
                            href={route('shm.loans.index')}
                            className="mb-1 inline-flex items-center gap-1 text-sm text-indigo-600 hover:underline"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Kembali ke daftar pinjaman
                        </Link>
                        <h1 className="text-xl font-semibold text-gray-800">
                            {loan.loan_number}
                        </h1>
                        <p className="text-sm text-gray-500">
                            {loan.customer?.name} &middot; {loan.product?.name}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {application && (
                            <Link href={route('shm.loans.pengajuan.show', application.id)}>
                                <span className="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                    <FileText className="h-4 w-4" />
                                    Lihat Pengajuan
                                </span>
                            </Link>
                        )}
                        <Link href={route('shm.loans.simulation')}>
                            <span className="inline-flex items-center gap-2 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100">
                                <Calculator className="h-4 w-4" />
                                Simulasi Pinjaman
                            </span>
                        </Link>
                        <span
                            className={`rounded-full px-3 py-1 text-sm font-medium ${
                                loan.status === 'active'
                                    ? 'bg-emerald-50 text-emerald-700'
                                    : 'bg-slate-100 text-slate-600'
                            }`}
                        >
                            {loan.status === 'active' ? 'Berjalan' : 'Ditutup'}
                        </span>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* 1. Informasi Pinjaman */}
                    <section className="rounded-lg bg-white p-5 shadow">
                        <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                            <Landmark className="h-4 w-4 text-slate-400" />
                            Informasi Pinjaman
                        </h2>
                        <dl className="space-y-3 text-sm">
                            <Field label="No. Pinjaman">{loan.loan_number}</Field>
                            <Field label="Status">
                                <span
                                    className={`rounded-full px-2 py-1 text-xs font-medium ${
                                        loan.status === 'active'
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : 'bg-slate-100 text-slate-600'
                                    }`}
                                >
                                    {loan.status === 'active' ? 'Berjalan' : 'Ditutup'}
                                </span>
                            </Field>
                            <Field label="Produk Pinjaman">{loan.product?.name}</Field>
                            <Field label="Plafon">
                                <span className="text-lg font-semibold text-indigo-700">
                                    {formatRupiah(loan.principal)}
                                </span>
                            </Field>
                            <Field label="Tenor">{loan.tenor_months} bulan</Field>
                            <Field label="Bunga / Jasa">
                                {Number(loan.rate_percent)}%
                                {loan.rate_period
                                    ? ` per ${PERIOD_LABEL[loan.rate_period]}`
                                    : ' — periode rate belum dipilih'}
                            </Field>
                            {loan.calculation_method && (
                                <Field label="Metode Perhitungan">
                                    {loan.calculation_method}
                                </Field>
                            )}
                            <Field label="Saldo Pokok">
                                {formatRupiah(loan.outstanding)}
                            </Field>
                            <Field label="Lokasi">{loan.location}</Field>
                            <Field label="Tujuan Pinjaman">{loan.purpose}</Field>
                            {loan.note && <Field label="Catatan">{loan.note}</Field>}
                            <Field label="Terdaftar">
                                {formatDateTime(loan.created_at)}
                            </Field>
                        </dl>
                    </section>

                    <section className="space-y-6 lg:col-span-2">
                        {/* 2. Data Customer */}
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <User className="h-4 w-4 text-slate-400" />
                                Data Nasabah BSM Makro
                            </h2>
                            <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                <Field label="No. Nasabah">
                                    {loan.customer?.customer_number}
                                </Field>
                                <Field label="Nama">{loan.customer?.name}</Field>
                                <Field label="NIK">{loan.customer?.nik}</Field>
                                <Field label="Telepon">{loan.customer?.phone}</Field>
                                <Field label="Alamat">{loan.customer?.address}</Field>
                                <Field label="Terdaftar">
                                    {loan.customer?.registered_at
                                        ? formatDate(loan.customer.registered_at)
                                        : '-'}
                                </Field>
                            </dl>
                        </section>

                        {/* 3. Informasi Pengajuan */}
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <FileText className="h-4 w-4 text-slate-400" />
                                Informasi Pengajuan
                            </h2>
                            {application ? (
                                <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                    <Field label="No. Pengajuan">
                                        <Link
                                            href={route(
                                                'shm.loans.pengajuan.show',
                                                application.id,
                                            )}
                                            className="text-indigo-600 hover:underline"
                                        >
                                            {application.application_number}
                                        </Link>
                                    </Field>
                                    <Field label="Tanggal Pengajuan">
                                        {application.application_date
                                            ? formatDate(application.application_date)
                                            : '-'}
                                    </Field>
                                    <Field label="Tujuan Pengajuan">
                                        {application.purpose}
                                    </Field>
                                    <Field label="Status Pengajuan">
                                        {APPLICATION_STATUS_LABEL[application.status] ??
                                            application.status}
                                    </Field>
                                    <Field label="Plafon Diajukan">
                                        {formatRupiah(application.principal)}
                                    </Field>
                                    <Field label="Tenor Diajukan">
                                        {application.tenor_months} bulan
                                    </Field>
                                </dl>
                            ) : (
                                <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-500">
                                    Pinjaman ini tidak berasal dari alur
                                    pencairan pengajuan, sehingga tidak ada
                                    pengajuan yang dapat dirujuk.
                                </p>
                            )}
                        </section>

                        {/* 4. Informasi Pencairan */}
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <CalendarCheck className="h-4 w-4 text-slate-400" />
                                Informasi Pencairan
                            </h2>
                            <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                <Field label="Status Pencairan">
                                    {loan.disbursement?.disbursed_at
                                        ? 'Sudah dicairkan'
                                        : 'Belum dicairkan'}
                                </Field>
                                <Field label="Tanggal Pencairan">
                                    {loan.disbursement?.disbursed_at
                                        ? formatDate(loan.disbursement.disbursed_at)
                                        : '-'}
                                </Field>
                                <Field label="No. Pinjaman">
                                    {loan.disbursement?.loan_number}
                                </Field>
                                <Field label="Plafond Pinjaman">
                                    {formatRupiah(loan.disbursement?.principal)}
                                </Field>
                                {/* T83 — jatuh tempo pertama (dipilih
                                    Marketing; anchor jadwal angsuran). */}
                                <Field label="Jatuh Tempo Pertama">
                                    {loan.first_due_date
                                        ? formatDate(loan.first_due_date)
                                        : 'Belum ditentukan'}
                                </Field>
                            </dl>

                            {/* T85 — Rincian Biaya Pencairan & Dana Bersih */}
                            {loan.disbursement?.costs?.total_deduction != null && (
                                <div className="mt-4 border-t border-slate-100 pt-4">
                                    <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">
                                        Rincian Biaya Pencairan & Dana Bersih
                                    </h3>
                                    <dl className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                                        <Field label="Plafond">
                                            {formatRupiah(loan.disbursement.principal)}
                                        </Field>
                                        <Field label="Biaya Admin (2%)">
                                            {formatRupiah(loan.disbursement.costs.admin_fee)}
                                        </Field>
                                        <Field label="Biaya Provisi (3%)">
                                            {formatRupiah(loan.disbursement.costs.provision_fee)}
                                        </Field>
                                        <Field label="Asuransi (1%)">
                                            {formatRupiah(loan.disbursement.costs.insurance_fee)}
                                        </Field>
                                        <Field label="Biaya Notaris">
                                            {formatRupiah(loan.disbursement.costs.notary_fee)}
                                        </Field>
                                        <Field label="Simpanan Pokok">
                                            {formatRupiah(loan.disbursement.costs.principal_savings)}
                                        </Field>
                                        <Field label="Simpanan Wajib">
                                            {formatRupiah(loan.disbursement.costs.mandatory_savings)}
                                        </Field>
                                        <Field label="Total Potongan">
                                            <span className="font-semibold text-rose-600">
                                                {formatRupiah(loan.disbursement.costs.total_deduction)}
                                            </span>
                                        </Field>
                                        <div className="sm:col-span-2 rounded-lg bg-emerald-50 p-3">
                                            <Field label="Dana Bersih Diterima">
                                                <span className="text-base font-bold text-emerald-700">
                                                    {formatRupiah(loan.disbursement.costs.net_disbursement)}
                                                </span>
                                            </Field>
                                        </div>
                                    </dl>
                                </div>
                            )}
                        </section>

                        {/* 5. Jaminan & Barang Agunan */}
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <Building2 className="h-4 w-4 text-slate-400" />
                                Jaminan
                            </h2>

                            <h3 className="mb-2 text-xs font-semibold text-slate-500">
                                Jaminan Pinjaman
                            </h3>
                            <ReportTable
                                columns={jaminanColumns}
                                rows={jaminan}
                                emptyTitle="Belum ada jaminan"
                                emptyDescription="Jaminan yang tersimpan pada pengajuan akan tampil di sini."
                            />

                            <h3 className="mb-2 mt-5 text-xs font-semibold text-slate-500">
                                Barang Agunan Kredit
                            </h3>
                            <ReportTable
                                columns={agunanColumns}
                                rows={agunan}
                                emptyTitle="Belum ada barang agunan"
                                emptyDescription="Barang agunan yang tersimpan pada pengajuan akan tampil di sini."
                            />
                        </section>

                        {/* 6. Timeline / Aktivitas */}
                        <section className="rounded-lg bg-white p-5 shadow">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <History className="h-4 w-4 text-slate-400" />
                                Timeline Aktivitas
                            </h2>
                            {timeline.length > 0 ? (
                                <ol className="space-y-3">
                                    {timeline.map((entry, index) => (
                                        <li
                                            key={`${entry.action}-${index}`}
                                            className="flex items-start gap-3 border-l-2 border-indigo-100 pl-3"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm text-slate-700">
                                                    {entry.description || entry.action}
                                                </p>
                                                <p className="text-xs text-slate-400">
                                                    {entry.actor
                                                        ? `${entry.actor} · `
                                                        : ''}
                                                    {formatDateTime(entry.created_at)}
                                                </p>
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            ) : (
                                <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-500">
                                    Belum ada catatan aktivitas untuk pinjaman ini.
                                </p>
                            )}
                        </section>
                    </section>
                </div>

                {/* T83 — Jadwal Angsuran TERSIMPAN + action eksplisit. */}
                <section className="rounded-lg bg-white p-5 shadow">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-sm font-semibold uppercase text-slate-600">
                            Jadwal Angsuran
                        </h2>

                        {scheduleGenerated ? (
                            <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                                Sudah dibuat · {installments.length}{' '}
                                Angsuran
                            </span>
                        ) : (
                            <span className="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">
                                Belum dibuat
                            </span>
                        )}
                    </div>

                    {!scheduleGenerated && (
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
                            <div>
                                <p className="text-xs text-gray-500">
                                    Jatuh tempo pertama
                                </p>
                                <p className="font-medium">
                                    {loan.first_due_date
                                        ? formatDate(loan.first_due_date)
                                        : 'Belum ditentukan'}
                                </p>
                            </div>

                            {can_generate_schedule ? (
                                <button
                                    type="button"
                                    onClick={generateSchedule}
                                    className="inline-flex items-center gap-2 rounded-lg bg-bsm-primary px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-bsm-primary-hover"
                                >
                                    <CalendarDays className="h-4 w-4" />
                                    Generate Jadwal Angsuran
                                </button>
                            ) : (
                                <p className="text-xs text-slate-500">
                                    Pinjaman ini belum memenuhi prasyarat
                                    pembangkitan jadwal (harus aktif, sudah
                                    dicairkan, dan memiliki jatuh tempo
                                    pertama).
                                </p>
                            )}
                        </div>
                    )}

                    {scheduleGenerated ? (
                        <ReportTable
                            columns={scheduleColumns}
                            rows={installments}
                            emptyTitle="Belum ada angsuran"
                            emptyDescription="Jadwal angsuran akan tampil di sini."
                        />
                    ) : result ? (
                        <>
                            <p className="mb-2 text-xs text-slate-500">
                                Pratinjau simulasi (belum tersimpan sebagai
                                jadwal) — gunakan tombol di atas untuk
                                membuat jadwal angsuran resmi.
                            </p>
                            <div className="flex flex-wrap gap-4 rounded-lg bg-white p-4 text-sm shadow">
                                <div>
                                    <p className="text-xs text-gray-500">Metode</p>
                                    <p className="font-medium">
                                        {result.method === 'flat'
                                            ? 'Bunga flat'
                                            : result.method === 'effective'
                                              ? 'Bunga efektif'
                                              : 'Anuitas'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs text-gray-500">Total jasa</p>
                                    <p className="font-medium">
                                        {formatRupiah(result.total_interest)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs text-gray-500">
                                        Total kewajiban
                                    </p>
                                    <p className="font-medium">
                                        {formatRupiah(result.total_obligation)}
                                    </p>
                                </div>
                            </div>

                            <ReportTable
                                columns={columns}
                                rows={rows}
                                emptyTitle="Belum ada jadwal angsuran"
                                emptyDescription="Jadwal angsuran akan tampil di sini."
                            />
                        </>
                    ) : (
                        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                            Pinjaman ini belum memiliki jadwal angsuran tersimpan. Rate pinjaman: <strong>{Number(loan.rate_percent)}%</strong>.
                        </div>
                    )}
                </section>
            </div>
        </ShmLayout>
    );
}
