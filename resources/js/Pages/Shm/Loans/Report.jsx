import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import { formatRupiah, formatDate } from '@/utils/format';
import { FileText, Download, User, Calendar, CreditCard, Shield, ChevronRight } from 'lucide-react';

export default function Report({ loansList, selectedLoanId, reportData }) {
    const [searchTerm, setSearchTerm] = useState('');

    const handleSelectLoan = (id) => {
        router.get(
            route('shm.loans.report.index'),
            { loan_id: id },
            { preserveState: true, replace: true }
        );
    };

    const handleExportPdf = () => {
        if (!selectedLoanId) return;
        window.location.href = route('shm.loans.report.pdf', selectedLoanId);
    };

    const filteredLoans = loansList.filter((l) => {
        const term = searchTerm.toLowerCase();
        return (
            l.loan_number?.toLowerCase().includes(term) ||
            l.customer_name?.toLowerCase().includes(term) ||
            l.customer_number?.toLowerCase().includes(term)
        );
    });

    return (
        <ShmLayout title="Laporan Pinjaman BSM Makro">
            <div className="space-y-6">
                {/* Header & Export Action */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
                            <FileText className="h-6 w-6 text-bsm-primary" />
                            Laporan Pinjaman BSM Makro
                        </h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Laporan snapshot komprehensif per pinjaman: Data Nasabah, Data Pinjaman, Jaminan, dan Riwayat Angsuran.
                        </p>
                    </div>
                    {reportData && (
                        <div>
                            <button
                                type="button"
                                onClick={handleExportPdf}
                                className="inline-flex items-center gap-2 rounded-lg bg-bsm-primary px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-bsm-primary-hover transition-colors"
                            >
                                <Download className="h-4 w-4" />
                                <span>Export PDF</span>
                            </button>
                        </div>
                    )}
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-4">
                    {/* Left Panel: Daftar Pinjaman Selector */}
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs lg:col-span-1">
                        <h3 className="text-sm font-bold text-slate-800 mb-2">Pilih Pinjaman</h3>
                        <div className="mb-3">
                            <input
                                type="text"
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                placeholder="Cari no. pinjaman / nasabah..."
                                className="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs focus:border-bsm-primary focus:outline-none"
                            />
                        </div>

                        <div className="max-h-[580px] overflow-y-auto space-y-2 pr-1">
                            {filteredLoans.length === 0 ? (
                                <p className="text-xs text-slate-400 py-4 text-center">Tidak ada pinjaman ditemukan.</p>
                            ) : (
                                filteredLoans.map((loan) => (
                                    <button
                                        key={loan.id}
                                        type="button"
                                        onClick={() => handleSelectLoan(loan.id)}
                                        className={`w-full text-left p-3 rounded-lg border transition-all text-xs ${
                                            selectedLoanId === loan.id
                                                ? 'border-bsm-primary bg-sky-50/50 shadow-xs ring-1 ring-bsm-primary'
                                                : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50'
                                        }`}
                                    >
                                        <div className="flex items-start justify-between">
                                            <span className="font-bold text-slate-900">{loan.loan_number}</span>
                                            <span
                                                className={`text-[10px] px-1.5 py-0.5 rounded font-medium ${
                                                    loan.status === 'active'
                                                        ? 'bg-emerald-50 text-emerald-700'
                                                        : 'bg-slate-100 text-slate-600'
                                                }`}
                                            >
                                                {loan.status === 'active' ? 'Aktif' : 'Closed'}
                                            </span>
                                        </div>
                                        <p className="mt-1 font-semibold text-slate-700">{loan.customer_name}</p>
                                        <p className="text-slate-500 font-mono text-[11px]">{loan.customer_number}</p>
                                        <div className="mt-2 flex justify-between items-center text-slate-600 border-t border-slate-100 pt-1.5">
                                            <span>{loan.product_name}</span>
                                            <span className="font-semibold text-slate-900 font-mono">{formatRupiah(loan.principal)}</span>
                                        </div>
                                    </button>
                                ))
                            )}
                        </div>
                    </div>

                    {/* Right Panel: Isi Laporan Pinjaman */}
                    <div className="lg:col-span-3">
                        {!reportData ? (
                            <div className="rounded-xl border border-slate-200 bg-white p-12 text-center shadow-xs">
                                <EmptyState
                                    icon={FileText}
                                    title="Pilih Pinjaman"
                                    description="Silakan pilih salah satu pinjaman dari panel di sebelah kiri untuk melihat rincian laporan dan mencetak PDF."
                                />
                            </div>
                        ) : (
                            <div className="space-y-6">
                                {/* Status Header Card */}
                                <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                                    <div className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 pb-4">
                                        <div>
                                            <span className="text-xs font-semibold uppercase tracking-wider text-slate-400">
                                                Laporan Resmi Pinjaman BSM Makro
                                            </span>
                                            <h2 className="text-xl font-bold text-slate-900 mt-0.5">
                                                {reportData.identitas.nomor_pinjaman}
                                            </h2>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <span
                                                className={`rounded-full px-3 py-1 text-xs font-semibold ${
                                                    reportData.identitas.status === 'active'
                                                        ? 'bg-emerald-100 text-emerald-800'
                                                        : 'bg-slate-100 text-slate-700'
                                                }`}
                                            >
                                                Status: {reportData.identitas.status === 'active' ? 'BERJALAN (ACTIVE)' : 'LUNAS (CLOSED)'}
                                            </span>
                                        </div>
                                    </div>

                                    {/* 1. Data Nasabah */}
                                    <div className="mt-6">
                                        <h3 className="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2 mb-3">
                                            <User className="h-4 w-4 text-bsm-primary" />
                                            <span>1. Data Nasabah</span>
                                        </h3>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 rounded-xl bg-slate-50 p-4 text-xs">
                                            <div>
                                                <p className="text-slate-500">Nomor Nasabah:</p>
                                                <p className="font-semibold text-slate-900 font-mono">{reportData.nasabah.nomor_nasabah}</p>
                                            </div>
                                            <div>
                                                <p className="text-slate-500">Nama Lengkap:</p>
                                                <p className="font-semibold text-slate-900">{reportData.nasabah.nama}</p>
                                            </div>
                                            <div>
                                                <p className="text-slate-500">No. Identitas (NIK):</p>
                                                <p className="font-medium text-slate-800 font-mono">{reportData.nasabah.nik}</p>
                                            </div>
                                            <div>
                                                <p className="text-slate-500">No. Ponsel:</p>
                                                <p className="font-medium text-slate-800">{reportData.nasabah.telepon}</p>
                                            </div>
                                            <div>
                                                <p className="text-slate-500">Tanggal Menjadi Nasabah:</p>
                                                <p className="font-medium text-slate-800">{reportData.nasabah.tanggal_gabung}</p>
                                            </div>
                                            <div>
                                                <p className="text-slate-500">Status Nasabah:</p>
                                                <p className="font-semibold text-emerald-700">{reportData.nasabah.status_aktif ? 'Aktif' : 'Nonaktif'}</p>
                                            </div>
                                            <div className="sm:col-span-2 md:col-span-3">
                                                <p className="text-slate-500">Alamat:</p>
                                                <p className="font-medium text-slate-800">{reportData.nasabah.alamat}</p>
                                            </div>
                                            {reportData.nasabah.unit_kerja && (
                                                <div>
                                                    <p className="text-slate-500">Unit Kerja:</p>
                                                    <p className="font-medium text-slate-800">{reportData.nasabah.unit_kerja}</p>
                                                </div>
                                            )}
                                            {reportData.nasabah.nip && (
                                                <div>
                                                    <p className="text-slate-500">NIP:</p>
                                                    <p className="font-medium text-slate-800">{reportData.nasabah.nip}</p>
                                                </div>
                                            )}
                                            {reportData.nasabah.ttl && (
                                                <div>
                                                    <p className="text-slate-500">Tempat, Tgl Lahir:</p>
                                                    <p className="font-medium text-slate-800">{reportData.nasabah.ttl}</p>
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    {/* 2. Data Pinjaman */}
                                    <div className="mt-6">
                                        <h3 className="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2 mb-3">
                                            <CreditCard className="h-4 w-4 text-bsm-primary" />
                                            <span>2. Data Pinjaman</span>
                                        </h3>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 rounded-xl bg-slate-50 p-4 text-xs">
                                            <div>
                                                <p className="text-slate-500">Produk Pinjaman:</p>
                                                <p className="font-semibold text-slate-900">{reportData.pinjaman.produk}</p>
                                            </div>
                                            <div>
                                                <p className="text-slate-500">Besar Pinjaman (Plafon):</p>
                                                <p className="font-bold text-slate-900 font-mono text-sm">{formatRupiah(reportData.pinjaman.plafon)}</p>
                                            </div>
                                            <div>
                                                <p className="text-slate-500">Jangka Waktu (Tenor):</p>
                                                <p className="font-semibold text-slate-800">{reportData.pinjaman.tenor_bulan} Bulan</p>
                                            </div>
                                            <div>
                                                <p className="text-slate-500">Suku Bunga / Jasa:</p>
                                                <p className="font-medium text-slate-800">
                                                    {reportData.pinjaman.rate_percent}% / {reportData.pinjaman.rate_period} ({reportData.pinjaman.calculation_method})
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-slate-500">Tanggal Pencairan:</p>
                                                <p className="font-medium text-slate-800">{reportData.identitas.tanggal_pencairan}</p>
                                            </div>
                                            <div>
                                                <p className="text-slate-500">Jatuh Tempo Pertama:</p>
                                                <p className="font-medium text-slate-800">{reportData.identitas.jatuh_tempo_pertama}</p>
                                            </div>
                                            <div className="sm:col-span-2 md:col-span-3">
                                                <p className="text-slate-500">Tujuan Pinjaman:</p>
                                                <p className="font-medium text-slate-800">{reportData.pinjaman.tujuan}</p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* 3. Data Jaminan */}
                                    <div className="mt-6">
                                        <h3 className="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2 mb-3">
                                            <Shield className="h-4 w-4 text-emerald-600" />
                                            <span>3. Data Jaminan Pinjaman</span>
                                        </h3>
                                        {reportData.jaminan.length === 0 ? (
                                            <p className="text-xs text-slate-400 italic bg-slate-50 p-4 rounded-xl">
                                                Tidak ada jaminan tercatat untuk pinjaman ini.
                                            </p>
                                        ) : (
                                            <div className="overflow-x-auto rounded-xl border border-slate-200">
                                                <table className="w-full text-left text-xs">
                                                    <thead className="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200">
                                                        <tr>
                                                            <th className="py-2.5 px-3">Jenis Jaminan</th>
                                                            <th className="py-2.5 px-3">Nama / Objek</th>
                                                            <th className="py-2.5 px-3">Keterangan</th>
                                                            <th className="py-2.5 px-3 text-right">Nilai Perkiraan</th>
                                                            <th className="py-2.5 px-3">Pengikatan</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y divide-slate-100 text-slate-700">
                                                        {reportData.jaminan.map((j) => (
                                                            <tr key={j.id} className="hover:bg-slate-50/50">
                                                                <td className="py-2 px-3 font-semibold text-slate-800">{j.jenis}</td>
                                                                <td className="py-2 px-3">{j.nama}</td>
                                                                <td className="py-2 px-3 text-slate-500">{j.keterangan || '-'}</td>
                                                                <td className="py-2 px-3 text-right font-mono font-medium">{formatRupiah(j.nilai_perkiraan)}</td>
                                                                <td className="py-2 px-3 capitalize">{j.status_pengikatan || '-'}</td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        )}
                                    </div>

                                    {/* 4. Ringkasan Kewajiban & Pembayaran */}
                                    <div className="mt-6">
                                        <h3 className="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2 mb-3">
                                            <Calendar className="h-4 w-4 text-bsm-primary" />
                                            <span>4. Ringkasan Kewajiban & Angsuran</span>
                                        </h3>
                                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                            <div className="rounded-xl border border-slate-200 bg-white p-3.5 shadow-2xs">
                                                <p className="text-[11px] font-medium text-slate-500">Pokok Dibayar</p>
                                                <p className="mt-1 text-sm font-bold text-emerald-600 font-mono">
                                                    {formatRupiah(reportData.ringkasan.pokok_dibayar)}
                                                </p>
                                            </div>
                                            <div className="rounded-xl border border-slate-200 bg-white p-3.5 shadow-2xs">
                                                <p className="text-[11px] font-medium text-slate-500">Bunga Dibayar</p>
                                                <p className="mt-1 text-sm font-bold text-emerald-600 font-mono">
                                                    {formatRupiah(reportData.ringkasan.bunga_dibayar)}
                                                </p>
                                            </div>
                                            <div className="rounded-xl border border-rose-200 bg-rose-50/50 p-3.5 shadow-2xs">
                                                <p className="text-[11px] font-medium text-rose-700">Sisa Pokok</p>
                                                <p className="mt-1 text-sm font-bold text-rose-600 font-mono">
                                                    {formatRupiah(reportData.ringkasan.sisa_pokok)}
                                                </p>
                                            </div>
                                            <div className="rounded-xl border border-rose-200 bg-rose-50/50 p-3.5 shadow-2xs">
                                                <p className="text-[11px] font-medium text-rose-700">Total Sisa Kewajiban</p>
                                                <p className="mt-1 text-sm font-bold text-rose-700 font-mono">
                                                    {formatRupiah(reportData.ringkasan.total_sisa_kewajiban)}
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* 5. Riwayat Jadwal Angsuran */}
                                    {reportData.angsuran.length > 0 && (
                                        <div className="mt-6">
                                            <h3 className="text-sm font-bold text-slate-800 uppercase tracking-wider mb-3">
                                                5. Jadwal Angsuran ({reportData.ringkasan.angsuran_terbayar} / {reportData.ringkasan.total_angsuran} Terbayar)
                                            </h3>
                                            <div className="max-h-72 overflow-y-auto rounded-xl border border-slate-200">
                                                <table className="w-full text-left text-xs">
                                                    <thead className="sticky top-0 bg-slate-50 text-slate-600 font-semibold border-b border-slate-200">
                                                        <tr>
                                                            <th className="py-2 px-3 text-center w-12">Ke</th>
                                                            <th className="py-2 px-3">Jatuh Tempo</th>
                                                            <th className="py-2 px-3 text-right">Pokok</th>
                                                            <th className="py-2 px-3 text-right">Bunga</th>
                                                            <th className="py-2 px-3 text-right">Total</th>
                                                            <th className="py-2 px-3 text-right">Terbayar</th>
                                                            <th className="py-2 px-3 text-center">Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y divide-slate-100 text-slate-700">
                                                        {reportData.angsuran.map((item) => (
                                                            <tr key={item.id} className="hover:bg-slate-50/50">
                                                                <td className="py-2 px-3 text-center font-bold text-slate-600">{item.nomor}</td>
                                                                <td className="py-2 px-3 font-mono">{item.jatuh_tempo}</td>
                                                                <td className="py-2 px-3 text-right font-mono">{formatRupiah(item.pokok)}</td>
                                                                <td className="py-2 px-3 text-right font-mono">{formatRupiah(item.bunga)}</td>
                                                                <td className="py-2 px-3 text-right font-mono font-semibold">{formatRupiah(item.total)}</td>
                                                                <td className="py-2 px-3 text-right font-mono text-emerald-600">{formatRupiah(item.terbayar)}</td>
                                                                <td className="py-2 px-3 text-center">
                                                                    <span
                                                                        className={`text-[10px] px-2 py-0.5 rounded font-medium ${
                                                                            item.status === 'paid'
                                                                                ? 'bg-emerald-100 text-emerald-800'
                                                                                : 'bg-slate-100 text-slate-600'
                                                                        }`}
                                                                    >
                                                                        {item.status === 'paid' ? 'LUNAS' : 'BELUM'}
                                                                    </span>
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </ShmLayout>
    );
}

