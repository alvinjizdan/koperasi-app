import React from 'react';
import { Menu, User, LogOut, ChevronRight, ShieldCheck } from 'lucide-react';
import Dropdown from '@/Components/Dropdown';
import { Link, usePage } from '@inertiajs/react';

export default function Navbar({ pageTitle, onMobileMenuToggle }) {
    const user = usePage().props.auth.user;

    return (
        <header className="sticky top-0 z-30 flex h-16 w-full items-center justify-between border-b border-slate-200 bg-white/95 px-4 backdrop-blur-md sm:px-6 lg:px-8">
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
                        <span>Aplikasi KSP</span>
                        <ChevronRight className="h-3.5 w-3.5 mx-1 text-slate-400" />
                    </div>
                    <h1 className="text-base font-semibold tracking-tight text-slate-900 sm:text-lg">
                        {pageTitle || 'Dashboard'}
                    </h1>
                </div>
            </div>

            {/* Right Header Section - User Identity */}
            <div className="flex items-center gap-3">
                <div className="hidden md:flex items-center gap-1.5 px-3 py-1 rounded-full bg-slate-100 text-xs font-medium text-slate-700">
                    <ShieldCheck className="h-3.5 w-3.5 text-emerald-600" />
                    <span>Petugas Koperasi</span>
                </div>

                {/* User Menu Dropdown */}
                <div className="relative">
                    <Dropdown>
                        <Dropdown.Trigger>
                            <button
                                type="button"
                                className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white p-1.5 sm:px-3 sm:py-1.5 text-sm font-medium text-slate-700 shadow-xs transition-colors hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:ring-offset-1 active:scale-95"
                            >
                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-slate-900 text-xs font-semibold text-white">
                                    {user.name ? user.name.charAt(0).toUpperCase() : 'U'}
                                </div>
                                <span className="hidden sm:inline-block max-w-[140px] truncate text-slate-800">
                                    {user.name}
                                </span>
                            </button>
                        </Dropdown.Trigger>

                        <Dropdown.Content align="right" width="48">
                            <div className="px-4 py-2.5 border-b border-slate-100">
                                <p className="text-xs font-semibold text-slate-900 truncate">
                                    {user.name}
                                </p>
                                <p className="text-xs text-slate-500 truncate">
                                    {user.email}
                                </p>
                            </div>

                            <Dropdown.Link
                                href={route('profile.edit')}
                                className="flex items-center gap-2 text-slate-700 hover:text-slate-900"
                            >
                                <User className="h-4 w-4 text-slate-500" />
                                <span>Pengaturan Profil</span>
                            </Dropdown.Link>

                            <Dropdown.Link
                                href={route('logout')}
                                method="post"
                                as="button"
                                className="flex items-center gap-2 text-rose-600 hover:bg-rose-50 hover:text-rose-700 font-medium"
                            >
                                <LogOut className="h-4 w-4 text-rose-500" />
                                <span>Keluar Sistem</span>
                            </Dropdown.Link>
                        </Dropdown.Content>
                    </Dropdown>
                </div>
            </div>
        </header>
    );
}
