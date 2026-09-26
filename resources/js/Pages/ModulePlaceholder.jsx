import React from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Users,
    PiggyBank,
    Landmark,
    Receipt,
    CreditCard,
    FileText,
    Settings,
    Layers,
    Clock,
    ArrowLeft,
} from 'lucide-react';
import { Link } from '@inertiajs/react';

const iconMap = {
    Users,
    PiggyBank,
    Landmark,
    Receipt,
    CreditCard,
    FileText,
    Settings,
    Layers,
};

export default function ModulePlaceholder({
    module_key,
    title,
    description,
    icon_name,
    status = 'Segera Hadir',
}) {
    const IconComponent = iconMap[icon_name] || Layers;

    return (
        <AdminLayout title={title} pageTitle={title}>
            <div className="flex flex-col items-center justify-center rounded-2xl border border-slate-200 bg-white p-8 sm:p-12 text-center shadow-xs">
                <div className="relative mb-4">
                    <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-100 text-slate-700">
                        <IconComponent className="h-8 w-8" />
                    </div>
                    <span className="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-emerald-500 ring-4 ring-white" />
                </div>

                <div className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 mb-3 border border-slate-200">
                    <Clock className="h-3.5 w-3.5 text-slate-500" />
                    <span>{status}</span>
                </div>

                <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                    {title}
                </h2>
                <p className="mt-2 max-w-md text-sm text-slate-500">
                    {description}
                </p>

                <div className="mt-6 rounded-lg bg-slate-50 border border-slate-200 p-4 text-xs text-slate-600 max-w-lg text-left">
                    <p className="font-semibold text-slate-800">
                        Catatan Pengembangan:
                    </p>
                    <p className="mt-1">
                        Halaman ini disiapkan sebagai tempat resmi untuk modul{' '}
                        <strong className="text-slate-900 font-semibold">
                            {title}
                        </strong>
                        . Pengoperasian database dan fitur bisnis lengkap akan
                        diimplementasikan pada fase selanjutnya.
                    </p>
                </div>

                <div className="mt-8">
                    <Link
                        href={route('dashboard')}
                        className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition-transform duration-100 hover:bg-slate-800 active:scale-95 focus-visible:outline-2 focus-visible:outline-slate-900"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        <span>Kembali ke Dashboard Utama</span>
                    </Link>
                </div>
            </div>
        </AdminLayout>
    );
}
