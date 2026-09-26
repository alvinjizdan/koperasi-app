<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ModulePlaceholderController extends Controller
{
    /**
     * Tampilkan halaman placeholder untuk modul bisnis yang belum diimplementasikan.
     */
    public function show(Request $request, string $module): Response
    {
        $modules = [
            'anggota' => [
                'title' => 'Modul Anggota',
                'description' => 'Manajemen data keanggotaan, registrasi anggota baru, profil anggota, dan status keaktifan.',
                'icon' => 'Users',
            ],
            'simpanan' => [
                'title' => 'Modul Simpanan',
                'description' => 'Pengelolaan simpanan pokok, simpanan wajib, simpanan sukarela, serta riwayat mutasi simpanan.',
                'icon' => 'PiggyBank',
            ],
            'pinjaman' => [
                'title' => 'Modul Pinjaman',
                'description' => 'Pengajuan pinjaman, persetujuan kredit, analisis kelayakan, dan data pinjaman aktif.',
                'icon' => 'Landmark',
            ],
            'angsuran' => [
                'title' => 'Modul Angsuran',
                'description' => 'Jadwal pembayaran angsuran, penerimaan pembayaran, serta pemantauan tunggakan.',
                'icon' => 'Receipt',
            ],
            'kasir' => [
                'title' => 'Modul Kasir',
                'description' => 'Penerimaan dan pengeluaran kas operasional harian koperasi.',
                'icon' => 'CreditCard',
            ],
            'laporan' => [
                'title' => 'Modul Laporan',
                'description' => 'Laporan keuangan, laporan neraca, laba rugi, dan rekapitulasi transaksi koperasi.',
                'icon' => 'FileText',
            ],
            'pengaturan' => [
                'title' => 'Modul Pengaturan',
                'description' => 'Konfigurasi sistem, profil koperasi, kebijakan bunga/bunga simpanan, dan akun pengguna.',
                'icon' => 'Settings',
            ],
        ];

        $info = $modules[$module] ?? [
            'title' => 'Modul Koperasi',
            'description' => 'Fasilitas modul bisnis koperasi.',
            'icon' => 'Layers',
        ];

        return Inertia::render('ModulePlaceholder', [
            'module_key' => $module,
            'title' => $info['title'],
            'description' => $info['description'],
            'icon_name' => $info['icon'],
            'status' => 'Segera Hadir',
        ]);
    }
}
