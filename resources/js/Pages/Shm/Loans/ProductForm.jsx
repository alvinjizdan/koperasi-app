import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { ArrowLeft, Landmark, Save } from 'lucide-react';

/**
 * Form tambah/edit master produk Pinjaman SHM (Ticket 98-FIX).
 *
 * Hanya mengelola baris master: kode, nama, kategori resmi (T82),
 * keterangan, ringkasan syarat, dan status aktif. TIDAK ada field rate —
 * rate resmi selalu diresolusi dari tier produk (ShmProductRateService,
 * T82) sehingga tidak pernah ada angka rate kedua di master. Tier rate
 * tetap seed-driven (di luar cakupan form ini).
 */
export default function ProductForm({ product, categories }) {
    const [form, setForm] = useState({
        code: product?.code ?? '',
        name: product?.name ?? '',
        category: product?.category ?? categories?.[0]?.id ?? '',
        description: product?.description ?? '',
        eligibility_summary: product?.eligibility_summary ?? '',
        is_active: product?.is_active ?? true,
    });
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);

    const set = (key) => (event) =>
        setForm((current) => ({ ...current, [key]: event.target.value }));

    const submit = (event) => {
        event.preventDefault();
        setProcessing(true);
        setErrors({});

        const payload = {
            code: form.code,
            name: form.name,
            category: form.category,
            description: form.description || null,
            eligibility_summary: form.eligibility_summary || null,
            is_active: form.is_active,
        };

        const done = {
            onFinish: () => setProcessing(false),
            onError: (errs) => setErrors(errs),
            preserveScroll: true,
        };

        if (product?.id) {
            router.put(
                route('shm.loans.products.update', product.id),
                payload,
                done,
            );
        } else {
            router.post(route('shm.loans.products.store'), payload, done);
        }
    };

    const fieldError = (key) =>
        errors[key] && (
            <p className="mt-1 text-xs text-rose-600">{errors[key]}</p>
        );

    return (
        <ShmLayout
            title={product?.id ? 'Edit Produk Pinjaman BSM Makro' : 'Tambah Produk Pinjaman BSM Makro'}
        >
            <Head
                title={
                    product?.id
                        ? 'Edit Produk Pinjaman BSM Makro'
                        : 'Tambah Produk Pinjaman BSM Makro'
                }
            />

            <div className="max-w-2xl space-y-6">
                <div>
                    <Link
                        href={route('shm.loans.products.index')}
                        className="mb-1 inline-flex items-center gap-1 text-sm text-indigo-600 hover:underline"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Kembali ke daftar produk
                    </Link>
                    <h1 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
                        <Landmark className="h-5 w-5 text-slate-400" />
                        {product?.id
                            ? 'Edit Produk Pinjaman BSM Makro'
                            : 'Tambah Produk Pinjaman BSM Makro'}
                    </h1>
                    <p className="text-sm text-gray-500">
                        Master ini hanya mengelola identitas &amp; syarat
                        produk. Rate resmi dihitung dari tier rate per tenor
                        (T82) dan tidak diisi melalui form ini — produk baru
                        baru dapat dipakai pengajuan setelah tier rate-nya
                        tersedia.
                    </p>
                </div>

                {product?.id != null && (
                    <div className="rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-600">
                        Jumlah pinjaman yang memakai produk ini:{' '}
                        <span className="font-semibold">
                            {product.loans_count ?? 0}
                        </span>
                        . Edit hanya mengubah data master; histori
                        pengajuan/pinjaman yang sudah ada tidak berubah.
                    </div>
                )}

                <form onSubmit={submit} className="space-y-4 rounded-lg bg-white p-5 shadow">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label className="block text-xs font-semibold text-slate-700">
                                Kode Produk
                            </label>
                            <input
                                type="text"
                                maxLength={30}
                                required
                                className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                value={form.code}
                                onChange={set('code')}
                            />
                            {fieldError('code')}
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-slate-700">
                                Nama Produk
                            </label>
                            <input
                                type="text"
                                maxLength={120}
                                required
                                className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                value={form.name}
                                onChange={set('name')}
                            />
                            {fieldError('name')}
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-slate-700">
                            Kategori
                        </label>
                        <select
                            required
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            value={form.category}
                            onChange={set('category')}
                        >
                            {(categories ?? []).map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.label}
                                </option>
                            ))}
                        </select>
                        {fieldError('category')}
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-slate-700">
                            Keterangan
                        </label>
                        <textarea
                            maxLength={1000}
                            rows={3}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            value={form.description}
                            onChange={set('description')}
                        />
                        {fieldError('description')}
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-slate-700">
                            Ringkasan Syarat (Eligibility)
                        </label>
                        <textarea
                            maxLength={2000}
                            rows={4}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            value={form.eligibility_summary}
                            onChange={set('eligibility_summary')}
                        />
                        {fieldError('eligibility_summary')}
                    </div>

                    <label className="flex items-center gap-2 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            checked={form.is_active}
                            onChange={(event) =>
                                setForm((current) => ({
                                    ...current,
                                    is_active: event.target.checked,
                                }))
                            }
                            className="rounded border-slate-300"
                        />
                        Aktif (dapat dipilih pada pengajuan baru)
                    </label>
                    {fieldError('is_active')}

                    <div className="flex gap-2">
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center gap-2 rounded-lg bg-bsm-primary px-4 py-2 text-sm font-semibold text-white hover:bg-bsm-primary-hover disabled:opacity-50"
                        >
                            <Save className="h-4 w-4" />
                            {product?.id
                                ? 'Simpan Perubahan'
                                : 'Tambah Produk'}
                        </button>
                        <Link
                            href={route('shm.loans.products.index')}
                            className="rounded-md bg-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-300"
                        >
                            Batal
                        </Link>
                    </div>
                </form>
            </div>
        </ShmLayout>
    );
}
