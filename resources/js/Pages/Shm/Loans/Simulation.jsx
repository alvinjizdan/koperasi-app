import React, { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import PrimaryButton from '@/Components/PrimaryButton';
import { formatRupiah } from '@/utils/format';
import { Calculator, Download, Info } from 'lucide-react';

const EMPTY_FEES = {
    administration: 0,
    provision: 0,
    insurance: 0,
    other: 0,
    block_funds: 0,
};

/**
 * Simulasi Pinjaman SHM (Ticket 64).
 *
 * Rate default = 2% (nilai awal legacy untuk sandbox; rate resmi produk
 * mengikuti matriks produk — T82). Metode angsuran dan periode rate WAJIB
 * dipilih pengguna: simulasi tidak pernah menebak input, dan produk resmi
 * memakai baseline flat + bulanan (T79).
 *
 * Rincian biaya diinput manual sebagai nominal; biaya pencairan resmi
 * (admin/provisi/asuransi/notaris/simpanan) dihitung otomatis saat
 * pencairan (T85), bukan di halaman simulasi; denda tidak dihitung (masih
 * PENDING).
 *
 * Form disubmit sebagai GET sehingga hasil simulasi dapat dibagikan/dicetak
 * ulang, dan tombol "Export PDF" memakai parameter yang sama sehingga PDF
 * selalu identik dengan hasil di layar.
 */
export default function Simulation({ form: initial, result, rate, products, options }) {
    const { data, setData, errors: visitErrors, clearErrors } = useForm({
        run: initial.run ?? 1,
        name: initial.name ?? '',
        product_id: initial.product_id ?? '',
        principal: initial.principal ?? '',
        tenor_months: initial.tenor_months ?? '',
        rate_percent: initial.rate_percent ?? rate,
        method: initial.method ?? '',
        rate_period: initial.rate_period ?? '',
        location: initial.location ?? '',
        purpose: initial.purpose ?? '',
        fees: { ...EMPTY_FEES, ...(initial.fees ?? {}) },
    });

    const [showFees, setShowFees] = useState(
        () => Object.values(initial.fees ?? {}).some((value) => Number(value) > 0),
    );

    // Error validasi berasal dari dua jalur: halaman yang dimuat ulang setelah
    // redirect validasi (props `errors` dari server) dan kunjungan Inertia yang
    // gagal (state internal useForm). Keduanya digabung supaya pesan selalu
    // tampil pada kedua kasus.
    const pageErrors = usePage().props.errors ?? {};
    const errors = { ...pageErrors, ...visitErrors };

    const update = (field) => (event) =>
        setData(field, event.target.value);

    const updateFee = (field) => (event) =>
        setData('fees', { ...data.fees, [field]: event.target.value });

    const submit = (event) => {
        event.preventDefault();
        clearErrors();
        router.get(
            route('shm.loans.simulation'),
            {
                run: 1,
                name: data.name || undefined,
                product_id: data.product_id || undefined,
                principal: data.principal,
                tenor_months: data.tenor_months,
                rate_percent: data.rate_percent,
                method: data.method,
                rate_period: data.rate_period,
                location: data.location || undefined,
                purpose: data.purpose || undefined,
                fees: data.fees,
            },
            { preserveState: true, replace: false },
        );
    };

    const resetForm = () => {
        setData({
            run: 1,
            name: '',
            product_id: '',
            principal: '',
            tenor_months: '',
            rate_percent: rate,
            method: '',
            rate_period: '',
            location: '',
            purpose: '',
            fees: { ...EMPTY_FEES },
        });
        clearErrors();
        // Muat ulang halaman agar hasil simulasi lama ikut hilang dari server.
        router.get(
            route('shm.loans.simulation'),
            {},
            { preserveState: true, replace: true },
        );
    };

    const error = (key) => errors[key];

    const feeFields = [
        ['administration', 'Biaya administrasi'],
        ['provision', 'Provisi'],
        ['insurance', 'Asuransi'],
        ['other', 'Biaya lainnya'],
        ['block_funds', 'Blokir dana'],
    ];

    const pdfParams = result
        ? {
              run: 1,
              name: data.name || undefined,
              product_id: data.product_id || undefined,
              principal: data.principal,
              tenor_months: data.tenor_months,
              rate_percent: data.rate_percent,
              method: data.method,
              rate_period: data.rate_period,
              location: data.location || undefined,
              purpose: data.purpose || undefined,
              fees: data.fees,
          }
        : null;

    const scheduleColumns = [
        { key: 'period', label: 'No', align: 'center', render: (row) => row.period },
        {
            key: 'principal',
            label: 'Pokok',
            align: 'right',
            render: (row) => formatRupiah(row.principal),
        },
        {
            key: 'interest',
            label: 'Jasa/Bunga',
            align: 'right',
            render: (row) => formatRupiah(row.interest),
        },
        {
            key: 'total',
            label: 'Total',
            align: 'right',
            render: (row) => formatRupiah(row.total),
        },
        {
            key: 'balance',
            label: 'Saldo',
            align: 'right',
            render: (row) => formatRupiah(row.balance),
        },
    ];

    const inputClass =
        'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500';
    const hasError = (key) => Boolean(error(key));

    return (
        <ShmLayout title="Simulasi Pinjaman SHM">
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
                            <Calculator className="h-5 w-5 text-slate-400" />
                            Simulasi Pinjaman SHM
                        </h1>
                        <p className="text-sm text-gray-500">
                            Kalkulasi simulasi pinjaman dengan pilihan metode angsuran dan tenor.
                        </p>
                    </div>
                </div>

                <form onSubmit={submit} className="space-y-6 rounded-lg bg-white p-5 shadow">
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label className="text-xs font-semibold text-slate-700">
                                Nama calon / nasabah
                            </label>
                            <input
                                type="text"
                                value={data.name}
                                onChange={update('name')}
                                placeholder="Opsional"
                                className={inputClass}
                            />
                            {hasError('name') && (
                                <p className="mt-1 text-xs text-rose-600">{error('name')}</p>
                            )}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-700">
                                Produk pinjaman
                            </label>
                            <select
                                value={data.product_id}
                                onChange={update('product_id')}
                                className={inputClass}
                            >
                                <option value="">— Pilih produk —</option>
                                {products.map((product) => (
                                    <option key={product.id} value={product.id}>
                                        {product.name} ({product.code})
                                    </option>
                                ))}
                            </select>
                            {hasError('product_id') && (
                                <p className="mt-1 text-xs text-rose-600">
                                    {error('product_id')}
                                </p>
                            )}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-700">
                                Plafon pinjaman (Rp)
                            </label>
                            <input
                                type="number"
                                min="1"
                                value={data.principal}
                                onChange={update('principal')}
                                placeholder="Contoh: 50000000"
                                className={inputClass}
                            />
                            {hasError('principal') && (
                                <p className="mt-1 text-xs text-rose-600">
                                    {error('principal')}
                                </p>
                            )}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-700">
                                Tenor (bulan)
                            </label>
                            <input
                                type="number"
                                min="1"
                                max="600"
                                value={data.tenor_months}
                                onChange={update('tenor_months')}
                                placeholder="Contoh: 24"
                                className={inputClass}
                            />
                            {hasError('tenor_months') && (
                                <p className="mt-1 text-xs text-rose-600">
                                    {error('tenor_months')}
                                </p>
                            )}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-700">
                                Bunga / jasa (%)
                            </label>
                            <input
                                type="number"
                                min="0"
                                max="100"
                                step="0.0001"
                                value={data.rate_percent}
                                onChange={update('rate_percent')}
                                className={inputClass}
                            />
                            <p className="mt-1 text-xs text-slate-500">
                                Default legacy simulasi: {Number(rate)}% — rate resmi = matriks produk (T82)
                            </p>
                            {hasError('rate_percent') && (
                                <p className="mt-1 text-xs text-rose-600">
                                    {error('rate_percent')}
                                </p>
                            )}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-700">
                                Periode rate <span className="text-rose-600">*</span>
                            </label>
                            <select
                                value={data.rate_period}
                                onChange={update('rate_period')}
                                className={inputClass}
                            >
                                <option value="">— Pilih periode —</option>
                                {Object.entries(options.periods).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                            {hasError('rate_period') && (
                                <p className="mt-1 text-xs text-rose-600">
                                    {error('rate_period')}
                                </p>
                            )}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-700">
                                Metode angsuran <span className="text-rose-600">*</span>
                            </label>
                            <select
                                value={data.method}
                                onChange={update('method')}
                                className={inputClass}
                            >
                                <option value="">— Pilih metode —</option>
                                {Object.entries(options.methods).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                            {hasError('method') && (
                                <p className="mt-1 text-xs text-rose-600">
                                    {error('method')}
                                </p>
                            )}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-700">
                                Lokasi pinjaman
                            </label>
                            <input
                                type="text"
                                value={data.location}
                                onChange={update('location')}
                                placeholder="Opsional"
                                className={inputClass}
                            />
                            {hasError('location') && (
                                <p className="mt-1 text-xs text-rose-600">
                                    {error('location')}
                                </p>
                            )}
                        </div>

                        <div className="md:col-span-2">
                            <label className="text-xs font-semibold text-slate-700">
                                Tujuan pinjaman
                            </label>
                            <input
                                type="text"
                                value={data.purpose}
                                onChange={update('purpose')}
                                placeholder="Opsional"
                                className={inputClass}
                            />
                            {hasError('purpose') && (
                                <p className="mt-1 text-xs text-rose-600">
                                    {error('purpose')}
                                </p>
                            )}
                        </div>
                    </div>

                    <div>
                        <button
                            type="button"
                            onClick={() => setShowFees((value) => !value)}
                            className="text-sm font-semibold text-indigo-600 hover:underline"
                        >
                            {showFees ? 'Sembunyikan' : 'Tampilkan'} rincian biaya
                        </button>

                        {showFees && (
                            <div className="mt-3 grid grid-cols-1 gap-4 rounded-lg border border-slate-200 p-4 md:grid-cols-3">
                                {feeFields.map(([key, label]) => (
                                    <div key={key}>
                                        <label className="text-xs font-semibold text-slate-700">
                                            {label} (Rp)
                                        </label>
                                        <input
                                            type="number"
                                            min="0"
                                            value={data.fees[key]}
                                            onChange={updateFee(key)}
                                            className={inputClass}
                                        />
                                        {errors[`fees.${key}`] && (
                                            <p className="mt-1 text-xs text-rose-600">
                                                {errors[`fees.${key}`]}
                                            </p>
                                        )}
                                    </div>
                                ))}
                                <p className="text-xs text-slate-500 md:col-span-3">
                                    Nilai diinput manual, bukan hasil hitungan
                                    sistem. Komponen biaya pencairan resmi sudah
                                    ditetapkan kontrak (administrasi 2%, provisi
                                    3%, asuransi 1%, notaris custom, simpanan
                                    pokok/wajib) dan dihitung otomatis saat
                                    pencairan; blokir dana bukan komponen resmi.
                                </p>
                            </div>
                        )}
                    </div>

                    <div className="flex flex-wrap gap-3">
                        <PrimaryButton type="submit">
                            <Calculator className="mr-2 h-4 w-4" />
                            Hitung Simulasi
                        </PrimaryButton>
                        <button
                            type="button"
                            onClick={resetForm}
                            className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        >
                            Reset
                        </button>
                    </div>
                </form>

                {result && (
                    <div className="space-y-6">
                        <section className="rounded-lg bg-white p-5 shadow">
                            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                                <h2 className="text-sm font-semibold uppercase text-slate-600">
                                    Hasil Simulasi
                                </h2>
                                <a
                                    href={route('shm.loans.simulation.pdf', pdfParams)}
                                    className="inline-flex items-center gap-2 rounded-lg bg-bsm-primary px-4 py-2 text-sm font-semibold text-white hover:bg-bsm-primary-hover"
                                >
                                    <Download className="h-4 w-4" />
                                    Export PDF
                                </a>
                            </div>

                            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                                <div className="rounded-lg bg-slate-50 p-4">
                                    <p className="text-xs text-slate-500">
                                        Angsuran periode pertama
                                    </p>
                                    <p className="text-lg font-semibold text-slate-800">
                                        {formatRupiah(result.installment_per_period)}
                                    </p>
                                </div>
                                <div className="rounded-lg bg-slate-50 p-4">
                                    <p className="text-xs text-slate-500">Total jasa</p>
                                    <p className="text-lg font-semibold text-slate-800">
                                        {formatRupiah(result.total_interest)}
                                    </p>
                                </div>
                                <div className="rounded-lg bg-slate-50 p-4">
                                    <p className="text-xs text-slate-500">
                                        Total kewajiban (pokok + jasa)
                                    </p>
                                    <p className="text-lg font-semibold text-slate-800">
                                        {formatRupiah(result.total_obligation)}
                                    </p>
                                </div>
                                <div className="rounded-lg bg-slate-50 p-4">
                                    <p className="text-xs text-slate-500">Denda</p>
                                    <p className="text-sm font-semibold text-slate-500">
                                        —
                                    </p>
                                </div>
                                <div className="rounded-lg bg-slate-50 p-4">
                                    <p className="text-xs text-slate-500">Total biaya</p>
                                    <p className="text-lg font-semibold text-slate-800">
                                        {formatRupiah(result.total_fees)}
                                    </p>
                                </div>
                                <div className="rounded-lg bg-slate-50 p-4">
                                    <p className="text-xs text-slate-500">Blokir dana</p>
                                    <p className="text-lg font-semibold text-slate-800">
                                        {formatRupiah(result.block_funds)}
                                    </p>
                                </div>
                                <div className="rounded-lg bg-indigo-50 p-4">
                                    <p className="text-xs text-indigo-500">
                                        Total bersih pencairan
                                    </p>
                                    <p className="text-lg font-semibold text-indigo-700">
                                        {formatRupiah(result.net_disbursement)}
                                    </p>
                                </div>
                            </div>

                            <div className="mt-4 rounded-lg border border-slate-200 p-4 text-sm text-slate-600">
                                <p>
                                    Rate: <strong>{result.rate_percent}%</strong> per{' '}
                                    {result.rate_period === 'monthly'
                                        ? 'bulan'
                                        : 'tahun'}{' '}
                                    &middot; Metode:{' '}
                                    <strong>{options.methods[result.method]}</strong>
                                    &middot; Tenor: <strong>{result.tenor_months} bulan</strong>
                                </p>
                                <p className="mt-1 text-xs text-slate-500">
                                    Blokir dana tidak dipotong dari pencairan. Total biaya = jumlah biaya yang diinput manual.
                                </p>
                            </div>
                        </section>

                        <section className="space-y-3">
                            <h2 className="text-sm font-semibold uppercase text-slate-600">
                                Tabel Angsuran
                            </h2>
                            <ReportTable
                                columns={scheduleColumns}
                                rows={result.rows}
                                emptyTitle="Belum ada jadwal angsuran"
                                emptyDescription="Jadwal angsuran akan tampil di sini."
                            />
                        </section>
                    </div>
                )}
            </div>
        </ShmLayout>
    );
}
