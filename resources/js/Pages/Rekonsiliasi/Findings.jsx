import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import { Combobox, ComboboxContent, ComboboxEmpty, ComboboxInput, ComboboxItem, ComboboxList } from '@/Components/ui/combobox';
import { Search, AlertTriangle, CheckCircle2 } from 'lucide-react';

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
    loans: 'Pinjaman',
    cash: 'Kas',
};

const PRIORITY_STYLES = {
    HIGH: 'bg-red-50 text-red-700 border-red-200',
    MEDIUM: 'bg-amber-50 text-amber-700 border-amber-200',
    LOW: 'bg-slate-100 text-slate-600 border-slate-200',
};

function OptionsCombobox({ options, value, onChange, ariaLabel }) {
    const items = options.map((o) => o.value);
    return (
        <Combobox
            items={items}
            value={value}
            onValueChange={(v) => onChange(v ?? 'all')}
            itemToStringValue={(v) => options.find((o) => o.value === v)?.label ?? v}
        >
            <ComboboxInput aria-label={ariaLabel} />
            <ComboboxContent>
                <ComboboxEmpty>Tidak ada pilihan.</ComboboxEmpty>
                <ComboboxList>
                    {(v) => (
                        <ComboboxItem key={v} value={v}>
                            {options.find((o) => o.value === v)?.label}
                        </ComboboxItem>
                    )}
                </ComboboxList>
            </ComboboxContent>
        </Combobox>
    );
}

const STATUS_FILTER_OPTIONS = [
    { value: 'all', label: 'Semua Status' },
    { value: 'OPEN', label: 'OPEN' },
    { value: 'UNDER_REVIEW', label: 'UNDER_REVIEW' },
    { value: 'RESOLVED', label: 'RESOLVED' },
    { value: 'ACCEPTED_WITH_REASON', label: 'ACCEPTED_WITH_REASON' },
];
const MODULE_FILTER_OPTIONS = [
    { value: 'all', label: 'Semua Modul' },
    { value: 'savings', label: 'Simpanan' },
    { value: 'loans', label: 'Pinjaman' },
    { value: 'cash', label: 'Kas' },
];
const PRIORITY_FILTER_OPTIONS = [
    { value: 'all', label: 'Semua Prioritas' },
    { value: 'HIGH', label: 'HIGH' },
    { value: 'MEDIUM', label: 'MEDIUM' },
    { value: 'LOW', label: 'LOW' },
];
const StatusCombobox = (props) => <OptionsCombobox ariaLabel="Filter status" options={STATUS_FILTER_OPTIONS} {...props} />;
const ModuleCombobox = (props) => <OptionsCombobox ariaLabel="Filter modul" options={MODULE_FILTER_OPTIONS} {...props} />;
const PriorityCombobox = (props) => <OptionsCombobox ariaLabel="Filter prioritas" options={PRIORITY_FILTER_OPTIONS} {...props} />;
const TypeCombobox = ({ value, onChange }) => (
    <OptionsCombobox
        ariaLabel="Filter jenis temuan"
        value={value}
        onChange={onChange}
        options={[{ value: 'all', label: 'Semua Jenis' }, ...Object.entries(TYPE_LABELS).map(([v, label]) => ({ value: v, label }))]}
    />
);

const FINDING_STATUS_STYLES = {
    OPEN: 'bg-red-50 text-red-700 border-red-200',
    UNDER_REVIEW: 'bg-amber-50 text-amber-700 border-amber-200',
    RESOLVED: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    ACCEPTED_WITH_REASON: 'bg-slate-100 text-slate-600 border-slate-200',
};

