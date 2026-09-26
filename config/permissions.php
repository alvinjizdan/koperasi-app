<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registry Permission
    |--------------------------------------------------------------------------
    |
    | Master daftar permission aplikasi, dikelompokkan per modul. Seeder
    | menyinkronkan tabel permissions dengan daftar ini. Nama permission
    | memakai format {module}.{action} dan dipakai pada Gate serta middleware.
    |
    | Tambahkan permission baru di sini terlebih dahulu, lalu jalankan
    | `php artisan db:seed --class=PermissionSeeder`.
    |
    */

    'modules' => [
        'dashboard' => [
            'label' => 'Dashboard',
            'actions' => [
                'view' => 'Melihat dashboard',
            ],
        ],

        'anggota' => [
            'label' => 'Anggota',
            'actions' => [
                'view' => 'Melihat daftar & detail anggota',
                'create' => 'Mendaftarkan anggota baru',
                'update' => 'Mengubah data anggota',
                'deactivate' => 'Menonaktifkan anggota',
            ],
        ],

        'simpanan' => [
            'label' => 'Simpanan',
            'actions' => [
                'view' => 'Melihat rekening & transaksi simpanan',
                'create' => 'Membuka rekening & mengelola produk',
                'deposit' => 'Mencatat setoran simpanan',
                'withdraw' => 'Mencatat penarikan simpanan',
            ],
        ],

        'pinjaman' => [
            'label' => 'Pinjaman',
            'actions' => [
                'view' => 'Melihat pinjaman',
                'create' => 'Mencatat pengajuan pinjaman',
                'approve' => 'Menyetujui pengajuan pinjaman',
                'reject' => 'Menolak pengajuan pinjaman',
                'disburse' => 'Mencairkan pinjaman',
            ],
        ],

        'angsuran' => [
            'label' => 'Angsuran',
            'actions' => [
                'view' => 'Melihat jadwal & pembayaran angsuran',
                'record_payment' => 'Mencatat pembayaran angsuran',
            ],
        ],

        'kasir' => [
            'label' => 'Kasir',
            'actions' => [
                'view' => 'Melihat kasir & transaksi kas',
                'open_session' => 'Membuka sesi kasir',
                'receive' => 'Mencatat penerimaan kas',
                'disburse' => 'Mencatat pengeluaran kas',
                'deposit' => 'Mencatat setoran kasir',
                'close_session' => 'Menutup sesi kasir',
                'reverse' => 'Membatalkan transaksi kas',
            ],
        ],

        'laporan' => [
            'label' => 'Laporan',
            'actions' => [
                'view' => 'Melihat laporan',
                'export' => 'Mengekspor laporan ke CSV',
            ],
        ],

        'pengguna' => [
            'label' => 'Pengguna',
            'actions' => [
                'view' => 'Melihat daftar & detail pengguna',
                'create' => 'Menambah pengguna',
                'update' => 'Mengubah data pengguna',
                'deactivate' => 'Mengaktifkan/menonaktifkan akun',
                'reset_password' => 'Mereset password pengguna',
                'assign_role' => 'Menetapkan role pengguna',
            ],
        ],

        'role' => [
            'label' => 'Role',
            'actions' => [
                'view' => 'Melihat role & permission',
                'create' => 'Menambah role',
                'update' => 'Mengubah role & permission-nya',
                'delete' => 'Menghapus role',
            ],
        ],

        'pengaturan' => [
            'label' => 'Pengaturan',
            'actions' => [
                'view' => 'Melihat pengaturan',
                'update' => 'Mengubah pengaturan',
            ],
        ],

        'audit' => [
            'label' => 'Audit Trail',
            'actions' => [
                'view' => 'Melihat audit trail',
            ],
        ],

        'rekonsiliasi' => [
            'label' => 'Rekonsiliasi',
            'actions' => [
                'view' => 'Melihat hasil rekonsiliasi & temuan',
                'run' => 'Menjalankan proses rekonsiliasi',
                'resolve' => 'Menindaklanjuti temuan rekonsiliasi',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Bawaan
    |--------------------------------------------------------------------------
    |
    | Role awal yang disemai oleh seeder. Superadmin selalu memperoleh seluruh
    | permission melalui mekanisme bypass. Daftar ini bukan struktur final;
    | role tambahan dapat dibuat dari halaman Pengaturan > Role.
    |
    */

    'default_roles' => [
        'admin' => [
            'display_name' => 'Admin',
            'description' => 'Pengelola sistem: pengguna, role, dan pengaturan.',
            // null = semua permission kecuali yang dikeluarkan.
            'all_permissions' => true,
            'except' => [],
        ],
        'kasir' => [
            'display_name' => 'Kasir',
            'description' => 'Operasional kas harian: sesi kasir, penerimaan, pengeluaran, setoran.',
            'permissions' => [
                'dashboard.view',
                'anggota.view',
                'simpanan.view',
                'simpanan.deposit',
                'simpanan.withdraw',
                'angsuran.view',
                'angsuran.record_payment',
                'kasir.view',
                'kasir.open_session',
                'kasir.receive',
                'kasir.disburse',
                'kasir.deposit',
                'kasir.close_session',
                'laporan.view',
            ],
        ],
        'petugas_simpanan' => [
            'display_name' => 'Petugas Simpanan',
            'description' => 'Pengelolaan rekening, produk, dan mutasi simpanan.',
            'permissions' => [
                'dashboard.view',
                'anggota.view',
                'simpanan.view',
                'simpanan.create',
                'simpanan.deposit',
                'simpanan.withdraw',
                'laporan.view',
            ],
        ],
        'petugas_pinjaman' => [
            'display_name' => 'Petugas Pinjaman',
            'description' => 'Pengajuan, persetujuan, pencairan pinjaman, dan pencatatan angsuran.',
            'permissions' => [
                'dashboard.view',
                'anggota.view',
                'pinjaman.view',
                'pinjaman.create',
                'pinjaman.approve',
                'pinjaman.reject',
                'pinjaman.disburse',
                'angsuran.view',
                'angsuran.record_payment',
                'laporan.view',
            ],
        ],
        'pengawas' => [
            'display_name' => 'Pengawas / Auditor',
            'description' => 'Akses baca untuk pengawasan dan audit.',
            'permissions' => [
                'dashboard.view',
                'anggota.view',
                'simpanan.view',
                'pinjaman.view',
                'angsuran.view',
                'kasir.view',
                'laporan.view',
                'laporan.export',
                'audit.view',
            ],
        ],
    ],
];
