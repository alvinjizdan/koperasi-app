import React from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import SettingsForm from '@/Pages/Pengaturan/SettingsForm';
import { CalendarClock, AlertCircle, CheckCircle2 } from 'lucide-react';

export default function Operational({ settings, definitions, flash }) {
    // Hanya field grup "operasional" yang tampil di halaman ini.
    const operationalDefinitions = Object.fromEntries(
        Object.entries(definitions).filter(([, d]) => d.group === 'operasional')
    );

    return (
        <AdminLayout title="Pengaturan Operasional" pageTitle="Pengaturan Operasional Dasar">
            {flash?.success && (
                <div className="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs text-emerald-800 shadow-xs sm:text-sm">
                    <CheckCircle2 className="h-5 w-5 shrink-0 text-emerald-600" />
                    <span className="font-medium">{flash.success}</span>
                </div>
            )}

            <div className="mb-6 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        <CalendarClock className="h-6 w-6 text-emerald-600" />
                        <span>Preferensi Operasional</span>
                    </h2>
                    <p className="mt-1 text-xs text-slate-500 sm:text-sm">
                        Zona waktu memengaruhi batas periode laporan dan hari operasional. Format tampilan tidak pernah
                        mengubah cara penyimpanan data historis.
                    </p>
                </div>
            </div>

            <div className="mb-6 flex items-center gap-3 rounded-xl border border-blue-200 bg-blue-50 p-4 text-xs text-blue-800 sm:text-sm">
                <AlertCircle className="h-5 w-5 shrink-0 text-blue-600" />
                <span className="font-medium">
                    Perubahan zona waktu hanya berlaku untuk perhitungan berjalan. Nilai transaksi dan tanggal historis
                    tidak diubah.
                </span>
            </div>

            <SettingsForm
                settings={settings}
                definitions={operationalDefinitions}
                updateRoute={route('pengaturan.operational.update')}
                groups={[{ key: 'operasional', label: 'Preferensi Operasional' }]}
            />
        </AdminLayout>
    );
}
