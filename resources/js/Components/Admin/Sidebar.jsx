import React, { useEffect, useState } from "react";
import { Link, usePage } from "@inertiajs/react";
import { ScrollArea } from "@/Components/ui/scroll-area";
import { UserRound, LogOut } from "lucide-react";
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
    BarChart3,
    Building2,
    ChevronDown,
    X,
} from "lucide-react";

/**
 * Struktur navigasi: modul dengan submodule dirender sebagai menu
 * collapsible (children), modul sederhana tetap link langsung.
 * Permission di tiap child memastikan submenu hanya memuat link yang
 * boleh diakses pengguna; parent tampil bila minimal satu child lolos.
 */
const navigationItems = [
    {
        name: "Dashboard",
        route: "dashboard",
        icon: LayoutDashboard,
        isReleased: true,
    },
    {
        name: "Anggota",
        route: "anggota.index",
        icon: Users,
        isReleased: true,
    },
    {
        name: "Simpanan",
        icon: PiggyBank,
        isReleased: true,
        children: [
            { name: "Produk Simpanan", route: "simpanan-produk.index", permission: "simpanan.view" },
            { name: "Rekening Simpanan", route: "simpanan-rekening.index", permission: "simpanan.view" },
        ],
    },
    {
        name: "Pinjaman",
        route: "pinjaman.index",
        icon: Landmark,
        isReleased: true,
    },
    {
        name: "Angsuran",
        route: "angsuran.index",
        icon: Receipt,
        isReleased: true,
    },
    {
        name: "Kasir",
        icon: CreditCard,
        isReleased: true,
        children: [
            { name: "Sesi Kasir", route: "kasir.sesi.index", permission: "kasir.view" },
            { name: "Transaksi Kas", route: "kasir.transaksi.index", permission: "kasir.view" },
            { name: "Ringkasan Harian", route: "kasir.ringkasan.index", permission: "kasir.view" },
        ],
    },
    {
        name: "Laporan",
        icon: FileText,
        isReleased: true,
        children: [
            { name: "Laporan Anggota", route: "laporan.anggota", permission: "laporan.view" },
            { name: "Laporan Simpanan", route: "laporan.simpanan", permission: "laporan.view" },
            { name: "Laporan Pinjaman", route: "laporan.pinjaman", permission: "laporan.view" },
            { name: "Laporan Pembayaran Angsuran", route: "laporan.angsuran", permission: "laporan.view" },
            { name: "Laporan Tunggakan", route: "laporan.tunggakan", permission: "laporan.view" },
            { name: "Laporan Transaksi Kas", route: "laporan.transaksi-kas", permission: "laporan.view" },
            { name: "Laporan Kas Harian", route: "laporan.kas-harian", permission: "laporan.view" },
            { name: "Posisi Pinjaman per Tanggal", route: "laporan.posisi-pinjaman", permission: "laporan.view" },
        ],
    },
    {
        name: "Rekonsiliasi",
        route: "rekonsiliasi.index",
        icon: ClipboardCheck,
        isReleased: true,
        permission: "rekonsiliasi.view",
    },
    {
        name: "Akuntansi",
        icon: BookOpen,
        isReleased: true,
        children: [
            { name: "Chart of Accounts", route: "akuntansi.coa.index", permission: "accounting.view" },
            { name: "Jurnal Umum", route: "akuntansi.jurnal.index", permission: "accounting.journals.view" },
            { name: "Buku Besar", route: "akuntansi.buku-besar.index", permission: "accounting.ledger.view" },
            { name: "Laporan Keuangan", route: "akuntansi.laporan-keuangan.neraca-saldo", permission: "accounting.reports.view" },
        ],
    },
    {
        name: "Pengaturan",
        icon: Settings,
        isReleased: true,
        children: [
            { name: "Pengguna", route: "pengaturan.users.index", permission: "pengguna.view" },
            { name: "Role & Permission", route: "pengaturan.roles.index", permission: "role.view" },
            { name: "Audit Log", route: "pengaturan.audit.index", permission: "audit.view" },
        ],
    },
];

/**
 * Footer sidebar: identitas pengguna dengan menu ke halaman profil dan
 * keluar. Menggantikan badge status sistem yang tidak membawa informasi.
 */
