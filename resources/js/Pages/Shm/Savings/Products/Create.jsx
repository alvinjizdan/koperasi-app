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

const CATEGORIES = [
    { value: 'voluntary', label: 'Simpanan Sukarela' },
    { value: 'mandatory', label: 'Simpanan Wajib' },
    { value: 'principal', label: 'Simpanan Pokok' },
];

export default function Create() {
    const categoryCollection = React.useMemo(() => toLabeledCollection(CATEGORIES), []);
    const { data, setData, post, processing, errors } = useForm({
        code: '',
        name: '',
        category: 'voluntary',
        description: '',
        is_active: true,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('shm.savings.products.store'));
    };

    return (
        <ShmLayout title="Tambah Produk Simpanan BSM Makro" pageTitle="Tambah Produk Simpanan SHM">
            <div className="max-w-2xl space-y-6">
                <Link
                    href={route('shm.savings.products.index')}
                    className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-800 transition-colors"
                >
                    <ChevronLeft className="h-4 w-4" />
                    Kembali ke daftar produk SHM
                </Link>

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="border-b border-slate-100 px-6 py-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700">
                                <Wallet className="h-5 w-5" />
                            </div>
                            <div>
                                <h2 className="font-semibold text-slate-900">Tambah Produk Simpanan SHM Baru</h2>
                                <p className="text-xs text-slate-500">Domain BSM Makro (SHM)</p>
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

                        {/* Kode Produk */}
                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
                                Kode Produk <span className="text-rose-500">*</span>
                            </label>
                            <input
                                type="text"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                placeholder="Contoh: SHM-SIMP-SUKARELA"
                                className="w-full rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 text-sm font-mono text-slate-800 placeholder-slate-400 transition-all focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100"
                            />
                            {errors.code && (
                                <p className="mt-1 text-xs text-rose-600">{errors.code}</p>
                            )}
                        </div>

                        {/* Nama Produk */}
                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
                                Nama Produk <span className="text-rose-500">*</span>
                            </label>
                            <input
                                type="text"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Contoh: Simpanan Sukarela Makro"
                                className="w-full rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 placeholder-slate-400 transition-all focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100"
                            />
                            {errors.name && (
                                <p className="mt-1 text-xs text-rose-600">{errors.name}</p>
                            )}
                        </div>

                        {/* Kategori */}
                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
                                Kategori Simpanan <span className="text-rose-500">*</span>
                            </label>
                            <Combobox
                                collection={categoryCollection}
                                value={data.category}
                                onValueChange={(val) => setData('category', val || 'voluntary')}
                            >
                                <ComboboxInput placeholder="Pilih Kategori Simpanan..." />
                                <ComboboxContent>
                                    <ComboboxEmpty>Kategori tidak ditemukan</ComboboxEmpty>
                                    <ComboboxList>
                                        {(item) => (
                                            <ComboboxItem key={item.value} value={item.value}>
                                                {item.label}
                                            </ComboboxItem>
                                        )}
                                    </ComboboxList>
                                </ComboboxContent>
                            </Combobox>
                            {errors.category && (
                                <p className="mt-1 text-xs text-rose-600">{errors.category}</p>
                            )}
                        </div>

                        {/* Deskripsi */}
                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
                                Deskripsi / Keterangan
                            </label>
                            <textarea
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                rows={3}
                                placeholder="Keterangan singkat mengenai produk simpanan SHM ini..."
                                className="w-full rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 placeholder-slate-400 transition-all focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100 resize-none"
                            />
                            {errors.description && (
                                <p className="mt-1 text-xs text-rose-600">{errors.description}</p>
                            )}
                        </div>

                        {/* Status Aktif */}
                        <div className="flex items-center gap-3 pt-2">
                            <input
                                type="checkbox"
                                id="is_active"
                                checked={data.is_active}
                                onChange={(e) => setData('is_active', e.target.checked)}
                                className="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-500"
                            />
                            <label htmlFor="is_active" className="text-sm font-medium text-slate-700 cursor-pointer">
                                Produk langsung aktif untuk pembukaan rekening baru
                            </label>
                        </div>

                        {/* Actions */}
                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                            <Link
                                href={route('shm.savings.products.index')}
                                className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50"
                            >
                                Batal
                            </Link>
                            <button
                                type="submit"
                                disabled={processing}
                                className="rounded-lg bg-slate-900 px-5 py-2 text-sm font-medium text-white transition-all duration-100 hover:bg-slate-800 active:scale-95 disabled:opacity-50"
                            >
                                {processing ? 'Menyimpan...' : 'Simpan Produk'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </ShmLayout>
    );
}

