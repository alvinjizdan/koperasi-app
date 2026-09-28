<?php

use App\Http\Controllers\Accounting\AccountController;
use App\Http\Controllers\Accounting\FinancialReportController;
use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Accounting\LedgerController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\CashierController;
use App\Http\Controllers\CashSummaryController;
use App\Http\Controllers\CashTransactionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstallmentController;
use App\Http\Controllers\LoanController;
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
            Route::get('/pengguna/{user}', [UserController::class, 'show'])->name('users.show');
        });
        Route::get('/pengguna/create', [UserController::class, 'create'])->middleware('permission:pengguna.create')->name('users.create');
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
