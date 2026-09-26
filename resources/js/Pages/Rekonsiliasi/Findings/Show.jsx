import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import { ChevronLeft, ClipboardList, History } from 'lucide-react';

const TYPE_LABELS = {
    BALANCE_MISMATCH: 'Selisih Saldo',
    DUPLICATE_REFERENCE: 'Referensi Ganda',
    MISSING_REFERENCE: 'Referensi Hilang',
    INVALID_STATUS: 'Status Tidak Valid',
    UNALLOCATED_PAYMENT: 'Pembayaran Tanpa Alokasi',
    CASH_VARIANCE: 'Selisih Kas',
    INCOMPLETE_DATA: 'Data Tidak Lengkap',
};

const MODULE_LABELS = {
    savings: 'Simpanan',
    loans: 'Pinjaman & Angsuran',
    cash: 'Kas',
};

const STATUS_OPTIONS = [
    { value: 'OPEN', label: 'OPEN — perlu ditindaklanjuti' },
    { value: 'UNDER_REVIEW', label: 'UNDER_REVIEW — sedang diperiksa' },
    { value: 'RESOLVED', label: 'RESOLVED — selesai ditangani' },
    { value: 'ACCEPTED_WITH_REASON', label: 'ACCEPTED_WITH_REASON — diterima dengan alasan' },
];

