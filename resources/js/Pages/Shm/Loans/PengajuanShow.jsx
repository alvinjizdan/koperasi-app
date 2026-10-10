import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import SecondaryButton from '@/Components/SecondaryButton';
import { formatDateTime, formatRupiah } from '@/utils/format';
import { Banknote, FileCheck, FileDown, Info, Pencil, Send } from 'lucide-react';

/**
 * Detail Pengajuan Pinjaman SHM yang SUDAH TERSIMPAN (Ticket 67).
 *
 * Satu halaman read-only yang menampilkan kembali seluruh 11 section dari
 * database sehingga pengguna tidak perlu mengisi ulang form. Rate yang
 * ditampilkan berasal dari domain object (rate resmi 2%), bukan kolom
 * tersendiri. Status memakai vocabulary workflow existing (pending/approved/
 * rejected); "DITANGGUHKAN" sengaja tidak ada karena belum dikonfirmasi
 * bisnisnya.
 */

const STATUS_LABELS = {
    pending: { text: 'BARU — Menunggu Keputusan', className: 'bg-indigo-50 text-indigo-700 border-indigo-200' },
    approved: { text: 'DISETUJUI', className: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
    rejected: { text: 'DITOLAK', className: 'bg-rose-50 text-rose-700 border-rose-200' },
};

/**
 * Status keputusan dari baris ShmApproval EXISTING (bukan kolom status
 * pengajuan) — Ticket 68 §6.
 */
const APPROVAL_LABELS = {
    pending: { text: 'MENUNGGU APPROVAL', className: 'bg-amber-50 text-amber-700 border-amber-200' },
    approved: { text: 'DISETUJUI', className: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
    rejected: { text: 'DITOLAK', className: 'bg-rose-50 text-rose-700 border-rose-200' },
};

function DetailSection({ index, title, children }) {
    return (
        <section className="rounded-lg bg-white p-5 shadow">
            <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-700">
                <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-800 text-[11px] font-bold text-white">
                    {index}
                </span>
                {title}
            </h2>
            {children}
        </section>
    );
}

function Row({ label, value, mono }) {
    return (
        <div className="flex items-start justify-between gap-4 border-b border-dotted border-slate-200 py-1.5 text-sm last:border-0">
            <dt className="text-slate-500">{label}</dt>
            <dd className={`text-right font-medium text-slate-800 ${mono ? 'tabular-nums' : ''}`}>
                {value ?? '—'}
            </dd>
        </div>
    );
}

export default function PengajuanShow({ application, flash }) {
    const status = STATUS_LABELS[application.status] ?? STATUS_LABELS.pending;
    const approval = application.approval;
    const approvalBadge = approval
        ? APPROVAL_LABELS[approval.status] ?? null
        : null;

    // "Ajukan Approval" (Ticket 68): sekali klik → ShmWorkflowService
    // existing; server menolak pengajuan yang sudah terkunci (idempoten).
    const submitForApproval = () => {
        if (
            !window.confirm(
                'Ajukan pengajuan ini ke modul Approval SHM? Setelah diajukan, pengajuan terkunci untuk edit.',
            )
        ) {
            return;
        }

        router.post(
            route('shm.loans.pengajuan.submit-approval', application.id),
            {},
            { preserveScroll: true },
        );
    };
    const customer = application.customer ?? {};
    const money = (value) =>
        value === null || value === undefined || value === ''
            ? '—'
            : formatRupiah(value);

    // "Pencairan" (Ticket 69): hanya tersedia bila approval DISETUJUI dan
    // belum ada pinjaman hasil pencairan; server tetap memegang guard
    // approval + idempotency (persembunyian tombol tidak cukup).
    const disburse = () => {
        if (
            !window.confirm(
                [
                    'Laksanakan pencairan pengajuan ini?',
                    `Nomor Pengajuan: ${application.application_number}`,
                    `Nasabah: ${customer.name ?? '—'}`,
                    `Jumlah Pinjaman: ${money(application.pengajuan.principal)}`,
                    `Status Approval: ${
                        approvalBadge?.text ?? approval?.status ?? '—'
                    }`,
                ].join('\n'),
            )
        ) {
            return;
        }

        router.post(
            route('shm.loans.pengajuan.disburse', application.id),
            {},
            { preserveScroll: true },
        );
    };

    return (
        <ShmLayout title={`Pengajuan ${application.application_number}`}>
            <Head title={`Pengajuan ${application.application_number}`} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
                            <FileCheck className="h-5 w-5 text-slate-400" />
                            Detail Pengajuan Pinjaman
                        </h1>
                        <p className="text-sm text-gray-500">
                            {application.application_number} &middot;{' '}
                            {application.application_date} &middot; Rate resmi{' '}
                            <strong>{Number(application.rate)}%</strong>
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span
                            className={`rounded-full border px-3 py-1.5 text-xs font-semibold ${status.className}`}
                        >
                            {status.text}
                        </span>
                        <span
                            className={`rounded-full border px-3 py-1.5 text-xs font-semibold ${
                                approvalBadge?.className ??
                                'bg-slate-100 text-slate-600 border-slate-200'
                            }`}
                        >
                            {approvalBadge?.text ?? 'BELUM DIAJUKAN'}
                        </span>
                        {application.can_edit && (
                            <Link
                                href={route('shm.loans.pengajuan.edit', application.id)}
                            >
                                <SecondaryButton type="button">
                                    <Pencil className="mr-1.5 inline h-4 w-4" />
                                    Edit
                                </SecondaryButton>
                            </Link>
                        )}
                        {application.can_submit_approval && (
                            <SecondaryButton type="button" onClick={submitForApproval}>
                                <Send className="mr-1.5 inline h-4 w-4" />
                                Ajukan Approval
                            </SecondaryButton>
                        )}
                        {application.can_disburse && (
                            <SecondaryButton type="button" onClick={disburse}>
                                <Banknote className="mr-1.5 inline h-4 w-4" />
                                Pencairan
                            </SecondaryButton>
                        )}
                        <a
                            href={route('shm.loans.pengajuan.pdf', application.id)}
                            className="inline-flex items-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 hover:bg-gray-700"
                        >
                            <FileDown className="mr-1.5 h-4 w-4" />
                            Export PDF
                        </a>
                        <Link href={route('shm.loans.pengajuan')}>
                            <SecondaryButton type="button">Formulir Baru</SecondaryButton>
                        </Link>
                    </div>
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

                {/* Ticket 68 — informasi approval dari ShmApproval existing. */}
                <div className="rounded-lg bg-white p-5 shadow">
                    <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-700">
                        Informasi Approval
                    </h2>
                    {approval ? (
                        <dl className="grid grid-cols-1 gap-x-8 gap-y-1.5 text-sm sm:grid-cols-2">
                            <div className="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                                <dt className="text-slate-500">Status</dt>
                                <dd className="font-medium text-slate-800">
                                    {approvalBadge?.text ?? approval.status}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                                <dt className="text-slate-500">Keputusan oleh</dt>
                                <dd className="font-medium text-slate-800">
                                    {approval.decider ?? '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                                <dt className="text-slate-500">Waktu keputusan</dt>
                                <dd className="font-medium text-slate-800">
                                    {approval.decided_at
                                        ? formatDateTime(approval.decided_at)
                                        : '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                                <dt className="text-slate-500">Catatan</dt>
                                <dd className="font-medium text-slate-800">
                                    {approval.notes || '—'}
                                </dd>
                            </div>
                        </dl>
                    ) : (
                        <p className="text-sm text-slate-500">
                            Belum diajukan approval. Pengajuan masih dapat
                            diedit; gunakan tombol{' '}
                            <strong>Ajukan Approval</strong> untuk mengirim ke
                            modul Approval SHM existing.
                        </p>
                    )}
                    {approval?.status === 'rejected' && (
                        <p className="mt-2 text-xs text-rose-600">
                            Pengajuan ditolak. Perbaikan/resubmit mengikuti
                            business rule yang belum dikonfirmasi (PENDING
                            BUSINESS CONFIRMATION) — pengajuan tetap terkunci.
                        </p>
                    )}
                </div>

                {/* Ticket 69 — state pencairan: tampil setelah ShmLoan dibuat;
                    tombol Pencairan hilang karena can_disburse = false. */}
                {application.loan && (
                    <div className="rounded-lg bg-white p-5 shadow">
                        <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-700">
                            <Banknote className="h-4 w-4 text-emerald-500" />
                            Informasi Pencairan
                        </h2>
                        <dl className="grid grid-cols-1 gap-x-8 gap-y-1.5 text-sm sm:grid-cols-2">
                            <div className="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                                <dt className="text-slate-500">Nomor Pinjaman</dt>
                                <dd className="font-medium text-slate-800">
                                    <Link
                                        href={route(
                                            'shm.loans.show',
                                            application.loan.id,
                                        )}
                                        className="text-indigo-600 hover:underline"
                                    >
                                        {application.loan.loan_number}
                                    </Link>
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                                <dt className="text-slate-500">
                                    Tanggal Pencairan
                                </dt>
                                <dd className="font-medium text-slate-800">
                                    {application.loan.disbursed_at ?? '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                                <dt className="text-slate-500">
                                    Status Pinjaman
                                </dt>
                                <dd>
                                    <span className="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-semibold uppercase text-emerald-700">
                                        {application.loan.status}
                                    </span>
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                                <dt className="text-slate-500">
                                    Jumlah Pinjaman
                                </dt>
                                <dd className="font-medium tabular-nums text-slate-800">
                                    {money(application.loan.principal)}
                                </dd>
                            </div>
                        </dl>
                    </div>
                )}

                <div className="flex items-start gap-2 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
                    <Info className="mt-0.5 h-4 w-4 shrink-0" />
                    <p>
                        Data tersimpan pada server dan tampil sesuai database.
                        Keputusan approval diambil melalui modul Approval SHM
                        existing; status DITANGGUHKAN belum tersedia pada
                        workflow (PENDING BUSINESS CONFIRMATION).
                    </p>
                </div>

                <DetailSection index={1} title="Data Pribadi">
                    <dl>
                        <Row label="Nomor Nasabah (Nomor Anggota SHM)" value={customer.customer_number} />
                        <Row label="Tanggal Pengajuan" value={application.application_date} />
                        <Row label="Nama Lengkap" value={customer.name} />
                        <Row label="No. Identitas (NIK)" value={customer.nik} />
                        <Row label="No. Ponsel" value={customer.phone} />
                        <Row label="Alamat" value={customer.address} />
                        <Row label="Status" value={customer.is_active ? 'Aktif' : 'Nonaktif'} />
                        <Row label="Tanggal Menjadi Nasabah SHM" value={customer.registered_at} />
                        <Row
                            label="Unit Kerja / NIP / Lahir / Tanggungan / Saldo Modal & Non Modal / Batas Hutang"
                            value="Belum tersedia (PENDING)"
                        />
                    </dl>
                </DetailSection>

                <DetailSection index={2} title="Pengajuan Pinjaman">
                    <dl>
                        <Row
                            label="Produk Pinjaman"
                            value={
                                application.product
                                    ? `${application.product.name} (${application.product.code})`
                                    : '—'
                            }
                        />
                        <Row label="Besar Pinjaman" value={money(application.pengajuan.principal)} mono />
                        <Row
                            label="Jangka Waktu"
                            value={`${application.pengajuan.tenor_months} kali angsuran`}
                        />
                        <Row label="Tujuan Pinjaman" value={application.pengajuan.purpose} />
                        <Row
                            label="Pinjaman yang ke-"
                            value={application.pengajuan.loan_sequence ?? '—'}
                        />
                        <Row
                            label="Metode penyetoran/pembayaran angsuran"
                            value={application.pengajuan.disbursement_method}
                        />
                    </dl>
                </DetailSection>

                <DetailSection index={3} title="Data Penghasilan dan Pengeluaran Tiap Bulan">
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Penghasilan
                            </h3>
                            <dl>
                                <Row label="Penghasilan Tetap" value={money(application.income.tetap)} mono />
                                <Row label="Penghasilan Istri/Suami" value={money(application.income.pasangan)} mono />
                                <Row label="Penghasilan Usaha/Lain-lain" value={money(application.income.usaha)} mono />
                                <Row label="Total" value={money(application.income.total)} mono />
                            </dl>
                        </div>
                        <div>
                            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Pengeluaran
                            </h3>
                            <dl>
                                <Row label="Potongan Gaji/Angsuran Pinjaman" value={money(application.expense.potongan)} mono />
                                <Row label="Biaya Rumah Tangga" value={money(application.expense.rumahTangga)} mono />
                                <Row label="Biaya Pendidikan" value={money(application.expense.pendidikan)} mono />
                                <Row label="Sewa/Kontrak Rumah" value={money(application.expense.sewa)} mono />
                                <Row label="Biaya Listrik/Telepon/Air" value={money(application.expense.listrik)} mono />
                                <Row label="Transportasi" value={money(application.expense.transportasi)} mono />
                                <Row label="Pengeluaran Lain-lain" value={money(application.expense.lain)} mono />
                                <Row label="Total" value={money(application.expense.total)} mono />
                            </dl>
                        </div>
                    </div>
                    <div className="mt-4 flex items-center justify-between rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3">
                        <span className="text-sm font-semibold text-indigo-900">
                            Sisa Penghasilan
                        </span>
                        <span className="text-base font-bold tabular-nums text-indigo-900">
                            {money(application.sisa)}
                        </span>
                    </div>
                </DetailSection>

                <DetailSection index={4} title="Data Tambahan">
                    <dl>
                        <Row
                            label="Saldo pinjaman koperasi saat ini"
                            value={money(application.tambahan.koperasi_loan_balance)}
                            mono
                        />
                        <Row label="Fasilitas pinjaman dari lembaga lain" value={application.tambahan.other_facility} />
                        <Row label="Nama lembaga" value={application.tambahan.institution_name} />
                    </dl>

                    <h3 className="mb-2 mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Barang agunan kredit
                    </h3>
                    {application.agunan.length === 0 ? (
                        <p className="text-sm text-slate-400">—</p>
                    ) : (
                        <div className="overflow-x-auto rounded-lg border border-slate-200">
                            <table className="w-full text-sm">
                                <thead className="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                                    <tr>
                                        <th className="px-3 py-2">No</th>
                                        <th className="px-3 py-2">Jenis barang</th>
                                        <th className="px-3 py-2">Nomor</th>
                                        <th className="px-3 py-2">Tahun</th>
                                        <th className="px-3 py-2 text-right">Nilai perkiraan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {application.agunan.map((item, index) => (
                                        <tr key={`${item.jenis}-${index}`} className="border-t border-slate-100">
                                            <td className="px-3 py-2">{index + 1}</td>
                                            <td className="px-3 py-2">{item.jenis}</td>
                                            <td className="px-3 py-2">{item.nomor ?? '—'}</td>
                                            <td className="px-3 py-2">{item.tahun ?? '—'}</td>
                                            <td className="px-3 py-2 text-right tabular-nums">
                                                {money(item.nilai)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </DetailSection>

                <DetailSection index={5} title="Jaminan Pinjaman">
                    {application.jaminan.length === 0 ? (
                        <p className="text-sm text-slate-400">Belum ada jaminan.</p>
                    ) : (
                        <div className="space-y-4">
                            {application.jaminan.map((item, index) => (
                                <div
                                    key={`${item.nama}-${index}`}
                                    className="rounded-lg border border-slate-200 p-4"
                                >
                                    <p className="mb-2 text-sm font-semibold text-slate-800">
                                        {index + 1}. {item.jenis} — {item.nama}
                                    </p>
                                    <dl>
                                        <Row label="Tanggal" value={item.tanggal} />
                                        <Row label="Keterangan" value={item.keterangan} />
                                        <Row label="Nilai Perkiraan" value={money(item.nilai_perkiraan)} mono />
                                        <Row label="Status Pengikatan" value={item.status_pengikatan} />
                                        <Row label="Tanggal Pengikatan" value={item.tanggal_pengikatan} />
                                        <Row label="Nilai Hak Tanggungan" value={money(item.nilai_hak_tanggungan)} mono />
                                    </dl>
                                </div>
                            ))}
                            <p className="text-xs text-slate-500">
                                Foto jaminan: PENDING INFRASTRUCTURE (upload
                                berkas belum tersedia).
                            </p>
                        </div>
                    )}
                </DetailSection>

                <DetailSection index={6} title="Keterangan / Catatan oleh Kasir / SC">
                    <dl>
                        <Row label="Tanggal" value={application.catatan.date} />
                        <Row label="Nama" value={application.catatan.name} />
                    </dl>
                    <p className="mt-2 whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-sm text-slate-700">
                        {application.catatan.notes || '—'}
                    </p>
                </DetailSection>

                <DetailSection index={7} title="Analisa Pinjaman">
                    <dl>
                        <Row label="Kode Analis" value={application.analisa.analyst_code} />
                        <Row label="Kantor (Analis)" value={application.analisa.analyst_office} />
                        <Row label="Kode Account Officer" value={application.analisa.officer_code} />
                        <Row label="Kantor (Account Officer)" value={application.analisa.officer_office} />
                        <Row label="Tanggal" value={application.analisa.date} />
                        <Row label="Nilai Pinjaman" value={money(application.analisa.value)} mono />
                    </dl>
                    <p className="mt-2 whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-sm text-slate-700">
                        {application.analisa.notes || '—'}
                    </p>
                </DetailSection>

                <DetailSection index={8} title="Data Tambahan Analisa Pinjaman">
                    <p className="text-sm text-slate-500">
                        Belum tersedia — struktur field masih PENDING BUSINESS
                        CONFIRMATION.
                    </p>
                </DetailSection>

                <DetailSection index={9} title="Kolektor Pinjaman">
                    <dl>
                        <Row label="Nama Kolektor Pinjaman" value={application.kolektor.name} />
                    </dl>
                    <p className="mt-2 text-xs text-slate-500">
                        Master kolektor belum tersedia (PENDING BUSINESS
                        CONFIRMATION).
                    </p>
                </DetailSection>

                <DetailSection index={10} title="Persetujuan Pinjaman">
                    <dl>
                        <Row
                            label="Produk Pinjaman"
                            value={
                                application.product
                                    ? `${application.product.name} (${application.product.code})`
                                    : '—'
                            }
                        />
                        <Row label="Pengajuan Pinjaman Sebesar" value={money(application.pengajuan.principal)} mono />
                        <Row label="Analisa Pinjaman Sebesar" value={money(application.analisa.value)} mono />
                        <Row label="Persetujuan Pinjaman Sebesar" value={money(application.persetujuan.approved_amount)} mono />
                        <Row label="Biaya Provisi" value={money(application.persetujuan.provision_fee)} mono />
                        <Row label="Biaya Administrasi" value={money(application.persetujuan.administration_fee)} mono />
                        <Row label="Jangka waktu" value={`${application.pengajuan.tenor_months} kali angsuran`} />
                        <Row label="Jasa / Rate resmi" value={`${Number(application.rate)}%`} />
                        <Row label="Metode perhitungan" value={application.persetujuan.method ?? 'Belum dipilih (PENDING)'} />
                        <Row label="Status keputusan" value={status.text} />
                    </dl>
                    <p className="mt-2 whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-sm text-slate-700">
                        {application.persetujuan.notes || '—'}
                    </p>
                </DetailSection>

                <DetailSection index={11} title="Persyaratan Tambahan Jika Pinjaman Disetujui">
                    {application.persyaratan.length === 0 ? (
                        <p className="text-sm text-slate-400">
                            Belum ada persyaratan tambahan.
                        </p>
                    ) : (
                        <ul className="space-y-2">
                            {application.persyaratan.map((item, index) => (
                                <li
                                    key={`${item.text}-${index}`}
                                    className="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700"
                                >
                                    <input
                                        type="checkbox"
                                        checked={item.done}
                                        readOnly
                                        aria-label={`Persyaratan ${item.text}`}
                                    />
                                    <span>{item.text}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </DetailSection>

                <div className="flex items-center justify-between rounded-lg bg-white p-4 shadow">
                    <p className="text-xs text-slate-500">
                        Dibuat: {formatDateTime(application.created_at)}
                    </p>
                    <Link href={route('shm.loans.pengajuan')}>
                        <SecondaryButton type="button">Kembali</SecondaryButton>
                    </Link>
                </div>
            </div>
        </ShmLayout>
    );
}
