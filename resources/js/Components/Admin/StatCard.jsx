import React from 'react';

/**
 * Kartu metrik dashboard/laporan.
 *
 * `available=false` menampilkan status "Belum tersedia" alih-alih angka nol yang
 * menyesatkan, `scope` menjelaskan cakupan data metrik tersebut, dan `tone`
 * memberi warna aksen pada kontainer ikon saja agar kartu tetap netral.
 */
const TONES = {
    emerald: 'bg-emerald-50 text-emerald-600',
    blue: 'bg-blue-50 text-blue-600',
    amber: 'bg-amber-50 text-amber-600',
    rose: 'bg-rose-50 text-rose-600',
    indigo: 'bg-indigo-50 text-indigo-600',
    slate: 'bg-slate-100 text-slate-700',
};

export default function StatCard({
    title,
    value,
    icon: Icon,
    statusNote,
    scope = null,
    available = true,
    badge = null,
    tone = 'slate',
}) {
    const iconClasses = available
        ? TONES[tone] ?? TONES.slate
        : 'bg-amber-50 text-amber-600';

    return (
        <div className="flex h-full flex-col justify-between rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-xs font-semibold tracking-wide text-slate-500">{title}</p>
                    {available ? (
                        <h3 className="mt-2 text-2xl font-bold tracking-tight text-slate-900">{value}</h3>
                    ) : (
                        <p className="mt-2 text-base font-semibold text-slate-400">Belum tersedia</p>
                    )}
                </div>
                {Icon && (
                    <div
                        className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-lg ${iconClasses}`}
                    >
                        <Icon className="h-6 w-6" />
                    </div>
                )}
            </div>

            {(statusNote || scope || badge) && (
                <div className="mt-4 space-y-1 border-t border-slate-100 pt-3 text-xs">
                    {statusNote && <p className="font-medium text-slate-500">{statusNote}</p>}
                    {scope && <p className="text-[11px] leading-relaxed text-slate-400">{scope}</p>}
                    {badge && (
                        <span className="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                            {badge}
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}
