import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import {
    LayoutDashboard,
    Users,
    PiggyBank,
    Landmark,
    Receipt,
    CreditCard,
    FileText,
    Settings,
    ClipboardCheck,
    BookOpen,
    NotebookPen,
    BookMarked,
    BarChart3,
    Building2,
    X,
} from 'lucide-react';

const navigationItems = [
    {
        name: 'Dashboard',
        route: 'dashboard',
        icon: LayoutDashboard,
        isReleased: true,
    },
    {
        name: 'Anggota',
        route: 'anggota.index',
        icon: Users,
        isReleased: true,
    },
    {
        name: 'Simpanan',
        route: 'simpanan.index',
        icon: PiggyBank,
        isReleased: true,
    },
    {
        name: 'Pinjaman',
        route: 'pinjaman.index',
        icon: Landmark,
        isReleased: true,
    },
    {
        name: 'Angsuran',
        route: 'angsuran.index',
        icon: Receipt,
        isReleased: true,
    },
    {
        name: 'Kasir',
        route: 'kasir.index',
        icon: CreditCard,
        isReleased: true,
    },
    {
        name: 'Laporan',
        route: 'laporan.index',
        icon: FileText,
        isReleased: true,
    },
    {
        name: 'Posisi Pinjaman per Tanggal',
        route: 'laporan.posisi-pinjaman',
        icon: FileText,
        isReleased: true,
    },
    {
        name: 'Rekonsiliasi',
        route: 'rekonsiliasi.index',
        icon: ClipboardCheck,
        isReleased: true,
        permission: 'rekonsiliasi.view',
    },
    {
        name: 'Akuntansi: COA',
        route: 'akuntansi.coa.index',
        icon: BookOpen,
        isReleased: true,
        permission: 'accounting.view',
    },
    {
        name: 'Akuntansi: Jurnal',
        route: 'akuntansi.jurnal.index',
        icon: NotebookPen,
        isReleased: true,
        permission: 'accounting.journals.view',
    },
    {
        name: 'Akuntansi: Buku Besar',
        route: 'akuntansi.buku-besar.index',
        icon: BookMarked,
        isReleased: true,
        permission: 'accounting.ledger.view',
    },
    {
        name: 'Akuntansi: Laporan Keuangan',
        route: 'akuntansi.laporan-keuangan.neraca-saldo',
        icon: BarChart3,
        isReleased: true,
        permission: 'accounting.reports.view',
    },
    {
        name: 'Pengaturan',
        route: 'pengaturan.index',
        icon: Settings,
        isReleased: true,
    },
];

