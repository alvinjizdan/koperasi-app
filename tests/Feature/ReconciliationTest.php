<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\CashTransaction;
use App\Models\InstallmentPayment;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\Permission;
use App\Models\ReconciliationFinding;
use App\Models\ReconciliationRun;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Reconciliation\CashReconciliationChecker;
use App\Services\Reconciliation\LoanReconciliationChecker;
use App\Services\Reconciliation\SavingsReconciliationChecker;
use App\Services\ReconciliationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = User::factory()->superadmin()->create();
    }

    private function service(): ReconciliationService
    {
        return app(ReconciliationService::class);
    }

    /** 5. Rekonsiliasi simpanan mendeteksi saldo yang tidak konsisten */
    public function test_savings_reconciliation_detects_balance_mismatch(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 500000]);

        // Transaksi sah: setoran 200000, saldo benar seharusnya 200000, tersimpan 500000.
        SavingsTransaction::factory()->create([
            'savings_account_id' => $account->id,
            'type' => 'deposit',
            'amount' => 200000,
            'status' => 'completed',
        ]);

        $result = app(SavingsReconciliationChecker::class)->check('2000-01-01', now()->format('Y-m-d'));

        $mismatch = collect($result['findings'])->firstWhere('finding_type', 'BALANCE_MISMATCH');
        $this->assertNotNull($mismatch, 'Harus mendeteksi selisih saldo');
        $this->assertSame(200000.0, (float) $mismatch['expected_value']);
        $this->assertSame(500000.0, (float) $mismatch['actual_value']);
        $this->assertSame(300000.0, (float) $mismatch['variance']);
        $this->assertSame(SavingsAccount::class, $mismatch['object_type']);
        $this->assertSame($account->id, $mismatch['object_id']);
    }

    /** 6. Rekonsiliasi tidak memasukkan transaksi gagal atau dibatalkan */
    public function test_reconciliation_ignores_failed_or_reversed_transactions(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 200000]);

        SavingsTransaction::factory()->create([
            'savings_account_id' => $account->id,
            'type' => 'deposit',
            'amount' => 200000,
            'status' => 'completed',
        ]);

        // Transaksi dengan status bukan completed tidak memengaruhi perhitungan.
        SavingsTransaction::factory()->create([
            'savings_account_id' => $account->id,
            'type' => 'withdrawal',
            'amount' => 150000,
            'status' => 'reversed',
        ]);

        $result = app(SavingsReconciliationChecker::class)->check('2000-01-01', now()->format('Y-m-d'));

        $mismatch = collect($result['findings'])->firstWhere('finding_type', 'BALANCE_MISMATCH');
        $this->assertNull($mismatch, 'Saldo harus konsisten ketika transaksi non-sah diabaikan');
    }

    /** 7. Rekonsiliasi pinjaman mendeteksi referensi pencairan yang tidak valid */
    public function test_loan_reconciliation_detects_missing_disbursement_reference(): void
    {
        Loan::factory()->active()->create(); // status ACTIVE tanpa pencairan

        $result = app(LoanReconciliationChecker::class)->check('2000-01-01', now()->format('Y-m-d'));

        $finding = collect($result['findings'])->firstWhere('finding_type', 'MISSING_REFERENCE');
        $this->assertNotNull($finding, 'Pinjaman aktif tanpa pencairan harus terdeteksi');
        $this->assertSame(Loan::class, $finding['object_type']);
    }

    /** 8. Rekonsiliasi pembayaran mendeteksi transaksi ganda dan alokasi tidak lengkap */
    public function test_payment_reconciliation_detects_unallocated_payment(): void
    {
        $loan = Loan::factory()->active()->create();
        LoanDisbursement::factory()->create(['loan_id' => $loan->id, 'amount' => $loan->amount]);

        InstallmentPayment::factory()->create([
            'loan_id' => $loan->id,
            'installment_schedule_id' => null, // tanpa alokasi jadwal
            'amount' => 500000,
            'status' => 'COMPLETED',
        ]);

        $result = app(LoanReconciliationChecker::class)->check('2000-01-01', now()->format('Y-m-d'));

        $finding = collect($result['findings'])->firstWhere('finding_type', 'UNALLOCATED_PAYMENT');
        $this->assertNotNull($finding, 'Pembayaran tanpa alokasi harus terdeteksi');
    }

    /** 8b. Rekonsiliasi pinjaman mendeteksi pencairan ganda */
    public function test_loan_reconciliation_detects_duplicate_disbursement(): void
    {
        $loan = Loan::factory()->active()->create();
        LoanDisbursement::factory()->create(['loan_id' => $loan->id]);
        LoanDisbursement::factory()->create(['loan_id' => $loan->id]); // ganda

        $result = app(LoanReconciliationChecker::class)->check('2000-01-01', now()->format('Y-m-d'));

        $finding = collect($result['findings'])->firstWhere('finding_type', 'DUPLICATE_REFERENCE');
        $this->assertNotNull($finding, 'Pencairan ganda harus terdeteksi');
        $this->assertSame(2.0, (float) $finding['actual_value']);
    }

    /** 9. Rekonsiliasi kas mendeteksi selisih saldo */
    public function test_cash_reconciliation_detects_balance_mismatch(): void
    {
        $session = CashierSession::factory()->create([
            'opening_balance' => 500000,
            'status' => CashierSession::STATUS_CLOSED,
            'total_income' => 100000,
            'total_expense' => 0,
            'total_deposit' => 0,
            'system_closing_balance' => 700000, // salah: hasil perhitungan 600000
            'physical_closing_balance' => 700000,
            'difference' => 100000,
        ]);

        CashTransaction::factory()->income(100000)->create(['cashier_session_id' => $session->id]);

        $result = app(CashReconciliationChecker::class)->check('2000-01-01', now()->format('Y-m-d'));

        $finding = collect($result['findings'])->firstWhere('finding_type', 'BALANCE_MISMATCH');
        $this->assertNotNull($finding, 'Snapshot saldo kas yang salah harus terdeteksi');
        $this->assertSame(600000.0, (float) $finding['expected_value']);
        $this->assertSame(700000.0, (float) $finding['actual_value']);
    }

    /** 9b. Rekonsiliasi kas mendeteksi selisih kas fisik pada penutupan */
    public function test_cash_reconciliation_detects_physical_variance(): void
    {
        $session = CashierSession::factory()->create([
            'opening_balance' => 500000,
            'status' => CashierSession::STATUS_CLOSED,
            'total_income' => 100000,
            'total_expense' => 0,
            'total_deposit' => 0,
            'system_closing_balance' => 600000,
            'physical_closing_balance' => 590000, // fisik kurang 10000
            'difference' => -10000,
        ]);

        CashTransaction::factory()->income(100000)->create(['cashier_session_id' => $session->id]);

        $result = app(CashReconciliationChecker::class)->check('2000-01-01', now()->format('Y-m-d'));

        $finding = collect($result['findings'])->firstWhere('finding_type', 'CASH_VARIANCE');
        $this->assertNotNull($finding, 'Selisih kas fisik harus dilaporkan');
        $this->assertSame(-10000.0, (float) $finding['variance']);
    }

    /** 10. Proses rekonsiliasi tidak mengubah saldo atau transaksi sumber */
    public function test_reconciliation_never_modifies_source_data(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 999999]);
        $transaction = SavingsTransaction::factory()->create([
            'savings_account_id' => $account->id,
            'amount' => 100000,
            'status' => 'completed',
        ]);

        $session = CashierSession::factory()->create(['opening_balance' => 1000000]);
        $cash = CashTransaction::factory()->income(50000)->create(['cashier_session_id' => $session->id]);

        $this->service()->run('2000-01-01', now()->format('Y-m-d'), ['savings', 'loans', 'cash'], $this->superadmin->id);

        // Saldo dan transaksi tidak berubah.
        $this->assertSame('999999.00', (string) $account->fresh()->balance);
        $this->assertSame('100000.00', (string) $transaction->fresh()->amount);
        $this->assertSame('completed', $transaction->fresh()->status);
        $this->assertSame('1000000.00', (string) $session->fresh()->opening_balance);
        $this->assertSame('50000.00', (string) $cash->fresh()->amount);
        $this->assertSame('POSTED', $cash->fresh()->status);
    }

    /** 11. Temuan tersimpan dengan referensi objek yang benar */
    public function test_findings_are_persisted_with_correct_object_references(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 123456]);
        SavingsTransaction::factory()->create([
            'savings_account_id' => $account->id,
            'type' => 'deposit',
            'amount' => 1000,
            'status' => 'completed',
        ]);

        $run = $this->service()->run('2000-01-01', now()->format('Y-m-d'), ['savings'], $this->superadmin->id);

        $this->assertSame(ReconciliationRun::STATUS_COMPLETED, $run->status);
        $this->assertGreaterThan(0, $run->findings_count);

        $finding = $run->findings()->firstOrFail();
        $this->assertSame(SavingsAccount::class, $finding->object_type);
        $this->assertSame($account->id, $finding->object_id);
        $this->assertSame($account->account_number, $finding->object_label);
        $this->assertSame(ReconciliationFinding::STATUS_OPEN, $finding->status);
        $this->assertSame($this->superadmin->id, $run->run_by);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
    }

    /** 12. Perubahan status temuan tercatat dalam audit trail */
    public function test_finding_status_change_is_recorded_in_audit_trail(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 777777]);
        SavingsTransaction::factory()->create([
            'savings_account_id' => $account->id,
            'type' => 'deposit',
            'amount' => 1000,
            'status' => 'completed',
        ]);

        $run = $this->service()->run('2000-01-01', now()->format('Y-m-d'), ['savings'], $this->superadmin->id);
        $finding = $run->findings()->firstOrFail();

        // AuditLogger mencatat aktor dari sesi.
        $this->actingAs($this->superadmin);

        $updated = $this->service()->followUp(
            $finding,
            ReconciliationFinding::STATUS_UNDER_REVIEW,
            'Sedang diperiksa oleh pengawas.',
            $this->superadmin->id
        );

        $this->assertSame(ReconciliationFinding::STATUS_UNDER_REVIEW, $updated->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reconciliation.finding.follow_up',
            'actor_id' => $this->superadmin->id,
            'object_type' => ReconciliationFinding::class,
            'object_id' => $finding->id,
        ]);

        // Riwayat tindak lanjut tersimpan.
        $this->assertDatabaseHas('reconciliation_follow_ups', [
            'reconciliation_finding_id' => $finding->id,
            'user_id' => $this->superadmin->id,
            'from_status' => ReconciliationFinding::STATUS_OPEN,
            'to_status' => ReconciliationFinding::STATUS_UNDER_REVIEW,
        ]);
    }

    /** 13. Temuan yang diselesaikan memiliki catatan atau bukti yang sesuai */
    public function test_resolved_finding_requires_sufficient_note(): void
    {
        $finding = ReconciliationFinding::factory()->create();

        // Catatan terlalu pendek ditolak.
        $this->expectException(ValidationException::class);
        $this->service()->followUp($finding, ReconciliationFinding::STATUS_RESOLVED, 'ok', $this->superadmin->id);
    }

    /** 13b. Temuan selesai dengan alasan memadai tersimpan */
    public function test_resolved_finding_with_sufficient_note_succeeds(): void
    {
        $finding = ReconciliationFinding::factory()->create();

        $updated = $this->service()->followUp(
            $finding,
            ReconciliationFinding::STATUS_RESOLVED,
            'Selisih terverifikasi sebagai biaya administrasi yang belum tercatat, sudah dikoreksi manual.',
            $this->superadmin->id
        );

        $this->assertSame(ReconciliationFinding::STATUS_RESOLVED, $updated->status);
    }

    /** 14. Pengguna tanpa permission tidak dapat menjalankan rekonsiliasi */
    public function test_user_without_permission_cannot_run_reconciliation(): void
    {
        $plain = User::factory()->create();

        $this->actingAs($plain)
            ->post(route('rekonsiliasi.runs.store'), [
                'period_start' => '2000-01-01',
                'period_end' => now()->format('Y-m-d'),
                'modules' => ['savings'],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('reconciliation_runs', 0);

        // Pengguna pun tidak dapat mengakses halaman rekonsiliasi.
        $this->actingAs($plain)
            ->get(route('rekonsiliasi.index'))
            ->assertForbidden();
    }

    /** 14b. Pengguna dengan permission rekonsiliasi.run dapat menjalankan */
    public function test_user_with_run_permission_can_run_reconciliation(): void
    {
        // Siapkan selisih agar proses menghasilkan temuan untuk uji tindak lanjut.
        $account = SavingsAccount::factory()->create(['balance' => 555555]);
        SavingsTransaction::factory()->create([
            'savings_account_id' => $account->id,
            'type' => 'deposit',
            'amount' => 1000,
            'status' => 'completed',
        ]);

        $user = User::factory()->create();
        $role = Role::query()->create(['name' => 'rekon_run_'.uniqid(), 'display_name' => 'Rekon Runner', 'is_system' => false]);
        $role->permissions()->attach(Permission::query()->whereIn('name', ['rekonsiliasi.view', 'rekonsiliasi.run'])->pluck('id'));
        $user->roles()->attach($role->id);

        $response = $this->actingAs($user)->post(route('rekonsiliasi.runs.store'), [
            'period_start' => '2000-01-01',
            'period_end' => now()->format('Y-m-d'),
            'modules' => ['savings'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseCount('reconciliation_runs', 1);

        // Permission resolve terpisah: user tanpa izin tidak dapat menindaklanjuti.
        $finding = ReconciliationFinding::query()->firstOrFail();

        $this->actingAs($user)
            ->put(route('rekonsiliasi.findings.update', $finding->id), [
                'to_status' => 'UNDER_REVIEW',
                'note' => 'Catatan pemeriksaan memadai.',
            ])
            ->assertForbidden();
    }

    /** 15. Proses rekonsiliasi gagal tidak meninggalkan status RUNNING */
    public function test_failed_reconciliation_does_not_remain_running(): void
    {
        // Paksa kegagalan: modul valid di-cek, lalu persistensi gagal (summary json tidak valid
        // sulit dipicu; gunakan mock checker yang melempar exception).
        $checker = $this->createMock(SavingsReconciliationChecker::class);
        $checker->method('check')->willThrowException(new \RuntimeException('Simulasi kegagalan checker'));

        $service = new ReconciliationService($checker, app(LoanReconciliationChecker::class), app(CashReconciliationChecker::class));

        try {
            $service->run('2000-01-01', now()->format('Y-m-d'), ['savings'], $this->superadmin->id);
            $this->fail('Harusnya melempar exception');
        } catch (\RuntimeException $e) {
            // Diharapkan.
        }

        $run = ReconciliationRun::query()->sole();
        $this->assertSame(ReconciliationRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString('Simulasi kegagalan checker', (string) $run->error_message);
    }

    /** 16. Pemeriksaan konsistensi menghasilkan hasil yang sesuai dengan data sumber */
    public function test_consistency_check_matches_source_data(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 0]);
        SavingsTransaction::factory()->create([
            'savings_account_id' => $account->id,
            'type' => 'deposit',
            'amount' => 300000,
            'status' => 'completed',
        ]);
        SavingsTransaction::factory()->create([
            'savings_account_id' => $account->id,
            'type' => 'withdrawal',
            'amount' => 100000,
            'status' => 'completed',
        ]);
        $account->update(['balance' => 200000]); // sinkron dengan transaksi

        $result = app(SavingsReconciliationChecker::class)->check('2000-01-01', now()->format('Y-m-d'));

        // Saldo sesuai perhitungan: tidak ada temuan BALANCE_MISMATCH.
        $mismatch = collect($result['findings'])->firstWhere('finding_type', 'BALANCE_MISMATCH');
        $this->assertNull($mismatch);
        $this->assertSame(1, $result['summary']['accounts_checked']);
        $this->assertSame(2, $result['summary']['transactions_reviewed']);
    }

    /** 16b. Temuan potensi double-posting simpanan terdeteksi */
    public function test_savings_reconciliation_detects_duplicate_reference(): void
    {
        // Nomor transaksi global dijaga unique constraint DB; deteksi duplikat
        // berlaku untuk potensi double-posting: jenis, nominal, dan tanggal sama.
        $account = SavingsAccount::factory()->create();
        SavingsTransaction::factory()->create([
            'savings_account_id' => $account->id,
            'transaction_number' => 'TRX-DUP-0001',
            'type' => 'deposit',
            'amount' => 50000,
            'transaction_date' => now()->format('Y-m-d'),
            'status' => 'completed',
        ]);
        SavingsTransaction::factory()->create([
            'savings_account_id' => $account->id,
            'transaction_number' => 'TRX-DUP-0002',
            'type' => 'deposit',
            'amount' => 50000,
            'transaction_date' => now()->format('Y-m-d'),
            'status' => 'completed',
        ]);

        $result = app(SavingsReconciliationChecker::class)->check('2000-01-01', now()->format('Y-m-d'));

        $finding = collect($result['findings'])->firstWhere('finding_type', 'DUPLICATE_REFERENCE');
        $this->assertNotNull($finding, 'Potensi double-posting harus terdeteksi');
        $this->assertSame(2.0, (float) $finding['actual_value']);
    }

    /** Halaman rekonsiliasi dapat diakses pengguna berizin */
    public function test_reconciliation_pages_render_for_authorized_user(): void
    {
        $run = $this->service()->run('2000-01-01', now()->format('Y-m-d'), ['savings', 'cash'], $this->superadmin->id);
        $finding = $run->findings()->firstOrCreate([], [
            'finding_type' => 'INCOMPLETE_DATA',
            'module' => 'cash',
            'found_at' => now(),
        ]);

        $this->actingAs($this->superadmin)->get(route('rekonsiliasi.index'))->assertOk();
        $this->actingAs($this->superadmin)->get(route('rekonsiliasi.runs'))->assertOk();
        $this->actingAs($this->superadmin)->get(route('rekonsiliasi.runs.show', $run->id))->assertOk();
        $this->actingAs($this->superadmin)->get(route('rekonsiliasi.findings'))->assertOk();
        $this->actingAs($this->superadmin)->get(route('rekonsiliasi.findings.show', $finding->id))->assertOk();

        // Tindak lanjut melalui HTTP juga tercatat di audit.
        $this->actingAs($this->superadmin)
            ->put(route('rekonsiliasi.findings.update', $finding->id), [
                'to_status' => 'UNDER_REVIEW',
                'note' => 'Diperiksa oleh pengawas internal.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reconciliation.finding.follow_up',
            'object_id' => $finding->id,
        ]);
    }
}
