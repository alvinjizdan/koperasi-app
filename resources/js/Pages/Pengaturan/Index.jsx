import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Building2,
    CalendarClock,
    ShieldCheck,
    Users,
    Lock,
    History,
    ChevronRight,
} from 'lucide-react';

const sections = [
    {
        key: 'profil',
        title: 'Profil Koperasi',
        description: 'Nama, alamat, kontak, dan legalitas koperasi yang dipakai pada identitas aplikasi.',
        icon: Building2,
        route: 'pengaturan.profile',
        permission: null,
    },
    {
        key: 'operasional',
        title: 'Pengaturan Operasional',
        description: 'Zona waktu operasional, format tanggal, dan preferensi tampilan.',
        icon: CalendarClock,
        route: 'pengaturan.operational',
        permission: null,
    },
    {
        key: 'keamanan',
        title: 'Keamanan Akun Saya',
        description: 'Status akun, aktivitas login, dan tautan ubah password pribadi.',
        icon: ShieldCheck,
        route: 'pengaturan.security',
        permission: null,
    },
    {
        key: 'users',
        title: 'Manajemen Pengguna',
        description: 'Kelola akun petugas: status aktif, role, dan reset password.',
        icon: Users,
        route: 'pengaturan.users.index',
        permission: 'pengguna.view',
    },
    {
        key: 'roles',
        title: 'Role & Permission',
        description: 'Kelola role beserta permission-nya dan tinjau matriks akses.',
        icon: Lock,
        route: 'pengaturan.roles.index',
        permission: 'role.view',
    },
    {
        key: 'audit',
        title: 'Audit Trail',
        description: 'Telusuri perubahan pengguna, role, permission, dan pengaturan.',
        icon: History,
        route: 'pengaturan.audit.index',
        permission: 'audit.view',
    },
];

export default function Index({ can }) {
    const available = sections.filter((section) => !section.permission || can?.[section.permission]);

    return (
        <AdminLayout title="Pengaturan" pageTitle="Pengaturan Sistem">
            <div className="mb-6 rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                    Pusat Pengaturan
                </h2>
                <p className="mt-1 text-xs text-slate-500 sm:text-sm">
                    Kelola profil koperasi, preferensi operasional, pengguna, dan keamanan akses aplikasi.
                </p>
            </div>

            {available.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50/50 p-10 text-center">
                    <ShieldCheck className="mx-auto h-10 w-10 text-slate-400" />
                    <h3 className="mt-3 text-sm font-semibold text-slate-900">
                        Belum Ada Pengaturan yang Dapat Diakses
                    </h3>
                    <p className="mt-1 text-xs text-slate-500">
                        Hubungi administrator untuk memperoleh akses ke bagian pengaturan.
                    </p>
                </div>
            ) : (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {available.map((section) => {
                        const Icon = section.icon;

                        return (
                            <Link
                                key={section.key}
                                href={route(section.route)}
                                className="group flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-xs transition-all hover:border-emerald-300 hover:shadow-md active:scale-[0.99]"
                            >
                                <div className="flex items-center justify-between">
                                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                                        <Icon className="h-5 w-5" />
                                    </div>
                                    <ChevronRight className="h-4 w-4 text-slate-300 transition-colors group-hover:text-emerald-600" />
                                </div>
                                <h3 className="mt-3 text-sm font-bold text-slate-900">{section.title}</h3>
                                <p className="mt-1 flex-1 text-xs text-slate-500">{section.description}</p>
                            </Link>
                        );
                    })}
                </div>
            )}
        </AdminLayout>
    );
}
