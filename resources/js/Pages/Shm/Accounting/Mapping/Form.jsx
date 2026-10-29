import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { ArrowLeft, Save, BookOpen } from 'lucide-react';

export default function Form({ 
    mapping = null, 
    products = [], 
    accounts = [], 
    supportedEvents = {}, 
    supportedRoles = {} 
}) {
    const isEdit = mapping !== null;

    const { data, setData, post, put, processing, errors } = useForm({
        business_event: mapping?.business_event || '',
        shm_loan_product_id: mapping?.shm_loan_product_id || '',
        debit_account_role: mapping?.debit_account_role || '',
        debit_account_id: mapping?.debit_account_id || '',
        credit_account_role: mapping?.credit_account_role || '',
        credit_account_id: mapping?.credit_account_id || '',
        is_active: mapping ? mapping.is_active : true,
        description: mapping?.description || '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        if (isEdit) {
            put(route('shm.accounting-mapping.update', mapping.id));
        } else {
            post(route('shm.accounting-mapping.store'));
        }
    };

    return (
        <ShmLayout>
            <Head title={`${isEdit ? 'Edit' : 'Tambah'} Accounting Mapping — Akuntansi SHM`} />

            <div className="max-w-4xl mx-auto space-y-6">
                <div className="flex items-center gap-4">
                    <Link
                        href={route('shm.accounting-mapping.index')}
                        className="p-2 bg-white border border-slate-200 rounded-lg text-slate-500 hover:text-slate-800 transition-colors shadow-sm"
                    >
                        <ArrowLeft className="w-5 h-5" />
                    </Link>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">
                            {isEdit ? 'Edit Accounting Mapping' : 'Tambah Accounting Mapping'}
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Konfigurasi aturan pemetaan debit dan kredit untuk event bisnis SHM.
                        </p>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="bg-white rounded-xl border border-slate-200 shadow-sm p-6 space-y-6">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {/* Business Event */}
                        <div className="space-y-1">
                            <label className="text-sm font-medium text-slate-700">
                                Business Event <span className="text-rose-500">*</span>
                            </label>
                            <select
                                value={data.business_event}
                                onChange={(e) => setData('business_event', e.target.value)}
                                className="w-full text-sm border border-slate-200 rounded-lg py-2 px-3 focus:ring-2 focus:ring-emerald-500"
                            >
                                <option value="">-- Pilih Event Bisnis --</option>
                                {Object.entries(supportedEvents).map(([val, label]) => (
                                    <option key={val} value={val}>{label}</option>
                                ))}
                            </select>
                            {errors.business_event && (
                                <p className="text-xs text-rose-500">{errors.business_event}</p>
                            )}
                        </div>

                        {/* Product (Optional / Generic) */}
                        <div className="space-y-1">
                            <label className="text-sm font-medium text-slate-700">
                                Produk Pinjaman (Opsional)
                            </label>
                            <select
                                value={data.shm_loan_product_id}
                                onChange={(e) => setData('shm_loan_product_id', e.target.value)}
                                className="w-full text-sm border border-slate-200 rounded-lg py-2 px-3 focus:ring-2 focus:ring-emerald-500"
                            >
                                <option value="">Semua Produk (Generic Fallback)</option>
                                {products.map((p) => (
                                    <option key={p.id} value={p.id}>{p.code} - {p.name}</option>
                                ))}
                            </select>
                            <p className="text-xs text-slate-400">Kosongkan jika berlaku umum untuk seluruh produk pinjaman.</p>
                            {errors.shm_loan_product_id && (
                                <p className="text-xs text-rose-500">{errors.shm_loan_product_id}</p>
                            )}
                        </div>
                    </div>

                    <div className="border-t border-slate-200 pt-6">
                        <h3 className="text-sm font-semibold text-slate-900 mb-4">Sisi Debit (Debit Side)</h3>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 bg-slate-50 p-4 rounded-xl border border-slate-200">
                            <div className="space-y-1">
                                <label className="text-sm font-medium text-slate-700">
                                    Via Peran Akun Produk (Role)
                                </label>
                                <select
                                    value={data.debit_account_role}
                                    onChange={(e) => {
                                        setData(prev => ({
                                            ...prev,
                                            debit_account_role: e.target.value,
                                            debit_account_id: e.target.value ? '' : prev.debit_account_id
                                        }));
                                    }}
                                    className="w-full text-sm border border-slate-200 rounded-lg py-2 px-3 focus:ring-2 focus:ring-emerald-500 bg-white"
                                >
                                    <option value="">-- Tidak Menggunakan Role --</option>
                                    {Object.entries(supportedRoles).map(([role, label]) => (
                                        <option key={role} value={role}>{label}</option>
                                    ))}
                                </select>
                            </div>

                            <div className="space-y-1">
                                <label className="text-sm font-medium text-slate-700">
                                    Atau Akun Spesifik CoA
                                </label>
                                <select
                                    value={data.debit_account_id}
                                    onChange={(e) => {
                                        setData(prev => ({
                                            ...prev,
                                            debit_account_id: e.target.value,
                                            debit_account_role: e.target.value ? '' : prev.debit_account_role
                                        }));
                                    }}
                                    disabled={Boolean(data.debit_account_role)}
                                    className="w-full text-sm border border-slate-200 rounded-lg py-2 px-3 focus:ring-2 focus:ring-emerald-500 bg-white disabled:bg-slate-100 disabled:text-slate-400"
                                >
                                    <option value="">-- Pilih Akun Spesifik --</option>
                                    {accounts.map((acc) => (
                                        <option key={acc.id} value={acc.id}>
                                            {acc.code} - {acc.name} ({acc.category})
                                        </option>
                                    ))}
                                </select>
                            </div>
                            {errors.debit_account && (
                                <p className="text-xs text-rose-500 col-span-2">{errors.debit_account}</p>
                            )}
                        </div>
                    </div>

                    <div className="border-t border-slate-200 pt-6">
                        <h3 className="text-sm font-semibold text-slate-900 mb-4">Sisi Kredit (Credit Side)</h3>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 bg-slate-50 p-4 rounded-xl border border-slate-200">
                            <div className="space-y-1">
                                <label className="text-sm font-medium text-slate-700">
                                    Via Peran Akun Produk (Role)
                                </label>
                                <select
                                    value={data.credit_account_role}
                                    onChange={(e) => {
                                        setData(prev => ({
                                            ...prev,
                                            credit_account_role: e.target.value,
                                            credit_account_id: e.target.value ? '' : prev.credit_account_id
                                        }));
                                    }}
                                    className="w-full text-sm border border-slate-200 rounded-lg py-2 px-3 focus:ring-2 focus:ring-emerald-500 bg-white"
                                >
                                    <option value="">-- Tidak Menggunakan Role --</option>
                                    {Object.entries(supportedRoles).map(([role, label]) => (
                                        <option key={role} value={role}>{label}</option>
                                    ))}
                                </select>
                            </div>

                            <div className="space-y-1">
                                <label className="text-sm font-medium text-slate-700">
                                    Atau Akun Spesifik CoA
                                </label>
                                <select
                                    value={data.credit_account_id}
                                    onChange={(e) => {
                                        setData(prev => ({
                                            ...prev,
                                            credit_account_id: e.target.value,
                                            credit_account_role: e.target.value ? '' : prev.credit_account_role
                                        }));
                                    }}
                                    disabled={Boolean(data.credit_account_role)}
                                    className="w-full text-sm border border-slate-200 rounded-lg py-2 px-3 focus:ring-2 focus:ring-emerald-500 bg-white disabled:bg-slate-100 disabled:text-slate-400"
                                >
                                    <option value="">-- Pilih Akun Spesifik --</option>
                                    {accounts.map((acc) => (
                                        <option key={acc.id} value={acc.id}>
                                            {acc.code} - {acc.name} ({acc.category})
                                        </option>
                                    ))}
                                </select>
                            </div>
                            {errors.credit_account && (
                                <p className="text-xs text-rose-500 col-span-2">{errors.credit_account}</p>
                            )}
                        </div>
                    </div>

                    <div className="border-t border-slate-200 pt-6 space-y-4">
                        <div className="space-y-1">
                            <label className="text-sm font-medium text-slate-700">
                                Keterangan / Deskripsi
                            </label>
                            <textarea
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                rows={3}
                                placeholder="Catatan operasional terkait aturan pemetaan..."
                                className="w-full text-sm border border-slate-200 rounded-lg py-2 px-3 focus:ring-2 focus:ring-emerald-500"
                            />
                        </div>

                        <div className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                id="is_active"
                                checked={data.is_active}
                                onChange={(e) => setData('is_active', e.target.checked)}
                                className="rounded text-emerald-600 focus:ring-emerald-500 border-slate-300"
                            />
                            <label htmlFor="is_active" className="text-sm text-slate-700">
                                Status Aktif (Mapping dapat digunakan oleh journal engine)
                            </label>
                        </div>
                    </div>

                    <div className="border-t border-slate-200 pt-6 flex justify-end gap-3">
                        <Link
                            href={route('shm.accounting-mapping.index')}
                            className="px-4 py-2 border border-slate-200 text-slate-600 rounded-lg text-sm font-medium hover:bg-slate-50 transition-colors"
                        >
                            Batal
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium transition-colors shadow-sm disabled:opacity-50"
                        >
                            <Save className="w-4 h-4" />
                            {processing ? 'Menyimpan...' : 'Simpan Mapping'}
                        </button>
                    </div>
                </form>
            </div>
        </ShmLayout>
    );
}

