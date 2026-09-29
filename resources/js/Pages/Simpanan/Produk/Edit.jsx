import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Combobox, ComboboxContent, ComboboxEmpty, ComboboxInput, ComboboxItem, ComboboxList, toLabeledCollection } from '@/Components/ui/combobox';
import { BookOpen, ChevronLeft, AlertCircle } from 'lucide-react';

const CATEGORIES = [
    { value: 'voluntary', label: 'Simpanan Sukarela' },
    { value: 'mandatory', label: 'Simpanan Wajib' },
    { value: 'principal', label: 'Simpanan Pokok' },
];

export default function Edit({ product }) {
    const categoryCollection = React.useMemo(() => toLabeledCollection(CATEGORIES), []);
    const { data, setData, put, processing, errors } = useForm({
        code: product.code || '',
        name: product.name || '',
        category: product.category || 'voluntary',
        description: product.description || '',
        is_active: product.is_active ?? true,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('simpanan-produk.update', product.id));
    };

    return (
        <AdminLayout title="Edit Produk Simpanan" pageTitle="Edit Produk Simpanan">
            <div className="max-w-2xl space-y-6">
                <Link
                    href={route('simpanan-produk.index')}
                    className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-800 transition-colors"
                >
                    <ChevronLeft className="h-4 w-4" />
                    Kembali ke daftar produk
                </Link>

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="border-b border-slate-100 px-6 py-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                                <BookOpen className="h-5 w-5" />
                            </div>
                            <div>
                                <h2 className="font-semibold text-slate-900">Edit Produk: {product.name}</h2>
                                <p className="text-sm font-mono text-slate-400">{product.code}</p>
                            </div>
                        </div>
                    </div>

                    <form onSubmit={handleSubmit} className="divide-y divide-slate-100">
                        <div className="grid grid-cols-1 gap-5 px-6 py-5 sm:grid-cols-2">
                            <div>
                                <label htmlFor="code" className="block text-sm font-medium text-slate-700">
                                    Kode Produk <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    id="code"
                                    type="text"
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                    maxLength={50}
                                    className={`mt-1.5 block w-full rounded-lg border px-3 py-2 text-sm font-mono transition-colors focus:outline-none focus:ring-1 ${
                                        errors.code
                                            ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                            : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                    }`}
                                />
                                {errors.code && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.code}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label htmlFor="name" className="block text-sm font-medium text-slate-700">
                                    Nama Produk <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    id="name"
                                    type="text"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    maxLength={255}
                                    className={`mt-1.5 block w-full rounded-lg border px-3 py-2 text-sm transition-colors focus:outline-none focus:ring-1 ${
                                        errors.name
                                            ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                            : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                    }`}
                                />
                                {errors.name && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.name}
                                    </p>
                                )}
                            </div>

                            {/* Kategori (Ticket 20) */}
                            <div>
                                <label htmlFor="category" className="block text-sm font-medium text-slate-700">
                                    Kategori <span className="text-rose-500">*</span>
                                </label>
                                <Combobox
                                    items={categoryCollection}
                                    value={data.category}
                                    onValueChange={(v) => setData('category', v ?? '')}
                                >
                                    <ComboboxInput
                                        id="category"
                                        aria-label="Kategori produk"
                                        aria-invalid={!!errors.category}
                                        className={errors.category ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500' : ''}
                                        required
                                    />
                                    <ComboboxContent>
                                        <ComboboxEmpty>Kategori tidak ditemukan.</ComboboxEmpty>
                                        <ComboboxList>
                                            {(category) => (
                                                <ComboboxItem key={category.value} value={category.value}>
                                                    {category.label}
                                                </ComboboxItem>
                                            )}
                                        </ComboboxList>
                                    </ComboboxContent>
                                </Combobox>
                                {errors.category && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.category}
                                    </p>
                                )}
                            </div>

                            <div className="sm:col-span-2">
                                <label htmlFor="description" className="block text-sm font-medium text-slate-700">
                                    Deskripsi
                                    <span className="ml-1 text-xs font-normal text-slate-400">(opsional)</span>
                                </label>
                                <textarea
                                    id="description"
                                    rows={3}
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    className="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-slate-700">Status</label>
                                <div className="mt-1.5 flex items-center gap-3">
                                    <label className="flex cursor-pointer items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={data.is_active}
                                            onChange={(e) => setData('is_active', e.target.checked)}
                                            className="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                        />
                                        <span className="text-sm text-slate-700">Aktif</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 px-6 py-4">
                            <Link
                                href={route('simpanan-produk.index')}
                                className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors"
                            >
                                Batal
                            </Link>
                            <button
                                type="submit"
                                disabled={processing}
                                className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50 active:scale-95 transition-all"
                            >
                                {processing ? 'Menyimpan...' : 'Simpan Perubahan'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AdminLayout>
    );
}
