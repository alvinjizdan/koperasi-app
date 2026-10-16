<?php

use App\Http\Controllers\Accounting\AccountController;
use App\Http\Controllers\Accounting\FinancialReportController;
use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Accounting\LedgerController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CashierController;
use App\Http\Controllers\CashSummaryController;
use App\Http\Controllers\CashTransactionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstallmentController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\Marketing\MarketingActivityController;
use App\Http\Controllers\Marketing\MemberLocationController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SavingProductController;
use App\Http\Controllers\SavingsAccountController;
use App\Http\Controllers\SavingsTransactionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Shm\ShmApplicationController;
use App\Http\Controllers\Shm\ShmApprovalController;
use App\Http\Controllers\Shm\ShmCollectionActivityController;
use App\Http\Controllers\Shm\ShmCollectionController;
use App\Http\Controllers\Shm\ShmCollectionPtpController;
use App\Http\Controllers\Shm\ShmCreditCheckController;
use App\Http\Controllers\Shm\ShmCustomerController;
use App\Http\Controllers\Shm\ShmDashboardController;
use App\Http\Controllers\Shm\ShmDocumentsController;
use App\Http\Controllers\Shm\ShmEntryController;
use App\Http\Controllers\Shm\ShmExportController;
use App\Http\Controllers\Shm\ShmLoanController;
use App\Http\Controllers\Shm\ShmLoanPaymentController;
use App\Http\Controllers\Shm\ShmLoanSettlementController;
use App\Http\Controllers\Shm\ShmLoginController;
use App\Http\Controllers\Shm\ShmMemorandumController;
use App\Http\Controllers\Shm\ShmSettlementDiscountPolicyController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Halaman awal: pengguna terautentikasi menuju dashboard, sisanya ke halaman login.
Route::get('/', function (Request $request) {
    return $request->user()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::middleware(['auth', 'verified', 'active.account'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Modul Anggota
    Route::middleware('permission:anggota.view')->group(function () {
        Route::get('/anggota', [MemberController::class, 'index'])->name('anggota.index');
        Route::get('/anggota/create', [MemberController::class, 'create'])->name('anggota.create');
        Route::get('/anggota/{member}', [MemberController::class, 'show'])->name('anggota.show');
    });
    Route::post('/anggota', [MemberController::class, 'store'])->middleware('permission:anggota.create')->name('anggota.store');
    Route::get('/anggota/{member}/edit', [MemberController::class, 'edit'])->middleware('permission:anggota.update')->name('anggota.edit');
    Route::put('/anggota/{member}', [MemberController::class, 'update'])->middleware('permission:anggota.update')->name('anggota.update');
    Route::patch('/anggota/{member}/deactivate', [MemberController::class, 'deactivate'])->middleware('permission:anggota.deactivate')->name('anggota.deactivate');

    // Modul Simpanan: halaman indeks utama (Ticket 17 BUG-01: dibungkus permission).
    Route::get('/simpanan', function () {
        return Inertia::render('Simpanan/Index');
    })->middleware('permission:simpanan.view')->name('simpanan.index');

    // Produk Simpanan: baca = simpanan.view, tulis = simpanan.create (BUG-01).
    Route::middleware('permission:simpanan.view')->group(function () {
        Route::get('/simpanan/produk', [SavingProductController::class, 'index'])->name('simpanan-produk.index');
        Route::get('/simpanan/produk/create', [SavingProductController::class, 'create'])->name('simpanan-produk.create');
        Route::get('/simpanan/produk/{saving_product}/edit', [SavingProductController::class, 'edit'])->name('simpanan-produk.edit');
    });
    Route::post('/simpanan/produk', [SavingProductController::class, 'store'])->middleware('permission:simpanan.create')->name('simpanan-produk.store');
    Route::put('/simpanan/produk/{saving_product}', [SavingProductController::class, 'update'])->middleware('permission:simpanan.create')->name('simpanan-produk.update');
    Route::patch('/simpanan/produk/{saving_product}/deactivate', [SavingProductController::class, 'deactivate'])
        ->middleware('permission:simpanan.create')
        ->name('simpanan-produk.deactivate');

    // Rekening Simpanan: baca = simpanan.view, buka rekening = simpanan.create (BUG-01).
    // Route literal (create) didaftarkan sebelum route parameter ({savingsAccount})
    // agar tidak tertelan wildcard (regresi Ticket 17).
    Route::get('/simpanan/rekening/create', [SavingsAccountController::class, 'create'])->middleware('permission:simpanan.create')->name('simpanan-rekening.create');
    Route::post('/simpanan/rekening', [SavingsAccountController::class, 'store'])->middleware('permission:simpanan.create')->name('simpanan-rekening.store');
    Route::middleware('permission:simpanan.view')->group(function () {
        Route::get('/simpanan/rekening', [SavingsAccountController::class, 'index'])->name('simpanan-rekening.index');
        Route::get('/simpanan/rekening/{savingsAccount}', [SavingsAccountController::class, 'show'])->name('simpanan-rekening.show');
        Route::get('/simpanan/rekening/{savingsAccount}/setor', [SavingsTransactionController::class, 'createDeposit'])
            ->name('simpanan-transaksi.deposit.create');

        Route::get('/simpanan/rekening/{savingsAccount}/tarik', [SavingsTransactionController::class, 'createWithdraw'])
            ->name('simpanan-transaksi.withdraw.create');

        Route::get('/simpanan/transaksi/{savingsTransaction}', [SavingsTransactionController::class, 'show'])
            ->name('simpanan-transaksi.show');
    });
    Route::post('/simpanan/rekening/{savingsAccount}/setor', [SavingsTransactionController::class, 'storeDeposit'])
        ->middleware('permission:simpanan.deposit')
        ->name('simpanan-transaksi.deposit.store');

    Route::post('/simpanan/rekening/{savingsAccount}/tarik', [SavingsTransactionController::class, 'storeWithdraw'])
        ->middleware('permission:simpanan.withdraw')
        ->name('simpanan-transaksi.withdraw.store');

    // Koreksi transaksi simpanan melalui reversal teraudit (Ticket 15).
    Route::post('/simpanan/transaksi/{savingsTransaction}/reversal', [SavingsTransactionController::class, 'reverse'])
        ->middleware('permission:simpanan.reverse')
        ->name('simpanan-transaksi.reverse');

    // Modul Pinjaman: baca = pinjaman.view, tulis = permission aksi masing-masing (BUG-01).
    Route::middleware('permission:pinjaman.view')->group(function () {
        Route::get('/pinjaman', [LoanController::class, 'index'])->name('pinjaman.index');
        Route::get('/pinjaman/create', [LoanController::class, 'create'])->name('pinjaman.create');
        Route::get('/pinjaman/{loan}', [LoanController::class, 'show'])->name('pinjaman.show');
        Route::get('/pinjaman/{loan}/disburse', [LoanController::class, 'disburseForm'])->name('pinjaman.disburse.create');
    });
    Route::post('/pinjaman', [LoanController::class, 'store'])->middleware('permission:pinjaman.create')->name('pinjaman.store');
    Route::post('/pinjaman/{loan}/approve', [LoanController::class, 'approve'])->middleware('permission:pinjaman.approve')->name('pinjaman.approve');
    Route::post('/pinjaman/{loan}/reject', [LoanController::class, 'reject'])->middleware('permission:pinjaman.reject')->name('pinjaman.reject');
    Route::post('/pinjaman/{loan}/disburse', [LoanController::class, 'disburse'])->middleware('permission:pinjaman.disburse')->name('pinjaman.disburse.store');

    // Modul Angsuran: baca = angsuran.view, catat pembayaran = angsuran.record_payment (BUG-01).
    Route::middleware('permission:angsuran.view')->group(function () {
        Route::get('/angsuran', [InstallmentController::class, 'index'])->name('angsuran.index');
        Route::get('/angsuran/pinjaman/{loan}', [InstallmentController::class, 'showSchedule'])->name('angsuran.schedule');
        Route::get('/angsuran/pinjaman/{loan}/bayar', [InstallmentController::class, 'paymentForm'])->name('angsuran.pay.create');
        Route::get('/angsuran/pembayaran/{payment}', [InstallmentController::class, 'showPayment'])->name('angsuran.payment.show');
    });
    Route::post('/angsuran/pinjaman/{loan}/bayar', [InstallmentController::class, 'processPayment'])->middleware('permission:angsuran.record_payment')->name('angsuran.pay.store');

    // Ticket 21: reversal pembayaran angsuran — permission terpisah agar
    // tidak setiap pencatat angsuran dapat mengoreksi transaksi finansial.
    Route::post('/angsuran/pembayaran/{payment}/reversal', [InstallmentController::class, 'reversePayment'])
        ->middleware('permission:angsuran.reverse')
        ->name('angsuran.payment.reverse');

    // Modul Kasir & Transaksi Kas: baca = kasir.view, tulis = permission aksi (BUG-01).
    Route::prefix('kasir')->name('kasir.')->group(function () {
        // Sesi kasir. Pembukaan/penutupan memerlukan permission eksplisit di
        // backend; frontend hanya menyembunyikan tombol, bukan pengganti otorisasi.
        // Route literal (buka/tutup) didaftarkan sebelum route parameter ({session}).
        Route::get('/sesi/buka', [CashierController::class, 'createOpen'])->middleware('permission:kasir.open_session')->name('sesi.create');
        Route::post('/sesi/buka', [CashierController::class, 'storeOpen'])->middleware('permission:kasir.open_session')->name('sesi.store');
        Route::get('/sesi/{session}/tutup', [CashierController::class, 'createClose'])->middleware('permission:kasir.close_session')->name('sesi.close.create');
        Route::post('/sesi/{session}/tutup', [CashierController::class, 'storeClose'])->middleware('permission:kasir.close_session')->name('sesi.close.store');

        Route::middleware('permission:kasir.view')->group(function () {
            Route::get('/', [CashierController::class, 'index'])->name('index');

            Route::get('/sesi', [CashierController::class, 'sessions'])->name('sesi.index');
            Route::get('/sesi/{session}', [CashierController::class, 'show'])->name('sesi.show');

            // Transaksi kas
            Route::get('/transaksi', [CashTransactionController::class, 'index'])->name('transaksi.index');
            Route::get('/transaksi/penerimaan', [CashTransactionController::class, 'createIncome'])->name('penerimaan.create');
            Route::get('/transaksi/pengeluaran', [CashTransactionController::class, 'createExpense'])->name('pengeluaran.create');
            Route::get('/transaksi/setoran', [CashTransactionController::class, 'createDeposit'])->name('setoran.create');
            Route::get('/transaksi/{transaction}', [CashTransactionController::class, 'show'])->name('transaksi.show');

            // Ringkasan kas harian
            Route::get('/ringkasan', [CashSummaryController::class, 'index'])->name('ringkasan.index');
        });

        Route::post('/transaksi/penerimaan', [CashTransactionController::class, 'storeIncome'])->middleware('permission:kasir.receive')->name('penerimaan.store');
        Route::post('/transaksi/pengeluaran', [CashTransactionController::class, 'storeExpense'])->middleware('permission:kasir.disburse')->name('pengeluaran.store');
        Route::post('/transaksi/setoran', [CashTransactionController::class, 'storeDeposit'])->middleware('permission:kasir.deposit')->name('setoran.store');
        Route::post('/transaksi/{transaction}/koreksi', [CashTransactionController::class, 'reverse'])->middleware('permission:kasir.reverse')->name('transaksi.reverse');
    });

    // Modul Akuntansi (Ticket 18): COA, jurnal umum, buku besar.
    // Semua route ber-middleware permission sesuai aksinya (pola BUG-01).
    Route::prefix('akuntansi')->name('akuntansi.')->group(function () {
        Route::middleware('permission:accounting.view')->group(function () {
            Route::get('/coa', [AccountController::class, 'index'])->name('coa.index');
        });
        Route::get('/coa/create', [AccountController::class, 'create'])->middleware('permission:accounting.accounts.manage')->name('coa.create');
        Route::post('/coa', [AccountController::class, 'store'])->middleware('permission:accounting.accounts.manage')->name('coa.store');
        Route::get('/coa/{account}/edit', [AccountController::class, 'edit'])->middleware('permission:accounting.accounts.manage')->name('coa.edit');
        Route::put('/coa/{account}', [AccountController::class, 'update'])->middleware('permission:accounting.accounts.manage')->name('coa.update');
        Route::patch('/coa/{account}/deactivate', [AccountController::class, 'deactivate'])->middleware('permission:accounting.accounts.manage')->name('coa.deactivate');

        Route::middleware('permission:accounting.journals.view')->group(function () {
            Route::get('/jurnal', [JournalController::class, 'index'])->name('jurnal.index');
            Route::get('/jurnal/{entry}', [JournalController::class, 'show'])->name('jurnal.show');
        });
        Route::get('/jurnal-baru', [JournalController::class, 'create'])->middleware('permission:accounting.journals.create')->name('jurnal.create');
        Route::post('/jurnal', [JournalController::class, 'store'])->middleware('permission:accounting.journals.create')->name('jurnal.store');
        Route::put('/jurnal/{entry}', [JournalController::class, 'update'])->middleware('permission:accounting.journals.create')->name('jurnal.update');
        Route::post('/jurnal/{entry}/post', [JournalController::class, 'post'])->middleware('permission:accounting.journals.post')->name('jurnal.post');
        Route::post('/jurnal/{entry}/reversal', [JournalController::class, 'reverse'])->middleware('permission:accounting.journals.reverse')->name('jurnal.reverse');

        Route::get('/buku-besar', [LedgerController::class, 'index'])->middleware('permission:accounting.ledger.view')->name('buku-besar.index');

        // Laporan keuangan (Ticket 19): neraca saldo, neraca, laba rugi, status arus kas.
        Route::middleware('permission:accounting.reports.view')->prefix('laporan-keuangan')->name('laporan-keuangan.')->group(function () {
            Route::get('/neraca-saldo', [FinancialReportController::class, 'trialBalance'])->name('neraca-saldo');
            Route::get('/neraca', [FinancialReportController::class, 'balanceSheet'])->name('neraca');
            Route::get('/laba-rugi', [FinancialReportController::class, 'incomeStatement'])->name('laba-rugi');
            Route::get('/arus-kas', [FinancialReportController::class, 'cashFlow'])->name('arus-kas');
        });
    });

    // Modul Laporan Dasar
    Route::prefix('laporan')->name('laporan.')->middleware('permission:laporan.view')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/anggota', [ReportController::class, 'members'])->name('anggota');
        Route::get('/simpanan', [ReportController::class, 'savings'])->name('simpanan');
        Route::get('/pinjaman', [ReportController::class, 'loans'])->name('pinjaman');
        Route::get('/angsuran', [ReportController::class, 'installments'])->name('angsuran');
        Route::get('/tunggakan', [ReportController::class, 'overdue'])->name('tunggakan');

        // Ticket 26: Posisi Pinjaman per Tanggal — read-only dari snapshot
        // tersimpan; tidak ada mutasi finansial dan tidak ada akrual penalti.
        Route::get('/posisi-pinjaman', [ReportController::class, 'loanPositions'])->name('posisi-pinjaman');

        // Ticket 27: ekspor CSV posisi pinjaman — permission ekspor terpisah
        // dari view; streaming read-only dari snapshot tersimpan.
        Route::get('/posisi-pinjaman/export', [ReportController::class, 'exportLoanPositions'])
            ->middleware('permission:laporan.export')
            ->name('posisi-pinjaman.export');

        Route::get('/transaksi-kas', [ReportController::class, 'cashTransactions'])->name('transaksi-kas');
        Route::get('/kas-harian', [ReportController::class, 'cashDaily'])->name('kas-harian');

        // Ekspor CSV mengikuti filter aktif. Hanya laporan dasar yang diekspor.
        Route::get('/{report}/export', [ReportExportController::class, 'export'])
            ->middleware('permission:laporan.export')
            ->where('report', 'anggota|simpanan|pinjaman|angsuran|tunggakan|transaksi-kas|kas-harian')
            ->name('export');
    });

    // Modul Marketing: hanya titik lokasi anggota yang tetap di sini.
    //
    // Ticket 63: aktivitas/kunjungan Marketing (activities.index, store, show,
    // result.update, monitoring.index) dipindahkan ke dalam boundary SHM di
    // /shm/marketing dengan nama route shm.marketing.* — fungsi tersebut
    // berelasi dengan data SHM (shm_applications.marketing_activity_id) dan
    // ownership marketing_id yang sama dengan konteks SHM. Yang tersisa di
    // koperasi hanya pembaruan lokasi anggota dari halaman detail anggota,
    // yaitu fungsi koperasi-spesifik (KOPERASI-SPECIFIC).
    Route::prefix('marketing')->name('marketing.')->group(function () {
        // Titik lokasi anggota: hak akses terpisah dari hak akses anggota.
        Route::put('/anggota/{member}/lokasi', [MemberLocationController::class, 'update'])
            ->middleware('permission:marketing.member_location.update')
            ->name('member-location.update');
    });

});

