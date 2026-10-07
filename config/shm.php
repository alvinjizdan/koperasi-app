<?php

/**
 * Registri aturan bisnis SHM/SHGB yang BELUM diputuskan KSP.
 *
 * PENTING: file ini adalah daftar keputusan yang MASIH MENGGANTUNG. Tidak ada
 * satu pun nilai di sini yang dipakai sebagai aturan bisnis oleh kode.
 *
 * Workflow yang berjalan saat ini adalah workflow TEKNIS MVP agar sistem dapat
 * dipakai dan didemokan. Workflow itu BUKAN SOP resmi KSP.
 *
 * Ketika KSP memberi keputusan, aturan tersebut harus ditambahkan sebagai
 * validasi dan perhitungan yang nyata, bukan sekadar mengubah teks di sini.
 */
return [

    /*
     * Daftar keputusan yang menunggu. Kunci registry permission di
     * config/permissions.php sengaja dibuat netral supaya tidak mengunci
     * keputusan yang belum diambil.
     */
    'pending_decisions' => [

        'Apakah nasabah SHM wajib menjadi anggota koperasi' => [
            'status' => 'pending',
            'current_implementation' => 'Ticket 46 memisahkan nasabah SHM menjadi master data sendiri (shm_customers). Pengajuan SHM sekarang HANYA merujuk ke shm_customer_id dan tidak lagi membaca tabel members. Nasabah SHM TIDAK dianggap anggota koperasi dan tidak ada konversi otomatis anggota menjadi nasabah SHM.',
            'note' => 'Pemisahan master nasabah sudah diputuskan dan diimplementasikan. Yang MASIH menunggu keputusan KSP adalah apakah seorang nasabah SHM pada akhirnya wajib menjadi anggota koperasi, dan siapa yang berwenang menetapkan hal itu.',
        ],

        'Metode BI Checking' => [
            'status' => 'pending',
            'current_implementation' => 'Tidak ada integrasi BI Checking maupun SLIK, dan tidak ada credential API yang disimpan. Ticket 47 menyediakan workflow manual Pending -> InProgress -> Completed yang dapat diganti integrasi API di kemudian hari.',
        ],

        'Hasil BI Checking dan cara memutuskan kelayakan' => [
            'status' => 'pending',
            'current_implementation' => 'Hasil dicatat manual sebagai PASS, REVIEW, atau FAIL. Menyelesaikan credit checking TIDAK mengubah status pengajuan dan TIDAK menolak otomatis; keputusan tetap di tahap Approval. Tidak ada skor atau ambang angka.',
        ],

        'Siapa yang berhak melakukan approval' => [
            'status' => 'pending',
            'current_implementation' => 'Permission shm.approval.decide saat ini hanya dimiliki admin. Superadmin mengikuti bypass yang sudah ada.',
        ],

        'Multi-level approval' => [
            'status' => 'pending',
            'current_implementation' => 'MVP memakai satu tahap approval.',
        ],

        'Batas kewenangan approval dan apakah ada pagu nominal' => [
            'status' => 'pending',
            'current_implementation' => 'Tidak ada pagu dan tidak ada kolom nominal di tabel pengajuan.',
        ],

        'Daftar dokumen wajib SHM/SHGB' => [
            'status' => 'pending',
            'current_implementation' => 'Checklist dokumen bebas dan dibuat pengguna. Tidak ada daftar legal yang dikunci.',
        ],

        'Aturan SHM versus SHGB' => [
            'status' => 'pending',
            'current_implementation' => 'certificate_type hanya membedakan jenis sertifikat. Tidak ada perbedaan perlakuan.',
        ],

        'Masa berlaku dan batas usia sertifikat' => [
            'status' => 'pending',
            'current_implementation' => 'Tidak ada validasi masa berlaku sertifikat.',
        ],

        'Luas dan batas nilai objek jaminan' => [
            'status' => 'pending',
            'current_implementation' => 'Tidak ada kolom luas maupun nilai objek, sehingga tidak ada batas yang ditegakkan.',
        ],

        'Aturan buyback dan pencairan' => [
            'status' => 'pending',
            'current_implementation' => 'Tidak ada perhitungan buyback maupun engine pembayaran. shm.disbursed hanya mencatat fakta pencairan.',
        ],

        'Aturan pembayaran, potongan pelunasan, dan diskon' => [
            'status' => 'pending',
            'current_implementation' => 'Tidak ada perhitungan keuangan apa pun. Tidak ada posting jurnal otomatis.',
        ],

        'Formula nominal, margin, bunga, dan bagi hasil' => [
            'status' => 'pending',
            'current_implementation' => 'Tidak ada kolom atau perhitungan finansial pada seluruh modul SHM.',
        ],

        'Apakah pengajuan boleh dikembalikan ke tahap sebelumnya' => [
            'status' => 'pending',
            'current_implementation' => 'Hanya status rejected yang boleh kembali ke draft. Transisi lain bersifat satu arah.',
        ],

        'Siapa yang boleh membatalkan atau memperbaiki pengajuan berjalan' => [
            'status' => 'pending',
            'current_implementation' => 'Pembatalan hanya diizinkan sebelum approval. Data dasar hanya dapat diubah saat draft.',
        ],

        'Retention data lokasi dan koordinat' => [
            'status' => 'pending',
            'current_implementation' => 'Tidak ada kebijakan retensi atau penghapusan berkala.',
        ],

        'Siapa yang boleh melihat titik lokasi objek dan nasabah' => [
            'status' => 'pending',
            'current_implementation' => 'Koordinat objek hanya tampil pada halaman detail pengajuan yang sudah diberikan izin akses. Tidak ada endpoint publik.',
        ],

        'Integrasi pengajuan SHM dengan modul pinjaman' => [
            'status' => 'pending',
            'current_implementation' => 'shm_applications tidak terhubung ke tabel loans. Pembiayaan SHM adalah ticket berikutnya.',
        ],

    ],

    /*
     * Daftar jenis sertifikat yang didukung.
     *
     * Ini hanya pembeda teknis jenis dokumen, bukan pernyataan kelayakan dan
     * bukan aturan legal. Menambah nilai di sini tidak otomatis menambah
     * kewajiban dokumen atau persyaratan lain.
     */
    'certificate_types' => [
        'SHM' => 'Sertifikat Hak Milik',
        'SHGB' => 'Sertifikat Hak Guna Bangunan',
    ],

    /*
     * Status proses pada entitas turunan. Semua ini status workflow sederhana
     * yang dapat dikembangkan, bukan status kelayakan kredit atau keuangan.
     */
    /*
     * Status WORKFLOW credit checking (Ticket 47). Ini urutan pengerjaan
     * manual, bukan status kelayakan kredit. Transisi yang sah hanya
     * pending -> in_progress -> completed, dan completed bersifat terminal.
     */
    'credit_checking_statuses' => [
        'pending' => 'Belum dimulai',
        'in_progress' => 'Sedang berjalan',
        'completed' => 'Selesai',
    ],

    /*
     * Hasil pencatatan credit checking.
     *
     * Ini HANYA hasil pencatatan manual petugas, BUKAN penilaian kelayakan,
     * skor, atau keputusan otomatis. Menyelesaikan credit checking tidak
     * menyetujui maupun menolak pengajuan. Apakah REVIEW wajib ditindaklanjuti
     * dan apakah FAIL menolak pengajuan masih PENDING BUSINESS DECISION.
     */
    'credit_checking_outcomes' => [
        'pass' => 'Tidak ditemukan hal yang perlu ditinjau',
        'review' => 'Perlu ditinjau lebih lanjut',
        'fail' => 'Ditemukan hal yang tidak memenuhi',
    ],

    'memorandum_statuses' => [
        'draft' => 'Draft',
        'submitted' => 'Diajukan',
    ],

    'document_statuses' => [
        'pending' => 'Belum lengkap',
        'complete' => 'Lengkap',
        'not_applicable' => 'Tidak berlaku',
    ],

    /*
     * Ticket 53: konteks requirement dokumen (taxonomy foundation).
     *
     * Hari ini sistem TIDAK memiliki daftar dokumen wajib/opsional/kondisional.
     * Satu-satunya sumbu pembeda requirement yang tersedia adalah jenis proses
     * yang tercatat pada memorandum pengajuan (SHM/SHGB vs Buyback), yang
     * merupakan source of truth existing dari Ticket 49 dan di-reuse di
     * ShmDocument::requirementContextFor().
     *
     * Ketika KSP memutuskan daftar dokumen wajib, aturan tersebut harus
     * diimplementasikan sebagai validasi/perhitungan nyata, bukan sekadar
     * mengubah teks konfigurasi ini.
     */
    'document_requirement_context' => [
        'shm_shgb' => [
            'label' => 'SHM/SHGB',
            'status' => 'pending',
            'note' => 'Daftar dokumen wajib untuk SHM/SHGB belum dikonfigurasi. Checklist dokumen saat ini bebas dan dibuat pengguna.',
        ],
        'buyback' => [
            'label' => 'Buyback',
            'status' => 'pending',
            'note' => 'Daftar dokumen wajib untuk Buyback belum dikonfigurasi. Checklist dokumen saat ini bebas dan dibuat pengguna.',
        ],
        'pending' => [
            'label' => 'Belum Dikonfigurasi',
            'status' => 'pending',
            'note' => 'Tidak ada memorandum yang mengidentifikasi jenis proses, sehingga konteks requirement dokumen belum dapat ditetapkan.',
        ],
    ],
];
