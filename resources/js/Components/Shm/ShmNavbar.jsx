import React from "react";
import { Link } from "@inertiajs/react";
import { ArrowLeft, ChevronRight, Menu } from "lucide-react";

/**
 * Header aplikasi SHM.
 *
 * Breadcrumb dibuat di layout, bukan di tiap halaman, supaya seluruh halaman
 * SHM memakai penanda area yang sama: "SHM › {judul halaman}".
 *
 * Tautan "Kembali ke Koperasi" hanya berpindah area: pengguna TETAP login dan
 * session-nya tidak disentuh. Keluar dari aplikasi ada di footer sidebar.
 */
export default function ShmNavbar({ pageTitle, onMobileMenuToggle }) {
    return (
        <header className="sticky top-0 z-30 flex h-16 w-full items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6 lg:px-8">
            <div className="flex min-w-0 items-center gap-3">
                <button
                    type="button"
                    onClick={onMobileMenuToggle}
                    className="flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 active:scale-95 lg:hidden"
                    aria-label="Buka Menu Navigasi SHM"
                >
                    <Menu className="h-5 w-5" />
                </button>

                <div className="flex min-w-0 flex-col sm:flex-row sm:items-center sm:gap-2">
                    <div className="flex items-center text-xs font-medium text-slate-500">
                        <span className="font-semibold text-bsm-accent">BSM Makro</span>
                        <ChevronRight className="mx-1 h-3.5 w-3.5 text-slate-400" />
                    </div>
                    <h2 className="truncate text-sm font-semibold tracking-tight text-slate-800 sm:text-base">
                        {pageTitle || "Dashboard"}
                    </h2>
                </div>
            </div>

            <Link
                href={route("dashboard")}
                className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                title="Pindah ke BSM Mikro tanpa keluar"
            >
                <ArrowLeft className="h-3.5 w-3.5" />
                <span className="hidden sm:inline">Kembali ke BSM Mikro</span>
                <span className="sm:hidden">BSM Mikro</span>
            </Link>
        </header>
    );
}
