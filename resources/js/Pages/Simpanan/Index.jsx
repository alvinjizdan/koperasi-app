import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PiggyBank, BookOpen, ArrowRight } from 'lucide-react';

export default function Index() {
    const modules = [
        {
            title: 'Produk Simpanan',
            description: 'Kelola jenis produk simpanan yang tersedia untuk anggota.',
            route: 'simpanan-produk.index',
            icon: BookOpen,
        },
        {
            title: 'Rekening Simpanan',
            description: 'Buka rekening, catat setoran dan penarikan anggota.',
            route: 'simpanan-rekening.index',
            icon: PiggyBank,
        },
    ];

    return (
        <AdminLayout title="Simpanan" pageTitle="Modul Simpanan">
            <div className="space-y-6">
                <div>
                    <h2 className="text-lg font-semibold text-slate-900">Simpanan</h2>
                    <p className="mt-1 text-sm text-slate-500">
                        Pilih sub-modul yang ingin dikelola.
                    </p>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {modules.map((mod) => {
                        const Icon = mod.icon;
                        return (
                            <Link
                                key={mod.route}
                                href={route(mod.route)}
                                className="group flex items-start gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs transition-all duration-150 hover:border-slate-300 hover:shadow-sm active:scale-[0.99]"
                            >
                                <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700 group-hover:bg-emerald-600 group-hover:text-white transition-colors">
                                    <Icon className="h-5 w-5" />
                                </div>
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="font-semibold text-slate-900">{mod.title}</p>
                                        <ArrowRight className="h-4 w-4 text-slate-400 group-hover:text-slate-600 flex-shrink-0 transition-colors" />
                                    </div>
                                    <p className="mt-0.5 text-sm text-slate-500">{mod.description}</p>
                                </div>
                            </Link>
                        );
                    })}
                </div>
            </div>
        </AdminLayout>
    );
}
