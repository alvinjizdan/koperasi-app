import React, { useEffect, useState } from "react";
import { Link, usePage } from "@inertiajs/react";
import { ScrollArea } from "@/Components/ui/scroll-area";
import {
    BookOpen,
    Briefcase,
    Calculator,
    ChevronDown,
    ClipboardCheck,
    FileText,
    FolderOpen,
    LayoutDashboard,
    LogOut,
    MapPin,
    Receipt,
    ScrollText,
    UserRound,
    X,
} from "lucide-react";

/**
 * Navigasi aplikasi SHM.
 *
 * Struktur ini adalah target product scope SHM. Menu yang halamannya BELUM
 * dibangun tidak diberi `route` dan ditandai "Segera", sehingga tidak pernah
 * menjadi link mati: item tanpa route dirender sebagai tombol tanpa tujuan,
 * bukan Link. Yang sudah tersedia (Dashboard, Pengajuan, Nasabah, dan Credit
 * Checking) memakai route name yang sudah ada sejak Ticket 44-47.
 *
 * `permission` menyaring item yang tidak boleh diakses pengguna. Ini hanya
 * penyembunyian tampilan: otorisasi sesungguhnya tetap dilakukan server pada
 * setiap request.
 */
const shmNavigation = [
    {
        name: "Dashboard",
        route: "shm.dashboard",
        icon: LayoutDashboard,
        permission: "shm.view",
    },
    {
        name: "Pengajuan",
        route: "shm.applications.index",
        icon: FileText,
        permission: "shm.view",
    },
    {
        name: "Nasabah",
        route: "shm.customers.index",
        icon: UserRound,
        permission: "shm.customer.view",
    },
    {
        name: "Credit Checking",
        icon: ClipboardCheck,
        children: [
            {
                name: "Proses Marketing",
                route: "shm.credit-checks.index",
                permission: "shm.credit_checking.view",
            },
            {
                name: "Hasil Marketing",
                route: "shm.credit-checks.results",
                permission: "shm.credit_checking.view",
            },
        ],
    },

    // Ticket 49: modul Memorandum kini operasional. Kedua kategori mengarah
    // ke modul yang sama dengan filter `type`; tidak ada domain/controller
    // kedua. Kategori dipisah sebagai submenu agar sesuai struktur menu SHM.
    {
        name: "Memorandum",
        icon: ScrollText,
        children: [
            {
                name: "SHM/SHGB",
                route: "shm.memorandums.index",
                query: { type: "shm_shgb" },
                permission: "shm.memorandum.update",
            },
            {
                name: "Buyback",
                route: "shm.memorandums.index",
                query: { type: "buyback" },
                permission: "shm.memorandum.update",
            },
        ],
    },

    // --- Admin: Kelengkapan Dokumen (Ticket 50) ---
    // Parent Admin kini operasional karena memiliki anak yang dapat dinavigasi.
    // Export dan Collection (Ticket 90) juga operasional; menu Collection
    // hanya menampilkan Monitoring/Detail read-only — action Collection lain
    // masih PENDING sehingga tidak ditampilkan sebagai menu.
    {
        name: "Admin",
        icon: FolderOpen,
        children: [
            {
                name: "Kelengkapan Dokumen",
                route: "shm.documents.index",
                permission: "shm.document.update",
            },
        ],
    },

    // Ticket 54: modul Approval kini operasional. Halaman keputusan memakai
    // endpoint dan workflow existing; tidak ada workflow kedua. Menu hanya
    // tampil bagi pemegang permission approval yang sudah ada
    // (shm.approval.decide), sehingga tetap tersembunyi dari Marketing.
    // Ticket 57: `pendingKey` menautkan item ini ke jumlah pending pada prop
    // bersama `shm.pending_approvals`.
    // Ticket 63: Marketing activities (aktivitas kunjungan) dipindahkan ke SHM.
    {
        name: "Marketing",
        icon: Briefcase,
        children: [
            {
                name: "Aktivitas Kunjungan",
                route: "shm.marketing.activities.index",
                permission: "marketing.view",
            },
            {
                name: "Monitoring Aktivitas",
                route: "shm.marketing.monitoring.index",
                permission: "marketing.monitoring.view",
            },
            {
                name: "Approval",
                route: "shm.approvals.index",
                permission: "shm.approval.decide",
                pendingKey: "approvals",
            },
        ],
    },
    // Ticket 64/65/66: modul finansial SHM — Pinjaman yang TERPISAH dari modul
    // Pinjaman koperasi. Memakai permission `shm.view` yang sudah ada (tidak ada
    // permission baru) dan hanya menampilkan data domain `shm_loan_*`.
    // Modul Simpanan SHM dihapus pada Ticket 65 (tidak diperlukan).
    //
    // Ticket 66: struktur menu Pinjaman sesuai target product scope — item
    // yang halamannya BELUM dibangun (Jaminan, Pembayaran/Angsuran, Pelunasan,
    // Laporan) TIDAK diberi `route`, sehingga dirender sebagai baris statis
    // yang tidak pernah menjadi tautan mati.
    {
        name: "Pinjaman",
        icon: Calculator,
        children: [
            {
                name: "Pengajuan Pinjaman",
                route: "shm.loans.pengajuan",
                permission: "shm.view",
            },
            {
                name: "Daftar Pengajuan",
                route: "shm.loans.pengajuan.daftar",
                permission: "shm.view",
            },
            {
                name: "Daftar Pinjaman",
                route: "shm.loans.index",
                permission: "shm.view",
            },
            {
                name: "Simulasi Pinjaman",
                route: "shm.loans.simulation",
                permission: "shm.view",
            },
            {
                name: "Produk Pinjaman",
                route: "shm.loans.products.index",
                permission: "shm.view",
            },
            { name: "Jaminan" },
            // Ticket 86: halaman transaksi pembayaran pinjaman SHM sudah ada;
            // permission baca = shm.view (sama dengan halaman Pinjaman lain).
            // Pencatatan pembayaran sendiri memerlukan shm.payment.create.
            {
                name: "Pembayaran / Angsuran",
                route: "shm.loans.payments.index",
                permission: "shm.view",
            },
            // Ticket 89: halaman Pelunasan / Settlement SHM sudah ada;
            // permission baca = shm.view (sama dengan halaman Pinjaman
            // lain). KONFIRMASI pelunasan sendiri memerlukan
            // shm.settlement.create — authority masih PENDING BUSINESS
            // CONFIRMATION, sehingga preview dapat dibuka tetapi tombol
            // konfirmasi tidak ditawarkan selama kontrak belum lengkap.
            {
                name: "Pelunasan",
                route: "shm.loans.pelunasan.index",
                permission: "shm.view",
            },
            // Ticket 89B: master policy diskon bunga pelunasan (data-driven).
            // Baca = shm.view; tulis = shm.settlement.policy.manage.
            {
                name: "Policy Diskon Pelunasan",
                route: "shm.loans.pelunasan.policies.index",
                permission: "shm.view",
            },
            { name: "Laporan" },
        ],
    },
    // Ticket 55: modul Export kini operasional sebagai pemantauan data
    // operasional READ-ONLY. Keempat halaman memetakan pengajuan ke tahap
    // existing ShmApplicationStatus dan memakai permission `shm.view` yang
    // sudah ada; scope kepemilikan SHM tetap ditegakkan di server.
    {
        name: "Export",
        icon: FileText,
        children: [
            {
                name: "Data Nasabah Masuk",
                route: "shm.exports.masuk",
                permission: "shm.view",
            },
            {
                name: "Data Nasabah Approve",
                route: "shm.exports.approve",
                permission: "shm.view",
            },
            {
                name: "Data Nasabah Reject",
                route: "shm.exports.reject",
                permission: "shm.view",
            },
            {
                name: "Data Nasabah Pencairan",
                route: "shm.exports.pencairan",
                permission: "shm.view",
            },
        ],
    },
    {
        name: "Collection",
        icon: Receipt,
        children: [
            {
                name: "Monitoring",
                route: "shm.collection.index",
                // Ticket 101: mengikuti permission route (shm.collection.view)
                // sehingga menu tidak terlihat bagi role tanpa hak Collection.
                permission: "shm.collection.view",
            },
        ],
    },
    {
        name: "Akuntansi",
        icon: BookOpen,
        children: [
            {
                name: "Daftar Perkiraan (COA)",
                route: "shm.coa.index",
                permission: "accounting.shm.view",
            },
            {
                name: "Konfigurasi Produk",
                route: "shm.product-accounting.index",
                permission: "accounting.shm.view",
            },
            {
                name: "Accounting Mapping",
                route: "shm.accounting-mapping.index",
                permission: "accounting.shm.view",
            },
            {
                name: "Neraca Saldo",
                route: "shm.trial-balance.index",
                permission: "accounting.shm.reports.view",
            },
            {
                name: "Laba Rugi Periode",
                route: "shm.period-profit-loss.index",
                permission: "accounting.shm.reports.view",
            },
            {
                name: "Laba Rugi Kumulatif",
                route: "shm.cumulative-profit-loss.index",
                permission: "accounting.shm.reports.view",
            },
            {
                name: "Neraca",
                route: "shm.balance-sheet.index",
                permission: "accounting.shm.reports.view",
            },
        ],
    },
];

