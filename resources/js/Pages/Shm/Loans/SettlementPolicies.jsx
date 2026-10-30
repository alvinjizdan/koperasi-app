import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { formatDate } from '@/utils/format';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Percent,
    PlusCircle,
    ToggleLeft,
    ToggleRight,
} from 'lucide-react';

/**
 * Master Policy Diskon Bunga Pelunasan SHM — Ticket 89B.
 *
 * DATA-DRIVEN: admin mengatur persentase diskon per rentang sisa bulan
 * TANPA mengubah source code. Tidak ada default percentage yang di-seed;
 * seluruh nilai adalah keputusan bisnis admin.
 *
 * Seluruh validasi bisnis (rentang valid, persentase 0–100, tanpa overlap
 * policy aktif) ditegakkan SERVER (ShmSettlementDiscountPolicyService);
 * React hanya menampilkan hasil. Setiap baris menampilkan jumlah settlement
 * yang memakai policy tersebut — snapshot settlement lama TIDAK berubah
 * saat policy diubah.
 */
function PolicyForm({ initial, onDone, canToggle }) {
    const [form, setForm] = useState({
        min_remaining_months: initial?.min_remaining_months ?? '',
        max_remaining_months: initial?.max_remaining_months ?? '',
        discount_percent: initial?.discount_percent ?? '',
        is_active: initial?.is_active ?? true,
        effective_from: initial?.effective_from ?? '',
        effective_until: initial?.effective_until ?? '',
        notes: initial?.notes ?? '',
    });
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);

    const set = (key) => (e) =>
        setForm((f) => ({ ...f, [key]: e.target.value }));

    const submit = (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        const payload = {
            min_remaining_months: form.min_remaining_months,
            max_remaining_months: form.max_remaining_months,
            discount_percent: form.discount_percent,
            is_active: form.is_active,
            effective_from: form.effective_from || null,
            effective_until: form.effective_until || null,
            notes: form.notes || null,
        };

        const done = {
            onFinish: () => setProcessing(false),
            onError: (errs) => setErrors(errs),
            preserveScroll: true,
        };

        if (initial?.id) {
            router.put(
                route('shm.loans.pelunasan.policies.update', initial.id),
                payload,
                done,
            );
        } else {
            router.post(
                route('shm.loans.pelunasan.policies.store'),
                payload,
                done,
            );
        }
    };

    const fieldError = (key) =>
        errors[key] && (
            <p className="mt-1 text-xs text-rose-600">{errors[key]}</p>
        );

    return (
        <form
            onSubmit={submit}
            className="space-y-3 rounded-md bg-slate-50 p-4"
        >
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                    <label className="block text-xs text-slate-500">
                        Sisa Bulan Minimum
                    </label>
                    <input
                        type="number"
                        min="0"
                        required
                        className="mt-1 w-full rounded-md border-slate-300 text-sm"
                        value={form.min_remaining_months}
                        onChange={set('min_remaining_months')}
                    />
                    {fieldError('min_remaining_months')}
                </div>
                <div>
                    <label className="block text-xs text-slate-500">
                        Sisa Bulan Maksimum
                    </label>
                    <input
                        type="number"
                        min="0"
                        required
                        className="mt-1 w-full rounded-md border-slate-300 text-sm"
                        value={form.max_remaining_months}
                        onChange={set('max_remaining_months')}
                    />
                    {fieldError('max_remaining_months')}
                </div>
                <div>
                    <label className="block text-xs text-slate-500">
                        Diskon (%)
                    </label>
                    <input
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        required
                        className="mt-1 w-full rounded-md border-slate-300 text-sm"
                        value={form.discount_percent}
                        onChange={set('discount_percent')}
                    />
                    {fieldError('discount_percent')}
                </div>
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                    <label className="block text-xs text-slate-500">
                        Berlaku Dari (opsional)
                    </label>
                    <input
                        type="date"
                        className="mt-1 w-full rounded-md border-slate-300 text-sm"
                        value={form.effective_from}
                        onChange={set('effective_from')}
                    />
                    {fieldError('effective_from')}
                </div>
                <div>
                    <label className="block text-xs text-slate-500">
                        Berlaku Sampai (opsional)
                    </label>
                    <input
                        type="date"
                        className="mt-1 w-full rounded-md border-slate-300 text-sm"
                        value={form.effective_until}
                        onChange={set('effective_until')}
                    />
                    {fieldError('effective_until')}
                </div>
                <div>
                    <label className="block text-xs text-slate-500">
                        Catatan (opsional)
                    </label>
                    <input
                        type="text"
                        maxLength={1000}
                        className="mt-1 w-full rounded-md border-slate-300 text-sm"
                        value={form.notes}
                        onChange={set('notes')}
                    />
                    {fieldError('notes')}
                </div>
            </div>

            {canToggle && (
                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                        type="checkbox"
                        checked={form.is_active}
                        onChange={(e) =>
                            setForm((f) => ({
                                ...f,
                                is_active: e.target.checked,
                            }))
                        }
                        className="rounded border-slate-300"
                    />
                    Aktif
                </label>
            )}

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={processing}
                    className="rounded-lg bg-bsm-primary px-4 py-2 text-sm font-semibold text-white hover:bg-bsm-primary-hover disabled:opacity-50"
                >
                    {initial?.id ? 'Simpan Perubahan' : 'Buat Policy'}
                </button>
                {onDone && (
                    <button
                        type="button"
                        onClick={onDone}
                        className="rounded-md bg-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-300"
                    >
                        Batal
                    </button>
                )}
            </div>
        </form>
    );
}

