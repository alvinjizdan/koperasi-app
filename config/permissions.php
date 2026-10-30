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
                // Ticket 102: hapus permanen nasabah yang BELUM memiliki
                // dependensi rekaman SHM (guard dependency ada di service —
                // nasabah yang sudah dipakai pengajuan/pinjaman/aktivitas
                // ditolak dan tetap dinonaktifkan lewat customer.update).
                // Penghapusan adalah kapasitas destruktif: SENGAJA tidak
                // ditambahkan ke daftar role marketing; role admin memperolehnya
                // lewat all_permissions dan superadmin lewat bypass. Authority
                // bisnis (SIAPA yang berwenang menghapus nasabah) belum
                // diputuskan KSP — PENDING BUSINESS CONFIRMATION, teknikal
                // permission ini tetap configurable via UI Role & Permission.
                'customer.delete' => 'Menghapus nasabah SHM',
                // Ticket 98-FIX: master produk pinjaman SHM (CRUD kategori,
                // deskripsi, syarat, status aktif). Baca tetap shm.view;
                // tulis memakai permission terpisah (pola BUG-01) supaya
                // pemegang shm.view saja TIDAK dapat mengubah master. TIDAK
                // diberikan ke marketing; otoritas bisnis final (siapa yang
                // berwenang mengelola master produk) tetap PENDING BUSINESS
                // CONFIRMATION. Tier rate TIDAK disentuh CRUD ini — tetap
                // seed-driven (T82; ShmProductRateService tetap satu-satunya
                // sumber resolusi rate).
                'product.manage' => 'Mengelola master produk pinjaman SHM',
                // Ticket 101: arsitektur permission Collection yang
                // configurable melalui UI Role & Permission. Menggantikan
                // gate `shm.view` tunggal pada route Collection (T90/T93/
                // T94/T97) dengan permission per-aksi, mengikuti pola
                // payment/settlement (baca ≠ tulis, satu permission per
                // route). Business rules TETAP: ownership pemilik case
                // tetap guard kedua di service; role Collector TIDAK
                // dibuat di sini — Superadmin membuat role sendiri lewat
                // UI dan memberikan permission ini ketika dibutuhkan;
                // matrix detail BM/PD tetap PENDING BUSINESS CONFIRMATION
                // (docs/SHM-BUSINESS-DECISION-PACKAGE.md, pending #16).
                'collection.view' => 'Melihat work queue & detail case Collection',
                'collection.transition' => 'Memutasi status workflow case Collection',
                'collection.activity.create' => 'Mencatat aktivitas follow-up pada case Collection',
                'collection.ptp.create' => 'Mencatat Promise to Pay pada case Collection',
                'collection.ptp.transition' => 'Menandai status PTP (kept/broken) pada case Collection',
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
            'label' => 'Akuntansi Koperasi',
            'actions' => [
                'view' => 'Melihat daftar perkiraan (COA)',
                'accounts.manage' => 'Mengelola daftar perkiraan (COA)',
                'journals.view' => 'Melihat jurnal umum',
                'journals.create' => 'Membuat & mengubah jurnal DRAFT',
                'journals.post' => 'Memposting jurnal ke buku besar',
                'journals.reverse' => 'Mereversi jurnal yang sudah diposting',
                'ledger.view' => 'Melihat buku besar',
                'reports.view' => 'Melihat laporan keuangan (neraca saldo, neraca, laba rugi)',
                // SHM Accounting Foundation actions
                'shm.view' => 'Melihat akuntansi SHM',
                'shm.accounts.manage' => 'Mengelola daftar perkiraan (COA) SHM',
                'shm.journals.view' => 'Melihat jurnal umum SHM',
                'shm.journals.create' => 'Membuat & mengubah jurnal DRAFT SHM',
                'shm.journals.post' => 'Memposting jurnal ke buku besar SHM',
                'shm.journals.reverse' => 'Mereversi jurnal yang sudah diposting SHM',
                'shm.ledger.view' => 'Melihat buku besar SHM',
                'shm.reports.view' => 'Melihat laporan keuangan SHM',
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
        'direktur_utama' => [
            'display_name' => 'President Director (Direktur Utama)',
            'description' => 'Otoritas approval tertinggi pada flow DRAFT → PENDING_APPROVAL → APPROVED/REJECTED.',
            'permissions' => [
                'dashboard.view',
                'shm.view',
                'shm.view_all',
                'shm.approval.decide',
            ],
        ],
        'branch_manager' => [
            'display_name' => 'Branch Manager',
            'description' => 'Pimpinan cabang: monitoring operasional, eskalasi penanganan, dan pengawasan.',
            'permissions' => [
                'dashboard.view',
                'anggota.view',
                'simpanan.view',
                'pinjaman.view',
                'angsuran.view',
                'kasir.view',
                'laporan.view',
                'laporan.export',
                'shm.view',
                'shm.view_all',
                'shm.collection.view',
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
                // Ticket 101: Marketing sebagai owner awal case Collection
                // tetap menjalankan siklus Collection (T90-T99) melalui
                // permission per-aksi yang kini configurable. Ownership
                // pemilik case tetap berlaku di service — permission tidak
                // membuka case milik Marketing lain.
                'shm.collection.view',
                'shm.collection.transition',
                'shm.collection.activity.create',
                'shm.collection.ptp.create',
                'shm.collection.ptp.transition',
            ],
        ],
    ],
];