/**
 * Child aktif bila route-nya cocok. Filter kategori (query `type`) SENGAJA
 * tidak ikut dibandingkan: membuka salah satu submenu Memorandum menandai
 * kedua kategori sebagai bagian dari modul yang sedang dibuka, sehingga
 * submenu tetap terlihat aktif meski pengguna mengganti filter di halaman.
 */
const isChildActive = (child) =>
    child.route && route().current(child.route);

/** Parent aktif bila salah satu child-nya sedang dibuka. */
const isItemActive = (item) =>
    item.children
        ? item.children.some(isChildActive)
        : route().current(item.route);

/**
 * Ticket 57: badge jumlah pending untuk child yang menautkan `pendingKey`.
 *
 * Aturan tampil:
 * - hanya bila angka adalah bilangan bulat >= 1 (0, undefined, null, teks,
 *   dan negatif tidak pernah dirender);
 * - server hanya mengirim angka ke pengguna yang memegang permission yang
 *   relevan, sehingga badge tidak pernah bocor ke pengguna lain.
 */
function renderPendingBadge(child, pendingCounts) {
    const count = pendingCounts?.[child.pendingKey];

    if (!child.pendingKey || !Number.isInteger(count) || count < 1) {
        return null;
    }

    return (
        <span
            className="inline-flex min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-bsm-accent px-1.5 py-0.5 text-[10px] font-bold leading-none text-white"
            title={`${count} approval menunggu keputusan`}
        >
            {count}
        </span>
    );
}