// =====================================================================
// Aplikasi SHM sebagai shell tersendiri (Ticket 48).
//
// `/shm` adalah boundary UI dan otorisasi di DALAM aplikasi Laravel yang sama:
// satu database, satu deployment, satu sistem autentikasi (guard `web` dan
// session yang sama), serta satu audit trail. TIDAK ada project, database,
// microservice, API internal, atau session kedua.
//
// Struktur pintu masuk:
//   GET  /shm           -> pintu masuk: tamu ke /shm/login, sisanya ke dashboard
//   GET  /shm/login     -> halaman masuk ber-branding SHM
//   POST /shm/login     -> memakai controller login yang SAMA (context=shm)
//   GET  /shm/dashboard -> dashboard shell SHM
//
// Seluruh halaman SHM tetap memerlukan login, akun aktif, email terverifikasi,
// dan permission SHM masing-masing seperti sebelumnya. Halaman modul SHM
// didaftarkan di bawah ini dan tidak lagi berada di dalam grup modul koperasi,
// tetapi middleware-nya identik sehingga otorisasi tidak berubah.
// =====================================================================
Route::prefix('shm')->name('shm.')->group(function () {
    // Route ini sengaja TANPA middleware auth supaya tamu diarahkan ke pintu
    // masuk SHM (bukan halaman login koperasi) dan pengguna yang sudah login
    // tanpa akses SHM memperoleh 403 yang jelas, bukan sekadar dipentalikan.
    Route::get('/', ShmEntryController::class)->name('entry');

    Route::middleware('guest')->group(function () {
        Route::get('/login', [ShmLoginController::class, 'create'])->name('login');

        // Otentikasi tetap milik AuthenticatedSessionController: tidak ada
        // controller login kedua, hanya tujuan bawaan setelah masuk yang
        // dibedakan lewat input `context` pada formulir SHM.
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    });

    Route::middleware(['auth', 'verified', 'active.account'])->group(function () {
        // Dashboard shell SHM. Batas akses memakai permission `shm.view` yang
        // sudah ada; tidak ada permission `shm.access` baru.
        Route::get('/dashboard', ShmDashboardController::class)
            ->middleware('permission:shm.view')
            ->name('dashboard');

        // -----------------------------------------------------------------
        // Modul SHM/SHGB (Ticket 44).
        //
        // Workflow di sini adalah workflow TEKNIS MVP agar sistem dapat dipakai
        // dan didemokan. Bukan SOP resmi KSP.
        //
        // Route literal didaftarkan sebelum route parameter agar tidak tertelan
        // wildcard. Setiap endpoint transisi dipisah per tujuan supaya arah
        // transisi tidak pernah berasal dari request klien.
        // -----------------------------------------------------------------

        // Pengajuan SHM. Route literal (/pengajuan/create) didaftarkan SEBELUM
        // route parameter (/pengajuan/{application}) agar tidak tertelan wildcard.
        Route::get('/pengajuan/create', [ShmApplicationController::class, 'create'])
            ->middleware('permission:shm.create')
            ->name('applications.create');

        Route::middleware('permission:shm.view')->group(function () {
            Route::get('/pengajuan', [ShmApplicationController::class, 'index'])->name('applications.index');
            Route::get('/pengajuan/{application}', [ShmApplicationController::class, 'show'])
                ->name('applications.show');
        });

        Route::post('/pengajuan', [ShmApplicationController::class, 'store'])
            ->middleware('permission:shm.create')
            ->name('applications.store');

        Route::put('/pengajuan/{application}', [ShmApplicationController::class, 'update'])
            ->middleware('permission:shm.update')
            ->name('applications.update');

        Route::get('/pengajuan/{application}/edit', [ShmApplicationController::class, 'edit'])
            ->middleware('permission:shm.update')
            ->name('applications.edit');

        // Transisi tahap. Setiap tujuan punya endpoint sendiri.
        Route::post('/pengajuan/{application}/submit', [ShmApplicationController::class, 'submit'])
            ->middleware('permission:shm.submit')
            ->name('applications.submit');

        Route::post('/pengajuan/{application}/setujui', [ShmApplicationController::class, 'approve'])
            ->middleware('permission:shm.approval.decide')
            ->name('applications.approve');

        Route::post('/pengajuan/{application}/tolak', [ShmApplicationController::class, 'reject'])
            ->middleware('permission:shm.approval.decide')
            ->name('applications.reject');

        Route::post('/pengajuan/{application}/cairkan', [ShmApplicationController::class, 'disburse'])
            ->middleware('permission:shm.disburse')
            ->name('applications.disburse');

        Route::post('/pengajuan/{application}/selesai', [ShmApplicationController::class, 'complete'])
            ->middleware('permission:shm.complete')
            ->name('applications.complete');

        Route::post('/pengajuan/{application}/batalkan', [ShmApplicationController::class, 'cancel'])
            ->middleware('permission:shm.submit')
            ->name('applications.cancel');

        // Transisi antar tahap kerja (Ticket 45).
        // Setiap endpoint punya permission sendiri dan hanya boleh dijalankan oleh
        // pemilik pengajuan. Arah transisi ditentukan server dan tidak dapat
        // dipalsukan lewat payload.
        Route::post('/pengajuan/{application}/credit-checking/lanjut', [ShmApplicationController::class, 'startCreditChecking'])
            ->middleware('permission:shm.submit')
            ->name('applications.start-credit-checking');

        Route::post('/pengajuan/{application}/memorandum/lanjut', [ShmApplicationController::class, 'startMemorandum'])
            ->middleware('permission:shm.memorandum.update')
            ->name('applications.start-memorandum');

        Route::post('/pengajuan/{application}/approval/lanjut', [ShmApplicationController::class, 'startApproval'])
            ->middleware('permission:shm.memorandum.update')
            ->name('applications.start-approval');

        Route::post('/pengajuan/{application}/dokumen/lanjut', [ShmApplicationController::class, 'startDocumentCheck'])
            ->middleware('permission:shm.document.update')
            ->name('applications.start-document-check');

        Route::post('/pengajuan/{application}/siap-cair', [ShmApplicationController::class, 'markReadyForDisbursement'])
            ->middleware('permission:shm.document.update')
            ->name('applications.mark-ready-for-disbursement');

        Route::post('/pengajuan/{application}/buka-kembali-dokumen', [ShmApplicationController::class, 'reopenDocumentCheck'])
            ->middleware('permission:shm.document.update')
            ->name('applications.reopen-document-check');

        Route::post('/pengajuan/{application}/kembali-draft', [ShmApplicationController::class, 'returnToDraft'])
            ->middleware('permission:shm.approval.decide')
            ->name('applications.return-to-draft');

        // Data tahap: objek, credit checking, memorandum, dokumen.
        Route::put('/pengajuan/{application}/objek', [ShmApplicationController::class, 'saveProperty'])
            ->middleware('permission:shm.property.manage')
            ->name('applications.property');

        // Mulai pengerjaan credit checking. Baris credit checking dibuat di sini
        // secara eksplisit, bukan otomatis saat pengajuan dibuat.
        Route::post('/pengajuan/{application}/credit-checking/mulai', [ShmCreditCheckController::class, 'start'])
            ->middleware('permission:shm.credit_checking.update')
            ->name('applications.credit-checking.start');

        Route::put('/pengajuan/{application}/memorandum', [ShmApplicationController::class, 'recordMemorandum'])
            ->middleware('permission:shm.memorandum.update')
            ->name('applications.memorandum');

        Route::put('/pengajuan/{application}/dokumen', [ShmApplicationController::class, 'recordDocument'])
            ->middleware('permission:shm.document.update')
            ->name('applications.document');

        // Kelengkapan Dokumen (Admin monitoring) — Ticket 50.
        // Dibaca/ditulis dengan permission yang sama dengan modul dokumen yang
        // sudah ada (shm.document.update); Admin memakai cakupan seluruh
        // pengajuan, Marketing memakai cakupan milik sendiri (sama seperti
        // modul Memorandum).
        Route::middleware('permission:shm.document.update')->group(function () {
            Route::get('/dokumen', [ShmDocumentsController::class, 'index'])->name('documents.index');
            Route::get('/dokumen/{application}', [ShmDocumentsController::class, 'show'])->name('documents.show');
            Route::patch('/dokumen/{application}', [ShmDocumentsController::class, 'update'])->name('documents.update');
        });

        // Nasabah SHM (Ticket 46).
        //
        // Master data nasabah TERPISAH dari anggota koperasi: route-routenya
        // TIDAK PERNAH MENULIS tabel members. Sejak T89C route create membaca
        // members hanya untuk memilih anggota sumber aktif (tanpa auto-create).
        //
        // Route literal (/nasabah/create) didaftarkan sebelum route parameter
        // ({customer}) agar tidak tertelan wildcard.
        Route::middleware('permission:shm.customer.create')->group(function () {
            Route::get('/nasabah/create', [ShmCustomerController::class, 'create'])->name('customers.create');
            Route::post('/nasabah', [ShmCustomerController::class, 'store'])->name('customers.store');
        });

        Route::middleware('permission:shm.customer.view')->group(function () {
            Route::get('/nasabah', [ShmCustomerController::class, 'index'])->name('customers.index');
            Route::get('/nasabah/{customer}', [ShmCustomerController::class, 'show'])->name('customers.show');
        });

        Route::get('/nasabah/{customer}/edit', [ShmCustomerController::class, 'edit'])
            ->middleware('permission:shm.customer.update')
            ->name('customers.edit');

        Route::put('/nasabah/{customer}', [ShmCustomerController::class, 'update'])
            ->middleware('permission:shm.customer.update')
            ->name('customers.update');

        // Hapus permanen (T102): permission khusus — berbeda dari update —
        // dan guard dependency tetap di service (nasabah terpakai ditolak).
        Route::delete('/nasabah/{customer}', [ShmCustomerController::class, 'destroy'])
            ->middleware('permission:shm.customer.delete')
            ->name('customers.destroy');

        Route::patch('/nasabah/{customer}/status', [ShmCustomerController::class, 'toggleActive'])
            ->middleware('permission:shm.customer.update')
            ->name('customers.toggle-active');

        // Credit Checking (Ticket 47).
        //
        // Workflow manual: Pending -> InProgress -> Completed. Bukan integrasi
        // BI Checking/SLIK dan bukan penilaian kredit.
        //
        // Route literal (/hasil) didaftarkan sebelum route parameter
        // ({creditCheck}) agar tidak tertelan wildcard.
        Route::prefix('credit-checking')->name('credit-checks.')->group(function () {
            Route::middleware('permission:shm.credit_checking.view')->group(function () {
                // Proses Marketing: daftar pengajuan yang perlu/sedang diperiksa.
                Route::get('/', [ShmCreditCheckController::class, 'index'])->name('index');
                // Hasil Marketing: hanya credit checking yang sudah selesai.
                Route::get('/hasil', [ShmCreditCheckController::class, 'results'])->name('results');
                Route::get('/{creditCheck}', [ShmCreditCheckController::class, 'show'])->name('show');
            });

            Route::put('/{creditCheck}', [ShmCreditCheckController::class, 'update'])
                ->middleware('permission:shm.credit_checking.update')
                ->name('update');

            Route::post('/{creditCheck}/selesai', [ShmCreditCheckController::class, 'complete'])
                ->middleware('permission:shm.credit_checking.update')
                ->name('complete');
        });

        // -----------------------------------------------------------------
        // Modul Memorandum (Ticket 49): SHM/SHGB & Buyback.
        //
        // Satu domain ShmMemorandum untuk dua kategori; kategori hanya
        // filter/atribut, bukan controller kedua. Route literal (create)
        // didaftarkan sebelum route parameter ({memorandum}) agar tidak
        // tertelan wildcard. Otorisasi tetap memakai permission memorandum
        // yang sudah ada sejak Ticket 44; tidak ada permission baru.
        // -----------------------------------------------------------------
        Route::middleware('permission:shm.memorandum.update')->group(function () {
            Route::get('/memorandum', [ShmMemorandumController::class, 'index'])
                ->name('memorandums.index');

            Route::get('/memorandum/create', [ShmMemorandumController::class, 'create'])
                ->name('memorandums.create');

            Route::post('/memorandum', [ShmMemorandumController::class, 'store'])
                ->name('memorandums.store');

            Route::get('/memorandum/{memorandum}', [ShmMemorandumController::class, 'show'])
                ->name('memorandums.show');

            Route::get('/memorandum/{memorandum}/edit', [ShmMemorandumController::class, 'edit'])
                ->name('memorandums.edit');

            Route::put('/memorandum/{memorandum}', [ShmMemorandumController::class, 'update'])
                ->name('memorandums.update');
        });

        // -----------------------------------------------------------------
        // Modul Approval operasional (Ticket 54).
        //
        // Halaman kerja untuk memutuskan pengajuan pada tahap approval.
        // Modul ini HANYA MEMBACA data existing: keputusan approve/reject tetap
        // memakai endpoint pengajuan yang sudah ada (shm.applications.approve /
        // shm.applications.reject) dengan ShmWorkflowService sebagai satu-satunya
        // penulis status, sehingga tidak ada state machine, status, permission,
        // atau audit kedua. Permission yang dipakai adalah permission approval
        // existing (shm.approval.decide); tidak ada permission baru.
        //
        // Route literal (/approval) didaftarkan sebelum route parameter
        // (/approval/{application}) agar tidak tertelan wildcard.
        // -----------------------------------------------------------------
        Route::middleware('permission:shm.approval.decide')->group(function () {
            Route::get('/approval', [ShmApprovalController::class, 'index'])
                ->name('approvals.index');

            Route::get('/approval/{application}', [ShmApprovalController::class, 'show'])
                ->name('approvals.show');
        });

        // -----------------------------------------------------------------
        // Modul Marketing (Ticket 63): aktivitas kunjungan dalam bounded context SHM.
        //
        // Fungsi Marketing (aktivitas kunjungan) dipindahkan dari aplikasi
        // Koperasi ke aplikasi SHM karena data aktivitas (marketing_activities)
        // sudah memiliki relasi kepemilikan (marketing_id) yang konsisten
        // dengan ownership SHM, dan digunakan oleh shm_applications melalui
        // shm_applications.marketing_activity_id.
        //
        // REUSE existing MarketingActivityController (index, store, show,
        // updateResult, monitoring) — hanya komponen Inertia yang kini berada
        // di resources/js/Pages/Shm/Marketing sehingga tampil dengan layout SHM.
        // Permission existing (marketing.view, marketing.create,
        // marketing.result.update, marketing.monitoring.view) tetap dipakai;
        // tidak ada permission baru dan tidak ada perubahan ownership.
        //
        // Route literal (/marketing, /marketing/monitoring) didaftarkan sebelum
        // route parameter (/marketing/{activity}) agar tidak tertelan wildcard.
        // -----------------------------------------------------------------
        // Monitoring seluruh Marketing: permission terpisah (marketing.monitoring.view),
        // bukan oleh nama role, dan TIDAK disilangkan dengan marketing.view.
        // Didaftarkan sebelum /marketing/{activity} agar tidak tertelan wildcard.
        Route::get('/marketing/monitoring', [MarketingActivityController::class, 'monitoring'])
            ->middleware('permission:marketing.monitoring.view')
            ->name('marketing.monitoring.index');

        Route::middleware('permission:marketing.view')->group(function () {
            Route::get('/marketing', [MarketingActivityController::class, 'index'])
                ->name('marketing.activities.index');
            Route::post('/marketing', [MarketingActivityController::class, 'store'])
                ->name('marketing.activities.store');
            Route::get('/marketing/{activity}', [MarketingActivityController::class, 'show'])
                ->name('marketing.activities.show');
            Route::patch('/marketing/{activity}/hasil', [MarketingActivityController::class, 'updateResult'])
                ->middleware('permission:marketing.result.update')
                ->name('marketing.activities.result.update');
        });

        // -----------------------------------------------------------------
        // Modul Finansial SHM (Ticket 64): Pinjaman SHM.
        //
        // Domain finansial SHM TERPISAH dari modul Pinjaman koperasi:
        // tabel `shm_loan_*` tidak berelasi ke `members`, `saving_products`,
        // `savings_accounts`, `loans`, maupun `installment_*`. Nasabah SHM
        // tidak otomatis menjadi anggota koperasi sehingga tidak ada foreign
        // key atau logika konversi ke arah sana.
        //
        // Modul Simpanan SHM dihapus pada Ticket 65 (tidak diperlukan).
        //
        // Route literal (/pinjaman/produk, /pinjaman/simulasi,
        // /pinjaman/pengajuan) didaftarkan sebelum route parameter ({loan})
        // agar tidak tertelan wildcard. Permission `shm.view` yang sudah ada dipakai untuk
        // seluruh halaman; tidak ada permission baru, dan halaman bersifat
        // baca (tulis transaksi/pinjaman = PENDING BUSINESS CONFIRMATION).
        // -----------------------------------------------------------------
        // -----------------------------------------------------------------
        // Ticket 98-FIX — CRUD master produk Pinjaman SHM.
        //
        // Baca memakai `shm.view` (grup di bawah); tulis memakai permission
        // terpisah `shm.product.manage` (pola BUG-01: baca ≠ tulis) sehingga
        // pemegang shm.view saja tidak dapat mengubah master. Route literal
        // didaftarkan SEBELUM /pinjaman/{loan} agar tidak tertelan wildcard.
        // CRUD ini hanya menyentuh baris master produk (code/nama/kategori/
        // keterangan/syarat/status aktif); skema, tier rate, maupun histori
        // pinjaman/pengajuan TIDAK berubah — rate tetap dari tier produk (T82).
        // -----------------------------------------------------------------
        Route::get('/pinjaman/produk/create', [ShmLoanController::class, 'createProduct'])
            ->middleware('permission:shm.product.manage')
            ->name('loans.products.create');
        Route::post('/pinjaman/produk', [ShmLoanController::class, 'storeProduct'])
            ->middleware('permission:shm.product.manage')
            ->name('loans.products.store');
        Route::get('/pinjaman/produk/{product}/edit', [ShmLoanController::class, 'editProduct'])
            ->middleware('permission:shm.product.manage')
            ->name('loans.products.edit');
        Route::put('/pinjaman/produk/{product}', [ShmLoanController::class, 'updateProduct'])
            ->middleware('permission:shm.product.manage')
            ->name('loans.products.update');

        Route::middleware('permission:shm.view')->group(function () {
            // Master produk Pinjaman SHM.
            Route::get('/pinjaman/produk', [ShmLoanController::class, 'products'])
                ->name('loans.products.index');
            // Simulasi Pinjaman SHM + ekspor PDF hasil simulasi.
            // Keduanya menerima input yang sama (`run=1` menandai submit)
            // sehingga PDF selalu identik dengan hasil di layar.
            Route::get('/pinjaman/simulasi', [ShmLoanController::class, 'simulation'])
                ->name('loans.simulation');
            Route::get('/pinjaman/simulasi/pdf', [ShmLoanController::class, 'simulationPdf'])
                ->name('loans.simulation.pdf');

            // Halaman Pengajuan Pinjaman (Ticket 66): SATU halaman dengan 11
            // section accordion. Ticket 67 menambahkan persistence (POST),
            // detail tersimpan, dan export PDF — seluruhnya route literal
            // SEBELUM /pinjaman/{loan} agar tidak tertelan wildcard.
            Route::get('/pinjaman/pengajuan', [ShmLoanController::class, 'pengajuan'])
                ->name('loans.pengajuan');
            // Ticket 68 — daftar (literal, SEBELUM /pengajuan/{application}).
            Route::get('/pinjaman/pengajuan/daftar', [ShmLoanController::class, 'daftar'])
                ->name('loans.pengajuan.daftar');
            Route::post('/pinjaman/pengajuan', [ShmLoanController::class, 'store'])
                ->name('loans.pengajuan.store');
            Route::get('/pinjaman/pengajuan/{application}', [ShmLoanController::class, 'pengajuanShow'])
                ->name('loans.pengajuan.show');
            Route::get('/pinjaman/pengajuan/{application}/pdf', [ShmLoanController::class, 'applicationPdf'])
                ->name('loans.pengajuan.pdf');
            // Ticket 77 — foto jaminan: route literal setelah {application}
            // (path lebih panjang, tidak tertelan wildcard) dengan aturan
            // kepemilikan pengajuan yang sama. Berkas tersimpan pada disk
            // privat; route ini satu-satunya jalan membacanya.
            Route::get('/pinjaman/pengajuan/{application}/jaminan/{collateral}/foto', [ShmLoanController::class, 'collateralPhoto'])
                ->name('loans.pengajuan.jaminan.foto');

            // Daftar record pinjaman SHM + detail pinjaman.
            Route::get('/pinjaman', [ShmLoanController::class, 'index'])
                ->name('loans.index');

            // -----------------------------------------------------------------
            // Ticket 86 — Transaksi pembayaran pinjaman SHM.
            //
            // Route literal (/pinjaman/pembayaran) didaftarkan SEBELUM
            // /pinjaman/{loan} agar tidak tertelan wildcard. Baca memakai
            // `shm.view` (sama dengan halaman Pinjaman lain) + scope
            // kepemilikan; pencatatan pembayaran (POST) memakai permission
            // TERPISAH `shm.payment.create` di bawah (pola BUG-01).
            // -----------------------------------------------------------------
            Route::get('/pinjaman/pembayaran', [ShmLoanPaymentController::class, 'index'])
                ->name('loans.payments.index');
            Route::get('/pinjaman/pembayaran/{payment}', [ShmLoanPaymentController::class, 'show'])
                ->name('loans.payments.show');

            // -----------------------------------------------------------------
            // Ticket 89 — Pelunasan / Settlement Pinjaman SHM.
            //
            // Route literal (/pinjaman/pelunasan) didaftarkan SEBELUM
            // /pinjaman/{loan} agar tidak tertelan wildcard. Baca (index &
            // preview) memakai `shm.view` (sama dengan halaman Pinjaman
            // lain) + scope kepemilikan; KONFIRMASI (POST) memakai permission
            // TERPISAH `shm.settlement.create` di bawah (pola BUG-01).
            // Preview = GET read-only, tidak pernah menulis record/audit/
            // menutup pinjaman. Konfirmasi saat ini ditolak deterministik di
            // ShmLoanSettlementService selama kontrak settlement masih
            // memiliki blocked_by items (PENDING BUSINESS CONFIRMATION).
            // -----------------------------------------------------------------
            Route::get('/pinjaman/pelunasan', [ShmLoanSettlementController::class, 'index'])
                ->name('loans.pelunasan.index');
            Route::get('/pinjaman/pelunasan/policy', [ShmSettlementDiscountPolicyController::class, 'index'])
                ->name('loans.pelunasan.policies.index');
            Route::get('/pinjaman/pelunasan/{loan}', [ShmLoanSettlementController::class, 'show'])
                ->name('loans.pelunasan.show');

            Route::get('/pinjaman/{loan}', [ShmLoanController::class, 'show'])
                ->name('loans.show');

            // -----------------------------------------------------------------
            // Ticket 83 — ACTION EKSPLISIT pembangkitan jadwal angsuran.
            //
            // Permission `shm.view` + ownership scope yang SAMA dengan
            // halaman detail (authorizeLoanRecordOwnership) — endpoint POST
            // bukan bypass visibility; tidak ada permission baru. Pembangkitan
            // TIDAK pernah otomatis (pencairan/approval/page-load); seluruh
            // logika finansial tetap di ShmRepaymentScheduleService.
            // -----------------------------------------------------------------
            Route::post('/pinjaman/{loan}/schedule', [ShmLoanController::class, 'generateSchedule'])
                ->name('loans.schedule.generate');
        });

        // -----------------------------------------------------------------
        // Collection — monitoring, aktivitas, dan PTP (T90/T93/T94/T97).
        //
        // Ticket 101: setiap route Collection kini memakai permission
        // PER-AKSI sendiri (`shm.collection.*` — configurable melalui UI
        // Role & Permission), mengikuti pola payment/settlement/product
        // (satu permission per route, di luar group `shm.view`). Sebelumnya
        // seluruh route berdiri di gate tunggal `shm.view`.
        //
        // Business rules TETAP tidak berubah:
        //   - Ownership: otorisasi tulis mutlak di domain (Marketing
        //     PEMILIK case; tanpa fallback admin/shm.view_all) — permission
        //     tidak menggantikan maupun membypass ownership.
        //   - Scope baca: authorizeLoanRecordOwnership (shm.view_all untuk
        //     cakupan seluruh data).
        //   - Role Collector TIDAK dibuat di sini; Superadmin membuat role
        //     sendiri via UI dan memberikan permission ini ketika perlu.
        //   - Matrix detail BM/PD tetap PENDING BUSINESS CONFIRMATION
        //     (docs/SHM-BUSINESS-DECISION-PACKAGE.md, pending #16).
        //
        // Route literal GET /collection didaftarkan SEBELUM /collection/
        // {loan}. Path POST 3/4 segmen tidak konflik dengan GET 2 segmen
        // (metode & jumlah segmen berbeda). Riwayat aktivitas/PTP dibaca
        // lewat payload halaman detail (/collection/{loan}).
        // -----------------------------------------------------------------
        Route::get('/collection', [ShmCollectionController::class, 'index'])
            ->middleware('permission:shm.collection.view')
            ->name('collection.index');
        Route::get('/collection/{loan}', [ShmCollectionController::class, 'show'])
            ->middleware('permission:shm.collection.view')
            ->name('collection.show');
        Route::post('/collection/{case}/activities', [ShmCollectionActivityController::class, 'store'])
            ->middleware('permission:shm.collection.activity.create')
            ->name('collection.activities.store');
        // Graph transisi = tabel ALLOWED_TRANSITIONS T92 (contract T91);
        // PROMISE_TO_PAY murni status workflow tanpa detail PTP.
        Route::post('/collection/{case}/transition', [ShmCollectionController::class, 'transition'])
            ->middleware('permission:shm.collection.transition')
            ->name('collection.cases.transition');
        // PTP hanya bisa dicatat saat case PROMISE_TO_PAY & tanpa PTP OPEN
        // (guard di service); pembuatan TIDAK mengubah status case dan
        // TIDAK membuat pembayaran.
        Route::post('/collection/{case}/ptp', [ShmCollectionPtpController::class, 'store'])
            ->middleware('permission:shm.collection.ptp.create')
            ->name('collection.ptp.store');
        Route::post('/collection/ptp/{ptp}/transition', [ShmCollectionPtpController::class, 'transition'])
            ->middleware('permission:shm.collection.ptp.transition')
            ->name('collection.ptp.transition');

        // -----------------------------------------------------------------
        // Ticket 68 — edit/update Pengajuan Pinjaman & Ajukan Approval.
        //
        // Permission mengikuti pola pengajuan legacy (shm.applications.*):
        // update = `shm.update`, submit/ajukan = `shm.submit` — keduanya sudah
        // ada (marketing punya keduanya); TANPA permission baru. Keputusan
        // approve/reject TIDAK punya endpoint baru di sini: tetap memakai
        // shm.applications.approve/reject existing dengan
        // `shm.approval.decide`.
        // -----------------------------------------------------------------
        Route::get('/pinjaman/pengajuan/{application}/edit', [ShmLoanController::class, 'edit'])
            ->middleware('permission:shm.update')
            ->name('loans.pengajuan.edit');
        Route::put('/pinjaman/pengajuan/{application}', [ShmLoanController::class, 'update'])
            ->middleware('permission:shm.update')
            ->name('loans.pengajuan.update');
        Route::post('/pinjaman/pengajuan/{application}/ajukan-approval', [ShmLoanController::class, 'submitApproval'])
            ->middleware('permission:shm.submit')
            ->name('loans.pengajuan.submit-approval');

        // -----------------------------------------------------------------
        // Ticket 69 — Pencairan Pengajuan yang approval-nya DISETUJUI.
        //
        // Permission `shm.disburse` existing ("Menandai pengajuan sebagai
        // dicairkan") — tanpa permission baru; marketing sengaja tidak
        // memilikinya (batas kewenangan pencairan). Ownership tetap di
        // AuthorizesShmOwnership; guard approval/idempotency ada di
        // ShmLoanDisbursementService (server-side).
        // -----------------------------------------------------------------
        Route::post('/pinjaman/pengajuan/{application}/pencairan', [ShmLoanController::class, 'disburse'])
            ->middleware('permission:shm.disburse')
            ->name('loans.pengajuan.disburse');

        // -----------------------------------------------------------------
        // Ticket 86 — Catat pembayaran pinjaman SHM.
        //
        // Permission `shm.payment.create` TERPISAH dari `shm.view` (pola
        // BUG-01: baca ≠ tulis transaksi finansial), konsisten dengan
        // `angsuran.record_payment` di koperasi. Scope kepemilikan pinjaman
        // ditegakkan di controller dengan aturan yang SAMA dengan halaman
        // detail (authorizeLoanRecordOwnership) — endpoint POST bukan bypass.
        // -----------------------------------------------------------------
        Route::post('/pinjaman/pembayaran', [ShmLoanPaymentController::class, 'store'])
            ->middleware('permission:shm.payment.create')
            ->name('loans.payments.store');

        // -----------------------------------------------------------------
        // Ticket 87 — Alokasikan pembayaran yang sudah tercatat (T86) ke
        // angsuran. Permission SAMA dengan pencatatan (`shm.payment.create`)
        // tanpa permission baru; scope kepemilikan pinjaman ditegakkan di
        // controller (authorizeLoanRecordOwnership). Idempoten: panggilan
        // ulang terhadap pembayaran teralokasi penuh tidak membuat alokasi
        // ganda (unique constraint payment+installment di database).
        // -----------------------------------------------------------------
        Route::post('/pinjaman/pembayaran/{payment}/allocate', [ShmLoanPaymentController::class, 'allocate'])
            ->middleware('permission:shm.payment.create')
            ->name('loans.payments.allocate');

        // -----------------------------------------------------------------
        // Ticket 89 — Konfirmasi pelunasan (settlement) pinjaman SHM.
        //
        // Permission `shm.settlement.create` TERPISAH dari `shm.view` dan
        // dari `shm.payment.create` (pola BUG-01: baca ≠ tulis transaksi
        // finansial). Marketing sengaja TIDAK memilikinya — authority
        // pelunasan masih PENDING BUSINESS CONFIRMATION. Scope kepemilikan
        // pinjaman ditegakkan di controller dengan aturan yang SAMA dengan
        // preview (authorizeLoanRecordOwnership) — endpoint POST bukan bypass.
        // -----------------------------------------------------------------
        // -----------------------------------------------------------------
        // Ticket 89B — Master policy diskon bunga pelunasan (data-driven).
        // Route literal (/pinjaman/pelunasan/policy) didaftarkan SEBELUM
        // POST /pinjaman/pelunasan/{loan} agar tidak tertelan wildcard.
        // Baca = shm.view; tulis = shm.settlement.policy.manage (technical,
        // TIDAK untuk marketing).
        // -----------------------------------------------------------------
        Route::post('/pinjaman/pelunasan/policy', [ShmSettlementDiscountPolicyController::class, 'store'])
            ->middleware('permission:shm.settlement.policy.manage')
            ->name('loans.pelunasan.policies.store');
        Route::put('/pinjaman/pelunasan/policy/{policy}', [ShmSettlementDiscountPolicyController::class, 'update'])
            ->middleware('permission:shm.settlement.policy.manage')
            ->name('loans.pelunasan.policies.update');
        Route::post('/pinjaman/pelunasan/policy/{policy}/toggle', [ShmSettlementDiscountPolicyController::class, 'toggle'])
            ->middleware('permission:shm.settlement.policy.manage')
            ->name('loans.pelunasan.policies.toggle');

        Route::post('/pinjaman/pelunasan/{loan}', [ShmLoanSettlementController::class, 'store'])
            ->middleware('permission:shm.settlement.create')
            ->name('loans.pelunasan.store');

        // -----------------------------------------------------------------
        // Ticket 89B — Workflow settlement Model B (semua POST memerlukan
        // `shm.settlement.create` + scope kepemilikan yang sama; role
        // authority bisnis tetap PENDING BUSINESS CONFIRMATION).
        // -----------------------------------------------------------------
        Route::post('/pinjaman/pelunasan/{loan}/approve', [ShmLoanSettlementController::class, 'approve'])
            ->middleware('permission:shm.settlement.create')
            ->name('loans.pelunasan.approve');
        Route::post('/pinjaman/pelunasan/{loan}/reject', [ShmLoanSettlementController::class, 'reject'])
            ->middleware('permission:shm.settlement.create')
            ->name('loans.pelunasan.reject');
        Route::post('/pinjaman/pelunasan/{loan}/payment', [ShmLoanSettlementController::class, 'recordPayment'])
            ->middleware('permission:shm.settlement.create')
            ->name('loans.pelunasan.payment');
        Route::post('/pinjaman/pelunasan/{loan}/payment/verify', [ShmLoanSettlementController::class, 'verifyPayment'])
            ->middleware('permission:shm.settlement.create')
            ->name('loans.pelunasan.payment.verify');
        Route::post('/pinjaman/pelunasan/{loan}/complete', [ShmLoanSettlementController::class, 'complete'])
            ->middleware('permission:shm.settlement.create')
            ->name('loans.pelunasan.complete');

        // -----------------------------------------------------------------
        // Modul Export — pemantauan data operasional (Ticket 55).
        //
        // Empat halaman READ-ONLY yang memetakan pengajuan ke tahap existing
        // ShmApplicationStatus (masuk = submitted_at terisi, approve = status
        // approved, reject = status rejected, pencairan = disbursed_at terisi).
        // Tidak ada CRUD, workflow, permission, atau ekspor file baru. Permission
        // `shm.view` existing dipakai; scope kepemilikan SHM tetap ditegakkan
        // di level query sehingga export bukan pintu bypass visibility.
        // -----------------------------------------------------------------
        Route::middleware('permission:shm.view')->group(function () {
            Route::get('/export/masuk', [ShmExportController::class, 'index'])
                ->defaults('category', 'masuk')
                ->name('exports.masuk');

            Route::get('/export/approve', [ShmExportController::class, 'index'])
                ->defaults('category', 'approve')
                ->name('exports.approve');

            Route::get('/export/reject', [ShmExportController::class, 'index'])
                ->defaults('category', 'reject')
                ->name('exports.reject');

            Route::get('/export/pencairan', [ShmExportController::class, 'index'])
                ->defaults('category', 'pencairan')
                ->name('exports.pencairan');
        });
    });
});

