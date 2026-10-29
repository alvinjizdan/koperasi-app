import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { 
    Search, 
    Plus, 
    CheckCircle, 
    XCircle, 
    Edit, 
    ToggleLeft, 
    ToggleRight, 
    BookOpen,
    ArrowRight
} from 'lucide-react';

export default function Index({ mappings = [] }) {
    const [searchTerm, setSearchTerm] = useState('');

    const filtered = mappings.filter(m => 
        m.business_event.toLowerCase().includes(searchTerm.toLowerCase()) ||
        m.business_event_label.toLowerCase().includes(searchTerm.toLowerCase()) ||
        (m.product?.name && m.product.name.toLowerCase().includes(searchTerm.toLowerCase()))
    );

    const handleToggle = (id) => {
        if (confirm('Ubah status aktif konfigurasi mapping ini?')) {
            router.post(route('shm.accounting-mapping.toggle', id));
        }
    };

    return (
        <ShmLayout>
            <Head title="Accounting Mapping — Akuntansi SHM" />

            <div className="space-y-6">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <BookOpen className="w-6 h-6 text-emerald-600" />
                            <h1 className="text-2xl font-bold tracking-tight text-slate-900">
                                Accounting Mapping
                            </h1>
                        </div>
                        <p className="mt-1 text-sm text-slate-500">
                            Pemetaan aturan event bisnis finansial SHM ke peran akun dan bagan akun (CoA).
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <Link
                            href={route('shm.accounting-mapping.create')}
                            className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium transition-colors shadow-sm"
                        >
                            <Plus className="w-4 h-4" />
                            Tambah Mapping
                        </Link>
                    </div>
                </div>

                <div className="flex items-center justify-between gap-4 bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                    <div className="relative flex-1 max-w-md">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                        <input
                            type="text"
                            placeholder="Cari business event atau produk..."
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            className="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        />
                    </div>
                    <div className="text-xs text-slate-500">
                        Total {filtered.length} mapping
                    </div>
                </div>

                <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm text-slate-600">
                            <thead className="bg-slate-50 border-b border-slate-200 text-xs font-semibold text-slate-700 uppercase">
                                <tr>
                                    <th className="px-6 py-4">Event Bisnis</th>
                                    <th className="px-6 py-4">Produk</th>
                                    <th className="px-6 py-4">Sisi Debit</th>
                                    <th className="px-6 py-4">Sisi Kredit</th>
                                    <th className="px-6 py-4 text-center">Status</th>
                                    <th className="px-6 py-4 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-200">
                                {filtered.length === 0 ? (
                                    <tr>
                                        <td colSpan="6" className="px-6 py-12 text-center text-slate-400">
                                            Belum ada konfigurasi mapping yang terdaftar.
                                        </td>
                                    </tr>
                                ) : (
                                    filtered.map((m) => (
                                        <tr key={m.id} className="hover:bg-slate-50 transition-colors">
                                            <td className="px-6 py-4">
                                                <div className="font-semibold text-slate-900">{m.business_event_label}</div>
                                                <div className="text-xs text-slate-400 font-mono mt-0.5">{m.business_event}</div>
                                            </td>
                                            <td className="px-6 py-4">
                                                {m.product ? (
                                                    <span className="inline-flex items-center px-2 py-1 rounded bg-slate-100 text-xs font-medium text-slate-700">
                                                        {m.product.code} - {m.product.name}
                                                    </span>
                                                ) : (
                                                    <span className="text-xs text-slate-400 italic">Semua Produk (Generic)</span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4">
                                                {m.debit_account_role ? (
                                                    <div>
                                                        <span className="inline-flex items-center px-2 py-0.5 rounded bg-blue-50 text-blue-700 font-medium text-xs">
                                                            Role: {m.debit_account_role_label}
                                                        </span>
                                                    </div>
                                                ) : m.debit_account ? (
                                                    <div className="text-xs">
                                                        <span className="font-mono text-slate-900">{m.debit_account.code}</span> - {m.debit_account.name}
                                                    </div>
                                                ) : null}
                                            </td>
                                            <td className="px-6 py-4">
                                                {m.credit_account_role ? (
                                                    <div>
                                                        <span className="inline-flex items-center px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 font-medium text-xs">
                                                            Role: {m.credit_account_role_label}
                                                        </span>
                                                    </div>
                                                ) : m.credit_account ? (
                                                    <div className="text-xs">
                                                        <span className="font-mono text-slate-900">{m.credit_account.code}</span> - {m.credit_account.name}
                                                    </div>
                                                ) : null}
                                            </td>
                                            <td className="px-6 py-4 text-center">
                                                {m.is_active ? (
                                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                        <CheckCircle className="w-3.5 h-3.5" />
                                                        Aktif
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-600 border border-slate-200">
                                                        <XCircle className="w-3.5 h-3.5" />
                                                        Nonaktif
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <button
                                                        onClick={() => handleToggle(m.id)}
                                                        className="p-1 text-slate-400 hover:text-slate-600 transition-colors"
                                                        title={m.is_active ? "Nonaktifkan" : "Aktifkan"}
                                                    >
                                                        {m.is_active ? (
                                                            <ToggleRight className="w-5 h-5 text-emerald-600" />
                                                        ) : (
                                                            <ToggleLeft className="w-5 h-5 text-slate-400" />
                                                        )}
                                                    </button>
                                                    <Link
                                                        href={route('shm.accounting-mapping.edit', m.id)}
                                                        className="p-1 text-slate-400 hover:text-blue-600 transition-colors"
                                                        title="Edit Mapping"
                                                    >
                                                        <Edit className="w-4 h-4" />
                                                    </Link>
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </ShmLayout>
    );
}

