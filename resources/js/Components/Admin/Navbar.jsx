import React from 'react';
import { Menu, ChevronRight } from 'lucide-react';
import { usePage } from '@inertiajs/react';

export default function Navbar({ pageTitle, onMobileMenuToggle }) {
    return (
        <header className="sticky top-0 z-30 flex h-16 w-full items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6 lg:px-8">
            {/* Left Header Section */}
            <div className="flex items-center gap-3">
                {/* Mobile Drawer Trigger Button */}
                <button
                    type="button"
                    onClick={onMobileMenuToggle}
                    className="flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 lg:hidden active:scale-95"
                    aria-label="Buka Menu Navigasi"
                >
                    <Menu className="h-5 w-5" />
                </button>

                {/* Page Title & Breadcrumb */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:gap-2">
                    <div className="flex items-center text-xs font-medium text-slate-500">
                        <span className="font-semibold text-bsm-accent">BSM Mikro</span>
                        <ChevronRight className="h-3.5 w-3.5 mx-1 text-slate-400" />
                    </div>
                    <h1 className="text-base font-semibold tracking-tight text-slate-900 sm:text-lg">
                        {pageTitle || 'Dashboard'}
                    </h1>
                </div>
            </div>
        </header>
    );
}
