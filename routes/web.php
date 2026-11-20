<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Marketing\MarketingActivityController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Shm\ShmAccountController;
use App\Http\Controllers\Shm\ShmAccountingImportController;
use App\Http\Controllers\Shm\ShmAccountingMappingController;
use App\Http\Controllers\Shm\ShmAgentController;
use App\Http\Controllers\Shm\ShmApplicationController;
use App\Http\Controllers\Shm\ShmApprovalController;
use App\Http\Controllers\Shm\ShmBalanceSheetController;
use App\Http\Controllers\Shm\ShmCashFlowController;
use App\Http\Controllers\Shm\ShmCollectionActivityController;
use App\Http\Controllers\Shm\ShmCollectionController;
use App\Http\Controllers\Shm\ShmCollectionPtpController;
use App\Http\Controllers\Shm\ShmCreditCheckController;
use App\Http\Controllers\Shm\ShmCumulativeProfitLossController;
use App\Http\Controllers\Shm\ShmCustomerController;
use App\Http\Controllers\Shm\ShmDailyJournalController;
use App\Http\Controllers\Shm\ShmDashboardController;
use App\Http\Controllers\Shm\ShmDocumentsController;
use App\Http\Controllers\Shm\ShmEntryController;
use App\Http\Controllers\Shm\ShmExportController;
use App\Http\Controllers\Shm\ShmLoanCollateralController;
use App\Http\Controllers\Shm\ShmLoanController;
use App\Http\Controllers\Shm\ShmLoanPaymentController;
use App\Http\Controllers\Shm\ShmLoanReportController;
use App\Http\Controllers\Shm\ShmLoanSettlementController;
use App\Http\Controllers\Shm\ShmLoginController;
use App\Http\Controllers\Shm\ShmMemorandumController;
use App\Http\Controllers\Shm\ShmPeriodProfitLossController;
use App\Http\Controllers\Shm\ShmProductAccountingController;
use App\Http\Controllers\Shm\ShmRoleController;
use App\Http\Controllers\Shm\ShmSavingProductController;
use App\Http\Controllers\Shm\ShmSavingsAccountController;
use App\Http\Controllers\Shm\ShmSavingsTransactionController;
use App\Http\Controllers\Shm\ShmSettlementDiscountPolicyController;
use App\Http\Controllers\Shm\ShmSettlementRefundController;
use App\Http\Controllers\Shm\ShmTrialBalanceController;
use App\Http\Controllers\Shm\ShmUserController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Halaman awal: tamu ke login, pengguna login diarahkan ke SHM Dashboard
Route::get('/', function (Request $request) {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return redirect()->route('shm.dashboard');
});

