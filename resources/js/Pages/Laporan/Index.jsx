import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    AlertTriangle,
    Banknote,
    Landmark,
    PiggyBank,
    Receipt,
    Users,
    Wallet,
} from 'lucide-react';

const REPORTS = [
    {
        name: 'Laporan Anggota',
        description: 'Daftar anggota beserta status, jumlah rekening simpanan, dan pinjaman aktif.',
        route: 'laporan.anggota',
        icon: Users,
    },
    {
        name: 'Laporan Simpanan',
        description: 'Saldo rekening resmi serta mutasi setoran dan penarikan pada periode terpilih.',
        route: 'laporan.simpanan',
        icon: PiggyBank,
    },
    {
        name: 'Laporan Pinjaman',
        description: 'Pengajuan, persetujuan, dan pencairan pinjaman dipisahkan sesuai status.',
        route: 'laporan.pinjaman',
        icon: Landmark,
    },
    {
        name: 'Laporan Pembayaran Angsuran',
        description: 'Pembayaran angsuran berhasil per periode, operator, dan status transaksi.',
        route: 'laporan.angsuran',
        icon: Receipt,
    },
    {
        name: 'Laporan Tunggakan',
        description: 'Angsuran yang sudah melewati jatuh tempo dan masih memiliki sisa tagihan.',
        route: 'laporan.tunggakan',
        icon: AlertTriangle,
    },
    {
        name: 'Laporan Transaksi Kas',
        description: 'Penerimaan, pengeluaran, dan setoran kasir dengan ringkasan terpisah.',
        route: 'laporan.transaksi-kas',
        icon: Banknote,
    },
    {
        name: 'Laporan Kas Harian',
        description: 'Rekap per sesi kasir: saldo awal, mutasi, saldo akhir sistem, dan selisih kas.',
        route: 'laporan.kas-harian',
        icon: Wallet,
    },
];

export default function Index({ operational_timezone }) {
    return (
        <AdminLayout title="Laporan" pageTitle="Laporan Dasar">
            <div className="space-y-6">
                <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                    <h2 className="text-lg font-bold tracking-tight text-slate-900">Laporan Dasar Koperasi</h2>
                    <p className="mt-1 text-sm text-slate-500">
                        Seluruh laporan membaca data langsung dari modul Anggota, Simpanan, Pinjaman, Angsuran, dan
                        Kasir. Angka posisi (saldo) dipisahkan dari angka mutasi periode, dan hanya transaksi berstatus
                        sah yang dihitung.
                    </p>
                    <p className="mt-2 text-[11px] text-slate-400">
                        Zona waktu operasional laporan: {operational_timezone}. Bunga, jasa simpanan, dan denda tidak
                        dihitung karena rumusnya belum dikonfirmasi.
                    </p>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {REPORTS.map((report) => {
                        const Icon = report.icon;

                        return (
                            <Link
                                key={report.route}
                                href={route(report.route)}
                                className="flex items-start gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-xs transition-all hover:shadow-md active:scale-[0.99]"
                            >
                                <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                                    <Icon className="h-5 w-5" />
                                </div>
                                <div>
                                    <h3 className="text-sm font-semibold text-slate-900">{report.name}</h3>
                                    <p className="mt-1 text-xs leading-relaxed text-slate-500">
                                        {report.description}
                                    </p>
                                </div>
                            </Link>
                        );
                    })}
                </div>
            </div>
        </AdminLayout>
    );
}
