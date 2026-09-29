import React, { useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Combobox, ComboboxContent, ComboboxEmpty, ComboboxInput, ComboboxItem, ComboboxList } from '@/Components/ui/combobox';
import { ArrowLeft, Plus, Trash2, CheckCircle2, AlertCircle } from 'lucide-react';

const emptyLine = () => ({ account_id: '', description: '', debit: '', credit: '' });

export default function Create({ accounts }) {
    const [values, setValues] = useState({
        transaction_date: new Date().toISOString().slice(0, 10),
        description: '',
    });
    const [lines, setLines] = useState([emptyLine(), emptyLine()]);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});

    const totalDebit = useMemo(
        () => lines.reduce((sum, line) => sum + (parseFloat(line.debit) || 0), 0),
        [lines]
    );
    const totalCredit = useMemo(
        () => lines.reduce((sum, line) => sum + (parseFloat(line.credit) || 0), 0),
        [lines]
    );
    const isBalanced = totalDebit > 0 && Math.abs(totalDebit - totalCredit) < 0.005;

    const setLine = (index, field, value) => {
        setLines(lines.map((line, i) => (i === index ? { ...line, [field]: value } : line)));
    };

    const submit = (event) => {
        event.preventDefault();
        setErrors({});

        const payload = {
            ...values,
            lines: lines.map((line) => ({
                account_id: line.account_id,
                description: line.description || null,
                debit: parseFloat(line.debit) || 0,
                credit: parseFloat(line.credit) || 0,
            })),
        };

        setProcessing(true);
        router.post(route('akuntansi.jurnal.store'), payload, {
            onError: (responseErrors) => setErrors(responseErrors),
            onFinish: () => setProcessing(false),
        });
    };

    const inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20';

    const accountOptions = React.useMemo(() => [
        { value: '', label: '— Pilih akun —' },
        ...accounts.map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name} (${a.normal_balance})` })),
    ], [accounts]);
    const labelForAccount = (id) => accountOptions.find((o) => o.value === String(id))?.label ?? String(id);

    return (
        <AdminLayout title="Buat Jurnal" pageTitle="Akuntansi — Jurnal Umum">
            <div className="mb-6">
                <Link href={route('akuntansi.jurnal.index')} className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 hover:text-slate-900">
                    <ArrowLeft className="h-4 w-4" /> Kembali ke Daftar Jurnal
                </Link>
            </div>

            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:grid-cols-2">
                    <div>
                        <label className="text-xs font-semibold text-slate-600" htmlFor="transaction_date">Tanggal Transaksi *</label>
                        <input
                            id="transaction_date"
                            type="date"
                            value={values.transaction_date}
                            onChange={(event) => setValues({ ...values, transaction_date: event.target.value })}
                            className={inputClass}
                            required
                        />
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-600" htmlFor="description">Deskripsi</label>
                        <input
                            id="description"
                            type="text"
                            value={values.description}
                            onChange={(event) => setValues({ ...values, description: event.target.value })}
                            className={inputClass}
                            maxLength={255}
                        />
                    </div>
                </div>

                <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-slate-700">Baris Debit / Kredit</h3>
                        <button
                            type="button"
                            onClick={() => setLines([...lines, emptyLine()])}
                            className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1.5 text-[11px] font-semibold text-slate-700 hover:bg-slate-50"
                        >
                            <Plus className="h-3.5 w-3.5" /> Tambah Baris
                        </button>
                    </div>

                    {typeof errors.lines === 'string' && (
                        <p className="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">{errors.lines}</p>
                    )}

                    <div className="space-y-3">
                        {lines.map((line, index) => (
                            <div key={index} className="grid grid-cols-12 gap-2 items-start rounded-lg border border-slate-100 bg-slate-50/60 p-3">
                                <div className="col-span-12 sm:col-span-4">
                                    <label className="text-[11px] font-semibold text-slate-500">Akun {index + 1} *</label>
                                    <Combobox
                                        items={accountOptions.map((o) => o.value)}
                                        value={line.account_id}
                                        onValueChange={(v) => setLine(index, 'account_id', v ?? '')}
                                        itemToStringValue={labelForAccount}
                                    >
                                        <ComboboxInput aria-label={`Akun ${index + 1}`} className={inputClass} required />
                                        <ComboboxContent>
                                            <ComboboxEmpty>Akun tidak ditemukan.</ComboboxEmpty>
                                            <ComboboxList>
                                                {(v) => (
                                                    <ComboboxItem key={v} value={v}>
                                                        {labelForAccount(v)}
                                                    </ComboboxItem>
                                                )}
                                            </ComboboxList>
                                        </ComboboxContent>
                                    </Combobox>
                                </div>
                                <div className="col-span-6 sm:col-span-3">
                                    <label className="text-[11px] font-semibold text-slate-500">Debit (Rp)</label>
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={line.debit}
                                        onChange={(event) => setLine(index, 'debit', event.target.value)}
                                        className={inputClass}
                                        placeholder="0"
                                    />
                                </div>
                                <div className="col-span-6 sm:col-span-3">
                                    <label className="text-[11px] font-semibold text-slate-500">Kredit (Rp)</label>
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={line.credit}
                                        onChange={(event) => setLine(index, 'credit', event.target.value)}
                                        className={inputClass}
                                        placeholder="0"
                                    />
                                </div>
                                <div className="col-span-12 sm:col-span-2 flex items-end">
                                    <button
                                        type="button"
                                        onClick={() => setLines(lines.length > 2 ? lines.filter((_, i) => i !== index) : lines)}
                                        disabled={lines.length <= 2}
                                        className="w-full inline-flex items-center justify-center gap-1 rounded-lg border border-rose-200 px-2.5 py-2 text-[11px] font-semibold text-rose-600 hover:bg-rose-50 disabled:opacity-40"
                                    >
                                        <Trash2 className="h-3.5 w-3.5" /> Hapus
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>

                    <div className="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
                            <span className="font-semibold text-slate-700">Total Debit: Rp {totalDebit.toLocaleString('id-ID')}</span>
                            <span className="font-semibold text-slate-700">Total Kredit: Rp {totalCredit.toLocaleString('id-ID')}</span>
                        </div>
                        <div className={`mt-2 inline-flex items-center gap-1.5 text-xs font-semibold ${isBalanced ? 'text-emerald-700' : 'text-rose-600'}`}>
                            {isBalanced ? <CheckCircle2 className="h-4 w-4" /> : <AlertCircle className="h-4 w-4" />}
                            {isBalanced ? 'Jurnal seimbang — siap diposting setelah disimpan.' : 'Jurnal belum seimbang (debit harus sama dengan kredit).'}
                        </div>
                    </div>
                </div>

                <div className="flex items-center gap-3">
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                    >
                        Simpan sebagai DRAFT
                    </button>
                    <Link href={route('akuntansi.jurnal.index')} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Batal
                    </Link>
                </div>
            </form>
        </AdminLayout>
    );
}
