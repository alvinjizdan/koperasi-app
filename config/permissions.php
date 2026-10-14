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
                'reverse' => 'Membatalkan transaksi simpanan melalui reversal',
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
                'reverse' => 'Membatalkan pembayaran angsuran melalui reversal',
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

        'marketing' => [
            'label' => 'Marketing',
            'actions' => [
                'view' => 'Melihat aktivitas kunjungan sendiri',
                'create' => 'Melakukan check-in kunjungan dan mencatat aktivitas',
                'result.update' => 'Mengubah hasil dan catatan kunjungan sendiri',
                'monitoring.view' => 'Memantau seluruh aktivitas Marketing',
                'member_location.view' => 'Melihat titik lokasi anggota',
                'member_location.update' => 'Memperbarui titik lokasi anggota',
            ],
        ],

        // Ticket 44: modul SHM/SHGB.
        //
        // Permission di sini hanya mengatur TEKNIS siapa yang boleh melakukan
        // apa pada lifecycle pengajuan. Permission ini BUKAN keputusan bisnis:
        // batas kewenangan approval, kelengkapan dokumen, dan panduan cara
        // approval masih PENDING BUSINESS DECISION dari KSP (lihat
        // config/shm.php dan docs/SHM-BUSINESS-DECISION-PACKAGE.md).
        'shm' => [
            'label' => 'SHM/SHGB',
            'actions' => [
                'view' => 'Melihat daftar pengajuan SHM yang tersedia',
                'view_all' => 'Melihat seluruh pengajuan SHM milik semua Marketing',
                'create' => 'Membuat pengajuan SHM atas nama diri sendiri',
                'update' => 'Mengubah data pengajuan SHM yang masih draft',
                'submit' => 'Mengirim pengajuan SHM dari draft ke tahap berikutnya',
                'property.manage' => 'Mengelola data objek SHM/SHGB',
                // Ticket 47: memisahkan wewenang MELIHAT proses/hasil credit
                // checking dari wewenang MENGERJAKANNYA. Mulai, mengubah, dan
                // menyelesaikan checking memakai permission update yang sama
                // karena KSP belum memutuskan pemisahan kewenangan tersebut.
                'credit_checking.view' => 'Melihat proses & hasil credit checking pengajuan',
                'credit_checking.update' => 'Mengerjakan & menyelesaikan credit checking pengajuan',
                'memorandum.update' => 'Mencatat memorandum pengajuan',
                'approval.decide' => 'Mengambil keputusan approval atau penolakan',
                'document.update' => 'Mencatat kelengkapan dokumen pengajuan',
                'disburse' => 'Menandai pengajuan sebagai dicairkan',
                'complete' => 'Menandai pengajuan sebagai selesai',
                // Ticket 86: mencatat transaksi pembayaran pinjaman SHM
                // terpisah dari hak MELIHAT (shm.view) mengikuti pola BUG-01
                // koperasi (angsuran.view vs angsuran.record_payment).
                // SIAPA yang berwenang mencatat pembayaran SHM masih PENDING
                // BUSINESS CONFIRMATION — karena itu permission ini TIDAK
                // ditambahkan ke daftar role marketing di bawah; role admin
                // memperolehnya lewat all_permissions dan superadmin lewat
                // bypass.
                'payment.create' => 'Mencatat pembayaran pinjaman SHM',
                // Ticket 89: konfirmasi pelunasan (settlement) TERPISAH dari
                // hak MELIHAT (shm.view) dan dari `shm.payment.create` —
                // settlement adalah mutasi finansial yang menutup pinjaman,
                // bukan pencatatan pembayaran. Pola BUG-01 (baca ≠ tulis).
                // SIAPA yang berwenang mengonfirmasi pelunasan masih PENDING
                // BUSINESS CONFIRMATION — karena itu permission ini TIDAK
                // ditambahkan ke daftar role marketing; role admin memperolehnya
                // lewat all_permissions dan superadmin lewat bypass.
                'settlement.create' => 'Mengonfirmasi pelunasan (settlement) pinjaman SHM',
                // Ticket 89B: master policy diskon bunga pelunasan (data-driven).
                // Permission teknis mengikuti pola existing; TIDAK diberikan ke
                // marketing dan role authority bisnis tetap PENDING.
                'settlement.policy.manage' => 'Mengelola policy diskon bunga pelunasan SHM',
                // Ticket 46: master data Nasabah SHM.
                //
                // Nasabah SHM adalah entity tersendiri, terpisah dari anggota
                // koperasi. Permission ini hanya mengatur TEKNIS siapa yang
                // boleh mengelola master nasabah, bukan aturan bisnis siapa
                // yang boleh menjadi nasabah SHM.
                'customer.view' => 'Melihat daftar & detail nasabah SHM',
                'customer.create' => 'Menambah nasabah SHM',
                'customer.update' => 'Mengubah data & status nasabah SHM',
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

        'accounting' => [
            'label' => 'Akuntansi',
            'actions' => [
                'view' => 'Melihat daftar perkiraan (COA)',
                'accounts.manage' => 'Mengelola daftar perkiraan (COA)',
                'journals.view' => 'Melihat jurnal umum',
                'journals.create' => 'Membuat & mengubah jurnal DRAFT',
                'journals.post' => 'Memposting jurnal ke buku besar',
                'journals.reverse' => 'Mereversi jurnal yang sudah diposting',
                'ledger.view' => 'Melihat buku besar',
                'reports.view' => 'Melihat laporan keuangan (neraca saldo, neraca, laba rugi)',
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
    | Role sistem yang tidak lagi ada di daftar ini dinonaktifkan oleh seeder
    | (is_active=false), bukan dihapus, agar data historis tetap utuh.
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
        // Marketing menggantikan Petugas Simpanan & Petugas Pinjaman sebagai role
        // operasional tunggal di garis depan.
        //
        // Hak akses dibatasi pada lingkup yang dinyatakan tiket:
        // mencatat dan melihat aktivitas kunjungan sendiri, serta melihat titik
        // lokasi anggota sebagai informasi pendukung kunjungan.
        //
        // SENGAJA TIDAK diberikan: marketing.monitoring.view (memantau Marketing
        // lain), marketing.member_location.update, dan seluruh authority tulis
        // modul lain. Siapa yang boleh memantau dan siapa yang boleh mengubah
        // lokasi anggota masih PENDING BUSINESS DECISION (lihat
        // docs/SHM-BUSINESS-DECISION-PACKAGE.md). Jangan menambah permission tanpa
        // dasar keputusan tersebut.
        'marketing' => [
            'display_name' => 'Marketing',
            'description' => 'Aktivitas kunjungan nasabah: check-in, catatan hasil, dan titik lokasi anggota.',
            'permissions' => [
                'dashboard.view',
                'anggota.view',
                'pinjaman.view',
                'angsuran.view',
                'marketing.view',
                'marketing.create',
                'marketing.result.update',
                'marketing.member_location.view',
                // Ticket 44: Marketing membuat dan menjalankan pengajuan SHM
                // miliknya sendiri. Marketing SENGAJA TIDAK diberi
                // shm.view_all, shm.approval.decide, shm.disburse, atau
                // shm.complete karena batas kewenangan approval dan
                // pencairan belum diputuskan KSP.
                'shm.view',
                'shm.create',
                'shm.update',
                'shm.submit',
                'shm.property.manage',
                'shm.credit_checking.view',
                'shm.credit_checking.update',
                'shm.memorandum.update',
                'shm.document.update',
                // Ticket 46: Marketing membuat & memilih nasabah SHM saat
                // membuat pengajuan. Master nasabah bukan anggota koperasi,
                // sehingga hak ini tidak menyentuh data keanggotaan.
                'shm.customer.view',
                'shm.customer.create',
                'shm.customer.update',
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
