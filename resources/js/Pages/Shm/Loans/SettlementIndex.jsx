import React from 'react';
import { Head, Link } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { formatRupiah, formatDate } from '@/utils/format';
import { ArrowLeft, Calculator } from 'lucide-react';

/**
 * Daftar pinjaman SHM yang layak dipreview pelunasannya (Ticket 89).
 *
 * Sumber data: `shm_loans` berstatus active + sudah dicairkan, dibatasi
 * scope kepemilikan (shm.view; tanpa shm.view_all hanya pinjaman milik
 * sendiri). SELURUH nilai berasal dari record yang sudah tersimpan di
 * server — tidak ada perhitungan pelunasan di React.
 *
 * Halaman ini HANYA pintu navigasi ke preview read-only; tidak ada mutasi
 * finansial di sini.
 */
export default function SettlementIndex({ loans }) {
    return (
        <ShmLayout title="Pelunasan Pinjaman">
            <Head title="Pelunasan Pinjaman" />

            <div className="space-y-6">
                <div>
                    <Link
                        href={route('shm.loans.index')}
                        className="mb-1 inline-flex items-center gap-1 text-sm text-indigo-600 hover:underline"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Kembali ke daftar pinjaman
                    </Link>
                    <h1 className="text-xl font-semibold text-gray-800">
                        Pelunasan Pinjaman
                    </h1>
                    <p className="text-sm text-gray-500">
                        Pilih pinjaman aktif yang sudah dicairkan untuk melihat
                        pratinjau kewajiban pelunasannya. Pratinjau bersifat
                        baca-saja dan tidak mengubah data apa pun.
                    </p>
                </div>

                <section className="rounded-lg bg-white p-5 shadow">
                    {loans.length === 0 ? (
                        <div className="flex items-center gap-3 rounded-md bg-slate-50 px-4 py-6 text-sm text-slate-600">
                            <Calculator className="h-5 w-5 text-slate-400" />
                            Tidak ada pinjaman aktif yang sudah dicairkan dalam
                            lingkup akses Anda.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b text-xs uppercase text-slate-500">
                                        <th className="px-3 py-2">No. Pinjaman</th>
                                        <th className="px-3 py-2">Nasabah</th>
                                        <th className="px-3 py-2 text-right">
                                            Plafon
                                        </th>
                                        <th className="px-3 py-2 text-right">
                                            Tenor
                                        </th>
                                        <th className="px-3 py-2">Dicairkan</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {loans.map((loan) => (
                                        <tr
                                            key={loan.id}
                                            className="border-b last:border-0 hover:bg-slate-50"
                                        >
                                            <td className="px-3 py-2 font-medium text-slate-800">
                                                {loan.loan_number}
                                            </td>
                                            <td className="px-3 py-2">
                                                <div>{loan.customer?.name || '—'}</div>
                                                <div className="text-xs text-slate-500">
                                                    {loan.customer?.customer_number || ''}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {formatRupiah(loan.principal)}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {loan.tenor_months} bulan
                                            </td>
                                            <td className="px-3 py-2">
                                                {loan.disbursed_at
                                                    ? formatDate(loan.disbursed_at)
                                                    : '—'}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                <Link
                                                    href={route(
                                                        'shm.loans.pelunasan.show',
                                                        loan.id,
                                                    )}
                                                    className="font-medium text-indigo-600 hover:underline"
                                                >
                                                    Preview Pelunasan
                                                </Link>
                                            </td>
                                        </tr>
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