/**
 * Satu item navigasi SHM: link langsung, dropdown tersedia, atau penanda
 * "Segera" yang tidak dapat dinavigasi.
 */
function ShmNavItem({ item, open, onToggle, variant = "desktop", onNavigate, pendingCounts }) {
    const Icon = item.icon;
    const isActive = isItemActive(item);
    const isMobile = variant === "mobile";
    const isReleased = item.isReleased !== false;

    const rowPadding = isMobile ? "px-3 py-3" : "px-3 py-2.5";
    const rowClass = `group flex w-full items-center justify-between rounded-lg ${rowPadding} text-sm font-medium transition-all duration-150 active:scale-[0.98] ${
        isActive
            ? "bg-slate-800 text-white font-semibold border-l-4 border-bsm-accent shadow-sm"
            : "text-slate-300 hover:bg-slate-800/60 hover:text-white border-l-4 border-transparent"
    }`;

    const rowInner = (
        <>
            <div className="flex items-center gap-3">
                <Icon
                    className={`${
                        isMobile ? "h-5 w-5" : "h-4 w-4"
                    } transition-colors ${
                        isActive
                            ? "text-orange-400"
                            : "text-slate-400 group-hover:text-slate-200"
                    }`}
                />
                <span>{item.name}</span>
            </div>
            {!isReleased && (
                <span className="rounded border border-slate-700/60 bg-slate-800 px-2 py-0.5 text-[10px] font-medium text-slate-400">
                    Segera
                </span>
            )}
        </>
    );

    const submenu =
        item.children && open ? (
            <div className="mt-1 ml-7 space-y-0.5 border-l border-slate-700/60 pl-3">
                {item.children.map((child) =>
                    child.route ? (
                        <Link
                            key={child.name}
                            href={
                                child.query
                                    ? route(child.route, child.query)
                                    : route(child.route)
                            }
                            onClick={onNavigate}
                            className={`flex items-center justify-between gap-2 rounded-md px-3 py-1.5 text-xs font-medium transition-colors duration-150 ${
                                isChildActive(child)
                                    ? "bg-slate-800/80 font-semibold text-indigo-300"
                                    : "text-slate-400 hover:bg-slate-800/60 hover:text-white"
                            }`}
                        >
                            <span>{child.name}</span>
                            {renderPendingBadge(child, pendingCounts)}
                        </Link>
                    ) : (
                        <span
                            key={child.name}
                            className="block cursor-default rounded-md px-3 py-1.5 text-xs text-slate-500"
                        >
                            {child.name}
                        </span>
                    ),
                )}
            </div>
        ) : null;

    // Item yang belum dibangun: dapat dibuka/ditutup, tetapi tidak pernah
    // menjadi tautan.
    if (!isReleased) {
        return (
            <div>
                <button
                    type="button"
                    onClick={(event) => {
                        event.stopPropagation();
                        onToggle?.(event);
                    }}
                    aria-expanded={open}
                    className={rowClass}
                >
                    {rowInner}
                    <ChevronDown
                        className={`h-4 w-4 text-slate-400 transition-transform duration-200 ${
                            open ? "rotate-180" : ""
                        }`}
                    />
                </button>
                {submenu}
            </div>
        );
    }

    if (item.children) {
        return (
            <div>
                <Link
                    href={route(item.children[0].route)}
                    onClick={onNavigate}
                    aria-expanded={open}
                    className={rowClass}
                >
                    {rowInner}
                    <button
                        type="button"
                        tabIndex={-1}
                        aria-label={`Buka submenu ${item.name}`}
                        onClick={(event) => {
                            event.stopPropagation();
                            event.preventDefault();
                            onToggle?.(event);
                        }}
                        className="rounded-md p-1 text-slate-400 transition-colors hover:bg-slate-700/60 hover:text-white"
                    >
                        <ChevronDown
                            className={`h-4 w-4 transition-transform duration-200 ${
                                open ? "rotate-180" : ""
                            }`}
                        />
                    </button>
                </Link>
                {submenu}
            </div>
        );
    }

    return (
        <Link
            href={route(item.route)}
            onClick={onNavigate}
            className={rowClass}
        >
            {rowInner}
        </Link>
    );
}

