import React, { useState } from "react";
import { Head } from "@inertiajs/react";
import ShmNavbar from "@/Components/Shm/ShmNavbar";
import ShmSidebar from "@/Components/Shm/ShmSidebar";

/**
 * Shell aplikasi SHM (Ticket 48).
 *
 * Semua halaman SHM memakai layout ini dan TIDAK pernah memakai sidebar
 * aplikasi koperasi. Layout ini hanya membungkus tampilan: otentikasi,
 * otorisasi, session, dan audit tetap milik aplikasi Laravel yang sama.
 *
 * Pesan flash TIDAK dirender di sini karena setiap halaman SHM sudah
 * menampilkan flash-nya sendiri; merender di layout akan memunculkan banner
 * ganda untuk pesan yang sama.
 *
 * Prop `title`/`pageTitle` sengaja sama dengan AdminLayout supaya perpindahan
 * halaman SHM ke layout ini tidak mengubah kontrak halaman.
 */
export default function ShmLayout({ title, pageTitle, children }) {
    const [mobileOpen, setMobileOpen] = useState(false);

    return (
        <div className="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased selection:bg-indigo-500 selection:text-white">
            <Head title={title || pageTitle || "SHM"} />

            <ShmSidebar mobileOpen={mobileOpen} setMobileOpen={setMobileOpen} />

            <div className="flex min-h-screen flex-col lg:pl-64">
                <ShmNavbar
                    pageTitle={pageTitle || title}
                    onMobileMenuToggle={() => setMobileOpen(true)}
                />

                <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:px-8">
                    {children}
                </main>

                <footer className="flex flex-col items-center justify-between gap-2 border-t border-slate-200 bg-white px-4 py-4 text-xs text-slate-500 sm:flex-row sm:px-6 lg:pl-64 lg:pr-8">
                    <div>
                        <span className="font-semibold text-slate-700">
                            SHM — Sistem Manajemen SHM/SHGB
                        </span>{" "}
                        &copy; {new Date().getFullYear()} KSP Bima Sakti Mandiri.
                    </div>
                    <div className="flex items-center gap-3 text-slate-400">
                        <span>Modul SHM</span>
                        <span>&bull;</span>
                        <span>Satu aplikasi dengan Koperasi</span>
                    </div>
                </footer>
            </div>
        </div>
    );
}
