import React from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { ScrollArea, ScrollBar } from '@/Components/ui/scroll-area';
import EmptyState from '@/Components/Admin/EmptyState';
import { Layers, Pencil, CheckCircle2, XCircle, ArrowRight } from 'lucide-react';

export default function Index({ products }) {
    const handleToggle = (configId) => {
        if (confirm('Ubah status keaktifan konfigurasi akuntansi produk ini?')) {
            router.post(route('shm.product-accounting.toggle', configId));
        }
    };

    return (
        <ShmLayout title="Konfigurasi Akuntansi Produk" pageTitle="Konfigurasi Akuntansi Produk BSM Makro">
            <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 className="text-xl font-bold text-slate-900">Akuntansi Produk Pinjaman BSM Makro</h2>
                    <p className="mt-1 text-xs sm:text-sm text-slate-500">
                        Penetapan akun perkiraan (COA BSM Makro) resmi untuk setiap produk pinjaman: Kas/Bank, Piutang Pokok, Pendapatan Bunga, Pendapatan Denda, dan Diskon Pelunasan.
                    </p>
                </div>
            </div>

            {products.length === 0 ? (
                <EmptyState
                    icon={Layers}
                    title="Belum Ada Produk Pinjaman BSM Makro"
                    description="Daftar produk pinjaman BSM Makro masih kosong. Silakan tambahkan produk di Master Produk Pinjaman."
                />
            ) : (
                <ScrollArea className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <ScrollBar orientation="horizontal" />
                    <table className="min-w-full divide-y divide-slate-200 text-xs sm:text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Produk</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Kas / Bank</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Piutang Pokok</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Pendapatan Bunga</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Pendapatan Denda</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Diskon Pelunasan</th>
                                <th className="px-4 py-3 text-center font-semibold text-slate-500">Status</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-500">Aksi</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {products.map((product) => {
                                const acc = product.accounting;
                                const isConfigured = acc !== null;

                                return (
                                    <tr key={product.id} className="hover:bg-slate-50">
                                        <td className="px-4 py-3">
                                            <div className="font-mono font-bold text-slate-900">{product.code}</div>
                                            <div className="text-xs text-slate-600">{product.name}</div>
                                            <div className="text-[10px] text-slate-400 uppercase">{product.category}</div>
                                        </td>
                                        <td className="px-4 py-3">
                                            {acc?.cash_account ? (
                                                <div>
                                                    <span className="font-mono font-semibold text-slate-800">{acc.cash_account.code}</span>
                                                    <div className="text-[11px] text-slate-500">{acc.cash_account.name}</div>
                                                </div>
                                            ) : (
                                                <span className="text-slate-400 italic text-xs">Belum diatur</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {acc?.receivable_account ? (
                                                <div>
                                                    <span className="font-mono font-semibold text-slate-800">{acc.receivable_account.code}</span>
                                                    <div className="text-[11px] text-slate-500">{acc.receivable_account.name}</div>
                                                </div>
                                            ) : (
                                                <span className="text-slate-400 italic text-xs">Belum diatur</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {acc?.interest_revenue_account ? (
                                                <div>
                                                    <span className="font-mono font-semibold text-slate-800">{acc.interest_revenue_account.code}</span>
                                                    <div className="text-[11px] text-slate-500">{acc.interest_revenue_account.name}</div>
                                                </div>
                                            ) : (
                                                <span className="text-slate-400 italic text-xs">Belum diatur</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {acc?.penalty_revenue_account ? (
                                                <div>
                                                    <span className="font-mono font-semibold text-slate-800">{acc.penalty_revenue_account.code}</span>
                                                    <div className="text-[11px] text-slate-500">{acc.penalty_revenue_account.name}</div>
                                                </div>
                                            ) : (
                                                <span className="text-slate-400 italic text-xs">Belum diatur</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {acc?.settlement_discount_account ? (
                                                <div>
                                                    <span className="font-mono font-semibold text-slate-800">{acc.settlement_discount_account.code}</span>
                                                    <div className="text-[11px] text-slate-500">{acc.settlement_discount_account.name}</div>
                                                </div>
                                            ) : (
                                                <span className="text-slate-400 italic text-xs">Belum diatur</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {isConfigured ? (
                                                <span className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${acc.is_active ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-500 border-slate-200'}`}>
                                                    {acc.is_active ? 'Aktif' : 'Nonaktif'}
                                                </span>
                                            ) : (
                                                <span className="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold text-amber-700">
                                                    Belum Konfigurasi
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right whitespace-nowrap">
                                            <div className="flex items-center justify-end gap-2">
                                                <Link
                                                    href={route('shm.product-accounting.edit', product.id)}
                                                    className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs"
                                                >
                                                    <Pencil className="h-3.5 w-3.5" />
                                                    <span>{isConfigured ? 'Ubah' : 'Atur'}</span>
                                                </Link>
                                                {isConfigured && (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleToggle(acc.id)}
                                                        className={`inline-flex items-center gap-1 rounded-lg border px-2.5 py-1.5 text-xs font-semibold shadow-xs ${acc.is_active ? 'border-amber-300 text-amber-700 hover:bg-amber-50' : 'border-emerald-300 text-emerald-700 hover:bg-emerald-50'}`}
                                                    >
                                                        {acc.is_active ? (
                                                            <>
                                                                <XCircle className="h-3.5 w-3.5" />
                                                                <span>Nonaktifkan</span>
                                                            </>
                                                        ) : (
                                                            <>
                                                                <CheckCircle2 className="h-3.5 w-3.5" />
                                                                <span>Aktifkan</span>
                                                            </>
                                                        )}
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </ScrollArea>
            )}
        </ShmLayout>
    );
}

