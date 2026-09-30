import React from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import SettingsForm from '@/Pages/Pengaturan/SettingsForm';
import { Building2, CheckCircle2 } from 'lucide-react';

export default function Profile({ settings, definitions, flash }) {
    // Hanya field grup "profil" yang tampil di halaman ini.
    const profileDefinitions = Object.fromEntries(
        Object.entries(definitions).filter(([, d]) => d.group === 'profil')
    );

    return (
        <AdminLayout title="Profil Koperasi" pageTitle="Pengaturan Profil Koperasi">
            {flash?.success && (
                <div className="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs text-emerald-800 shadow-xs sm:text-sm">
                    <CheckCircle2 className="h-5 w-5 shrink-0 text-emerald-600" />
                    <span className="font-medium">{flash.success}</span>
                </div>
            )}

            <div className="mb-6 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        <Building2 className="h-6 w-6 text-emerald-600" />
                        <span>Identitas Koperasi</span>
                    </h2>
                    <p className="mt-1 text-xs text-slate-500 sm:text-sm">
                        Informasi ini dipakai pada identitas aplikasi dan dokumen laporan. Perubahan tercatat pada audit
                        trail.
                    </p>
                </div>
            </div>

            <SettingsForm
                settings={settings}
                definitions={profileDefinitions}
                updateRoute={route('pengaturan.profile.update')}
                groups={[{ key: 'profil', label: 'Data Profil Koperasi' }]}
            />
        </AdminLayout>
    );
}
