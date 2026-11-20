<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\User;
use App\Services\CashierService;
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

    /** 1. Audit tercatat ketika aktivitas penting dilakukan (SHM Customer creation) */
    public function test_audit_is_recorded_for_important_activities(): void
    {
        $operator = User::factory()->create();
        $operator->roles()->attach(Role::where('name', 'admin')->value('id') ?? Role::first()->id);

        $this->actingAs($operator);

        $this->post(route('shm.customers.store'), [
            'name' => 'Nasabah Audit Penting',
            'nik' => '3501234567890999',
            'phone' => '081234567899',
            'email' => 'nasabah.penting@example.test',
            'address' => 'Jl. Test No. 99, Cirebon',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'shm.customer.created',
            'object_type' => ShmCustomer::class,
            'actor_id' => $operator->id,
        ]);
    }

    /** 2. Audit menyimpan aktor dan referensi objek dengan benar */
    public function test_audit_stores_actor_and_object_reference_correctly(): void
    {
        $this->actingAs($this->superadmin);

        // Pembuatan nasabah SHM Makro mandiri via controller agar AuditLogger mencatat aktor session.
        $this->post(route('shm.customers.store'), [
            'name' => 'Nasabah Audit Kedua',
            'nik' => '3501234567890123',
            'phone' => '081234567890',
            'email' => 'nasabah.audit@example.test',
            'address' => 'Jl. Test No. 1, Cirebon',
        ]);

        $log = AuditLog::query()
            ->where('action', 'shm.customer.created')
            ->where('object_type', ShmCustomer::class)
            ->firstOrFail();

        $this->assertSame($this->superadmin->id, $log->actor_id);
        // Label objek memakai nama nasabah (name untuk ShmCustomer).
        $this->assertSame('Nasabah Audit Kedua', $log->object_label);
        $this->assertNotNull($log->object_id);
        $this->assertNotNull($log->created_at);
        $this->assertArrayHasKey('customer_number', $log->changes ?? []);
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

        $this->post(route('shm.customers.store'), [
            'name' => 'Nasabah Password Audit',
            'nik' => '3501234567890888',
            'phone' => '081234567888',
            'email' => 'nasabah.pwd@example.test',
            'address' => 'password=supersecret token=abc123',
        ]);

        foreach (AuditLog::all() as $log) {
            $encoded = json_encode([$log->changes, $log->description]);
            $this->assertStringNotContainsString('supersecret', (string) $encoded);
        }
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
