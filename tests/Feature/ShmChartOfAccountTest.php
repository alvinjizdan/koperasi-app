<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\ShmChartOfAccountService;
use App\Services\Accounting\SimpleXlsxService;
use App\Support\AccountingContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShmChartOfAccountTest extends TestCase
{
    use RefreshDatabase;

    private ShmChartOfAccountService $coaService;

    private SimpleXlsxService $xlsxService;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->coaService = app(ShmChartOfAccountService::class);
        $this->xlsxService = app(SimpleXlsxService::class);

        $this->admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id);
        }

        $this->staff = User::factory()->create();
    }

    /**
     * 1. SHM CoA list excludes Cooperative accounts.
     */
    public function test_shm_coa_list_excludes_cooperative_accounts(): void
    {
        ChartOfAccount::create([
            'accounting_context' => AccountingContext::KOPERASI,
            'code' => '1-1100',
            'name' => 'Kas Koperasi',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '1-1100',
            'name' => 'Kas SHM',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('shm.coa.index'));
        $response->assertOk();

        $accounts = $response->viewData('page')['props']['accounts'];
        $this->assertCount(1, $accounts);
        $this->assertEquals('Kas SHM', $accounts[0]['name']);
    }

    /**
     * 2. Cooperative CoA list excludes SHM accounts.
     */
    public function test_cooperative_coa_list_excludes_shm_accounts(): void
    {
        ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '9-9999',
            'name' => 'Akun Khusus SHM',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('akuntansi.coa.index'));
        $response->assertOk();

        $accounts = $response->viewData('page')['props']['accounts'];
        $codes = collect($accounts)->pluck('code')->all();
        $this->assertNotContains('9-9999', $codes);
    }

    /**
     * 3. SHM create creates account with SHM context strictly.
     */
    public function test_shm_create_assigns_shm_context(): void
    {
        $response = $this->actingAs($this->admin)->post(route('shm.coa.store'), [
            'code' => '1-2000',
            'name' => 'Piutang Pinjaman SHM',
            'category' => 'asset',
            'description' => 'Akun piutang SHM',
        ]);

        $response->assertRedirect(route('shm.coa.index'));

        $this->assertDatabaseHas('chart_of_accounts', [
            'code' => '1-2000',
            'name' => 'Piutang Pinjaman SHM',
            'accounting_context' => AccountingContext::SHM,
        ]);
    }

    /**
     * 4. SHM update cannot modify a Cooperative account.
     */
    public function test_shm_update_cannot_modify_cooperative_account(): void
    {
        $koperasiAcc = ChartOfAccount::create([
            'accounting_context' => AccountingContext::KOPERASI,
            'code' => '1-5555',
            'name' => 'Akun Koperasi',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
        ]);

        $response = $this->actingAs($this->admin)->put(route('shm.coa.update', $koperasiAcc->id), [
            'code' => '1-5555-MOD',
            'name' => 'Hijack Koperasi',
            'category' => 'asset',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('chart_of_accounts', ['name' => 'Hijack Koperasi']);
    }

    /**
     * 5. Duplicate account code inside SHM is rejected, but same code can exist across Koperasi and SHM.
     */
    public function test_shm_code_uniqueness_and_cross_context_coexistence(): void
    {
        ChartOfAccount::create([
            'accounting_context' => AccountingContext::KOPERASI,
            'code' => '1-1100',
            'name' => 'Kas Koperasi',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
        ]);

        // Creating 1-1100 in SHM must succeed
        $acc = $this->coaService->createAccount([
            'code' => '1-1100',
            'name' => 'Kas SHM',
            'category' => 'asset',
        ]);
        $this->assertNotNull($acc->id);

        // Creating 1-1100 in SHM again must fail
        $this->expectException(ValidationException::class);
        $this->coaService->createAccount([
            'code' => '1-1100',
            'name' => 'Kas SHM Duplicate',
            'category' => 'asset',
        ]);
    }

    /**
     * 6. Invalid category and normal balance rejected.
     */
    public function test_invalid_category_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->coaService->createAccount([
            'code' => '9-9999',
            'name' => 'Invalid Acc',
            'category' => 'unsupported_category',
        ]);
    }

    /**
     * 7. Account referenced by journal lines cannot be destructively deleted (deactivated instead).
     */
    public function test_referenced_shm_account_is_deactivated_not_deleted(): void
    {
        $account = $this->coaService->createAccount([
            'code' => '1-7777',
            'name' => 'Kas Operasional Khusus',
            'category' => 'asset',
        ]);

        $journal = JournalEntry::create([
            'accounting_context' => AccountingContext::SHM,
            'journal_number' => 'JRNL-TEST-001',
            'transaction_date' => now()->toDateString(),
            'status' => JournalEntry::STATUS_POSTED,
            'posting_type' => 'manual',
            'created_by' => $this->admin->id,
        ]);

        JournalLine::create([
            'journal_entry_id' => $journal->id,
            'account_id' => $account->id,
            'debit' => 100000,
            'credit' => 0,
            'line_order' => 1,
        ]);

        $result = $this->coaService->deactivateOrDelete($account);
        $this->assertEquals('deactivated', $result['action']);

        $this->assertDatabaseHas('chart_of_accounts', [
            'id' => $account->id,
            'is_active' => false,
        ]);
    }

    /**
     * 8. Unreferenced SHM account can be deleted.
     */
    public function test_unreferenced_shm_account_can_be_deleted(): void
    {
        $account = $this->coaService->createAccount([
            'code' => '1-8888',
            'name' => 'Akun Sementara',
            'category' => 'asset',
        ]);

        $result = $this->coaService->deactivateOrDelete($account);
        $this->assertEquals('deleted', $result['action']);

        $this->assertDatabaseMissing('chart_of_accounts', [
            'id' => $account->id,
        ]);
    }

    /**
     * 9. Permission boundaries: Unauthorized user cannot access SHM CoA.
     */
    public function test_unauthorized_user_cannot_access_shm_coa(): void
    {
        $response = $this->actingAs($this->staff)->get(route('shm.coa.index'));
        $response->assertForbidden();

        $postResponse = $this->actingAs($this->staff)->post(route('shm.coa.store'), [
            'code' => '1-1111',
            'name' => 'Illegal Acc',
            'category' => 'asset',
        ]);
        $postResponse->assertForbidden();
    }

    /**
     * 10. XLSX Import: Valid file imports atomically.
     */
    public function test_valid_xlsx_import(): void
    {
        $tempPath = sys_get_temp_dir().'/test_import_'.uniqid().'.xlsx';
        $rows = [
            ['Kode Akun', 'Nama Akun', 'Kategori', 'Saldo Normal', 'Kode Induk', 'Status', 'Keterangan'],
            ['1-1000', 'Aset Lancar SHM', 'asset', 'debit', '', 'ACTIVE', 'Induk'],
            ['1-1100', 'Kas SHM', 'asset', 'debit', '1-1000', 'ACTIVE', 'Kas'],
            ['4-1100', 'Pendapatan Jasa SHM', 'revenue', 'credit', '', 'ACTIVE', 'Pendapatan'],
        ];
        $this->xlsxService->writeRows($tempPath, $rows);

        $uploadedFile = new UploadedFile($tempPath, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $result = $this->coaService->importFromXlsx($uploadedFile, $this->admin->id);

        $this->assertEquals(3, $result['total_rows']);
        $this->assertEquals(3, $result['successful_rows']);
        $this->assertEquals(0, $result['failed_rows']);

        $this->assertDatabaseHas('chart_of_accounts', [
            'accounting_context' => AccountingContext::SHM,
            'code' => '1-1100',
            'name' => 'Kas SHM',
        ]);

        $parent = ChartOfAccount::query()->shm()->where('code', '1-1000')->first();
        $child = ChartOfAccount::query()->shm()->where('code', '1-1100')->first();
        $this->assertEquals($parent->id, $child->parent_id);
    }

    /**
     * 11. XLSX Import: Rejects .xls and .csv files.
     */
    public function test_import_rejects_non_xlsx_extensions(): void
    {
        $dummyPath = sys_get_temp_dir().'/test.csv';
        file_put_contents($dummyPath, "Kode Akun,Nama Akun\n1-1100,Kas");

        $csvFile = new UploadedFile($dummyPath, 'test.csv', 'text/csv', null, true);

        $this->expectException(ValidationException::class);
        $this->coaService->importFromXlsx($csvFile, $this->admin->id);
    }

    /**
     * 12. XLSX Import: In-file duplicate rows fail-closed.
     */
    public function test_import_fails_on_in_file_duplicate_code(): void
    {
        $tempPath = sys_get_temp_dir().'/test_dup_'.uniqid().'.xlsx';
        $rows = [
            ['Kode Akun', 'Nama Akun', 'Kategori', 'Saldo Normal'],
            ['1-1100', 'Kas SHM 1', 'asset', 'debit'],
            ['1-1100', 'Kas SHM 2', 'asset', 'debit'],
        ];
        $this->xlsxService->writeRows($tempPath, $rows);

        $file = new UploadedFile($tempPath, 'dup.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $result = $this->coaService->importFromXlsx($file, $this->admin->id);

        $this->assertGreaterThan(0, $result['failed_rows']);
        $this->assertEquals(0, $result['successful_rows']);
        $this->assertEquals('Kode Akun duplikat di dalam berkas impor yang sama.', $result['errors'][0]['reason']);
        $this->assertDatabaseMissing('chart_of_accounts', ['code' => '1-1100']);
    }

    /**
     * 13. XLSX Import: Conflicting existing SHM account rejected in CREATE ONLY mode.
     */
    public function test_import_rejects_existing_shm_account_conflict(): void
    {
        $this->coaService->createAccount([
            'code' => '1-1100',
            'name' => 'Existing Kas SHM',
            'category' => 'asset',
        ]);

        $tempPath = sys_get_temp_dir().'/test_conflict_'.uniqid().'.xlsx';
        $rows = [
            ['Kode Akun', 'Nama Akun', 'Kategori', 'Saldo Normal'],
            ['1-1100', 'Kas SHM Baru', 'asset', 'debit'],
        ];
        $this->xlsxService->writeRows($tempPath, $rows);

        $file = new UploadedFile($tempPath, 'conflict.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $result = $this->coaService->importFromXlsx($file, $this->admin->id);

        $this->assertGreaterThan(0, $result['failed_rows']);
        $this->assertStringContainsString('sudah terdaftar di SHM CoA', $result['errors'][0]['reason']);
    }

    /**
     * 14. XLSX Export: Exports SHM accounts only and excludes Cooperative accounts.
     */
    public function test_xlsx_export_contains_shm_accounts_only(): void
    {
        ChartOfAccount::create([
            'accounting_context' => AccountingContext::KOPERASI,
            'code' => '1-9999',
            'name' => 'Kas Rahasia Koperasi',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
        ]);

        $this->coaService->createAccount([
            'code' => '1-1100',
            'name' => 'Kas Resmi SHM',
            'category' => 'asset',
        ]);

        $exportPath = $this->coaService->exportToXlsx();
        $this->assertFileExists($exportPath);

        $rows = $this->xlsxService->readRows($exportPath);
        $this->assertNotEmpty($rows);

        $header = $rows[0];
        $this->assertEquals('Kode Akun', $header[0]);
        $this->assertEquals('Nama Akun', $header[1]);

        $allData = json_encode($rows);
        $this->assertStringContainsString('Kas Resmi SHM', $allData);
        $this->assertStringNotContainsString('Kas Rahasia Koperasi', $allData);

        unlink($exportPath);
    }
}
