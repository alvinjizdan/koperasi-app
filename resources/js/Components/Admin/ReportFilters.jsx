import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { Download, Filter, RotateCcw } from 'lucide-react';

export const PERIOD_OPTIONS = [
    { value: 'today', label: 'Hari Ini' },
    { value: 'last_7_days', label: '7 Hari Terakhir' },
    { value: 'this_month', label: 'Bulan Ini' },
    { value: 'last_month', label: 'Bulan Sebelumnya' },
    { value: 'custom', label: 'Rentang Khusus' },
];

/**
 * Bar filter bersama untuk seluruh halaman laporan.
 *
 * Nilai kosong dan sentinel "all" dibuang saat dikirim agar URL tetap bersih;
 * backend sudah memberikan nilai bawaan untuk filter yang tidak dikirim.
 */
export default function ReportFilters({
    routeName,
    filters,
    fields = [],
    showPeriod = true,
    showDateRange = true,
    exportRoute = null,
    reportSlug = null,
    periodLabel = null,
    timezone = null,
}) {
    const [state, setState] = useState(() => ({
        period: showPeriod ? filters.period || 'this_month' : undefined,
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
        ...Object.fromEntries(
            fields.map((field) => [field.name, filters[field.name] ?? (field.type === 'select' ? 'all' : '')])
        ),
    }));

    const set = (name, value) => setState((prev) => ({ ...prev, [name]: value }));

    const value = (name) => state[name] ?? '';

    const cleaned = (source) => {
        const payload = {};

        Object.entries(source).forEach(([key, raw]) => {
            if (raw === null || raw === undefined) return;
            if (typeof raw === 'string' && raw.trim() === '') return;
            if (raw === 'all') return;
            payload[key] = raw;
        });

        if (showPeriod && payload.period === undefined) {
            payload.period = 'this_month';
        }

        return payload;
    };

    const handleSubmit = (event) => {
        event.preventDefault();
        router.get(route(routeName), cleaned(state), { preserveState: true, replace: true });
    };

    const handleReset = () => {
        const reset = {
            period: showPeriod ? 'this_month' : undefined,
            date_from: '',
            date_to: '',
            ...Object.fromEntries(fields.map((field) => [field.name, field.type === 'select' ? 'all' : ''])),
        };

        // Tanggal operasional laporan tunggakan dipertahankan sebagai acuan default.
        if (filters.as_of_date !== undefined) {
            reset.as_of_date = filters.as_of_date;
        }

        setState(reset);
        router.get(route(routeName), cleaned(reset), { preserveState: true, replace: true });
    };

    const exportHref = exportRoute && reportSlug
        ? route(exportRoute, { report: reportSlug, ...cleaned(state) })
        : null;

    const inputClass =
        'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500';

    return (
        <form
            onSubmit={handleSubmit}
            className="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs sm:grid-cols-2 lg:grid-cols-6"
        >
            {showPeriod && (
                <div>
                    <label className="block text-xs font-medium text-slate-600">Periode</label>
                    <select
                        value={value('period')}
                        onChange={(event) => set('period', event.target.value)}
                        className={inputClass}
                    >
                        {PERIOD_OPTIONS.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>
            )}

            {showDateRange && (
                <>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Tanggal Mulai</label>
                        <input
                            type="date"
                            value={value('date_from')}
                            onChange={(event) => set('date_from', event.target.value)}
                            className={inputClass}
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Tanggal Akhir</label>
                        <input
                            type="date"
                            value={value('date_to')}
                            onChange={(event) => set('date_to', event.target.value)}
                            className={inputClass}
                        />
                    </div>
                </>
            )}

            {fields.map((field) => (
                <div key={field.name}>
                    <label className="block text-xs font-medium text-slate-600">{field.label}</label>
                    {field.type === 'select' ? (
                        <select
                            value={value(field.name)}
                            onChange={(event) => set(field.name, event.target.value)}
                            className={inputClass}
                        >
                            {(field.options || []).map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    ) : (
                        <input
                            type={field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'}
                            min={field.type === 'number' ? field.min ?? 0 : undefined}
                            value={value(field.name)}
                            onChange={(event) => set(field.name, event.target.value)}
                            placeholder={field.placeholder}
                            className={field.type === 'number' ? `${inputClass} tabular-nums` : inputClass}
                        />
                    )}
                </div>
            ))}

            <div className="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-6">
                <button
                    type="submit"
                    className="inline-flex items-center gap-1.5 rounded-lg bg-slate-800 px-3 py-2 text-xs font-semibold text-white transition-all hover:bg-slate-900 active:scale-95"
                >
                    <Filter className="h-3.5 w-3.5" />
                    Terapkan
                </button>
                <button
                    type="button"
                    onClick={handleReset}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                >
                    <RotateCcw className="h-3.5 w-3.5" />
                    Reset
                </button>

                {exportHref && (
                    <a
                        href={exportHref}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800 transition-colors hover:bg-emerald-100"
                    >
                        <Download className="h-3.5 w-3.5" />
                        Ekspor CSV
                    </a>
                )}

                {(periodLabel || timezone) && (
                    <p className="ml-auto text-[11px] leading-relaxed text-slate-400">
                        {periodLabel && (
                            <>
                                Periode aktif: <span className="font-semibold text-slate-600">{periodLabel}</span>.{' '}
                            </>
                        )}
                        {timezone && <>Zona waktu operasional: {timezone}.</>}
                    </p>
                )}
            </div>
        </form>
    );
}