export default function SettlementPolicies({ policies, flash, errors }) {
    const [creating, setCreating] = useState(false);
    const [editingId, setEditingId] = useState(null);

    const toggle = (policy) => {
        router.post(
            route('shm.loans.pelunasan.policies.toggle', policy.id),
            {},
            { preserveScroll: true },
        );
    };

    return (
        <ShmLayout title="Policy Diskon Pelunasan">
            <Head title="Policy Diskon Pelunasan" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link
                            href={route('shm.loans.pelunasan.index')}
                            className="mb-1 inline-flex items-center gap-1 text-sm text-indigo-600 hover:underline"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Kembali ke daftar pelunasan
                        </Link>
                        <h1 className="text-xl font-semibold text-gray-800">
                            Policy Diskon Bunga Pelunasan
                        </h1>
                        <p className="text-sm text-gray-500">
                            Data-driven: persentase diskon per rentang sisa
                            bulan untuk pinjaman &gt; Rp50 juta. Hanya berlaku
                            untuk settlement SHM. Settlement lama memakai
                            snapshot-nya sendiri.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => {
                            setCreating((v) => !v);
                            setEditingId(null);
                        }}
                        className="inline-flex items-center gap-2 rounded-lg bg-bsm-primary px-4 py-2 text-sm font-semibold text-white hover:bg-bsm-primary-hover"
                    >
                        <PlusCircle className="h-4 w-4" />
                        {creating ? 'Tutup Form' : 'Policy Baru'}
                    </button>
                </div>

                {flash?.success && (
                    <div className="flex items-start gap-2 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{flash.success}</span>
                    </div>
                )}

                {flash?.error && (
                    <div className="flex items-start gap-2 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{flash.error}</span>
                    </div>
                )}

                {creating && (
                    <section className="rounded-lg bg-white p-5 shadow">
                        <h2 className="mb-3 text-sm font-semibold uppercase text-slate-600">
                            Policy Baru
                        </h2>
                        <PolicyForm
                            onDone={() => setCreating(false)}
                            canToggle
                        />
                    </section>
                )}

                <section className="rounded-lg bg-white p-5 shadow">
                    <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                        <Percent className="h-4 w-4" />
                        Daftar Policy ({policies.length})
                    </h2>

                    {policies.length === 0 ? (
                        <div className="flex items-start gap-3 rounded-md bg-amber-50 px-4 py-4 text-sm text-amber-800">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>
                                Belum ada policy diskon. Pinjaman &gt; Rp50
                                juta yang diajukan pelunasannya akan DITAHAN
                                sampai policy dikonfigurasi — sistem tidak
                                menggunakan persentase default.
                            </span>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b text-xs uppercase text-slate-500">
                                        <th className="px-3 py-2">
                                            Rentang Sisa Bulan
                                        </th>
                                        <th className="px-3 py-2 text-right">
                                            Diskon
                                        </th>
                                        <th className="px-3 py-2">
                                            Periode Efektif
                                        </th>
                                        <th className="px-3 py-2">Status</th>
                                        <th className="px-3 py-2">
                                            Dipakai Settlement
                                        </th>
                                        <th className="px-3 py-2">Catatan</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {policies.map((policy) => (
                                        <React.Fragment key={policy.id}>
                                            <tr className="border-b last:border-0 hover:bg-slate-50">
                                                <td className="px-3 py-2 font-medium text-slate-800">
                                                    {policy.min_remaining_months}{' '}
                                                    –{' '}
                                                    {
                                                        policy.max_remaining_months
                                                    }{' '}
                                                    bulan
                                                </td>
                                                <td className="px-3 py-2 text-right font-semibold text-indigo-700">
                                                    {Number(
                                                        policy.discount_percent,
                                                    )}
                                                    %
                                                </td>
                                                <td className="px-3 py-2 text-xs text-slate-500">
                                                    {policy.effective_from
                                                        ? formatDate(
                                                              policy.effective_from,
                                                          )
                                                        : '—'}{' '}
                                                    s.d.{' '}
                                                    {policy.effective_until
                                                        ? formatDate(
                                                              policy.effective_until,
                                                          )
                                                        : 'tanpa batas'}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <span
                                                        className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                                                            policy.is_active
                                                                ? 'bg-emerald-50 text-emerald-700'
                                                                : 'bg-slate-100 text-slate-500'
                                                        }`}
                                                    >
                                                        {policy.is_active
                                                            ? 'Aktif'
                                                            : 'Nonaktif'}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-2 text-slate-600">
                                                    {policy.used_by_settlements}{' '}
                                                    settlement
                                                </td>
                                                <td className="px-3 py-2 text-xs text-slate-500">
                                                    {policy.notes || '—'}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <div className="flex items-center justify-end gap-2 text-xs">
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setEditingId(
                                                                    editingId ===
                                                                        policy.id
                                                                        ? null
                                                                        : policy.id,
                                                                );
                                                                setCreating(
                                                                    false,
                                                                );
                                                            }}
                                                            className="font-medium text-indigo-600 hover:underline"
                                                        >
                                                            {editingId ===
                                                            policy.id
                                                                ? 'Tutup'
                                                                : 'Edit'}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                toggle(policy)
                                                            }
                                                            className={`inline-flex items-center gap-1 font-medium hover:underline ${
                                                                policy.is_active
                                                                    ? 'text-rose-600'
                                                                    : 'text-emerald-600'
                                                            }`}
                                                        >
                                                            {policy.is_active ? (
                                                                <>
                                                                    <ToggleRight className="h-3.5 w-3.5" />
                                                                    Nonaktifkan
                                                                </>
                                                            ) : (
                                                                <>
                                                                    <ToggleLeft className="h-3.5 w-3.5" />
                                                                    Aktifkan
                                                                </>
                                                            )}
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                            {editingId === policy.id && (
                                                <tr>
                                                    <td
                                                        colSpan={7}
                                                        className="px-3 py-3"
                                                    >
                                                        <PolicyForm
                                                            initial={policy}
                                                            canToggle
                                                            onDone={() =>
                                                                setEditingId(
                                                                    null,
                                                                )
                                                            }
                                                        />
                                                    </td>
                                                </tr>
                                            )}
                                        </React.Fragment>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </ShmLayout>
    );
}