export default function Findings({ findings, filters }) {
    const [local, setLocal] = useState(filters);

    const apply = (overrides = {}) => {
        router.get(route('rekonsiliasi.findings'), { ...local, ...overrides }, { preserveState: true, replace: true });
    };

    const setFilter = (key, value) => {
        const next = { ...local, [key]: value };
        setLocal(next);
        router.get(route('rekonsiliasi.findings'), next, { preserveState: true, replace: true });
    };

    const selectClass =
        'rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900 sm:text-sm';

    return (
        <AdminLayout title="Temuan Rekonsiliasi" pageTitle="Temuan Rekonsiliasi">
            <div className="mb-6 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        <AlertTriangle className="h-6 w-6 text-amber-500" />
                        <span>Seluruh Temuan Pemeriksaan</span>
                    </h2>
                    <p className="mt-1 text-xs text-slate-500 sm:text-sm">
                        Temuan belum ditangani (OPEN, UNDER_REVIEW) dan yang sudah selesai (RESOLVED,
                        ACCEPTED_WITH_REASON) dapat dibedakan melalui filter status.
                    </p>
                </div>
            </div>

            {/* Filter */}
            <div className="mb-6 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs sm:flex-row sm:flex-wrap">
                <StatusCombobox value={local.status} onChange={(v) => setFilter('status', v)} />
                <ModuleCombobox value={local.module} onChange={(v) => setFilter('module', v)} />
                <TypeCombobox value={local.finding_type} onChange={(v) => setFilter('finding_type', v)} />
                <PriorityCombobox value={local.priority} onChange={(v) => setFilter('priority', v)} />
            </div>

            <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                {findings.data.length > 0 ? (
                    <div className="divide-y divide-slate-100">
                        {findings.data.map((finding) => (
                            <Link
                                key={finding.id}
                                href={route('rekonsiliasi.findings.show', finding.id)}
                                className="flex flex-col gap-2 px-6 py-4 transition-colors hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-700">
                                            {TYPE_LABELS[finding.finding_type] || finding.finding_type}
                                        </span>
                                        <span className="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-medium text-slate-600">
                                            {MODULE_LABELS[finding.module] || finding.module}
                                        </span>
                                        <span className={`rounded-full border px-2 py-0.5 text-[10px] font-semibold ${PRIORITY_STYLES[finding.priority]}`}>
                                            {finding.priority}
                                        </span>
                                        <span className="font-mono text-[11px] text-slate-400">
                                            {finding.object_label ?? finding.object_type ?? '-'}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-[11px] text-slate-400">
                                        Ditemukan {finding.found_at}
                                        {finding.run ? ` · proses ${finding.run.run_number}` : ''}
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center gap-3">
                                    {finding.variance !== null && finding.variance !== undefined && (
                                        <span className="text-[11px] font-medium text-slate-600">
                                            Rp {Number(finding.variance).toLocaleString('id-ID')}
                                        </span>
                                    )}
                                    <span className={`rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${FINDING_STATUS_STYLES[finding.status]}`}>
                                        {finding.status}
                                    </span>
                                </div>
                            </Link>
                        ))}
                    </div>
                ) : (
                    <div className="p-8">
                        <EmptyState
                            icon={CheckCircle2}
                            title="Tidak Ada Temuan"
                            description="Tidak ada temuan yang cocok dengan filter saat ini. Seluruh pemeriksaan mungkin sudah sesuai."
                        />
                    </div>
                )}

                {findings.links && findings.links.length > 3 && (
                    <div className="flex flex-wrap items-center justify-center gap-1 border-t border-slate-200 bg-slate-50/50 px-6 py-4">
                        {findings.links.map((link, idx) =>
                            link.url ? (
                                <Link
                                    key={idx}
                                    href={link.url}
                                    className={`rounded-md px-3 py-1.5 text-xs font-semibold transition-colors ${
                                        link.active
                                            ? 'bg-slate-900 text-white shadow-xs'
                                            : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-200/80'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : (
                                <span
                                    key={idx}
                                    className="rounded-md bg-slate-100/60 px-3 py-1.5 text-xs font-medium text-slate-400"
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            )
                        )}
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