Route::middleware(['auth', 'verified', 'active.account'])->group(function () {
    Route::get('/dashboard', function () {
        return redirect()->route('shm.dashboard');
    })->name('dashboard');
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

        Route::post('/pengajuan/{application}/setujui-bm', [ShmApplicationController::class, 'approveBm'])
            ->middleware('permission:shm.approval.bm')
            ->name('applications.approve-bm');

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

        // Dokumen Legalitas Buyback pada Pengajuan (Ticket 14)
        Route::post('/pengajuan/{application}/buyback-documents', [ShmApplicationController::class, 'buybackUploadDocument'])
            ->middleware('permission:shm.document.update')
            ->name('applications.buyback-documents.upload');
        Route::get('/pengajuan/{application}/buyback-documents/{document}', [ShmApplicationController::class, 'buybackDownloadDocument'])
            ->middleware('permission:shm.document.update')
            ->name('applications.buyback-documents.download');
        Route::delete('/pengajuan/{application}/buyback-documents/{document}', [ShmApplicationController::class, 'buybackDestroyDocument'])
            ->middleware('permission:shm.document.update')
            ->name('applications.buyback-documents.destroy');

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

        // Master Agen SHM / BSM Makro.
        Route::middleware('permission:shm.agent.create')->group(function () {
            Route::get('/agen/create', [ShmAgentController::class, 'create'])->name('agents.create');
            Route::post('/agen', [ShmAgentController::class, 'store'])->name('agents.store');
        });

        Route::middleware('permission:shm.agent.view')->group(function () {
            Route::get('/agen', [ShmAgentController::class, 'index'])->name('agents.index');
        });

        Route::get('/agen/{agent}/edit', [ShmAgentController::class, 'edit'])
            ->middleware('permission:shm.agent.update')
            ->name('agents.edit');

        Route::put('/agen/{agent}', [ShmAgentController::class, 'update'])
            ->middleware('permission:shm.agent.update')
            ->name('agents.update');

        Route::patch('/agen/{agent}/status', [ShmAgentController::class, 'toggleActive'])
            ->middleware('permission:shm.agent.update')
            ->name('agents.toggle-active');

        Route::delete('/agen/{agent}', [ShmAgentController::class, 'destroy'])
            ->middleware('permission:shm.agent.delete')
            ->name('agents.destroy');

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

            Route::post('/{creditCheck}/transition', [ShmCreditCheckController::class, 'transition'])
                ->name('transition');

            Route::post('/{creditCheck}/selesai', [ShmCreditCheckController::class, 'complete'])
                ->middleware('permission:shm.credit_checking.update')
                ->name('complete');
        });

        // -----------------------------------------------------------------
        // Modul Memorandum: Pengajuan SHGB, SHM/SHGB, Buyback, dan Summary.
        // -----------------------------------------------------------------
        Route::middleware('permission:shm.memorandum.update')->group(function () {
            // Submenu Memorandum SHM/SHGB (Ticket 1 & 2)
            Route::get('/memorandum/shm-shgb', [ShmMemorandumController::class, 'shmShgbIndex'])
                ->name('memorandums.shm-shgb.index');
            Route::get('/memorandum/shm-shgb/create', [ShmMemorandumController::class, 'shmShgbCreate'])
                ->name('memorandums.shm-shgb.create');
            Route::post('/memorandum/shm-shgb', [ShmMemorandumController::class, 'shmShgbStore'])
                ->name('memorandums.shm-shgb.store');
            Route::get('/memorandum/shm-shgb/{memorandum}', [ShmMemorandumController::class, 'shmShgbShow'])
                ->name('memorandums.shm-shgb.show');
            Route::get('/memorandum/shm-shgb/{memorandum}/edit', [ShmMemorandumController::class, 'shmShgbEdit'])
                ->name('memorandums.shm-shgb.edit');
            Route::put('/memorandum/shm-shgb/{memorandum}', [ShmMemorandumController::class, 'shmShgbUpdate'])
                ->name('memorandums.shm-shgb.update');
            Route::delete('/memorandum/shm-shgb/{memorandum}', [ShmMemorandumController::class, 'shmShgbDestroy'])
                ->name('memorandums.shm-shgb.destroy');

            // Dokumen Legalitas Buyback (Ticket 7) - didaftarkan sebelum {memorandum}
            Route::post('/memorandum/buyback/{memorandum}/documents', [ShmMemorandumController::class, 'buybackUploadDocument'])
                ->name('memorandums.buyback.documents.upload');
            Route::get('/memorandum/buyback/{memorandum}/documents/{document}', [ShmMemorandumController::class, 'buybackDownloadDocument'])
                ->name('memorandums.buyback.documents.download');
            Route::delete('/memorandum/buyback/{memorandum}/documents/{document}', [ShmMemorandumController::class, 'buybackDestroyDocument'])
                ->name('memorandums.buyback.documents.destroy');

            // Submenu Memorandum Buyback (Ticket 1 & 4 & 7)
            Route::get('/memorandum/buyback', [ShmMemorandumController::class, 'buybackIndex'])
                ->name('memorandums.buyback.index');
            Route::get('/memorandum/buyback/create', [ShmMemorandumController::class, 'buybackCreate'])
                ->name('memorandums.buyback.create');
            Route::get('/memorandum/buyback/{memorandum}/edit', [ShmMemorandumController::class, 'buybackEdit'])
                ->name('memorandums.buyback.edit');
            Route::get('/memorandum/buyback/{memorandum}', [ShmMemorandumController::class, 'buybackShow'])
                ->name('memorandums.buyback.show');
            Route::post('/memorandum/buyback', [ShmMemorandumController::class, 'buybackStore'])
                ->name('memorandums.buyback.store');
            Route::put('/memorandum/buyback/{memorandum}', [ShmMemorandumController::class, 'buybackUpdate'])
                ->name('memorandums.buyback.update');
            Route::delete('/memorandum/buyback/{memorandum}', [ShmMemorandumController::class, 'buybackDestroy'])
                ->name('memorandums.buyback.destroy');

            // Submenu Memorandum Summary
            Route::get('/memorandum/summary', [ShmMemorandumController::class, 'summaryIndex'])
                ->name('memorandums.summary.index');

            // Workflow Approval Memorandum (Ticket 8)
            Route::post('/memorandum/{memorandum}/submit', [ShmMemorandumController::class, 'submit'])
                ->middleware('permission:shm.memorandum.submit')
                ->name('memorandums.submit');
            Route::post('/memorandum/{memorandum}/start-review', [ShmMemorandumController::class, 'startReview'])
                ->middleware('permission:shm.memorandum.review')
                ->name('memorandums.start-review');
            Route::post('/memorandum/{memorandum}/approve', [ShmMemorandumController::class, 'approve'])
                ->middleware('permission:shm.memorandum.approve')
                ->name('memorandums.approve');
            Route::post('/memorandum/{memorandum}/reject', [ShmMemorandumController::class, 'reject'])
                ->middleware('permission:shm.memorandum.reject')
                ->name('memorandums.reject');

            // Otorisasi Completion Bypass (Ticket CC-11 D-01)
            Route::post('/memorandum/{memorandum}/completion-bypass', [ShmMemorandumController::class, 'authorizeCompletionBypass'])
                ->withoutMiddleware('permission:shm.memorandum.update')
                ->name('memorandums.completion-bypass');

            // CRUD Memorandum Existing
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
        Route::middleware('permission:shm.view')->group(function () {
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
            // Foto identitas pemohon (KTP / KK): disk privat & terotorisasi.
            Route::get('/pinjaman/pengajuan/{application}/identitas/{type}/foto', [ShmLoanController::class, 'identityPhoto'])
                ->name('loans.pengajuan.identitas.foto');
            // Dokumen pernikahan pemohon (Buku Nikah / Akta Cerai): disk privat & terotorisasi.
            Route::get('/pinjaman/pengajuan/{application}/dokumen-pernikahan/{type}', [ShmLoanController::class, 'maritalDocument'])
                ->name('loans.pengajuan.dokumen-pernikahan');
            // Ticket 77 — foto jaminan: route literal setelah {application}
            // (path lebih panjang, tidak tertelan wildcard) dengan aturan
            // kepemilikan pengajuan yang sama. Berkas tersimpan pada disk
            // privat; route ini satu-satunya jalan membacanya.
            Route::get('/pinjaman/pengajuan/{application}/jaminan/{collateral}/foto', [ShmLoanController::class, 'collateralPhoto'])
                ->name('loans.pengajuan.jaminan.foto');
            Route::get('/pinjaman/pengajuan/{application}/jaminan/{collateral}/dokumen', [ShmLoanController::class, 'collateralDocument'])
                ->name('loans.pengajuan.jaminan.dokumen');
            // Section 12 Lampiran: dokumen PDF lampiran pengajuan (terotorisasi disk privat)
            Route::get('/pinjaman/pengajuan/{application}/lampiran/{attachmentId}/dokumen', [ShmLoanController::class, 'attachmentDocument'])
                ->name('loans.pengajuan.lampiran.dokumen');

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
            Route::get('/pinjaman/pelunasan/{loan}/correct/preview', [ShmLoanSettlementController::class, 'previewCorrection'])
                ->name('loans.pelunasan.correct.preview');
            Route::get('/pinjaman/pelunasan/{loan}', [ShmLoanSettlementController::class, 'show'])
                ->name('loans.pelunasan.show');

            // Ticket 42 / 42A — Refund Overpayment views
            Route::get('/pinjaman/refund', [ShmSettlementRefundController::class, 'index'])
                ->middleware('permission:shm.refunds.view')
                ->name('loans.refunds.index');
            Route::get('/pinjaman/refund/{refund}', [ShmSettlementRefundController::class, 'show'])
                ->middleware('permission:shm.refunds.view')
                ->name('loans.refunds.show');

            // -----------------------------------------------------------------
            // Jaminan Pinjaman BSM Makro (Nasabah -> Pinjaman -> Jaminan).
            // Laporan Per Pinjaman BSM Makro + Export PDF.
            // Route literal didaftarkan SEBELUM /pinjaman/{loan}.
            // -----------------------------------------------------------------
            Route::get('/pinjaman/jaminan', [ShmLoanCollateralController::class, 'index'])
                ->name('loans.collaterals.index');
            Route::get('/pinjaman/laporan', [ShmLoanReportController::class, 'index'])
                ->name('loans.report.index');
            Route::get('/pinjaman/laporan/{loan}/pdf', [ShmLoanReportController::class, 'exportPdf'])
                ->name('loans.report.pdf');

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
        Route::post('/pinjaman/pelunasan/{loan}/correct', [ShmLoanSettlementController::class, 'correct'])
            ->middleware('permission:shm.settlement.create')
            ->name('loans.pelunasan.correct');

        // -----------------------------------------------------------------
        // Ticket 42 / 42A — Refund Kelebihan Bayar Pelunasan (Settlement Overpayment).
        // Hak akses dedicated per-aksi (BR-42A-03):
        //   - store: shm.refunds.create
        //   - approve: shm.refunds.approve
        //   - reject: shm.refunds.reject
        //   - cancel: shm.refunds.cancel
        //   - executePayout: shm.refunds.execute
        //   - reverse: shm.refunds.reverse
        //   - confirmRecovery: shm.refunds.confirm_recovery
        // -----------------------------------------------------------------
        Route::post('/pinjaman/pelunasan/{settlement}/refund', [ShmSettlementRefundController::class, 'store'])
            ->middleware('permission:shm.refunds.create')
            ->name('loans.refunds.store');
        Route::post('/pinjaman/refund/{refund}/approve', [ShmSettlementRefundController::class, 'approve'])
            ->middleware('permission:shm.refunds.approve')
            ->name('loans.refunds.approve');
        Route::post('/pinjaman/refund/{refund}/reject', [ShmSettlementRefundController::class, 'reject'])
            ->middleware('permission:shm.refunds.reject')
            ->name('loans.refunds.reject');
        Route::post('/pinjaman/refund/{refund}/cancel', [ShmSettlementRefundController::class, 'cancel'])
            ->middleware('permission:shm.refunds.cancel')
            ->name('loans.refunds.cancel');
        Route::post('/pinjaman/refund/{refund}/payout', [ShmSettlementRefundController::class, 'executePayout'])
            ->middleware('permission:shm.refunds.execute')
            ->name('loans.refunds.payout');
        Route::post('/pinjaman/refund/{refund}/reverse', [ShmSettlementRefundController::class, 'reverse'])
            ->middleware('permission:shm.refunds.reverse')
            ->name('loans.refunds.reverse');
        Route::post('/pinjaman/refund/{refund}/confirm-recovery', [ShmSettlementRefundController::class, 'confirmRecovery'])
            ->middleware('permission:shm.refunds.confirm_recovery')
            ->name('loans.refunds.confirm_recovery');

        // -----------------------------------------------------------------
        // Modul Simpanan SHM — Produk, Rekening & Transaksi
        // -----------------------------------------------------------------
        Route::prefix('simpanan')->name('savings.')->group(function () {
            // Produk Simpanan SHM
            Route::get('/produk/create', [ShmSavingProductController::class, 'create'])
                ->middleware('permission:shm.savings.product.manage')
                ->name('products.create');
            Route::post('/produk', [ShmSavingProductController::class, 'store'])
                ->middleware('permission:shm.savings.product.manage')
                ->name('products.store');
            Route::get('/produk/{product}/edit', [ShmSavingProductController::class, 'edit'])
                ->middleware('permission:shm.savings.product.manage')
                ->name('products.edit');
            Route::put('/produk/{product}', [ShmSavingProductController::class, 'update'])
                ->middleware('permission:shm.savings.product.manage')
                ->name('products.update');
            Route::patch('/produk/{product}/deactivate', [ShmSavingProductController::class, 'deactivate'])
                ->middleware('permission:shm.savings.product.manage')
                ->name('products.deactivate');

            // Rekening Simpanan SHM
            Route::get('/rekening/create', [ShmSavingsAccountController::class, 'create'])
                ->middleware('permission:shm.savings.account.create')
                ->name('accounts.create');
            Route::post('/rekening', [ShmSavingsAccountController::class, 'store'])
                ->middleware('permission:shm.savings.account.create')
                ->name('accounts.store');

            // Transaksi Setoran & Penarikan
            Route::get('/rekening/{account}/setor', [ShmSavingsTransactionController::class, 'createDeposit'])
                ->middleware('permission:shm.savings.transaction.create')
                ->name('transactions.deposit.create');
            Route::post('/rekening/{account}/setor', [ShmSavingsTransactionController::class, 'storeDeposit'])
                ->middleware('permission:shm.savings.transaction.create')
                ->name('transactions.deposit.store');
            Route::get('/rekening/{account}/tarik', [ShmSavingsTransactionController::class, 'createWithdraw'])
                ->middleware('permission:shm.savings.transaction.create')
                ->name('transactions.withdraw.create');
            Route::post('/rekening/{account}/tarik', [ShmSavingsTransactionController::class, 'storeWithdraw'])
                ->middleware('permission:shm.savings.transaction.create')
                ->name('transactions.withdraw.store');

            // View Routes (Produk, Rekening, Detail Transaksi)
            Route::middleware('permission:shm.savings.view')->group(function () {
                Route::get('/produk', [ShmSavingProductController::class, 'index'])->name('products.index');
                Route::get('/rekening', [ShmSavingsAccountController::class, 'index'])->name('accounts.index');
                Route::get('/rekening/{account}', [ShmSavingsAccountController::class, 'show'])->name('accounts.show');
                Route::get('/transaksi/{transaction}', [ShmSavingsTransactionController::class, 'show'])->name('transactions.show');
            });
        });

        // -----------------------------------------------------------------
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

        // -----------------------------------------------------------------
        // T006B: Modul Akuntansi SHM — Chart of Accounts Master Data & XLSX
        // -----------------------------------------------------------------
        Route::prefix('akuntansi')->name('coa.')->group(function () {
            Route::middleware('permission:accounting.shm.view')->group(function () {
                Route::get('/coa', [ShmAccountController::class, 'index'])->name('index');
                Route::get('/coa/export', [ShmAccountController::class, 'export'])->name('export');
                Route::get('/coa/template', [ShmAccountController::class, 'template'])->name('template');
            });

            Route::middleware('permission:accounting.shm.accounts.manage')->group(function () {
                Route::get('/coa/create', [ShmAccountController::class, 'create'])->name('create');
                Route::post('/coa', [ShmAccountController::class, 'store'])->name('store');
                Route::post('/coa/import', [ShmAccountController::class, 'import'])->name('import');
                Route::get('/coa/{account}/edit', [ShmAccountController::class, 'edit'])->name('edit');
                Route::put('/coa/{account}', [ShmAccountController::class, 'update'])->name('update');
                Route::patch('/coa/{account}/deactivate', [ShmAccountController::class, 'deactivate'])->name('deactivate');
            });
        });

        // -----------------------------------------------------------------
        // T006C: Modul Akuntansi SHM — Product Accounting Configuration
        // -----------------------------------------------------------------
        Route::prefix('akuntansi/produk')->name('product-accounting.')->group(function () {
            Route::middleware('permission:accounting.shm.view')->group(function () {
                Route::get('/', [ShmProductAccountingController::class, 'index'])->name('index');
            });

            Route::middleware('permission:accounting.shm.accounts.manage')->group(function () {
                Route::get('/{product}/edit', [ShmProductAccountingController::class, 'edit'])->name('edit');
                Route::put('/{product}', [ShmProductAccountingController::class, 'update'])->name('update');
                Route::post('/{config}/toggle', [ShmProductAccountingController::class, 'toggle'])->name('toggle');
            });
        });

        // -----------------------------------------------------------------
        // T006D: Modul Akuntansi SHM — Accounting Mapping & Journal Engine
        // -----------------------------------------------------------------
        Route::prefix('akuntansi/mapping')->name('accounting-mapping.')->group(function () {
            Route::middleware('permission:accounting.shm.view')->group(function () {
                Route::get('/', [ShmAccountingMappingController::class, 'index'])->name('index');
            });

            Route::middleware('permission:accounting.shm.accounts.manage')->group(function () {
                Route::get('/create', [ShmAccountingMappingController::class, 'create'])->name('create');
                Route::post('/', [ShmAccountingMappingController::class, 'store'])->name('store');
                Route::get('/{mapping}/edit', [ShmAccountingMappingController::class, 'edit'])->name('edit');
                Route::put('/{mapping}', [ShmAccountingMappingController::class, 'update'])->name('update');
                Route::post('/{mapping}/toggle', [ShmAccountingMappingController::class, 'toggle'])->name('toggle');
            });
        });

        // -----------------------------------------------------------------
        // Modul Akuntansi SHM — Jurnal Harian (Daily Journal)
        // -----------------------------------------------------------------
        Route::prefix('akuntansi/jurnal-harian')->name('daily-journal.')->group(function () {
            Route::middleware('permission:accounting.shm.reports.view')->group(function () {
                Route::get('/', [ShmDailyJournalController::class, 'index'])->name('index');
                Route::get('/export', [ShmDailyJournalController::class, 'export'])->name('export');
            });
        });

        // -----------------------------------------------------------------
        // T006F: Modul Akuntansi SHM — Neraca Saldo (Trial Balance)
        // -----------------------------------------------------------------
        Route::prefix('akuntansi/neraca-saldo')->name('trial-balance.')->group(function () {
            Route::middleware('permission:accounting.shm.reports.view')->group(function () {
                Route::get('/', [ShmTrialBalanceController::class, 'index'])->name('index');
                Route::get('/export', [ShmTrialBalanceController::class, 'export'])->name('export');
                Route::get('/template', [ShmTrialBalanceController::class, 'template'])->name('template');
            });
        });

        // -----------------------------------------------------------------
        // T006G: Modul Akuntansi SHM — Laba Rugi Periode (Period Profit & Loss)
        // -----------------------------------------------------------------
        Route::prefix('akuntansi/laba-rugi-periode')->name('period-profit-loss.')->group(function () {
            Route::middleware('permission:accounting.shm.reports.view')->group(function () {
                Route::get('/', [ShmPeriodProfitLossController::class, 'index'])->name('index');
                Route::get('/export', [ShmPeriodProfitLossController::class, 'export'])->name('export');
                Route::get('/template', [ShmPeriodProfitLossController::class, 'template'])->name('template');
            });
        });

        // -----------------------------------------------------------------
        // T006H: Modul Akuntansi SHM — Laba Rugi Kumulatif (Cumulative Profit & Loss)
        // -----------------------------------------------------------------
        Route::prefix('akuntansi/laba-rugi-kumulatif')->name('cumulative-profit-loss.')->group(function () {
            Route::middleware('permission:accounting.shm.reports.view')->group(function () {
                Route::get('/', [ShmCumulativeProfitLossController::class, 'index'])->name('index');
                Route::get('/export', [ShmCumulativeProfitLossController::class, 'export'])->name('export');
                Route::get('/template', [ShmCumulativeProfitLossController::class, 'template'])->name('template');
            });
        });

        // -----------------------------------------------------------------
        // TICKET 31: Modul Akuntansi SHM — Arus Kas Masuk & Arus Kas Keluar
        // -----------------------------------------------------------------
        Route::prefix('akuntansi/arus-kas-masuk')->name('cash-inflow.')->group(function () {
            Route::middleware('permission:accounting.shm.journals.view')->group(function () {
                Route::get('/', [ShmCashFlowController::class, 'indexInflow'])->name('index');
            });
            Route::middleware('permission:accounting.shm.journals.create')->group(function () {
                Route::post('/', [ShmCashFlowController::class, 'storeInflow'])->name('store');
                Route::put('/{transaction}', [ShmCashFlowController::class, 'updateInflow'])->name('update');
                Route::delete('/{transaction}', [ShmCashFlowController::class, 'destroyInflow'])->name('destroy');
            });
            Route::middleware('permission:accounting.shm.journals.post')->group(function () {
                Route::post('/{transaction}/post', [ShmCashFlowController::class, 'postInflow'])->name('post');
            });
            Route::middleware('permission:accounting.shm.journals.reverse')->group(function () {
                Route::post('/{transaction}/reverse', [ShmCashFlowController::class, 'reverseInflow'])->name('reverse');
            });
        });

        Route::prefix('akuntansi/arus-kas-keluar')->name('cash-outflow.')->group(function () {
            Route::middleware('permission:accounting.shm.journals.view')->group(function () {
                Route::get('/', [ShmCashFlowController::class, 'indexOutflow'])->name('index');
            });
            Route::middleware('permission:accounting.shm.journals.create')->group(function () {
                Route::post('/', [ShmCashFlowController::class, 'storeOutflow'])->name('store');
                Route::put('/{transaction}', [ShmCashFlowController::class, 'updateOutflow'])->name('update');
                Route::delete('/{transaction}', [ShmCashFlowController::class, 'destroyOutflow'])->name('destroy');
            });
            Route::middleware('permission:accounting.shm.journals.post')->group(function () {
                Route::post('/{transaction}/post', [ShmCashFlowController::class, 'postOutflow'])->name('post');
            });
            Route::middleware('permission:accounting.shm.journals.reverse')->group(function () {
                Route::post('/{transaction}/reverse', [ShmCashFlowController::class, 'reverseOutflow'])->name('reverse');
            });
        });

        // -----------------------------------------------------------------
        // T006I: Modul Akuntansi SHM — Neraca (Balance Sheet)
        // -----------------------------------------------------------------
        Route::prefix('akuntansi/neraca')->name('balance-sheet.')->group(function () {
            Route::middleware('permission:accounting.shm.reports.view')->group(function () {
                Route::get('/', [ShmBalanceSheetController::class, 'index'])->name('index');
                Route::get('/export', [ShmBalanceSheetController::class, 'export'])->name('export');
                Route::get('/template', [ShmBalanceSheetController::class, 'template'])->name('template');
            });
        });

        // -----------------------------------------------------------------
        // Modul Akuntansi SHM — Import Center & Manual Posting
        // -----------------------------------------------------------------
        Route::prefix('akuntansi/import')->name('accounting-import.')->group(function () {
            Route::middleware('permission:accounting.shm.view')->group(function () {
                Route::get('/', [ShmAccountingImportController::class, 'index'])->name('index');
                Route::get('/{batch}', [ShmAccountingImportController::class, 'show'])->name('show');
                Route::get('/template/jurnal', [ShmAccountingImportController::class, 'journalTemplate'])->name('template.journal');
                Route::post('/preview/jurnal', [ShmAccountingImportController::class, 'previewJournal'])->name('preview.journal');
                Route::post('/preview/laporan', [ShmAccountingImportController::class, 'previewReport'])->name('preview.report');
            });

            Route::middleware('permission:accounting.shm.accounts.manage')->group(function () {
                Route::post('/jurnal', [ShmAccountingImportController::class, 'storeJournal'])->name('store.journal');
                Route::post('/laporan', [ShmAccountingImportController::class, 'storeReport'])->name('store.report');
            });

            Route::middleware('permission:accounting.shm.journals.post')->group(function () {
                Route::post('/jurnal/{entry}/post', [ShmAccountingImportController::class, 'postJournal'])->name('post.journal');
            });
        });

        // -----------------------------------------------------------------
        // Ticket 15I: Modul Pengaturan SHM / BSM Makro (Roles & Users)
        // -----------------------------------------------------------------
        Route::prefix('settings')->name('settings.')->group(function () {
            // Roles & Permissions SHM
            Route::prefix('roles')->name('roles.')->group(function () {
                Route::get('/', [ShmRoleController::class, 'index'])
                    ->middleware('permission:shm.role.update')
                    ->name('index');
                Route::get('/create', [ShmRoleController::class, 'create'])
                    ->middleware('permission:shm.role.update')
                    ->name('create');
                Route::post('/', [ShmRoleController::class, 'store'])
                    ->middleware('permission:shm.role.update')
                    ->name('store');
                Route::get('/{role}/edit', [ShmRoleController::class, 'edit'])
                    ->middleware('permission:shm.role.update')
                    ->name('edit');
                Route::put('/{role}', [ShmRoleController::class, 'update'])
                    ->middleware('permission:shm.role.update')
                    ->name('update');
                Route::delete('/{role}', [ShmRoleController::class, 'destroy'])
                    ->middleware('permission:shm.role.update')
                    ->name('destroy');
            });

            // Users SHM Access Management
            Route::prefix('users')->name('users.')->group(function () {
                Route::get('/', [ShmUserController::class, 'index'])
                    ->middleware('permission:shm.role.update')
                    ->name('index');
                Route::get('/{user}', [ShmUserController::class, 'show'])
                    ->middleware('permission:shm.role.update')
                    ->name('show');
                Route::patch('/{user}/role', [ShmUserController::class, 'updateRoles'])
                    ->middleware('permission:shm.role.update')
                    ->name('update-roles');
            });
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
        Route::delete('/pengguna/{user}', [UserController::class, 'destroy'])->middleware('permission:pengguna.deactivate')->name('users.destroy');
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

// Modul Rekonsiliasi dinonaktifkan (BSM Mikro Decommissioned).

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