function UserFooter({ onNavigate }) {
    const { auth } = usePage().props;
    const user = auth?.user;
    const [open, setOpen] = useState(false);

    if (!user) return null;

    const initial = user.name ? user.name.charAt(0).toUpperCase() : "U";

    return (
        <div className="relative p-3 border-t border-slate-800 bg-slate-950/40">
            {open && (
                <div
                    className="absolute inset-0 z-10"
                    onClick={() => setOpen(false)}
                    aria-hidden="true"
                />
            )}

            <button
                type="button"
                onClick={() => setOpen((prev) => !prev)}
                aria-expanded={open}
                className="relative z-20 flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors hover:bg-slate-800/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400"
            >
                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-700 text-xs font-semibold text-white">
                    {initial}
                </div>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-slate-200">
                        {user.name}
                    </p>
                    <p className="truncate text-xs text-slate-500">
                        {user.email}
                    </p>
                </div>
            </button>

            {open && (
                <div className="absolute bottom-full left-3 right-3 z-30 mb-2 overflow-hidden rounded-lg border border-slate-700 bg-slate-800 shadow-lg">
                    <Link
                        href={route("profile.edit")}
                        onClick={() => {
                            setOpen(false);
                            onNavigate?.();
                        }}
                        className="flex items-center gap-2 px-3 py-2.5 text-sm text-slate-200 transition-colors hover:bg-slate-700/60 hover:text-white"
                    >
                        <UserRound className="h-4 w-4 text-slate-400" />
                        <span>Profil Saya</span>
                    </Link>
                    <Link
                        href={route("logout")}
                        method="post"
                        as="button"
                        onClick={() => {
                            setOpen(false);
                            onNavigate?.();
                        }}
                        className="flex w-full items-center gap-2 border-t border-slate-700 px-3 py-2.5 text-sm font-medium text-rose-400 transition-colors hover:bg-slate-700/60 hover:text-rose-300"
                    >
                        <LogOut className="h-4 w-4" />
                        <span>Keluar</span>
                    </Link>
                </div>
            )}
        </div>
    );
}

/** Parent aktif bila halaman hub atau salah satu child-nya sedang dibuka. */
const isItemActive = (item) =>
    item.children
        ? (item.hubRoute && route().current(item.hubRoute)) ||
          item.children.some((child) => route().current(child.route))
        : route().current(item.route);

/**
 * Item navigasi: link biasa atau dropdown collapsible.
 * variant "mobile" memakai ukuran sentuhan lebih besar dan menutup drawer.
 */