/**
 * Footer sidebar: identitas pengguna, tautan kembali ke aplikasi koperasi, dan
 * keluar. Keluar memakai route `logout` yang sama dengan aplikasi koperasi
 * dengan penanda `context=shm` agar kembali ke pintu masuk SHM — tidak ada
 * session atau implementasi logout kedua.
 */
function ShmUserFooter({ onNavigate }) {
    const { auth } = usePage().props;
    const user = auth?.user;

    if (!user) return null;

    const initial = user.name ? user.name.charAt(0).toUpperCase() : "U";

    return (
        <div className="border-t border-slate-800 bg-slate-950/40 p-3">
            <div className="flex items-center gap-3 rounded-lg px-2 py-2">
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
            </div>

            <Link
                href={route("logout")}
                method="post"
                as="button"
                data={{ context: "shm" }}
                onClick={onNavigate}
                className="mt-1 flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-rose-400 transition-colors hover:bg-slate-800/60 hover:text-rose-300"
            >
                <LogOut className="h-4 w-4" />
                <span>Keluar dari BSM Makro</span>
            </Link>
        </div>
    );
}

export default function ShmSidebar({ mobileOpen, setMobileOpen }) {
    const { auth, url, shm } = usePage().props;
    const permissions = auth?.permissions ?? [];
    const hasPermission = (permission) =>
        !permission || permissions.includes(permission);

    // Ticket 57: jumlah approval pending (ShmApproval.status = pending) dari
    // prop Inertia bersama `shm.pending_approvals`. Server menghitungnya
    // scope-safe hanya untuk pengguna yang memegang shm.approval.decide;
    // pengguna lain selalu menerima undefined/null, sehingga badge tidak
    // pernah muncul. 0 dan nilai tidak valid tidak menampilkan badge.

    // Saring child tanpa permission lalu buang parent yang tidak menyisakan
    // child. Item "Segera" tidak punya route sehingga tidak pernah bergantung
    // pada permission.
    const visibleItems = shmNavigation
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
        <div className="p-3">
            <div className="px-3 pb-2 pt-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                Menu BSM Makro
            </div>
            <nav className="space-y-1">
                {visibleItems.map((item) => (
                    <ShmNavItem
                        key={item.name}
                        item={item}
                        variant={variant}
                        pendingCounts={shm?.pending_approvals}
                        onNavigate={
                            variant === "mobile"
                                ? () => setMobileOpen(false)
                                : undefined
                        }
                        open={openModules.has(item.name)}
                        onToggle={(event) => {
                            event?.stopPropagation?.();
                            toggleModule(item.name);
                        }}
                    />
                ))}
            </nav>
        </div>
    );

    return (
        <>
            {/* Sidebar desktop tetap */}
            <aside className="hidden lg:fixed lg:inset-y-0 lg:z-40 lg:flex lg:w-64 lg:flex-col lg:border-r lg:border-slate-200 lg:bg-slate-900 lg:text-slate-100">
                <div className="flex h-16 items-center border-b border-slate-800 px-6">
                    <Link
                        href={route("shm.dashboard")}
                        className="group flex items-center gap-3 rounded-lg p-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-bsm-accent"
                    >
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white p-1 shadow-sm transition-transform duration-100 active:scale-95">
                            <img
                                src="/images/logo.png"
                                alt="BSM Logo"
                                className="h-full w-full object-contain"
                            />
                        </div>
                        <div className="flex flex-col">
                            <span className="text-sm font-semibold tracking-tight text-white transition-colors group-hover:text-indigo-300">
                                BSM Makro
                            </span>
                            <span className="text-xs font-medium text-slate-400">
                                KSP Bima Sakti Mandiri
                            </span>
                        </div>
                    </Link>
                </div>

                <ScrollArea className="flex-1">{renderNav("desktop")}</ScrollArea>

                <ShmUserFooter />
            </aside>

            {/* Drawer mobile */}
            {mobileOpen && (
                <div className="fixed inset-0 z-50 lg:hidden">
                    <div
                        className="fixed inset-0 bg-slate-900/60 backdrop-blur-xs transition-opacity duration-200"
                        onClick={() => setMobileOpen(false)}
                    />

                    <div className="fixed inset-y-0 left-0 flex w-full max-w-xs flex-col bg-slate-900 text-slate-100 shadow-xl transition-transform duration-200 ease-out">
                        <div className="flex h-16 items-center justify-between border-b border-slate-800 px-6">
                            <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white p-1">
                                    <img
                                        src="/images/logo.png"
                                        alt="BSM Logo"
                                        className="h-full w-full object-contain"
                                    />
                                </div>
                                <div className="flex flex-col">
                                    <span className="text-sm font-semibold tracking-tight text-white">
                                        BSM Makro
                                    </span>
                                    <span className="text-xs font-medium text-slate-400">
                                        KSP Bima Sakti Mandiri
                                    </span>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setMobileOpen(false)}
                                className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-800 hover:text-white active:scale-95"
                                aria-label="Tutup Menu Navigasi"
                            >
                                <X className="h-5 w-5" />
                            </button>
                        </div>

                        <ScrollArea className="flex-1">
                            {renderNav("mobile")}
                        </ScrollArea>

                        <ShmUserFooter onNavigate={() => setMobileOpen(false)} />
                    </div>
                </div>
            )}
        </>
    );
}