export default function Show({ finding, followUps, can }) {
    const form = useForm({
        to_status: finding.status === 'OPEN' ? 'UNDER_REVIEW' : finding.status,
        note: '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.put(route('rekonsiliasi.findings.update', finding.id), {
            preserveScroll: true,
            onSuccess: () => form.setData('note', ''),
        });
    };

    const formatRupiah = (value) =>
        value === null || value === undefined ? '-' : `Rp ${Number(value).toLocaleString('id-ID')}`;

    return (
        <AdminLayout title={`Temuan #${finding.id}`} pageTitle={`Detail Temuan #${finding.id}`}>
            <Link
                href={route('rekonsiliasi.findings')}
                className="mb-4 inline-flex items-center gap-1 text-xs font-semibold text-slate-500 hover:text-slate-900"
            >
                <ChevronLeft className="h-3.5 w-3.5" />
                Kembali ke daftar temuan
            </Link>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {/* Detail temuan */}
                <div className="space-y-6 lg:col-span-2">
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                        <div className="flex items-center gap-2">
                            <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-700">
                                {TYPE_LABELS[finding.finding_type] || finding.finding_type}
                            </span>
                            <span className="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-medium text-slate-600">
                                {MODULE_LABELS[finding.module] || finding.module}
                            </span>
                            <span className="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-semibold text-slate-600">
                                {finding.status}
                            </span>
                            <span className="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-semibold text-slate-600">
                                Prioritas {finding.priority}
                            </span>
                        </div>

                        <dl className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <dt className="text-[11px] font-medium uppercase tracking-wider text-slate-400">Objek Terkait</dt>
                                <dd className="mt-0.5 font-mono text-xs font-semibold text-slate-900">
                                    {finding.object_label ?? '-'}
                                </dd>
                                {finding.object_type && (
                                    <dd className="text-[11px] text-slate-400">{finding.object_type}#{finding.object_id}</dd>
                                )}
                            </div>
                            <div>
                                <dt className="text-[11px] font-medium uppercase tracking-wider text-slate-400">Waktu Ditemukan</dt>
                                <dd className="mt-0.5 text-xs text-slate-900">{finding.found_at}</dd>
                            </div>
                            <div>
                                <dt className="text-[11px] font-medium uppercase tracking-wider text-slate-400">Nilai Diharapkan</dt>
                                <dd className="mt-0.5 text-xs font-semibold text-slate-900">{formatRupiah(finding.expected_value)}</dd>
                            </div>
                            <div>
                                <dt className="text-[11px] font-medium uppercase tracking-wider text-slate-400">Nilai Aktual</dt>
                                <dd className="mt-0.5 text-xs font-semibold text-slate-900">{formatRupiah(finding.actual_value)}</dd>
                            </div>
                            <div>
                                <dt className="text-[11px] font-medium uppercase tracking-wider text-slate-400">Selisih</dt>
                                <dd className={`mt-0.5 text-xs font-bold ${finding.variance ? 'text-red-600' : 'text-slate-900'}`}>
                                    {formatRupiah(finding.variance)}
                                </dd>
                            </div>
                            {finding.run && (
                                <div>
                                    <dt className="text-[11px] font-medium uppercase tracking-wider text-slate-400">Proses Rekonsiliasi</dt>
                                    <dd className="mt-0.5">
                                        <Link
                                            href={route('rekonsiliasi.runs.show', finding.run.id)}
                                            className="font-mono text-xs font-semibold text-emerald-600 hover:text-emerald-700"
                                        >
                                            {finding.run.run_number}
                                        </Link>
                                    </dd>
                                </div>
                            )}
                        </dl>

                        <div className="mt-4 rounded-lg border border-slate-200 bg-slate-50/80 p-3 text-[11px] text-slate-600">
                            Rekomendasi perbaikan tidak mengubah saldo otomatis. Gunakan mekanisme koreksi resmi pada
                            modul terkait (misalnya reversal transaksi kas) yang tercatat dalam audit trail.
                        </div>
                    </div>

                    {/* Riwayat tindak lanjut */}
                    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                        <div className="flex items-center gap-2 border-b border-slate-200 px-6 py-4">
                            <History className="h-4 w-4 text-slate-500" />
                            <h3 className="text-sm font-semibold text-slate-900">Riwayat Tindak Lanjut</h3>
                        </div>
                        {followUps.length > 0 ? (
                            <ol className="divide-y divide-slate-100">
                                {followUps.map((fu) => (
                                    <li key={fu.id} className="px-6 py-4">
                                        <div className="flex flex-wrap items-center gap-2 text-[11px]">
                                            <span className="font-semibold text-slate-700">{fu.user?.name ?? 'Sistem'}</span>
                                            <span className="text-slate-400">{fu.created_at}</span>
                                            <span className="text-slate-400">·</span>
                                            <span className="font-mono text-slate-500">
                                                {fu.from_status ?? '-'} &rarr; {fu.to_status}
                                            </span>
                                        </div>
                                        <p className="mt-1 text-xs text-slate-700">{fu.note}</p>
                                    </li>
                                ))}
                            </ol>
                        ) : (
                            <div className="p-6">
                                <EmptyState
                                    icon={ClipboardList}
                                    title="Belum Ada Tindak Lanjut"
                                    description="Setiap perubahan status temuan akan tercatat di sini beserta catatan dan penggunanya."
                                />
                            </div>
                        )}
                    </div>
                </div>

                {/* Form tindak lanjut */}
                <div>
                    {can?.resolve ? (
                        <form onSubmit={submit} className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                            <h3 className="text-sm font-bold text-slate-900">Tindak Lanjut Temuan</h3>
                            <p className="mt-1 text-[11px] text-slate-500">
                                Perubahan status wajib disertai catatan dan tercatat dalam audit trail.
                            </p>

                            <label className="mt-4 block text-xs font-semibold text-slate-700" htmlFor="to_status">
                                Status Baru
                            </label>
                            <select
                                id="to_status"
                                value={form.data.to_status}
                                onChange={(e) => form.setData('to_status', e.target.value)}
                                className="mt-1 w-full rounded-lg border border-slate-300 bg-slate-50/50 px-3 py-2 text-xs text-slate-900 focus:border-slate-900 focus:bg-white focus:outline-none focus:ring-1 focus:ring-slate-900"
                            >
                                {STATUS_OPTIONS.map((opt) => (
                                    <option key={opt.value} value={opt.value}>
                                        {opt.label}
                                    </option>
                                ))}
                            </select>
                            {form.errors.to_status && <p className="mt-1 text-xs text-red-600">{form.errors.to_status}</p>}

                            <label className="mt-4 block text-xs font-semibold text-slate-700" htmlFor="note">
                                Catatan Pemeriksaan {['RESOLVED', 'ACCEPTED_WITH_REASON'].includes(form.data.to_status) && '(wajib jelaskan bukti/alasan)'}
                            </label>
                            <textarea
                                id="note"
                                rows={5}
                                value={form.data.note}
                                onChange={(e) => form.setData('note', e.target.value)}
                                placeholder="Jelaskan hasil pemeriksaan, bukti, atau alasan tindak lanjut..."
                                className="mt-1 w-full rounded-lg border border-slate-300 bg-slate-50/50 px-3 py-2 text-xs text-slate-900 placeholder:text-slate-400 focus:border-slate-900 focus:bg-white focus:outline-none focus:ring-1 focus:ring-slate-900"
                            />
                            {form.errors.note && <p className="mt-1 text-xs text-red-600">{form.errors.note}</p>}

                            <button
                                type="submit"
                                disabled={form.processing}
                                className="mt-4 w-full rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700 active:scale-95 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {form.processing ? 'Menyimpan...' : 'Simpan Tindak Lanjut'}
                            </button>
                        </form>
                    ) : (
                        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs text-[11px] text-slate-500">
                            Anda tidak memiliki izin menindaklanjuti temuan (permission{' '}
                            <span className="font-mono">rekonsiliasi.resolve</span>).
                        </div>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
