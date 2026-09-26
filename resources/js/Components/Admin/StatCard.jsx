import React from 'react';

/**
 * Kartu metrik dashboard/laporan.
 *
 * `available=false` menampilkan status "Belum tersedia" alih-alih angka nol yang
 * menyesatkan, dan `scope` menjelaskan cakupan data metrik tersebut.
 */
export default function StatCard({
    title,
    value,
    icon: Icon,
    statusNote,
    scope = null,
    available = true,
    badge = null,
}) {
    return (
        <div className="flex h-full flex-col justify-between rounded-xl border border-slate-200 bg-white p-5 shadow-xs transition-shadow hover:shadow-md">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">{title}</p>
                    {available ? (
                        <h3 className="mt-2 text-2xl font-bold tracking-tight text-slate-900">{value}</h3>
                    ) : (
                        <p className="mt-2 text-base font-semibold text-slate-400">Belum tersedia</p>
                    )}
                </div>
                {Icon && (
                    <div
                        className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-lg ${
                            available ? 'bg-slate-100 text-slate-700' : 'bg-amber-50 text-amber-600'
                        }`}
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