export default function Sidebar({ mobileOpen, setMobileOpen }) {
    // Menu dengan permission hanya tampil bila pengguna memegang permission-nya.
    // Superadmin memperoleh seluruh permission melalui effectivePermissions.
    const { auth } = usePage().props;
    const permissions = auth?.permissions ?? [];
    const visibleItems = navigationItems.filter((item) => !item.permission || permissions.includes(item.permission));

    return (
        <>
            {/* Desktop Fixed Sidebar */}
            <aside className="hidden lg:fixed lg:inset-y-0 lg:z-40 lg:flex lg:w-64 lg:flex-col lg:border-r lg:border-slate-200 lg:bg-slate-900 lg:text-slate-100">
                {/* Brand Header */}
                <div className="flex h-16 items-center px-6 border-b border-slate-800">
                    <Link
                        href={route('dashboard')}
                        className="flex items-center gap-3 group focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 rounded-lg p-1"
                    >
                        <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-600 text-white font-bold shadow-sm transition-transform duration-100 active:scale-95">
                            <Building2 className="h-5 w-5" />
                        </div>
                        <div className="flex flex-col">
                            <span className="text-sm font-semibold tracking-tight text-white group-hover:text-emerald-300 transition-colors">
                                KSP Bima Sakti
                            </span>
                            <span className="text-xs text-slate-400 font-medium">
                                Mandiri
                            </span>
                        </div>
                    </Link>
                </div>

                {/* Navigation Menu */}
                <div className="flex-1 overflow-y-auto px-4 py-4 space-y-1">
                    <div className="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Menu Utama
                    </div>
                    <nav className="space-y-1">
                        {visibleItems.map((item) => {
                            const Icon = item.icon;
                            const isActive = route().current(item.route);

                            return (
                                <Link
                                    key={item.name}
                                    href={route(item.route)}
                                    className={`group flex items-center justify-between rounded-lg px-3 py-2.5 text-sm font-medium transition-all duration-150 active:scale-[0.98] ${
                                        isActive
                                            ? 'bg-slate-800 text-white font-semibold border-l-4 border-emerald-500 shadow-sm'
                                            : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'
                                    }`}
                                >
                                    <div className="flex items-center gap-3">
                                        <Icon
                                            className={`h-4 w-4 transition-colors ${
                                                isActive
                                                    ? 'text-emerald-400'
                                                    : 'text-slate-400 group-hover:text-slate-200'
                                            }`}
                                        />
                                        <span>{item.name}</span>
                                    </div>
                                    {!item.isReleased && (
                                        <span className="text-[10px] font-medium px-2 py-0.5 rounded bg-slate-800 text-slate-400 border border-slate-700/60">
                                            Segera
                                        </span>
                                    )}
                                </Link>
                            );
                        })}
                    </nav>
                </div>

                {/* System Footer Badge */}
                <div className="p-4 border-t border-slate-800 bg-slate-950/40">
                    <div className="flex items-center gap-2 text-xs text-slate-400">
                        <div className="h-2 w-2 rounded-full bg-emerald-500 animate-pulse" />
                        <span className="font-medium text-slate-300">
                            Sistem Siap Operasional
                        </span>
                    </div>
                </div>
            </aside>

            {/* Mobile Drawer Navigation Backdrop & Sheet */}
            {mobileOpen && (
                <div className="fixed inset-0 z-50 lg:hidden">
                    {/* Backdrop */}
                    <div
                        className="fixed inset-0 bg-slate-900/60 backdrop-blur-xs transition-opacity duration-200"
                        onClick={() => setMobileOpen(false)}
                    />

                    {/* Drawer Content */}
                    <div className="fixed inset-y-0 left-0 w-full max-w-xs bg-slate-900 text-slate-100 shadow-xl flex flex-col transition-transform duration-200 ease-out">
                        <div className="flex h-16 items-center justify-between px-6 border-b border-slate-800">
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-600 text-white font-bold">
                                    <Building2 className="h-5 w-5" />
                                </div>
                                <div className="flex flex-col">
                                    <span className="text-sm font-semibold tracking-tight text-white">
                                        KSP Bima Sakti
                                    </span>
                                    <span className="text-xs text-slate-400 font-medium">
                                        Mandiri
                                    </span>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setMobileOpen(false)}
                                className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-800 hover:text-white transition-colors active:scale-95"
                                aria-label="Tutup Menu Navigation"
                            >
                                <X className="h-5 w-5" />
                            </button>
                        </div>

                        <div className="flex-1 overflow-y-auto px-4 py-4 space-y-1">
                            <div className="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                Menu Utama
                            </div>
                    <nav className="space-y-1">
                        {visibleItems.map((item) => {
                                    const Icon = item.icon;
                                    const isActive = route().current(item.route);

                                    return (
                                        <Link
                                            key={item.name}
                                            href={route(item.route)}
                                            onClick={() => setMobileOpen(false)}
                                            className={`group flex items-center justify-between rounded-lg px-3 py-3 text-sm font-medium transition-all duration-150 active:scale-[0.98] ${
                                                isActive
                                                    ? 'bg-slate-800 text-white font-semibold border-l-4 border-emerald-500 shadow-sm'
                                                    : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'
                                            }`}
                                        >
                                            <div className="flex items-center gap-3">
                                                <Icon
                                                    className={`h-5 w-5 ${
                                                        isActive
                                                            ? 'text-emerald-400'
                                                            : 'text-slate-400'
                                                    }`}
                                                />
                                                <span>{item.name}</span>
                                            </div>
                                            {!item.isReleased && (
                                                <span className="text-[10px] font-medium px-2 py-0.5 rounded bg-slate-800 text-slate-400 border border-slate-700/60">
                                                    Segera
                                                </span>
                                            )}
                                        </Link>
                                    );
                                })}
                            </nav>
                        </div>

                        <div className="p-4 border-t border-slate-800 bg-slate-950/40">
                            <div className="flex items-center gap-2 text-xs text-slate-400">
                                <div className="h-2 w-2 rounded-full bg-emerald-500" />
                                <span className="font-medium text-slate-300">
                                    Local MVP Mode
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