// =====================================================================
// Modul koperasi (lanjutan). Grup auth dibuka kembali di sini karena blok SHM
// di atas berdiri sebagai boundary tersendiri.
// =====================================================================
Route::middleware(['auth', 'verified', 'active.account'])->group(function () {
    // Modul Pengaturan & Manajemen Pengguna (BUG-01: halaman pengaturan umum
    // dibungkus pengaturan.view; halaman keamanan akun tetap milik akun sendiri).
    Route::prefix('pengaturan')->name('pengaturan.')->group(function () {
        Route::middleware('permission:pengaturan.view')->group(function () {
            Route::get('/', [SettingsController::class, 'index'])->name('index');

            // Pengaturan profil koperasi
            Route::get('/profil', [SettingsController::class, 'profile'])->name('profile');

            // Pengaturan operasional dasar
            Route::get('/operasional', [SettingsController::class, 'operational'])->name('operational');
        });
        Route::put('/profil', [SettingsController::class, 'update'])
            ->middleware('permission:pengaturan.update')
            ->name('profile.update');

        Route::put('/operasional', [SettingsController::class, 'update'])
            ->middleware('permission:pengaturan.update')
            ->name('operational.update');

        // Pengaturan keamanan akun (milik akun sendiri, tanpa permission modul
        // agar seluruh pengguna tetap dapat mengelola keamanan akunnya).
        Route::get('/keamanan', [SettingsController::class, 'security'])->name('security');

        // Manajemen pengguna
        Route::middleware('permission:pengguna.view')->group(function () {
            Route::get('/pengguna', [UserController::class, 'index'])->name('users.index');
        });
        // Route literal (create) didaftarkan sebelum route parameter ({user})
        // agar tidak tertelan wildcard — halaman tambah pengguna sebelumnya
        // menghasilkan 404 karena tertangkap /pengguna/{user} dengan
        // {user} = "create".
        Route::get('/pengguna/create', [UserController::class, 'create'])->middleware('permission:pengguna.create')->name('users.create');
        Route::middleware('permission:pengguna.view')->group(function () {
            Route::get('/pengguna/{user}', [UserController::class, 'show'])->name('users.show');
        });
        Route::post('/pengguna', [UserController::class, 'store'])->middleware('permission:pengguna.create')->name('users.store');
        Route::get('/pengguna/{user}/edit', [UserController::class, 'edit'])->middleware('permission:pengguna.update')->name('users.edit');
        Route::put('/pengguna/{user}', [UserController::class, 'update'])->middleware('permission:pengguna.update')->name('users.update');
        Route::patch('/pengguna/{user}/status', [UserController::class, 'toggleActive'])->middleware('permission:pengguna.deactivate')->name('users.toggle-active');
        Route::patch('/pengguna/{user}/role', [UserController::class, 'updateRoles'])->middleware('permission:pengguna.assign_role')->name('users.update-roles');
        Route::patch('/pengguna/{user}/password', [UserController::class, 'resetPassword'])->middleware('permission:pengguna.reset_password')->name('users.reset-password');

        // Manajemen role & permission
        Route::middleware('permission:role.view')->group(function () {
            Route::get('/role', [RoleController::class, 'index'])->name('roles.index');
            Route::get('/role/permissions', [RoleController::class, 'permissions'])->name('roles.permissions');
            Route::get('/role/{role}/pengguna', [RoleController::class, 'users'])->name('roles.users');
        });
        Route::get('/role/create', [RoleController::class, 'create'])->middleware('permission:role.create')->name('roles.create');
        Route::post('/role', [RoleController::class, 'store'])->middleware('permission:role.create')->name('roles.store');
        Route::get('/role/{role}/edit', [RoleController::class, 'edit'])->middleware('permission:role.update')->name('roles.edit');
        Route::put('/role/{role}', [RoleController::class, 'update'])->middleware('permission:role.update')->name('roles.update');
        Route::delete('/role/{role}', [RoleController::class, 'destroy'])->middleware('permission:role.delete')->name('roles.destroy');

        // Audit trail
        Route::get('/audit', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->name('audit.index');
        Route::get('/audit/{audit_log}', [AuditLogController::class, 'show'])->middleware('permission:audit.view')->name('audit.show');
    });
});

// Modul Rekonsiliasi: pemeriksaan konsistensi read-only atas data sumber
// (grup tersendiri dengan middleware lengkap — BUG-04: komentar tidak lagi
// menempel di luar grup auth).
Route::middleware(['auth', 'verified', 'active.account', 'permission:rekonsiliasi.view'])->prefix('rekonsiliasi')->name('rekonsiliasi.')->group(function () {
    Route::get('/', [ReconciliationController::class, 'index'])->name('index');
    Route::get('/proses', [ReconciliationController::class, 'runs'])->name('runs');
    Route::get('/proses/{run}', [ReconciliationController::class, 'show'])->name('runs.show');
    Route::get('/temuan', [ReconciliationController::class, 'findings'])->name('findings');
    Route::get('/temuan/{finding}', [ReconciliationController::class, 'showFinding'])->name('findings.show');

    // Menjalankan dan menindaklanjuti memerlukan permission terpisah.
    Route::post('/proses', [ReconciliationController::class, 'store'])
        ->middleware('permission:rekonsiliasi.run')
        ->name('runs.store');
    Route::put('/temuan/{finding}', [ReconciliationController::class, 'updateFinding'])
        ->middleware('permission:rekonsiliasi.resolve')
        ->name('findings.update');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
