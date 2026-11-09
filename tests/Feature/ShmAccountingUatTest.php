<?php

namespace Tests\Feature;

use App\Models\AccountingImportBatch;
use App\Models\AccountingImportedReport;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\SimpleXlsxService;
use App\Support\AccountingContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * UAT OPERASIONAL SHM ACCOUNTING:
 * XLSX IMPORT, MANUAL POSTING & REPORTING
 */
class ShmAccountingUatTest extends TestCase
{
    use RefreshDatabase;

    private User $userImport;

    private User $userApprover;

    private User $userUnauthorized;

    private SimpleXlsxService $xlsx;

    private FinancialReportService $reportService;

    private JournalService $journalService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->xlsx = app(SimpleXlsxService::class);
        $this->reportService = app(FinancialReportService::class);
        $this->journalService = app(JournalService::class);

        // Ensure SHM Accounts exist
        ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1100'],
            ['name' => 'Kas Operasional SHM', 'category' => 'asset', 'normal_balance' => 'debit', 'is_active' => true]
        );
        ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '4-1300'],
            ['name' => 'Pendapatan Lain-lain SHM', 'category' => 'revenue', 'normal_balance' => 'credit', 'is_active' => true]
        );
        ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '5-1100'],
            ['name' => 'Beban Operasional SHM', 'category' => 'expense', 'normal_balance' => 'debit', 'is_active' => true]
        );

        // User Import: accounting.shm.view, accounting.shm.accounts.manage
        $this->userImport = User::factory()->create([
            'name' => 'Operator Import SHM',
            'email' => 'operator.import@ksp.local',
        ]);
        $importRole = Role::create([
            'name' => 'operator_shm_import',
            'display_name' => 'Operator SHM Import',
            'is_system' => false,
        ]);
        $viewPerm = Permission::where('name', 'accounting.shm.view')->first();
        $managePerm = Permission::where('name', 'accounting.shm.accounts.manage')->first();
        $importRole->permissions()->attach([$viewPerm->id, $managePerm->id]);
        $this->userImport->roles()->attach($importRole->id);

        // User Approver: accounting.shm.view, accounting.shm.journals.post
        $this->userApprover = User::factory()->create([
            'name' => 'Approver Akuntansi SHM',
            'email' => 'approver.akuntansi@ksp.local',
        ]);
        $approverRole = Role::create([
            'name' => 'approver_shm_accounting',
            'display_name' => 'Approver SHM Accounting',
            'is_system' => false,
        ]);
        $postPerm = Permission::where('name', 'accounting.shm.journals.post')->first();
        $approverRole->permissions()->attach([$viewPerm->id, $postPerm->id]);
        $this->userApprover->roles()->attach($approverRole->id);

        // Unauthorized user: no permissions
        $this->userUnauthorized = User::factory()->create([
            'name' => 'Nasabah/Marketing Umum',
            'email' => 'umum@ksp.local',
        ]);
    }

    /**
     * UAT-J01: Download Template Jurnal XLSX
     */
    public function test_uat_j01_download_template(): void
    {
        $response = $this->actingAs($this->userImport)->get(route('shm.accounting-import.template.journal'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /**
     * UAT-J02 & UAT-J03: Preview dan Import Jurnal Valid
     */
    public function test_uat_j02_preview_and_j03_import_valid_journal(): void
    {
        $rows = [
            ['Tanggal', 'Nomor Referensi', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            ['2026-11-01', 'UAT-JRN-001', '1-1100', 'Penerimaan Kas Operasional UAT', '5000000.00', '0.00'],
            ['2026-11-01', 'UAT-JRN-001', '4-1300', 'Pendapatan Lain-lain SHM UAT', '0.00', '5000000.00'],
            ['2026-11-02', 'UAT-JRN-002', '5-1100', 'Beban Operasional UAT', '750000.00', '0.00'],
            ['2026-11-02', 'UAT-JRN-002', '1-1100', 'Kas Bank Pembayaran Beban', '0.00', '750000.00'],
        ];

        $filePath = sys_get_temp_dir().'/uat_jurnal_valid_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Jurnal');
        $file = new UploadedFile($filePath, 'uat_jurnal_valid.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        // UAT-J02: Preview
        $previewResp = $this->actingAs($this->userImport)->postJson(route('shm.accounting-import.preview.journal'), [
            'file' => $file,
        ]);
        $previewResp->assertOk();
        $previewData = $previewResp->json();
        $this->assertEquals(2, $previewData['total_journals']);
        $this->assertEquals(4, $previewData['total_lines']);
        $this->assertEquals(5750000.0, (float) $previewData['total_debit']);
        $this->assertEquals(5750000.0, (float) $previewData['total_credit']);
        $this->assertTrue($previewData['is_balanced']);
        if (! empty($previewData['errors'])) {
            dump($previewData['errors']);
        }
        $this->assertEmpty($previewData['errors']);

        // UAT-J03: Store/Import
        $fileForImport = new UploadedFile($filePath, 'uat_jurnal_valid.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $importResp = $this->actingAs($this->userImport)->post(route('shm.accounting-import.store.journal'), [
            'file' => $fileForImport,
        ]);
        $importResp->assertRedirect(route('shm.accounting-import.index'));

        // Batch created and entries in DRAFT
        $batch = AccountingImportBatch::first();
        $this->assertNotNull($batch);
        $this->assertEquals(AccountingImportBatch::TYPE_JOURNAL, $batch->import_type);
        $this->assertEquals(2, $batch->success_count);
        $this->assertEquals($this->userImport->id, $batch->imported_by);

        $entries = JournalEntry::where('import_batch_id', $batch->id)->get();
        $this->assertCount(2, $entries);
        foreach ($entries as $e) {
            $this->assertEquals('DRAFT', $e->status);
            $this->assertEquals(AccountingContext::SHM, $e->accounting_context);
            $this->assertCount(2, $e->lines);
        }

        @unlink($filePath);
    }

    /**
     * UAT-J04, UAT-J05, UAT-J06: Verifikasi Status DRAFT, Manual Posting oleh User Berwenang, Penolakan User Tanpa Permission
     */
    public function test_uat_j04_j05_j06_draft_posting_workflow_and_permissions(): void
    {
        $rows = [
            ['Tanggal', 'Nomor Referensi', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            ['2026-11-05', 'UAT-POST-01', '1-1100', 'Kas Masuk UAT', '1000000.00', '0.00'],
            ['2026-11-05', 'UAT-POST-01', '4-1300', 'Pendapatan UAT', '0.00', '1000000.00'],
        ];
        $filePath = sys_get_temp_dir().'/uat_post_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Jurnal');
        $file = new UploadedFile($filePath, 'uat_post.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->userImport)->post(route('shm.accounting-import.store.journal'), [
            'file' => $file,
        ]);
        $entry = JournalEntry::first();
        $this->assertEquals('DRAFT', $entry->status);

        // UAT-J06: User Import (without posting permission) attempts to post -> 403 Forbidden
        $forbiddenResp = $this->actingAs($this->userImport)->post(route('shm.accounting-import.post.journal', $entry));
        $forbiddenResp->assertForbidden();

        // Refresh and check status remains DRAFT
        $entry->refresh();
        $this->assertEquals('DRAFT', $entry->status);

        // UAT-J05: User Approver posts -> Success
        $postResp = $this->actingAs($this->userApprover)->post(route('shm.accounting-import.post.journal', $entry));
        $postResp->assertSessionHas('success');

        $entry->refresh();
        $this->assertEquals('POSTED', $entry->status);
        $this->assertEquals($this->userApprover->id, $entry->posted_by);
        $this->assertNotNull($entry->posted_at);

        // Posting again should fail (JournalService handles idempotent / already posted guard)
        $this->expectException(ValidationException::class);
        $this->journalService->post($entry, $this->userApprover->id);

        @unlink($filePath);
    }

    /**
     * UAT-J07: Import Jurnal Tidak Valid (Unbalanced)
     */
    public function test_uat_j07_invalid_unbalanced_journal_rejected_atomically(): void
    {
        $rows = [
            ['Tanggal', 'Nomor Referensi', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            ['2026-11-01', 'UAT-UNBAL', '1-1100', 'Kas Masuk', '1000000.00', '0.00'],
            ['2026-11-01', 'UAT-UNBAL', '4-1300', 'Pendapatan Beda', '0.00', '950000.00'], // Unbalanced!
        ];
        $filePath = sys_get_temp_dir().'/uat_unbal_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Jurnal');
        $file = new UploadedFile($filePath, 'uat_unbalanced.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        // Preview reports error
        $preview = $this->actingAs($this->userImport)->postJson(route('shm.accounting-import.preview.journal'), [
            'file' => $file,
        ]);
        $preview->assertOk();
        $this->assertFalse($preview->json('is_balanced'));
        $this->assertNotEmpty($preview->json('errors'));

        // Import is rejected with validation exception
        $file2 = new UploadedFile($filePath, 'uat_unbalanced.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $importResp = $this->actingAs($this->userImport)->post(route('shm.accounting-import.store.journal'), [
            'file' => $file2,
        ]);
        $importResp->assertSessionHasErrors('file');

        // Atomicity: 0 journals, 0 batches created
        $this->assertEquals(0, JournalEntry::count());
        $this->assertEquals(0, AccountingImportBatch::count());

        @unlink($filePath);
    }

    /**
     * UAT-J08: Duplicate Journal Import Prevention
     */
    public function test_uat_j08_duplicate_journal_rejected_by_checksum_and_reference(): void
    {
        $rows = [
            ['Tanggal', 'Nomor Referensi', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            ['2026-11-01', 'REF-DUP-001', '1-1100', 'Kas Masuk', '500000.00', '0.00'],
            ['2026-11-01', 'REF-DUP-001', '4-1300', 'Pendapatan', '0.00', '500000.00'],
        ];
        $filePath1 = sys_get_temp_dir().'/uat_dup1_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath1, $rows, 'Jurnal');
        $file1 = new UploadedFile($filePath1, 'jurnal_asli.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->userImport)->post(route('shm.accounting-import.store.journal'), [
            'file' => $file1,
        ])->assertRedirect();

        // 1. Same file (checksum duplicate)
        $file1Again = new UploadedFile($filePath1, 'jurnal_asli.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $respChecksumDup = $this->actingAs($this->userImport)->post(route('shm.accounting-import.store.journal'), [
            'file' => $file1Again,
        ]);
        $respChecksumDup->assertSessionHasErrors('file');

        // 2. Different file but containing identical reference number
        $rows2 = [
            ['Tanggal', 'Nomor Referensi', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            ['2026-11-01', 'REF-DUP-001', '1-1100', 'Kas Masuk Beda', '600000.00', '0.00'],
            ['2026-11-01', 'REF-DUP-001', '4-1300', 'Pendapatan Beda', '0.00', '600000.00'],
        ];
        $filePath2 = sys_get_temp_dir().'/uat_dup2_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath2, $rows2, 'Jurnal');
        $file2 = new UploadedFile($filePath2, 'jurnal_lain.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $respRefDup = $this->actingAs($this->userImport)->post(route('shm.accounting-import.store.journal'), [
            'file' => $file2,
        ]);
        $respRefDup->assertSessionHasErrors('file');

        @unlink($filePath1);
        @unlink($filePath2);
    }

    /**
     * UAT-R01 through UAT-R05: Import Semua 5 Jenis Laporan Keuangan
     */
    public function test_uat_r01_to_r05_import_all_five_report_types(): void
    {
        $reportTypes = [
            AccountingImportBatch::REPORT_BALANCE_SHEET => [
                'name' => 'uat_laporan_neraca.xlsx',
                'title' => 'Laporan Neraca SHM',
                'section' => 'Aset',
            ],
            AccountingImportBatch::REPORT_PERIOD_PROFIT_LOSS => [
                'name' => 'uat_laporan_laba_rugi_periode.xlsx',
                'title' => 'Laporan Laba Rugi Periode SHM',
                'section' => 'Pendapatan',
            ],
            AccountingImportBatch::REPORT_CUMULATIVE_PROFIT_LOSS => [
                'name' => 'uat_laporan_laba_rugi_kumulatif.xlsx',
                'title' => 'Laporan Laba Rugi Kumulatif SHM',
                'section' => 'Pendapatan',
            ],
            AccountingImportBatch::REPORT_TRIAL_BALANCE => [
                'name' => 'uat_laporan_trial_balance.xlsx',
                'title' => 'Laporan Neraca Saldo SHM',
                'section' => 'Aktiva',
            ],
            AccountingImportBatch::REPORT_DAILY_JOURNAL => [
                'name' => 'uat_laporan_daily_journal.xlsx',
                'title' => 'Buku Jurnal Harian SHM',
                'section' => 'Jurnal',
            ],
        ];

        foreach ($reportTypes as $type => $meta) {
            $rows = [
                [$meta['title']],
                ['Periode 2026-11-01 s/d 2026-11-30'],
                [$meta['section'], '', ''],
                ['1-1100', 'Kas Bank Operasional', '15000000.00'],
                ['Total '.$meta['section'], '', '15000000.00'],
            ];

            $filePath = sys_get_temp_dir().'/'.$meta['name'];
            $this->xlsx->writeRows($filePath, $rows, 'Sheet1');
            $file = new UploadedFile($filePath, $meta['name'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

            // Preview
            $previewResp = $this->actingAs($this->userImport)->postJson(route('shm.accounting-import.preview.report'), [
                'file' => $file,
                'report_type' => $type,
            ]);
            $previewResp->assertOk();
            $this->assertEquals($type, $previewResp->json('report_type'));
            $this->assertGreaterThan(0, $previewResp->json('total_rows'));

            // Store
            $fileForStore = new UploadedFile($filePath, $meta['name'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $storeResp = $this->actingAs($this->userImport)->post(route('shm.accounting-import.store.report'), [
                'file' => $fileForStore,
                'report_type' => $type,
            ]);
            $storeResp->assertRedirect(route('shm.accounting-import.index'));

            // Ledger Isolation Check: 0 journal entries created
            $this->assertEquals(0, JournalEntry::count(), "Report {$type} must not create journal entries!");

            // Report records created in accounting_imported_reports
            $this->assertTrue(
                AccountingImportedReport::where('report_type', $type)->exists(),
                "Report {$type} records must exist in accounting_imported_reports"
            );

            @unlink($filePath);
        }

        $this->assertEquals(5, AccountingImportBatch::where('import_type', AccountingImportBatch::TYPE_REPORT)->count());
    }

    /**
     * UAT-R06: Duplicate Report Import Prevention
     */
    public function test_uat_r06_duplicate_report_rejected(): void
    {
        $rows = [
            ['Template Neraca SHM'],
            ['Laporan Keuangan Per 2026-11-30'],
            ['ASET', '', ''],
            ['1-1100', 'Kas', '10000000.00'],
        ];

        $filePath = sys_get_temp_dir().'/uat_neraca_orig.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Neraca');
        $file1 = new UploadedFile($filePath, 'neraca_orig.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->userImport)->post(route('shm.accounting-import.store.report'), [
            'file' => $file1,
            'report_type' => AccountingImportBatch::REPORT_BALANCE_SHEET,
        ])->assertRedirect();

        // Second import of same report type and same period date
        $rows2 = [
            ['Template Neraca SHM'],
            ['Laporan Keuangan Per 2026-11-30'],
            ['ASET', '', ''],
            ['1-1100', 'Kas Bank', '12000000.00'],
        ];
        $filePath2 = sys_get_temp_dir().'/uat_neraca_dup.xlsx';
        $this->xlsx->writeRows($filePath2, $rows2, 'Neraca');
        $file2 = new UploadedFile($filePath2, 'neraca_dup.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $dupResp = $this->actingAs($this->userImport)->post(route('shm.accounting-import.store.report'), [
            'file' => $file2,
            'report_type' => AccountingImportBatch::REPORT_BALANCE_SHEET,
        ]);
        $dupResp->assertSessionHasErrors('file');

        @unlink($filePath);
        @unlink($filePath2);
    }

    /**
     * UAT-L01: Ledger Verification (DRAFT tidak mengubah ledger, POSTED mengubah ledger, Laporan Impor terisolasi)
     */
    public function test_uat_l01_ledger_verification_before_and_after_posting(): void
    {
        $date = '2026-11-15';
        $amount = 1000000.0;

        $kasAccount = ChartOfAccount::where('code', '1-1100')->where('accounting_context', AccountingContext::SHM)->firstOrFail();
        $pendapatanAccount = ChartOfAccount::where('code', '4-1300')->where('accounting_context', AccountingContext::SHM)->firstOrFail();

        // 1. Initial State: Ledger query before anything
        $initialLedgerKas = $this->journalService->ledger($kasAccount->id, $date, $date, AccountingContext::SHM);
        $initialLedgerPendapatan = $this->journalService->ledger($pendapatanAccount->id, $date, $date, AccountingContext::SHM);

        // 2. Import Valid Journal (Debit Kas 1.000.000, Kredit Pendapatan 1.000.000)
        $rows = [
            ['Tanggal', 'Nomor Referensi', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            [$date, 'UAT-LEDGER-01', '1-1100', 'Penerimaan Kas UAT', '1000000.00', '0.00'],
            [$date, 'UAT-LEDGER-01', '4-1300', 'Pendapatan UAT', '0.00', '1000000.00'],
        ];
        $filePath = sys_get_temp_dir().'/uat_ledger_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Jurnal');
        $file = new UploadedFile($filePath, 'uat_ledger.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->userImport)->post(route('shm.accounting-import.store.journal'), [
            'file' => $file,
        ]);

        $entry = JournalEntry::where('accounting_context', AccountingContext::SHM)->first();
        $this->assertEquals('DRAFT', $entry->status);

        // Also import a separate report spreadsheet to verify mutual isolation
        $repRows = [
            ['Laporan Neraca Manual'],
            ['Per 2026-11-15'],
            ['ASET', '', ''],
            ['1-1100', 'Kas', '8888888.00'],
        ];
        $repPath = sys_get_temp_dir().'/uat_rep_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($repPath, $repRows, 'Neraca');
        $repFile = new UploadedFile($repPath, 'rep.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->actingAs($this->userImport)->post(route('shm.accounting-import.store.report'), [
            'file' => $repFile,
            'report_type' => AccountingImportBatch::REPORT_BALANCE_SHEET,
        ]);

        // VERIFIKASI SEBELUM POSTING:
        // Saldo ledger tidak boleh berubah dari DRAFT journal (0 mutasi di ledger)
        $prePostKas = $this->journalService->ledger($kasAccount->id, $date, $date, AccountingContext::SHM);
        $prePostPendapatan = $this->journalService->ledger($pendapatanAccount->id, $date, $date, AccountingContext::SHM);
        $this->assertEquals($initialLedgerKas['totals']['debit'], $prePostKas['totals']['debit']);
        $this->assertEquals($initialLedgerPendapatan['totals']['credit'], $prePostPendapatan['totals']['credit']);
        $this->assertCount(0, $prePostKas['rows']);
        $this->assertCount(0, $prePostPendapatan['rows']);

        // Trial Balance pada FinancialReportService juga masih 0
        $tbPre = $this->reportService->trialBalance($date, $date, false, AccountingContext::SHM);
        $this->assertEquals(0.0, (float) $tbPre['totals']['debit']);
        $this->assertEquals(0.0, (float) $tbPre['totals']['credit']);

        // VERIFIKASI POSTING MANUAL:
        $postResponse = $this->actingAs($this->userApprover)->post(route('shm.accounting-import.post.journal', $entry));
        $entry->refresh();
        $this->assertEquals('POSTED', $entry->status);

        // VERIFIKASI SETELAH POSTING:
        // Saldo ledger sekarang bertambah tepat 1.000.000
        // VERIFIKASI SETELAH POSTING:
        // Saldo ledger sekarang bertambah tepat 1.000.000
        $postKas = $this->journalService->ledger($kasAccount->id, '2026-11-01', '2026-11-30', AccountingContext::SHM);
        $postPendapatan = $this->journalService->ledger($pendapatanAccount->id, '2026-11-01', '2026-11-30', AccountingContext::SHM);

        $this->assertEquals($initialLedgerKas['totals']['debit'] + $amount, (float) $postKas['totals']['debit']);
        $this->assertEquals($initialLedgerPendapatan['totals']['credit'] + $amount, (float) $postPendapatan['totals']['credit']);
        $this->assertCount(1, $postKas['rows']);
        $this->assertCount(1, $postPendapatan['rows']);

        // Laporan Neraca Saldo (Trial Balance) seimbang dan mencakup mutasi posting
        $tbPost = $this->reportService->trialBalance('2026-11-01', '2026-11-30', false, AccountingContext::SHM);
        $this->assertEquals($amount, (float) $tbPost['totals']['debit']);
        $this->assertEquals($amount, (float) $tbPost['totals']['credit']);
        $this->assertTrue($tbPost['balanced']);

        // Data laporan impor tetap tidak berubah (8.888.888) dan terisolasi dari ledger
        $importedRepRow = AccountingImportedReport::where('account_code', '1-1100')->first();
        $this->assertEquals(8888888.00, (float) $importedRepRow->amount);

        @unlink($filePath);
        @unlink($repPath);
    }
}