function SidebarNavItem({ item, open, onToggle, variant = "desktop" }) {
    const Icon = item.icon;
    const isActive = isItemActive(item);
    const isMobile = variant === "mobile";

    const rowPadding = isMobile ? "px-3 py-3" : "px-3 py-2.5";

    if (item.children) {
        // Judul modul selalu navigasi: ke halaman hub bila ada, jika tidak
        // ke sub-menu pertama. Chevron terpisah khusus expand/collapse.
        const headerHref = route(item.hubRoute ?? item.children[0].route);

        const submenu = (
            <div
                onClick={(e) =>
                    // Di drawer mobile, klik child tidak boleh ikut menutup
                    // drawer lewat wrapper; navigasi yang menutup drawer.
                    e.stopPropagation()
                }
            >
                {open && (
                    <div className="mt-1 ml-7 space-y-0.5 border-l border-slate-700/60 pl-3">
                        {item.children.map((child) => {
                            const childActive = route().current(child.route);
                            return (
                                <Link
                                    key={child.route}
                                    href={route(child.route)}
                                    className={`block rounded-md px-3 py-1.5 text-[13px] transition-colors duration-150 ${
                                        childActive
                                            ? "bg-slate-800/80 font-semibold text-emerald-300"
                                            : "text-slate-400 hover:bg-slate-800/60 hover:text-white"
                                    }`}
                                >
                                    {child.name}
                                </Link>
                            );
                        })}
                    </div>
                )}
            </div>
        );

        const headerContent = (
            <>
                <div className="flex items-center gap-3">
                    <Icon
                        className={`${
                            isMobile ? "h-5 w-5" : "h-4 w-4"
                        } transition-colors ${
                            isActive
                                ? "text-emerald-400"
                                : "text-slate-400 group-hover:text-slate-200"
                        }`}
                    />
                    <span>{item.name}</span>
                </div>
                {!item.isReleased && (
                    <span className="text-[10px] font-medium px-2 py-0.5 rounded bg-slate-800 text-slate-400 border border-slate-700/60">
                        Segera
                    </span>
                )}
                <button
                    type="button"
                    tabIndex={-1}
                    aria-label={`Buka submenu ${item.name}`}
                    onClick={(e) => {
                        e.stopPropagation();
                        e.preventDefault();
                        onToggle?.(e);
                    }}
                    className="rounded-md p-1 text-slate-400 transition-colors hover:bg-slate-700/60 hover:text-white"
                >
                    <ChevronDown
                        className={`h-4 w-4 transition-transform duration-200 ${
                            open ? "rotate-180" : ""
                        }`}
                    />
                </button>
            </>
        );

        return (
            <div>
                <Link
                    href={headerHref}
                    aria-expanded={open}
                    className={`group flex w-full items-center justify-between rounded-lg ${rowPadding} text-sm font-medium transition-all duration-150 active:scale-[0.98] ${
                        isActive
                            ? "bg-slate-800 text-white font-semibold border-l-4 border-emerald-500 shadow-sm"
                            : "text-slate-300 hover:bg-slate-800/60 hover:text-white border-l-4 border-transparent"
                    }`}
                >
                    {headerContent}
                </Link>
                {submenu}
            </div>
        );
    }

    return (
        <Link
            href={route(item.route)}
            className={`group flex items-center justify-between rounded-lg ${rowPadding} text-sm font-medium transition-all duration-150 active:scale-[0.98] ${
                isActive
                    ? "bg-slate-800 text-white font-semibold border-l-4 border-emerald-500 shadow-sm"
                    : "text-slate-300 hover:bg-slate-800/60 hover:text-white border-l-4 border-transparent"
            }`}
        >
            <div className="flex items-center gap-3">
                <Icon
                    className={`${
                        isMobile ? "h-5 w-5" : "h-4 w-4"
                    } transition-colors ${
                        isActive
                            ? "text-emerald-400"
                            : "text-slate-400 group-hover:text-slate-200"
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
}

export default function Sidebar({ mobileOpen, setMobileOpen }) {
    // Menu dengan permission hanya tampil bila pengguna memegang permission-nya.
    // Superadmin memperoleh seluruh permission melalui effectivePermissions.
    const { auth, url } = usePage().props;
    const permissions = auth?.permissions ?? [];
    const hasPermission = (p) => !p || permissions.includes(p);

    // Saring child tanpa permission, lalu buang parent yang tak punya child lagi.
    const visibleItems = navigationItems
        .map((item) =>
            item.children
                ? {
                      ...item,
                      children: item.children.filter((child) =>
                          hasPermission(child.permission),
                      ),
                  }
                : item,
        )
        .filter((item) =>
            item.children
                ? item.children.length > 0
                : hasPermission(item.permission),
        );

    // Modul yang membungkus halaman aktif otomatis terbuka (juga saat pindah halaman).
    const activeModules = visibleItems
        .filter((item) => item.children && isItemActive(item))
        .map((item) => item.name);
    const [openModules, setOpenModules] = useState(
        () => new Set(activeModules),
    );

    useEffect(() => {
        if (activeModules.length === 0) return;
        setOpenModules((prev) => {
            const next = new Set(prev);
            let changed = false;
            activeModules.forEach((name) => {
                if (!next.has(name)) {
                    next.add(name);
                    changed = true;
                }
            });
            return changed ? next : prev;
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [url]);

    const toggleModule = (name) =>
        setOpenModules((prev) => {
            const next = new Set(prev);
            if (next.has(name)) {
                next.delete(name);
            } else {
                next.add(name);
            }
            return next;
        });

    const renderNav = (variant) => (
        <>
            <div className="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                Menu Utama
            </div>
            <nav className="space-y-1">
                {visibleItems.map((item) => (
                    <SidebarNavItem
                        key={item.name}
                        item={item}
                        variant={variant}
                        open={openModules.has(item.name)}
                        onToggle={(e) => {
                            // Di drawer mobile, klik header modul tidak boleh
                            // ikut menutup drawer (bubble ke wrapper onClick).
                            e?.stopPropagation?.();
                            toggleModule(item.name);
                        }}
                    />
                ))}
            </nav>
        </>
    );

    return (
        <>
            {/* Desktop Fixed Sidebar */}
            <aside className="hidden lg:fixed lg:inset-y-0 lg:z-40 lg:flex lg:w-64 lg:flex-col lg:border-r lg:border-slate-200 lg:bg-slate-900 lg:text-slate-100">
                {/* Brand Header */}
                <div className="flex h-16 items-center px-6 border-b border-slate-800">
                    <Link
                        href={route("dashboard")}
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
                <ScrollArea className="flex-1">{renderNav("desktop")}</ScrollArea>

                {/* User Footer: akses profil */}
                <UserFooter />
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

                        <ScrollArea className="flex-1">
                            <div onClick={() => setMobileOpen(false)}>
                                {renderNav("mobile")}
                            </div>
                        </ScrollArea>

                        <UserFooter onNavigate={() => setMobileOpen(false)} />
                    </div>
                </div>
            )}
        </>
    );
}
