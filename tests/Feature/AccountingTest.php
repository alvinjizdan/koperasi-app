<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Accounting\JournalService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Ticket 18 — fondasi akuntansi berpasangan.
 * Seluruh data dummy; tidak menyentuh saldo modul bisnis.
 */
class AccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private JournalService $journals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);

        $this->superadmin = User::factory()->superadmin()->create();
        $this->journals = app(JournalService::class);
    }

    private function account(string $code): ChartOfAccount
    {
        return ChartOfAccount::query()->where('code', $code)->firstOrFail();
    }

    /**
     * Pasangan baris seimbang siap pakai (kas vs pendapatan).
     *
     * @return array<int, array<string, mixed>>
     */
    private function balancedLines(float $amount = 500_000): array
    {
        return [
            ['account_id' => $this->account('1-1100')->id, 'debit' => $amount, 'credit' => 0, 'description' => 'Penerimaan kas'],
            ['account_id' => $this->account('4-1300')->id, 'debit' => 0, 'credit' => $amount, 'description' => 'Pendapatan lain'],
        ];
    }

    // =====================================================================
    // COA
    // =====================================================================

    public function test_coa_seeder_marks_accounts_as_template(): void
    {
        $this->assertSame(21, ChartOfAccount::count());
        $this->assertSame(21, ChartOfAccount::where('is_template', true)->count());

        foreach (ChartOfAccount::CATEGORIES as $category => $definition) {
            $this->assertTrue(ChartOfAccount::where('category', $category)->exists(), "Kategori {$category} harus tersedia.");
        }
    }

    public function test_coa_code_must_be_unique(): void
    {
        $this->actingAs($this->superadmin);

        $response = $this->post(route('akuntansi.coa.store'), [
            'code' => '1-1100', // sudah dipakai Kas
            'name' => 'Kas Duplikat',
            'category' => 'asset',
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertSame(1, ChartOfAccount::where('code', '1-1100')->count());
    }

    public function test_coa_normal_balance_follows_category(): void
    {
        $this->actingAs($this->superadmin);

        $this->post(route('akuntansi.coa.store'), [
            'code' => '9-9001',
            'name' => 'Akun Uji Liabilitas',
            'category' => 'liability',
        ]);

        $account = ChartOfAccount::where('code', '9-9001')->firstOrFail();
        $this->assertSame('credit', $account->normal_balance, 'Saldo normal liabilitas harus kredit.');
    }

    public function test_coa_parent_must_not_reference_itself(): void
    {
        $this->actingAs($this->superadmin);
        $kas = $this->account('1-1100');

        $response = $this->put(route('akuntansi.coa.update', $kas->id), [
            'code' => $kas->code,
            'name' => $kas->name,
            'category' => 'asset',
            'parent_id' => (string) $kas->id, // diri sendiri
        ]);

        $response->assertSessionHasErrors('parent_id');
        // Data tidak berubah: parent tetap induk semula (1-0000), bukan diri sendiri.
        $this->assertSame($this->account('1-0000')->id, $kas->fresh()->parent_id);
        $this->assertSame('Kas', $kas->fresh()->name, 'Data tidak boleh berubah saat validasi gagal.');
    }

    public function test_coa_parent_must_share_category(): void
    {
        $this->actingAs($this->superadmin);
        $expense = $this->account('5-1100');

        $response = $this->put(route('akuntansi.coa.update', $expense->id), [
            'code' => $expense->code,
            'name' => $expense->name,
            'category' => 'expense',
            'parent_id' => (string) $this->account('1-0000')->id, // induk aset utk akun beban
        ]);

        $response->assertSessionHasErrors('parent_id');
    }

    public function test_account_used_in_journal_cannot_be_deleted_only_deactivated(): void
    {
        $kas = $this->account('1-1100');

        $entry = $this->journals->createDraft([
            'transaction_date' => now()->format('Y-m-d'),
            'description' => 'Uji penghapusan',
        ], $this->balancedLines(100_000), $this->superadmin->id);
        $this->journals->post($entry, $this->superadmin->id);

        $this->actingAs($this->superadmin)
            ->patch(route('akuntansi.coa.deactivate', $kas->id))
            ->assertRedirect();

        // Akun terpakai: tetap ada, hanya nonaktif.
        $this->assertDatabaseHas('chart_of_accounts', ['id' => $kas->id, 'is_active' => false]);
    }

    public function test_unused_account_can_be_deleted(): void
    {
        $this->actingAs($this->superadmin);

        $this->post(route('akuntansi.coa.store'), [
            'code' => '9-9002',
            'name' => 'Akun Sekali Pakai',
            'category' => 'asset',
        ]);
        $account = ChartOfAccount::where('code', '9-9002')->firstOrFail();

        $this->patch(route('akuntansi.coa.deactivate', $account->id))->assertRedirect();

        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $account->id]);
    }

    public function test_inactive_account_cannot_be_used_in_new_journal(): void
    {
        $bank = $this->account('1-1200');
        $bank->update(['is_active' => false]);

        $lines = [
            ['account_id' => $bank->id, 'debit' => 200_000, 'credit' => 0],
            ['account_id' => $this->account('4-1300')->id, 'debit' => 0, 'credit' => 200_000],
        ];

        $entry = $this->journals->createDraft([
            'transaction_date' => now()->format('Y-m-d'),
        ], $lines, $this->superadmin->id);

        $this->expectException(ValidationException::class);
        $this->journals->post($entry, $this->superadmin->id);
    }

    // =====================================================================
    // JURNAL: VALIDASI DASAR
    // =====================================================================

    public function test_journal_requires_at_least_two_lines(): void
    {
        $this->expectException(ValidationException::class);

        $this->journals->createDraft([
            'transaction_date' => now()->format('Y-m-d'),
        ], [
            ['account_id' => $this->account('1-1100')->id, 'debit' => 100_000, 'credit' => 0],
        ], $this->superadmin->id);
    }

    public function test_line_cannot_have_both_debit_and_credit(): void
    {
        $this->expectException(ValidationException::class);

        $this->journals->createDraft([
            'transaction_date' => now()->format('Y-m-d'),
        ], [
            ['account_id' => $this->account('1-1100')->id, 'debit' => 100_000, 'credit' => 100_000],
            ['account_id' => $this->account('4-1300')->id, 'debit' => 0, 'credit' => 0],
        ], $this->superadmin->id);
    }

    public function test_line_must_have_one_nonzero_side(): void
    {
        $this->expectException(ValidationException::class);

        $this->journals->createDraft([
            'transaction_date' => now()->format('Y-m-d'),
        ], [
            ['account_id' => $this->account('1-1100')->id, 'debit' => 0, 'credit' => 0],
            ['account_id' => $this->account('4-1300')->id, 'debit' => 0, 'credit' => 0],
        ], $this->superadmin->id);
    }

    public function test_unbalanced_journal_cannot_be_posted(): void
    {
        $entry = $this->journals->createDraft([
            'transaction_date' => now()->format('Y-m-d'),
        ], [
            ['account_id' => $this->account('1-1100')->id, 'debit' => 500_000, 'credit' => 0],
            ['account_id' => $this->account('4-1300')->id, 'debit' => 0, 'credit' => 400_000],
        ], $this->superadmin->id);

        $this->expectException(ValidationException::class);
        $this->journals->post($entry, $this->superadmin->id);
    }

    // =====================================================================
    // JURNAL: POSTING
    // =====================================================================

    public function test_balanced_journal_can_be_posted(): void
    {
        $entry = $this->journals->createDraft([
            'transaction_date' => '2026-01-15',
            'description' => 'Penerimaan pendapatan lain',
        ], $this->balancedLines(), $this->superadmin->id);

        $posted = $this->journals->post($entry, $this->superadmin->id);

        $this->assertSame(JournalEntry::STATUS_POSTED, $posted->status);
        $this->assertSame($this->superadmin->id, $posted->posted_by);
        $this->assertNotNull($posted->posted_at);
        $this->assertTrue($posted->isBalanced());
        $this->assertSame(500_000.0, $posted->totalDebit());
        $this->assertSame(500_000.0, $posted->totalCredit());
    }

    public function test_double_posting_is_rejected(): void
    {
        $entry = $this->journals->createDraft([
            'transaction_date' => now()->format('Y-m-d'),
        ], $this->balancedLines(), $this->superadmin->id);

        $this->journals->post($entry, $this->superadmin->id);

        $this->expectException(ValidationException::class);
        $this->journals->post($entry->fresh(), $this->superadmin->id);
    }

    public function test_posted_journal_cannot_be_edited(): void
    {
        $entry = $this->journals->createDraft([
            'transaction_date' => now()->format('Y-m-d'),
        ], $this->balancedLines(), $this->superadmin->id);
        $this->journals->post($entry, $this->superadmin->id);

        $this->expectException(ValidationException::class);
        $this->journals->updateDraft($entry->fresh(), [
            'transaction_date' => now()->format('Y-m-d'),
            'description' => 'Coba ubah jurnal posted',
        ], $this->balancedLines(999_999));
    }

    // =====================================================================
    // JURNAL: REVERSAL
    // =====================================================================

    public function test_reversal_creates_linked_posted_entry_with_swapped_sides(): void
    {
        $entry = $this->journals->createDraft([
            'transaction_date' => '2026-01-15',
            'description' => 'Setoran awal',
        ], $this->balancedLines(750_000), $this->superadmin->id);
        $this->journals->post($entry, $this->superadmin->id);

        $reversal = $this->journals->reverse($entry->fresh(), $this->superadmin->id, 'Salah pencatatan');

        $this->assertSame(JournalEntry::STATUS_POSTED, $reversal->status);
        $this->assertSame(750_000.0, $reversal->totalDebit());
        $this->assertSame(750_000.0, $reversal->totalCredit());

        // Sisi dibalik per baris, akun tetap.
        $originalLines = $entry->lines()->orderBy('line_order')->get();
        $reversalLines = $reversal->lines()->orderBy('line_order')->get();

        foreach ($originalLines as $index => $originalLine) {
            $this->assertSame((float) $originalLine->debit, (float) $reversalLines[$index]->credit);
            $this->assertSame((float) $originalLine->credit, (float) $reversalLines[$index]->debit);
            $this->assertSame($originalLine->account_id, $reversalLines[$index]->account_id);
        }

        // Jurnal asal ditandai REVERSED dengan referensi pembalik; isinya tak berubah.
        $entry->refresh();
        $this->assertSame(JournalEntry::STATUS_REVERSED, $entry->status);
        $this->assertSame($reversal->id, $entry->reversed_by_entry_id);
        $this->assertSame(750_000.0, $entry->totalDebit());
    }

    public function test_double_reversal_is_rejected(): void
    {
        $entry = $this->journals->createDraft([
            'transaction_date' => now()->format('Y-m-d'),
        ], $this->balancedLines(), $this->superadmin->id);
        $this->journals->post($entry, $this->superadmin->id);

        $this->journals->reverse($entry->fresh(), $this->superadmin->id, 'Koreksi pertama');

        $this->expectException(ValidationException::class);
        $this->journals->reverse($entry->fresh(), $this->superadmin->id, 'Koreksi kedua');
    }

    public function test_draft_journal_cannot_be_reversed(): void
    {
        $entry = $this->journals->createDraft([
            'transaction_date' => now()->format('Y-m-d'),
        ], $this->balancedLines(), $this->superadmin->id);

        $this->expectException(ValidationException::class);
        $this->journals->reverse($entry, $this->superadmin->id, 'Coba reversal draft');
    }

    // =====================================================================
    // BUKU BESAR
    // =====================================================================

    public function test_ledger_includes_only_posted_entries(): void
    {
        $posted = $this->journals->createDraft([
            'transaction_date' => '2026-02-01',
        ], $this->balancedLines(300_000), $this->superadmin->id);
        $this->journals->post($posted, $this->superadmin->id);

        // Draft tidak boleh masuk buku besar.
        $this->journals->createDraft([
            'transaction_date' => '2026-02-02',
        ], $this->balancedLines(999_000), $this->superadmin->id);

        $result = $this->journals->ledger($this->account('1-1100')->id, '2026-02-01', '2026-02-28');

        $this->assertCount(1, $result['rows']);
        $this->assertSame(300_000.0, $result['totals']['debit']);
        $this->assertSame(300_000.0, $result['totals']['balance']);
    }

    public function test_ledger_running_balance_respects_normal_balance(): void
    {
        // Dua posting pada akun kas (saldo normal debit).
        $first = $this->journals->createDraft(['transaction_date' => '2026-03-01'], $this->balancedLines(200_000), $this->superadmin->id);
        $this->journals->post($first, $this->superadmin->id);

        $second = $this->journals->createDraft(['transaction_date' => '2026-03-05'], $this->balancedLines(150_000), $this->superadmin->id);
        $this->journals->post($second, $this->superadmin->id);

        // Reversal posting pertama: kas berkurang.
        $this->journals->reverse($first->fresh(), $this->superadmin->id, 'Koreksi');

        $result = $this->journals->ledger($this->account('1-1100')->id, '2026-03-01', '2026-03-31');

        // Saldo berjalan: +200.000, +150.000, -200.000 = 150.000.
        $this->assertSame(150_000.0, $result['totals']['balance']);
        $this->assertSame(150_000.0, round($result['totals']['debit'] - $result['totals']['credit'], 2));

        // Jurnal reversal tetap dapat ditelusuri via halaman jurnal (status REVERSED + link).
        $first->refresh();
        $this->assertSame(JournalEntry::STATUS_REVERSED, $first->status);
        $this->assertNotNull($first->reversed_by_entry_id);
    }

    public function test_ledger_filter_by_account_and_date(): void
    {
        $entry = $this->journals->createDraft(['transaction_date' => '2026-04-10'], $this->balancedLines(120_000), $this->superadmin->id);
        $this->journals->post($entry, $this->superadmin->id);

        // Filter akun kas saja.
        $kasResult = $this->journals->ledger($this->account('1-1100')->id, null, null);
        $this->assertCount(1, $kasResult['rows']);

        // Tanpa filter akun: seluruh baris (2).
        $allResult = $this->journals->ledger(null, null, null);
        $this->assertCount(2, $allResult['rows']);
        $this->assertSame(120_000.0, $allResult['totals']['debit']);
        $this->assertSame(120_000.0, $allResult['totals']['credit']);

        // Filter tanggal di luar rentang.
        $emptyResult = $this->journals->ledger(null, '2027-01-01', '2027-12-31');
        $this->assertSame([], $emptyResult['rows']);
    }

    // =====================================================================
    // IDEMPOTENSI SUMBER
    // =====================================================================

    public function test_source_idempotency_returns_existing_entry(): void
    {
        // Transaksi sumber nyata (dummy) agar getKey() valid.
        $sourceTransaction = SavingsTransaction::factory()->create([
            'type' => 'deposit',
            'amount' => 50_000,
        ]);

        $entry = $this->journals->createDraft([
            'transaction_date' => now()->format('Y-m-d'),
            'source_type' => SavingsTransaction::class,
            'source_id' => $sourceTransaction->id,
            'posting_type' => 'auto',
        ], $this->balancedLines(), $this->superadmin->id);
        $this->journals->post($entry, $this->superadmin->id);

        $found = $this->journals->findForSource($sourceTransaction, 'auto');
        $this->assertNotNull($found);
        $this->assertSame($entry->id, $found->id);

        // Transaksi lain belum memiliki jurnal.
        $other = SavingsTransaction::factory()->create(['type' => 'deposit', 'amount' => 10_000]);
        $this->assertNull($this->journals->findForSource($other, 'auto'));
    }

    public function test_source_unique_constraint_blocks_duplicate_postings(): void
    {
        $sourceTransaction = CashTransaction::factory()->create([
            'type' => 'income',
            'amount' => 25_000,
        ]);

        $sourceData = [
            'transaction_date' => now()->format('Y-m-d'),
            'source_type' => CashTransaction::class,
            'source_id' => $sourceTransaction->id,
            'posting_type' => 'auto',
        ];

        $this->journals->createDraft($sourceData, $this->balancedLines(), $this->superadmin->id);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->journals->createDraft($sourceData, $this->balancedLines(), $this->superadmin->id);
    }

    // =====================================================================
    // OTORISASI
    // =====================================================================

    public function test_accounting_routes_require_permissions(): void
    {
        $plain = User::factory()->create();

        foreach ([
            'akuntansi.coa.index' => 'get',
            'akuntansi.coa.create' => 'get',
            'akuntansi.jurnal.index' => 'get',
            'akuntansi.jurnal.create' => 'get',
            'akuntansi.buku-besar.index' => 'get',
        ] as $routeName => $method) {
            $response = $this->actingAs($plain)->{$method}(route($routeName));
            $this->assertSame(403, $response->status(), "Route {$routeName} harus 403 tanpa permission.");
        }

        $this->actingAs($plain)
            ->post(route('akuntansi.jurnal.store'), [])
            ->assertForbidden();
    }

    public function test_permissioned_user_can_view_but_not_post(): void
    {
        $viewer = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'viewer_'.uniqid(),
            'display_name' => 'Viewer Akuntansi',
            'is_system' => false,
        ]);
        $role->permissions()->attach(
            Permission::query()->where('name', 'accounting.journals.view')->pluck('id')
        );
        $viewer->roles()->attach($role->id);

        $this->actingAs($viewer)->get(route('akuntansi.jurnal.index'))->assertOk();

        $entry = $this->journals->createDraft([
            'transaction_date' => now()->format('Y-m-d'),
        ], $this->balancedLines(), $this->superadmin->id);

        $this->actingAs($viewer)
            ->post(route('akuntansi.jurnal.post', $entry->id))
            ->assertForbidden();

        $this->assertSame(JournalEntry::STATUS_DRAFT, $entry->fresh()->status);
    }

    // =====================================================================
    // AUDIT TRAIL
    // =====================================================================

    public function test_journal_lifecycle_is_audited(): void
    {
        $this->actingAs($this->superadmin);

        $entry = $this->journals->createDraft([
            'transaction_date' => now()->format('Y-m-d'),
        ], $this->balancedLines(), $this->superadmin->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'accounting.journal.created',
            'object_id' => $entry->id,
        ]);

        $this->journals->post($entry, $this->superadmin->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'accounting.journal.posted',
            'object_id' => $entry->id,
        ]);

        $this->journals->reverse($entry->fresh(), $this->superadmin->id, 'Uji audit reversal');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'accounting.journal.reversed',
            'object_id' => $entry->id,
        ]);
    }
}
