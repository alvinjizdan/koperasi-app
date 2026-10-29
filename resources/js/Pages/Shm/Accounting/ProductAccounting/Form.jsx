import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { ArrowLeft, Save, AlertCircle } from 'lucide-react';

export default function Form({ product, configuration, selectableAccounts }) {
    const isConfigured = configuration !== null;

    const { data, setData, put, processing, errors } = useForm({
        cash_account_id: configuration?.cash_account_id ? String(configuration.cash_account_id) : '',
        receivable_account_id: configuration?.receivable_account_id ? String(configuration.receivable_account_id) : '',
        interest_revenue_account_id: configuration?.interest_revenue_account_id ? String(configuration.interest_revenue_account_id) : '',
        penalty_revenue_account_id: configuration?.penalty_revenue_account_id ? String(configuration.penalty_revenue_account_id) : '',
        settlement_discount_account_id: configuration?.settlement_discount_account_id ? String(configuration.settlement_discount_account_id) : '',
        is_active: configuration?.is_active ?? true,
        notes: configuration?.notes || '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('shm.product-accounting.update', product.id));
    };

    return (
        <ShmLayout
            title={`Atur Akuntansi Produk - ${product.code}`}
            pageTitle={`Konfigurasi Akuntansi - ${product.code}`}
        >
            <div className="mb-6">
                <Link
                    href={route('shm.product-accounting.index')}
                    className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-slate-800"
                >
                    <ArrowLeft className="h-4 w-4" />
                    <span>Kembali ke Konfigurasi Akuntansi Produk</span>
                </Link>
                <div className="mt-2 flex items-center gap-3">
                    <h2 className="text-xl font-bold text-slate-900">
                        {product.code} — {product.name}
                    </h2>
                    <span className="rounded bg-slate-100 border border-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-600 uppercase">
                        {product.category}
                    </span>
                </div>
                <p className="mt-1 text-xs text-slate-500">
                    Pilih akun perkiraan resmi (COA SHM) yang akan dikaitkan dengan transaksi produk ini.
                </p>
            </div>

            <div className="max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-xs">
                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Notice */}
                    <div className="rounded-xl border border-blue-200 bg-blue-50 p-4 text-xs text-blue-800 flex items-start gap-3">
                        <AlertCircle className="h-5 w-5 text-blue-600 shrink-0 mt-0.5" />
                        <div>
                            <p className="font-semibold mb-1">Prinsip Isolasi & Validasi Server-Side</p>
                            <p className="text-blue-700 leading-relaxed">
                                Hanya akun yang terdaftar dalam <strong>Akuntansi SHM (AccountingContext::SHM)</strong> dan berstatus <strong>Aktif</strong> yang dapat dipilih. Pilihan akun Koperasi tidak dapat diakses dari modul ini.
                            </p>
                        </div>
                    </div>

                    {/* Kas / Bank Account */}
                    <div>
                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Akun Kas / Bank Operasional (Asset)
                        </label>
                        <select
                            value={data.cash_account_id}
                            onChange={(e) => setData('cash_account_id', e.target.value)}
                            className="w-full rounded-lg border border-slate-300 px-3.5 py-2 text-xs sm:text-sm text-slate-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        >
                            <option value="">-- Belum Ditetapkan --</option>
                            {selectableAccounts.assets.map((acc) => (
                                <option key={acc.id} value={acc.id}>
                                    {acc.code} — {acc.name} ({acc.normal_balance.toUpperCase()})
                                </option>
                            ))}
                        </select>
                        <p className="mt-1 text-[11px] text-slate-400">Akun penampung pencairan dan penerimaan angsuran tunai/bank.</p>
                        {errors.cash_account_id && <p className="mt-1 text-xs text-rose-600">{errors.cash_account_id}</p>}
                    </div>

                    {/* Receivable Account */}
                    <div>
                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Akun Piutang Pokok Pinjaman (Asset)
                        </label>
                        <select
                            value={data.receivable_account_id}
                            onChange={(e) => setData('receivable_account_id', e.target.value)}
                            className="w-full rounded-lg border border-slate-300 px-3.5 py-2 text-xs sm:text-sm text-slate-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        >
                            <option value="">-- Belum Ditetapkan --</option>
                            {selectableAccounts.assets.map((acc) => (
                                <option key={acc.id} value={acc.id}>
                                    {acc.code} — {acc.name} ({acc.normal_balance.toUpperCase()})
                                </option>
                            ))}
                        </select>
                        <p className="mt-1 text-[11px] text-slate-400">Akun pencatatan kewajiban pokok pinjaman debitur SHM.</p>
                        {errors.receivable_account_id && <p className="mt-1 text-xs text-rose-600">{errors.receivable_account_id}</p>}
                    </div>

                    {/* Interest Revenue Account */}
                    <div>
                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Akun Pendapatan Jasa Bunga (Revenue)
                        </label>
                        <select
                            value={data.interest_revenue_account_id}
                            onChange={(e) => setData('interest_revenue_account_id', e.target.value)}
                            className="w-full rounded-lg border border-slate-300 px-3.5 py-2 text-xs sm:text-sm text-slate-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        >
                            <option value="">-- Belum Ditetapkan --</option>
                            {selectableAccounts.revenues.map((acc) => (
                                <option key={acc.id} value={acc.id}>
                                    {acc.code} — {acc.name} ({acc.normal_balance.toUpperCase()})
                                </option>
                            ))}
                        </select>
                        <p className="mt-1 text-[11px] text-slate-400">Akun pengakuan pendapatan jasa bunga angsuran atau pelunasan.</p>
                        {errors.interest_revenue_account_id && <p className="mt-1 text-xs text-rose-600">{errors.interest_revenue_account_id}</p>}
                    </div>

                    {/* Penalty Revenue Account */}
                    <div>
                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Akun Pendapatan Denda / Penalti (Revenue)
                        </label>
                        <select
                            value={data.penalty_revenue_account_id}
                            onChange={(e) => setData('penalty_revenue_account_id', e.target.value)}
                            className="w-full rounded-lg border border-slate-300 px-3.5 py-2 text-xs sm:text-sm text-slate-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        >
                            <option value="">-- Belum Ditetapkan --</option>
                            {selectableAccounts.revenues.map((acc) => (
                                <option key={acc.id} value={acc.id}>
                                    {acc.code} — {acc.name} ({acc.normal_balance.toUpperCase()})
                                </option>
                            ))}
                        </select>
                        <p className="mt-1 text-[11px] text-slate-400">Akun pengakuan pendapatan atas denda keterlambatan pembayaran.</p>
                        {errors.penalty_revenue_account_id && <p className="mt-1 text-xs text-rose-600">{errors.penalty_revenue_account_id}</p>}
                    </div>

                    {/* Settlement Discount Account */}
                    <div>
                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Akun Diskon Pelunasan (Contra-Revenue / Expense)
                        </label>
                        <select
                            value={data.settlement_discount_account_id}
                            onChange={(e) => setData('settlement_discount_account_id', e.target.value)}
                            className="w-full rounded-lg border border-slate-300 px-3.5 py-2 text-xs sm:text-sm text-slate-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        >
                            <option value="">-- Belum Ditetapkan (Pending Business Confirmation) --</option>
                            {selectableAccounts.all.map((acc) => (
                                <option key={acc.id} value={acc.id}>
                                    {acc.code} — {acc.name} [{acc.category.toUpperCase()}] ({acc.normal_balance.toUpperCase()})
                                </option>
                            ))}
                        </select>
                        <p className="mt-1 text-[11px] text-slate-400">
                            Perlakuan akuntansi potongan diskon pelunasan SHM (kebijakan contra-revenue vs beban operasional tetap berstatus Pending Business Confirmation).
                        </p>
                        {errors.settlement_discount_account_id && <p className="mt-1 text-xs text-rose-600">{errors.settlement_discount_account_id}</p>}
                    </div>

                    {/* Notes */}
                    <div>
                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Catatan / Dokumen Referensi
                        </label>
                        <textarea
                            rows={3}
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            placeholder="Catatan persetujuan SK komite atau instruksi pemetaan akuntansi produk ini"
                            className="w-full rounded-lg border border-slate-300 px-3.5 py-2 text-xs sm:text-sm text-slate-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        />
                        {errors.notes && <p className="mt-1 text-xs text-rose-600">{errors.notes}</p>}
                    </div>

                    {/* Status Aktif */}
                    <div className="flex items-center gap-2 pt-1">
                        <input
                            type="checkbox"
                            id="is_active"
                            checked={data.is_active}
                            onChange={(e) => setData('is_active', e.target.checked)}
                            className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        <label htmlFor="is_active" className="text-xs font-semibold text-slate-700">
                            Konfigurasi Aktif (Siap dikonsumsi oleh modul integrasi jurnal SHM)
                        </label>
                    </div>

                    {/* Tombol Simpan */}
                    <div className="flex justify-end gap-3 pt-4 border-t border-slate-100">
                        <Link
                            href={route('shm.product-accounting.index')}
                            className="rounded-lg border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                        >
                            Batal
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-5 py-2 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 shadow-xs"
                        >
                            <Save className="h-4 w-4" />
                            <span>{processing ? 'Menyimpan...' : 'Simpan Konfigurasi'}</span>
                        </button>
                    </div>
                </form>
            </div>
        </ShmLayout>
    );
}

