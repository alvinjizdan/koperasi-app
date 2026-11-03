import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { Wallet, ChevronLeft, AlertCircle } from 'lucide-react';
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
    toLabeledCollection,
} from '@/Components/ui/combobox';

export default function Create({ customers, products, today_date }) {
    const customerOptions = React.useMemo(
        () =>
            toLabeledCollection(
                customers.map((c) => ({
                    value: String(c.id),
                    label: `${c.name} (${c.customer_number})`,
                }))
            ),
        [customers]
    );

    const productOptions = React.useMemo(
        () =>
            toLabeledCollection(
                products.map((p) => ({
                    value: String(p.id),
                    label: `${p.name} (${p.code})`,
                }))
            ),
        [products]
    );

    const { data, setData, post, processing, errors } = useForm({
        shm_customer_id: '',
        shm_saving_product_id: '',
        opened_at: today_date,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('shm.savings.accounts.store'));
    };

    return (
        <ShmLayout title="Buka Rekening Simpanan BSM Makro" pageTitle="Buka Rekening Simpanan SHM">
            <div className="max-w-2xl space-y-6">
                <Link
                    href={route('shm.savings.accounts.index')}
                    className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-800 transition-colors"
                >
                    <ChevronLeft className="h-4 w-4" />
                    Kembali ke daftar rekening SHM
                </Link>

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="border-b border-slate-100 px-6 py-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700">
                                <Wallet className="h-5 w-5" />
                            </div>
                            <div>
                                <h2 className="font-semibold text-slate-900">Pembukaan Rekening Simpanan SHM</h2>
                                <p className="text-xs text-slate-500">
                                    Nomor rekening SHM akan di-generate otomatis secara unik (format: REK-SHM-YYYYMMDD-XXXXXX).
                                </p>
                            </div>
                        </div>
                    </div>

                    <form onSubmit={handleSubmit} className="p-6 space-y-5">
                        {errors.error && (
                            <div className="flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                                <AlertCircle className="h-4 w-4 flex-shrink-0" />
                                {errors.error}
                            </div>
                        )}

                        {/* Nasabah SHM */}
                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
                                Nasabah SHM (BSM Makro) <span className="text-rose-500">*</span>
                            </label>
                            <Combobox
                                collection={customerOptions}
                                value={String(data.shm_customer_id || '')}
                                onValueChange={(val) => setData('shm_customer_id', val)}
                            >
                                <ComboboxInput placeholder="Cari nama atau nomor nasabah SHM..." />
                                <ComboboxContent>
                                    <ComboboxEmpty>Nasabah tidak ditemukan</ComboboxEmpty>
                                    <ComboboxList>
                                        {(item) => (
                                            <ComboboxItem key={item.value} value={item.value}>
                                                {item.label}
                                            </ComboboxItem>
                                        )}
                                    </ComboboxList>
                                </ComboboxContent>
                            </Combobox>
                            {errors.shm_customer_id && (
                                <p className="mt-1 text-xs text-rose-600">{errors.shm_customer_id}</p>
                            )}
                            <p className="mt-1 text-xs text-slate-400">
                                Rekening simpanan SHM dimiliki langsung oleh identitas Nasabah SHM (independen dari keanggotaan Koperasi).
                            </p>
                        </div>

                        {/* Produk Simpanan SHM */}
                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
                                Produk Simpanan SHM <span className="text-rose-500">*</span>
                            </label>
                            <Combobox
                                collection={productOptions}
                                value={String(data.shm_saving_product_id || '')}
                                onValueChange={(val) => setData('shm_saving_product_id', val)}
                            >
                                <ComboboxInput placeholder="Pilih produk simpanan SHM..." />
                                <ComboboxContent>
                                    <ComboboxEmpty>Produk tidak ditemukan</ComboboxEmpty>
                                    <ComboboxList>
                                        {(item) => (
                                            <ComboboxItem key={item.value} value={item.value}>
                                                {item.label}
                                            </ComboboxItem>
                                        )}
                                    </ComboboxList>
                                </ComboboxContent>
                            </Combobox>
                            {errors.shm_saving_product_id && (
                                <p className="mt-1 text-xs text-rose-600">{errors.shm_saving_product_id}</p>
                            )}
                        </div>

                        {/* Tanggal Buka */}
                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
                                Tanggal Pembukaan <span className="text-rose-500">*</span>
                            </label>
                            <input
                                type="date"
                                value={data.opened_at}
                                onChange={(e) => setData('opened_at', e.target.value)}
                                className="w-full rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 transition-all focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100"
                            />
                            {errors.opened_at && (
                                <p className="mt-1 text-xs text-rose-600">{errors.opened_at}</p>
                            )}
                        </div>

                        {/* Actions */}
                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                            <Link
                                href={route('shm.savings.accounts.index')}
                                className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50"
                            >
                                Batal
                            </Link>
                            <button
                                type="submit"
                                disabled={processing}
                                className="rounded-lg bg-slate-900 px-5 py-2 text-sm font-medium text-white transition-all duration-100 hover:bg-slate-800 active:scale-95 disabled:opacity-50"
                            >
                                {processing ? 'Membuka Rekening...' : 'Buka Rekening'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </ShmLayout>
    );
}

