<?php

namespace Tests\Feature;

use App\Models\AccountingImportBatch;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\SimpleXlsxService;
use App\Support\AccountingContext;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Concurrency\ConcurrencyHarness;
use Tests\TestCase;

/**
 * TICKET 35A — Isolated PostgreSQL Concurrency Test Suite for SHM Accounting Import & Journal Integrity.
 */
#[RequiresPhpExtension('pdo_pgsql')]
class ShmImportPostgresConcurrencyTest extends TestCase
{
    private ConcurrencyHarness $harness;

    private User $financeUser;

    private bool $concurrencyDatabaseReady = false;

    private SimpleXlsxService $xlsx;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->postgresAvailable()) {
            $this->markTestSkipped('PostgreSQL untuk pengujian konkurensi tidak tersedia.');
        }

        $this->harness = new ConcurrencyHarness($this);
        $this->harness->setUpDatabase();
        $this->concurrencyDatabaseReady = true;

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(MakroPhysicalRoleSeeder::class);

        // Seed SHM CoA on isolated pgsql database
        ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1100'],
            ['name' => 'Kas Operasional SHM', 'category' => 'asset', 'normal_balance' => 'debit', 'is_active' => true]
        );
        ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '4-1100'],
            ['name' => 'Pendapatan Bunga SHM', 'category' => 'revenue', 'normal_balance' => 'credit', 'is_active' => true]
        );

        $this->financeUser = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::where('name', 'finance_operational_makro')->first();
        if ($role) {
            $this->financeUser->roles()->attach($role->id);
        }

        $this->xlsx = app(SimpleXlsxService::class);
    }

    protected function tearDown(): void
    {
        if ($this->concurrencyDatabaseReady) {
            $this->harness->tearDownDatabase();
        }

        parent::tearDown();
    }

    private function postgresAvailable(): bool
    {
        try {
            $base = config('database.connections.pgsql');
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $base['host'], $base['port'] ?? 5432);
            new \PDO($dsn, $base['username'], $base['password'], [\PDO::ATTR_TIMEOUT => 2]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 1. Concurrency: Identical file upload simultaneously.
     * Expected: Exactly 1 succeeds, other fails (validation / unique conflict), exactly 1 batch created.
     */
    public function test_concurrent_identical_file_import_creates_single_batch(): void
    {
        $rows = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            [now()->toDateString(), 'JU-CONC-001', '1-1100', 'Kas Masuk Konkuren', '1000000', '0'],
            [now()->toDateString(), 'JU-CONC-001', '4-1100', 'Pendapatan Konkuren', '0', '1000000'],
        ];

        $filePath = sys_get_temp_dir().'/conc_file_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Jurnal');

        $tasks = [
            ['task' => 'shm_journal_import', 'params' => ['file_path' => $filePath, 'user_id' => $this->financeUser->id]],
            ['task' => 'shm_journal_import', 'params' => ['file_path' => $filePath, 'user_id' => $this->financeUser->id]],
        ];

        $results = $this->harness->runParallel($tasks);

        // Exactly 1 successful worker
        $this->assertSame(1, $results->whereOk()->count(), 'Tepat 1 worker harus berhasil mengimpor berkas identik.');
        $this->assertSame(1, $results->count() - $results->whereOk()->count(), 'Worker kedua harus ditolak karena duplikasi checksum.');

        // Database assertion: Exactly 1 batch and 1 journal created
        $batchCount = AccountingImportBatch::query()
            ->where('accounting_context', AccountingContext::SHM)
            ->where('status', 'COMPLETED')
            ->count();
        $this->assertSame(1, $batchCount, 'Tepat 1 batch COMPLETED harus tercatat di PostgreSQL.');

        $journalCount = JournalEntry::query()
            ->where('accounting_context', AccountingContext::SHM)
            ->where('description', 'Jurnal Impor JU-CONC-001')
            ->count();
        $this->assertSame(1, $journalCount, 'Tepat 1 jurnal harus dibuat, tidak boleh ada duplikasi.');

        @unlink($filePath);
    }

    /**
     * 2. Concurrency: Two differently formatted files with identical voucher ref concurrently.
     * Expected: Exactly 1 succeeds, other fails via semantic duplicate check / lock.
     */
    public function test_concurrent_semantic_duplicate_voucher_import(): void
    {
        $rows1 = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            [now()->toDateString(), 'JU-SEM-DUP', '1-1100', 'Kas Masuk File A', '5000000', '0'],
            [now()->toDateString(), 'JU-SEM-DUP', '4-1100', 'Pendapatan Bunga File A', '0', '5000000'],
        ];
        $rows2 = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            [now()->toDateString(), 'JU-SEM-DUP', '1-1100', 'Kas Masuk Format B Berbeda', '5000000', '0'],
            [now()->toDateString(), 'JU-SEM-DUP', '4-1100', 'Pendapatan Bunga Format B', '0', '5000000'],
        ];

        $filePath1 = sys_get_temp_dir().'/conc_sem_1_'.uniqid().'.xlsx';
        $filePath2 = sys_get_temp_dir().'/conc_sem_2_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath1, $rows1, 'SheetA');
        $this->xlsx->writeRows($filePath2, $rows2, 'SheetB');

        $tasks = [
            ['task' => 'shm_journal_import', 'params' => ['file_path' => $filePath1, 'user_id' => $this->financeUser->id]],
            ['task' => 'shm_journal_import', 'params' => ['file_path' => $filePath2, 'user_id' => $this->financeUser->id]],
        ];

        $results = $this->harness->runParallel($tasks);

        $this->assertSame(1, $results->whereOk()->count(), 'Tepat 1 import semantic harus berhasil.');
        $this->assertSame(1, $results->count() - $results->whereOk()->count(), 'Import kedua dengan nomor bukti sama harus digagalkan.');

        $journalCount = JournalEntry::query()
            ->where('accounting_context', AccountingContext::SHM)
            ->where('description', 'like', '%JU-SEM-DUP%')
            ->count();
        $this->assertSame(1, $journalCount, 'Hanya ada 1 record jurnal yang tersimpan di PostgreSQL.');

        @unlink($filePath1);
        @unlink($filePath2);
    }

    /**
     * 3. Concurrency: Repeated posting of the same journal entry concurrently.
     * Expected: Exactly 1 succeeds, other fails (validation: "Hanya jurnal DRAFT yang dapat diposting").
     */
    public function test_concurrent_repeated_posting_protection(): void
    {
        $journalService = app(JournalService::class);
        $kas = ChartOfAccount::where('code', '1-1100')->where('accounting_context', AccountingContext::SHM)->first();
        $pendapatan = ChartOfAccount::where('code', '4-1100')->where('accounting_context', AccountingContext::SHM)->first();

        $entry = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Jurnal Uji Konkuren Posting',
            'posting_type' => 'import_xlsx',
        ], [
            ['account_id' => $kas->id, 'description' => 'Debit Kas', 'debit' => 2000000, 'credit' => 0],
            ['account_id' => $pendapatan->id, 'description' => 'Kredit Pendapatan', 'debit' => 0, 'credit' => 2000000],
        ], $this->financeUser->id);

        $tasks = [
            ['task' => 'shm_journal_post', 'params' => ['entry_id' => $entry->id, 'user_id' => $this->financeUser->id]],
            ['task' => 'shm_journal_post', 'params' => ['entry_id' => $entry->id, 'user_id' => $this->financeUser->id]],
        ];

        $results = $this->harness->runParallel($tasks);

        $this->assertSame(1, $results->whereOk()->count(), 'Tepat 1 request posting yang berhasil.');
        $this->assertSame(1, $results->count() - $results->whereOk()->count(), 'Request posting kedua harus ditolak karena status sudah POSTED.');

        $entry->refresh();
        $this->assertSame(JournalEntry::STATUS_POSTED, $entry->status);
    }

    /**
     * 4. Concurrency: Repeated reversal of the same posted journal entry concurrently.
     * Expected: Exactly 1 succeeds, other fails (validation: "Jurnal ini sudah pernah direversi").
     */
    public function test_concurrent_repeated_reversal_protection(): void
    {
        $journalService = app(JournalService::class);
        $kas = ChartOfAccount::where('code', '1-1100')->where('accounting_context', AccountingContext::SHM)->first();
        $pendapatan = ChartOfAccount::where('code', '4-1100')->where('accounting_context', AccountingContext::SHM)->first();

        $entry = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Jurnal Uji Konkuren Reversal',
            'posting_type' => 'import_xlsx',
        ], [
            ['account_id' => $kas->id, 'description' => 'Debit Kas', 'debit' => 3000000, 'credit' => 0],
            ['account_id' => $pendapatan->id, 'description' => 'Kredit Pendapatan', 'debit' => 0, 'credit' => 3000000],
        ], $this->financeUser->id);

        $entry = $journalService->post($entry, $this->financeUser->id);

        $tasks = [
            ['task' => 'shm_journal_reverse', 'params' => ['entry_id' => $entry->id, 'user_id' => $this->financeUser->id, 'reason' => 'Worker 1']],
            ['task' => 'shm_journal_reverse', 'params' => ['entry_id' => $entry->id, 'user_id' => $this->financeUser->id, 'reason' => 'Worker 2']],
        ];

        $results = $this->harness->runParallel($tasks);

        $this->assertSame(1, $results->whereOk()->count(), 'Tepat 1 pembalikan (reversal) yang berhasil.');
        $this->assertSame(1, $results->count() - $results->whereOk()->count(), 'Pembalikan kedua harus ditolak.');

        $entry->refresh();
        $this->assertSame(JournalEntry::STATUS_REVERSED, $entry->status);
        $this->assertNotNull($entry->reversed_by_entry_id);

        // Exactly 1 reversal journal created
        $reversalCount = JournalEntry::where('id', $entry->reversed_by_entry_id)
            ->count();
        $this->assertSame(1, $reversalCount, 'Tepat 1 jurnal pembalik yang dibuat.');
    }
}
