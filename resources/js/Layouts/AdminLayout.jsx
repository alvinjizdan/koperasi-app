import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import Sidebar from '@/Components/Admin/Sidebar';
import Navbar from '@/Components/Admin/Navbar';

export default function AdminLayout({ title, pageTitle, children }) {
    const [mobileOpen, setMobileOpen] = useState(false);

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900 font-sans antialiased selection:bg-emerald-500 selection:text-white">
            <Head title={title || pageTitle || 'Dashboard KSP'} />

            {/* Sidebar Component */}
            <Sidebar mobileOpen={mobileOpen} setMobileOpen={setMobileOpen} />

            {/* Main Application Outer Container */}
            <div className="flex flex-col lg:pl-64 min-h-screen">
                {/* Navbar Component */}
                <Navbar
                    pageTitle={pageTitle || title}
                    onMobileMenuToggle={() => setMobileOpen(true)}
                />

                {/* Main Content Viewport Area */}
                <main className="flex-1 px-4 py-6 sm:px-6 lg:px-8 max-w-7xl w-full mx-auto">
                    {children}
                </main>

                {/* Footer Section */}
                <footer className="border-t border-slate-200 bg-white px-4 py-4 sm:px-6 lg:px-8 lg:pl-64 text-xs text-slate-500 flex flex-col sm:flex-row justify-between items-center gap-2">
                    <div>
                        <span className="font-semibold text-slate-700">
                            KSP Bima Sakti Mandiri
                        </span>{' '}
                        &copy; {new Date().getFullYear()} Sistem Informasi
                        Koperasi Simpan Pinjam.
                    </div>
                    <div className="flex items-center gap-3 text-slate-400">
                        <span>Local MVP Mode</span>
                        <span>&bull;</span>
                        <span>PostgreSQL Active</span>
                    </div>
                </footer>
            </div>
        </div>
    );
}
