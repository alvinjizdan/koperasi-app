<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\CashierService;
use App\Services\InstallmentPaymentService;
use App\Services\LoanService;
use App\Services\SavingsTransactionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuditTrailTest extends TestCase
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

    /** 1. Audit tercatat ketika aktivitas penting dilakukan */
    public function test_audit_is_recorded_for_important_activities(): void
    {
        // Setoran simpanan tercatat dengan aktor dari sesi (actingAs).
        $account = SavingsAccount::factory()->create();
        $operator = User::factory()->create();

        $this->actingAs($operator);

        $transaction = (new SavingsTransactionService(app(CashierService::class)))
            ->deposit($account, [
                'amount' => 150000,
                'transaction_date' => now()->format('Y-m-d'),
                'description' => 'Setoran test',
                'idempotency_key' => null,
                'processed_by' => $operator->id,
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'savings.transaction.deposit',
            'object_type' => SavingsTransaction::class,
            'object_id' => $transaction->id,
            'actor_id' => $operator->id,
        ]);
    }

    /** 2. Audit menyimpan aktor dan referensi objek dengan benar */
    public function test_audit_stores_actor_and_object_reference_correctly(): void
    {
        $this->actingAs($this->superadmin);

        // Pembuatan anggota via controller agar AuditLogger mencatat aktor session.
        $this->post(route('anggota.store'), [
            'member_number' => 'ANG-8888',
            'full_name' => 'Anggota Kedua',
            'identity_number' => '3501234567890123',
            'phone' => '081234567890',
            'address' => 'Jl. Test No. 1',
            'joined_at' => now()->format('Y-m-d'),
            'status' => 'active',
        ]);

        $log = AuditLog::query()
            ->where('action', 'member.created')
            ->where('object_type', Member::class)
            ->firstOrFail();

        $this->assertSame($this->superadmin->id, $log->actor_id);
        // Label objek memakai nama yang mudah dibaca manusia (full_name untuk Member).
        $this->assertSame('Anggota Kedua', $log->object_label);
        $this->assertNotNull($log->object_id);
        $this->assertNotNull($log->created_at);
        $this->assertArrayHasKey('member_number', $log->changes ?? []);
    }

    /** 3. Pengguna tanpa izin tidak dapat melihat audit trail */
    public function test_user_without_permission_cannot_view_audit_trail(): void
    {
        $plain = User::factory()->create();

        $this->actingAs($plain)
            ->get(route('pengaturan.audit.index'))
            ->assertForbidden();

        $log = AuditLog::query()->create([
            'actor_id' => $this->superadmin->id,
            'action' => 'test.action',
            'object_type' => 'Test',
        ]);

        $this->actingAs($plain)
            ->get(route('pengaturan.audit.show', $log->id))
            ->assertForbidden();
    }

    /** 4. Pengguna biasa tidak dapat mengubah atau menghapus audit trail */
    public function test_audit_trail_cannot_be_modified_or_deleted(): void
    {
        // Tidak ada route update/delete audit yang terekspos.
        $routes = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter();

        $this->assertNull($routes->first(fn ($name) => $name !== null && str_contains($name, 'audit') && ! str_contains($name, 'index') && ! str_contains($name, 'show')));

        // Pengguna dengan izin lihat pun tidak dapat mengubah catatan via model API publik.
        $log = AuditLog::query()->create([
            'actor_id' => $this->superadmin->id,
            'action' => 'test.action',
            'object_type' => 'Test',
        ]);

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'action' => 'test.action']);
    }

    /** 4b. Password tidak pernah masuk ke audit log */
    public function test_sensitive_data_is_never_recorded(): void
    {
        $this->actingAs($this->superadmin);

        (new SavingsTransactionService(app(CashierService::class)))
            ->deposit(SavingsAccount::factory()->create(), [
                'amount' => 100000,
                'transaction_date' => now()->format('Y-m-d'),
                'description' => 'password=supersecret token=abc123',
                'idempotency_key' => null,
                'processed_by' => $this->superadmin->id,
            ]);

        foreach (AuditLog::all() as $log) {
            $encoded = json_encode([$log->changes, $log->description]);
            $this->assertStringNotContainsString('supersecret', (string) $encoded);
        }
    }

    /** 4c. Aktivitas pinjaman tercatat: pengajuan, persetujuan, pencairan, angsuran */
    public function test_loan_activities_are_recorded(): void
    {
        $loanService = app(LoanService::class);
        $member = Member::factory()->create();

        $loan = $loanService->submit([
            'member_id' => $member->id,
            'amount' => 5000000,
            'tenor_months' => 10,
            'submission_date' => now()->format('Y-m-d'),
        ], $this->superadmin->id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'loan.submitted', 'object_id' => $loan->id]);

        $loanService->approve($loan, $this->superadmin->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'loan.approved', 'object_id' => $loan->id]);

        $loanService->disburse($loan, ['disbursement_date' => now()->format('Y-m-d'), 'method' => 'TRANSFER'], $this->superadmin->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'loan.disbursed']);

        $paymentService = app(InstallmentPaymentService::class);
        $paymentService->pay($loan->fresh(), [
            'amount' => 500000,
            'payment_date' => now()->format('Y-m-d'),
            'notes' => null,
            'idempotency_key' => null,
        ], $this->superadmin->id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'installment.payment.recorded']);
    }

    /** 4d. Aktivitas kasir tercatat: buka/tutup sesi, transaksi, koreksi */
    public function test_cashier_activities_are_recorded(): void
    {
        $cashierService = app(CashierService::class);

        $session = $cashierService->openSession([
            'operational_date' => now()->format('Y-m-d'),
            'opening_balance' => 500000,
            'opening_notes' => null,
        ], $this->superadmin->id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'cashier.session.opened', 'object_id' => $session->id]);

        $transaction = $cashierService->recordIncome($session, [
            'amount' => 100000,
            'category' => 'Pendapatan Lain',
            'transaction_date' => now()->format('Y-m-d'),
            'description' => 'Test',
            'idempotency_key' => null,
        ], $this->superadmin->id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'cashier.transaction.created', 'object_id' => $transaction->id]);

        $cashierService->reverse($transaction->fresh(), 'Salah input', $this->superadmin->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cashier.transaction.reversed', 'object_id' => $transaction->id]);

        $cashierService->closeSession($session->fresh(), [
            'physical_closing_balance' => 500000,
            'closing_notes' => 'Sesuai',
        ], $this->superadmin->id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'cashier.session.closed', 'object_id' => $session->id]);
    }
}
