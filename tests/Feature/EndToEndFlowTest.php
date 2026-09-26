<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashierSession;
use App\Models\CashTransaction;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Permission;
use App\Models\ReconciliationRun;
use App\Models\Role;
use App\Models\SavingProduct;
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
use Tests\TestCase;

/**
 * Validasi alur bisnis end-to-end lintas modul (Ticket 10 bagian J).
 *
 * ALUR 1: Anggota dan Simpanan.
 * ALUR 2: Pinjaman dan Angsuran.
 * ALUR 3: Integrasi Kasir.
 * ALUR 4: Dashboard dan Laporan.
 * ALUR 5: Audit dan Rekonsiliasi.
 * ALUR 6: Otorisasi.
 *
 * Setiap alur menekankan sumber data resmi: saldo rekening, ledger transaksi
 * sah, snapshot sesi kasir, dan audit trail yang dapat ditelusuri.
 */
class EndToEndFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = User::factory()->create();
        $this->superadmin->roles()->attach(Role::query()->where('name', 'superadmin')->value('id'));
    }

    /**
     * Pengguna dengan permission modul tertentu (untuk ALUR 6).
     */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'custom_'.uniqid(),
            'display_name' => 'Custom',
            'is_system' => false,
        ]);
        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissions)->pluck('id')
        );
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * Buka sesi kasir untuk operator dan kembalikan sesinya.
     */
    private function openCashierSession(User $operator, float $openingBalance = 1000000): CashierSession
    {
        return app(CashierService::class)->openSession([
            'operational_date' => now()->format('Y-m-d'),
            'opening_balance' => $openingBalance,
            'opening_notes' => 'Pengujian alur end-to-end.',
        ], $operator->id);
    }

    // =====================================================================
    // ALUR 1 — ANGGOTA DAN SIMPANAN
    // =====================================================================

    public function test_flow_1_member_and_savings_end_to_end(): void
    {
        // 1. Buat anggota.
        $this->actingAs($this->superadmin)->post(route('anggota.store'), [
            'member_number' => 'ANG-E2E-001',
            'full_name' => 'Anggota Alur Satu',
            'identity_number' => '3501234567890001',
            'phone' => '081234567890',
            'address' => 'Jl. Testing No. 1',
            'joined_at' => now()->format('Y-m-d'),
            'status' => 'active',
        ])->assertRedirect();

        $member = Member::query()->where('member_number', 'ANG-E2E-001')->firstOrFail();
        $this->assertSame('active', $member->status);

        // 2. Buka rekening simpanan.
        $productId = SavingProduct::factory()->create(['is_active' => true])->id;
        $this->actingAs($this->superadmin)->post(route('simpanan-rekening.store'), [
            'member_id' => $member->id,
            'saving_product_id' => $productId,
            'opened_at' => now()->format('Y-m-d'),
        ])->assertRedirect();

        $account = SavingsAccount::query()->where('member_id', $member->id)->firstOrFail();
        $this->assertSame(0.0, (float) $account->balance);

        // 3-4. Catat setoran lalu verifikasi saldo.
        $this->actingAs($this->superadmin)->post(route('simpanan-transaksi.deposit.store', $account->id), [
            'amount' => 500000,
            'transaction_date' => now()->format('Y-m-d'),
            'description' => 'Setoran awal alur 1',
        ])->assertRedirect();

        // 5-6. Penarikan valid lalu verifikasi saldo dan riwayat.
        $this->actingAs($this->superadmin)->post(route('simpanan-transaksi.withdraw.store', $account->id), [
            'amount' => 150000,
            'transaction_date' => now()->format('Y-m-d'),
            'description' => 'Penarikan alur 1',
        ])->assertRedirect();

        $account->refresh();
        $this->assertSame(350000.0, (float) $account->balance);
        $this->assertSame(2, $account->transactions()->where('status', 'completed')->count());

        // Saldo = hasil perhitungan ulang ledger transaksi sah.
        $recalculated = (float) $account->transactions()->where('type', 'deposit')->where('status', 'completed')->sum('amount')
            - (float) $account->transactions()->where('type', 'withdrawal')->where('status', 'completed')->sum('amount');
        $this->assertSame(round($recalculated, 2), (float) $account->balance);

        // 7. Laporan simpanan sesuai dengan data sumber.
        $response = $this->actingAs($this->superadmin)->get(route('laporan.simpanan'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('summary.total_balance', fn ($v) => (float) $v === 350000.0)
            ->where('summary.period_deposits', fn ($v) => (float) $v === 500000.0)
            ->where('summary.period_withdrawals', fn ($v) => (float) $v === 150000.0)
        );

        // Penarikan melebihi saldo ditolak dan tidak mengubah saldo.
        $this->actingAs($this->superadmin)->post(route('simpanan-transaksi.withdraw.store', $account->id), [
            'amount' => 999999,
            'transaction_date' => now()->format('Y-m-d'),
        ])->assertSessionHasErrors(['amount']);
        $this->assertSame(350000.0, (float) $account->fresh()->balance);
    }

    // =====================================================================
    // ALUR 2 — PINJAMAN DAN ANGSURAN
    // =====================================================================

    public function test_flow_2_loan_and_installment_end_to_end(): void
    {
        $member = Member::factory()->create(['status' => 'active']);

        // 1. Pengajuan pinjaman.
        $this->actingAs($this->superadmin)->post(route('pinjaman.store'), [
            'member_id' => $member->id,
            'amount' => 6000000,
            'tenor_months' => 6,
            'submission_date' => now()->format('Y-m-d'),
        ])->assertRedirect();

        $loan = Loan::query()->where('member_id', $member->id)->firstOrFail();
        $this->assertSame('PENDING', $loan->status);

        // Pinjaman PENDING tidak dapat dicairkan.
        $this->actingAs($this->superadmin)->post(route('pinjaman.disburse.store', $loan->id), [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'TRANSFER',
        ])->assertSessionHasErrors();

        // 2. Persetujuan oleh pengguna berwenang.
        $this->actingAs($this->superadmin)->post(route('pinjaman.approve', $loan->id))->assertRedirect();
        $this->assertSame('APPROVED', $loan->fresh()->status);

        // Persetujuan ganda ditolak.
        $this->actingAs($this->superadmin)->post(route('pinjaman.approve', $loan->id))->assertSessionHasErrors();

        // 3-4. Pencairan (TRANSFER: tanpa dampak kas) lalu status pinjaman.
        $this->actingAs($this->superadmin)->post(route('pinjaman.disburse.store', $loan->id), [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'TRANSFER',
        ])->assertRedirect();

        $loan->refresh();
        $this->assertSame('ACTIVE', $loan->status);
        $this->assertNotNull($loan->disbursement);
        $this->assertSame(6, $loan->schedules()->count());

        // 5. Jadwal angsuran tersedia (pokok, tanpa bunga: rumus belum dikonfirmasi).
        $scheduleTotal = (float) $loan->schedules()->sum('total_amount');
        $this->assertSame(6000000.0, $scheduleTotal);
        $this->assertSame(0.0, (float) $loan->schedules()->sum('interest_amount'));

        // 6-8. Pembayaran angsuran terhubung ke pinjaman yang benar.
        $this->actingAs($this->superadmin)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => 1000000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertRedirect();

        $this->actingAs($this->superadmin)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => 5000000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertRedirect();

        $loan->refresh();
        $this->assertSame(6000000.0, (float) $loan->payments()->where('status', 'COMPLETED')->sum('amount'));
        $this->assertSame('PAID_OFF', $loan->status);
        $this->assertSame(0.0, (float) $loan->schedules()->sum('remaining_amount'));

        // Pembayaran melebihi sisa kewajiban (pinjaman sudah lunas) ditolak.
        $this->actingAs($this->superadmin)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => 100000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertSessionHasErrors(['status']);

        // 9. Laporan pinjaman dan pembayaran sesuai data sumber.
        $this->actingAs($this->superadmin)->get(route('laporan.pinjaman'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.disbursed_amount', fn ($v) => (float) $v === 6000000.0)
                ->where('summary.paid_total', fn ($v) => (float) $v === 6000000.0)
                ->where('summary.outstanding_amount', fn ($v) => (float) $v === 0.0)
            );

        $this->actingAs($this->superadmin)->get(route('laporan.angsuran'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.completed_total', fn ($v) => (float) $v === 6000000.0)
            );
    }

    // =====================================================================
    // ALUR 3 — INTEGRASI KASIR
    // =====================================================================

    public function test_flow_3_cashier_integration_end_to_end(): void
    {
        $operator = User::factory()->superadmin()->create();

        // 1. Buka sesi kasir.
        $session = $this->openCashierSession($operator, 1000000);

        // 2-3. Penerimaan dan pengeluaran kas manual.
        $this->actingAs($operator)->post(route('kasir.penerimaan.store'), [
            'amount' => 250000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
            'description' => 'Penerimaan manual alur 3',
        ])->assertRedirect();

        $this->actingAs($operator)->post(route('kasir.pengeluaran.store'), [
            'amount' => 100000,
            'category' => 'Biaya Operasional',
            'transaction_date' => now()->format('Y-m-d'),
            'description' => 'Pengeluaran manual alur 3',
        ])->assertRedirect();

        // 4. Transaksi tunai dari modul sumber: setoran simpanan.
        $account = SavingsAccount::factory()->create(['balance' => 0]);
        $this->actingAs($operator)->post(route('simpanan-transaksi.deposit.store', $account->id), [
            'amount' => 300000,
            'transaction_date' => now()->format('Y-m-d'),
        ])->assertRedirect();

        // 5. Tidak ada dampak kas ganda untuk referensi sumber yang sama.
        $source = SavingsTransaction::query()->where('savings_account_id', $account->id)->firstOrFail();
        $this->assertSame(1, CashTransaction::query()
            ->where('source_type', SavingsTransaction::class)
            ->where('source_id', $source->id)
            ->count());

        // Saldo kas sistem sesuai ledger: 1.000.000 + 250.000 + 300.000 - 100.000.
        $totals = app(CashierService::class)->totals($session->fresh());
        $this->assertSame(1450000.0, $totals['closing_balance']);

        // Pengeluaran melebihi kas ditolak.
        $this->actingAs($operator)->post(route('kasir.pengeluaran.store'), [
            'amount' => 99999999,
            'category' => 'Biaya Operasional',
            'transaction_date' => now()->format('Y-m-d'),
        ])->assertSessionHasErrors(['amount']);

        // 6-7. Tutup sesi kasir, verifikasi saldo akhir dan selisih kas.
        $this->actingAs($operator)->post(route('kasir.sesi.close.store', $session->id), [
            'physical_closing_balance' => 1450000,
        ])->assertSessionHas('success');

        $session->refresh();
        $this->assertSame('CLOSED', $session->status);
        $this->assertSame(1450000.0, (float) $session->system_closing_balance);
        $this->assertSame(0.0, (float) $session->difference);

        // Sesi tertutup menolak transaksi baru.
        $this->actingAs($operator)->post(route('kasir.penerimaan.store'), [
            'amount' => 50000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
        ])->assertRedirect(route('kasir.index'));

        $this->assertSame(3, $session->transactions()->count());
    }

    // =====================================================================
    // ALUR 4 — DASHBOARD DAN LAPORAN
    // =====================================================================

    public function test_flow_4_dashboard_and_reports_match_source_data(): void
    {
        $operator = User::factory()->superadmin()->create();

        // Sesi kasir terbuka agar transaksi tunai sumber menghasilkan dampak kas.
        $this->openCashierSession($operator, 0);

        // 1. Bangun data transaksi yang sah: anggota, rekening, mutasi,
        //    pinjaman, dan pembayaran.
        $memberA = Member::factory()->create(['status' => 'active']);
        $memberB = Member::factory()->create(['status' => 'active']);
        $accountA = SavingsAccount::factory()->create(['member_id' => $memberA->id, 'balance' => 0]);
        $accountB = SavingsAccount::factory()->create(['member_id' => $memberB->id, 'balance' => 0]);

        $service = app(SavingsTransactionService::class);
        $service->deposit($accountA, ['amount' => 400000, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $operator->id]);
        $service->deposit($accountB, ['amount' => 200000, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $operator->id]);
        $service->withdraw($accountB, ['amount' => 50000, 'transaction_date' => now()->format('Y-m-d'), 'processed_by' => $operator->id]);

        $loan = Loan::factory()->approved()->create(['member_id' => $memberA->id, 'amount' => 2000000, 'tenor_months' => 4]);
        app(LoanService::class)->disburse($loan, [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'TRANSFER',
        ], $operator->id);
        app(InstallmentPaymentService::class)->pay($loan, [
            'amount' => 500000,
            'payment_date' => now()->format('Y-m-d'),
        ], $operator->id);

        // 2-3. Dashboard: ringkasan sesuai data sumber.
        $this->actingAs($this->superadmin)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('metrics.members.total', 2)
                ->where('metrics.savings.total_balance', fn ($v) => (float) $v === 550000.0)
                ->where('metrics.savings.period_deposits', fn ($v) => (float) $v === 600000.0)
                ->where('metrics.savings.period_withdrawals', fn ($v) => (float) $v === 50000.0)
                // Hanya pencairan yang terhitung, pengajuan/persetujuan belum.
                ->where('metrics.loans.disbursed_amount', fn ($v) => (float) $v === 2000000.0)
                ->where('metrics.loans.paid_total', fn ($v) => (float) $v === 500000.0)
                ->where('metrics.loans.outstanding_amount', fn ($v) => (float) $v === 1500000.0)
            );

        // 4-8. Seluruh laporan terbuka dan angkanya sesuai sumber.
        $this->actingAs($this->superadmin)->get(route('laporan.simpanan'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.total_balance', fn ($v) => (float) $v === 550000.0)
            );

        $this->actingAs($this->superadmin)->get(route('laporan.pinjaman'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.disbursed_amount', fn ($v) => (float) $v === 2000000.0)
            );

        $this->actingAs($this->superadmin)->get(route('laporan.angsuran'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.completed_total', fn ($v) => (float) $v === 500000.0)
            );

        $this->actingAs($this->superadmin)->get(route('laporan.transaksi-kas'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Penerimaan: setoran tunai 400.000 + 200.000 + pembayaran
                // angsuran 500.000. Pengeluaran: penarikan tunai 50.000.
                ->where('summary.income_total', fn ($v) => (float) $v === 1100000.0)
                ->where('summary.expense_total', fn ($v) => (float) $v === 50000.0)
            );

        // Filter tanggal tidak mengubah saldo saat ini.
        $this->actingAs($this->superadmin)->get(route('laporan.simpanan', [
            'period' => 'custom',
            'date_from' => now()->subDays(30)->format('Y-m-d'),
            'date_to' => now()->subDays(20)->format('Y-m-d'),
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.total_balance', fn ($v) => (float) $v === 550000.0)
            );
    }

    // =====================================================================
    // ALUR 5 — AUDIT DAN REKONSILIASI
    // =====================================================================

    public function test_flow_5_audit_trail_and_reconciliation_end_to_end(): void
    {
        $operator = User::factory()->superadmin()->create();
        $account = SavingsAccount::factory()->create(['balance' => 0]);

        // 1. Transaksi keuangan yang sah.
        app(SavingsTransactionService::class)->deposit($account, [
            'amount' => 800000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $operator->id,
        ]);

        // 2. Audit trail tercatat dan dapat ditelusuri ke transaksi sumber.
        $audit = AuditLog::query()
            ->where('object_type', SavingsTransaction::class)
            ->where('action', 'savings.transaction.deposit')
            ->firstOrFail();

        // Aktor berasal dari sesi: null saat dipanggil via service di luar HTTP,
        // terisi saat melalui request (keduanya valid untuk penelusuran).
        $this->assertTrue($audit->actor_id === null || $audit->actor_id === $operator->id);

        $transaction = SavingsTransaction::query()->where('savings_account_id', $account->id)->firstOrFail();
        $this->assertSame($transaction->id, $audit->object_id);

        // 3-4. Jalankan rekonsiliasi dan verifikasi hasil pemeriksaan.
        // Periode maksimum adalah hari ini (validasi request).
        $this->actingAs($this->superadmin)->post(route('rekonsiliasi.runs.store'), [
            'period_start' => now()->subDay()->format('Y-m-d'),
            'period_end' => now()->format('Y-m-d'),
            'modules' => ['savings', 'loans', 'cash'],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $run = ReconciliationRun::query()->latest('id')->firstOrFail();
        $this->assertSame('COMPLETED', $run->status);
        $this->assertSame(0, $run->findings_count, 'Data konsisten seharusnya tanpa temuan saldo.');

        // 5-6. Skenario data tidak konsisten pada lingkungan testing harus terdeteksi.
        $anotherAccount = SavingsAccount::factory()->create(['balance' => 123456.78]);
        $this->actingAs($this->superadmin)->post(route('rekonsiliasi.runs.store'), [
            'period_start' => now()->subDay()->format('Y-m-d'),
            'period_end' => now()->format('Y-m-d'),
            'modules' => ['savings'],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $secondRun = ReconciliationRun::query()->latest('id')->firstOrFail();
        $this->assertGreaterThanOrEqual(1, $secondRun->findings_count, 'Saldo tanpa ledger wajib terdeteksi sebagai temuan.');

        $finding = $secondRun->findings()->first();
        $this->assertSame('BALANCE_MISMATCH', $finding->finding_type);
        $this->assertSame(SavingsAccount::class, $finding->object_type);
        $this->assertSame($anotherAccount->id, $finding->object_id);
        $this->assertSame('OPEN', $finding->status);

        // 7. Tindak lanjut temuan dapat ditelusuri dan rekonsiliasi tidak pernah
        //    mengubah saldo sumber (rekening kedua tetap tidak tersentuh).
        $this->actingAs($this->superadmin)->put(route('rekonsiliasi.findings.update', $finding->id), [
            'to_status' => 'UNDER_REVIEW',
            'note' => 'Skenario uji data tidak konsisten pada lingkungan testing.',
        ])->assertSessionHas('success');

        $this->assertSame('UNDER_REVIEW', $finding->fresh()->status);
        $this->assertDatabaseHas('reconciliation_follow_ups', [
            'reconciliation_finding_id' => $finding->id,
            'user_id' => $this->superadmin->id,
            'from_status' => 'OPEN',
            'to_status' => 'UNDER_REVIEW',
        ]);
        $this->assertSame(123456.78, (float) $anotherAccount->fresh()->balance);
    }

    // =====================================================================
    // ALUR 6 — OTORISASI
    // =====================================================================

    public function test_flow_6_authorization_across_modules(): void
    {
        $member = Member::factory()->create(['status' => 'active']);
        $account = SavingsAccount::factory()->create(['member_id' => $member->id, 'balance' => 100000]);
        $loan = Loan::factory()->approved()->create(['member_id' => $member->id, 'amount' => 1000000, 'tenor_months' => 2]);
        $operator = User::factory()->superadmin()->create();
        $session = $this->openCashierSession($operator);

        // Pinjaman harus sudah dicairkan sebelum menerima pembayaran angsuran.
        app(LoanService::class)->disburse($loan, [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'TRANSFER',
        ], $operator->id);

        // 1. Pengguna dengan permission yang sesuai dapat melakukan alur bisnis.
        $teller = $this->userWithPermissions(['dashboard.view', 'simpanan.view', 'simpanan.deposit', 'angsuran.view', 'angsuran.record_payment', 'kasir.view']);

        $this->actingAs($teller)->post(route('simpanan-transaksi.deposit.store', $account->id), [
            'amount' => 50000,
            'transaction_date' => now()->format('Y-m-d'),
        ])->assertRedirect();
        $this->assertSame(150000.0, (float) $account->fresh()->balance);

        $this->actingAs($teller)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => 500000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertRedirect();

        $this->actingAs($teller)->get(route('dashboard'))->assertOk();
        $this->actingAs($teller)->get(route('laporan.index'))->assertForbidden();

        // 2-3. Pengguna tanpa permission ditolak di backend (bukan hanya UI).
        $plain = User::factory()->create();

        $this->actingAs($plain)->post(route('simpanan-transaksi.deposit.store', $account->id), [
            'amount' => 50000,
            'transaction_date' => now()->format('Y-m-d'),
        ])->assertForbidden();

        $this->actingAs($plain)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => 50000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertForbidden();

        $this->actingAs($plain)->post(route('kasir.penerimaan.store'), [
            'amount' => 50000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
        ])->assertForbidden();

        // Buka/tutup sesi kasir kini memerlukan permission eksplisit.
        $this->actingAs($teller)->get(route('kasir.sesi.create'))->assertForbidden();
        $this->actingAs($teller)->post(route('kasir.sesi.store'), [
            'operational_date' => now()->format('Y-m-d'),
            'opening_balance' => 100000,
        ])->assertForbidden();
        $this->actingAs($teller)->post(route('kasir.sesi.close.store', $session->id), [
            'physical_closing_balance' => 100000,
        ])->assertForbidden();

        // Pengajuan dan persetujuan pinjaman juga terlindungi.
        $this->actingAs($plain)->post(route('pinjaman.store'), [
            'member_id' => $member->id,
            'amount' => 1000000,
            'tenor_months' => 6,
            'submission_date' => now()->format('Y-m-d'),
        ])->assertForbidden();

        $clerk = $this->userWithPermissions(['dashboard.view', 'pinjaman.create']);
        $this->actingAs($clerk)->post(route('pinjaman.store'), [
            'member_id' => $member->id,
            'amount' => 1000000,
            'tenor_months' => 6,
            'submission_date' => now()->format('Y-m-d'),
        ])->assertRedirect();

        // Ambil pinjaman terbaru milik member: itulah yang baru diajukan clerk.
        // (member yang sama sudah memiliki pinjaman sebelumnya pada test ini.)
        $newLoan = Loan::query()->where('member_id', $member->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame('PENDING', $newLoan->fresh()->status);

        $this->actingAs($clerk)->post(route('pinjaman.approve', $newLoan->id))->assertForbidden();
        $this->assertSame('PENDING', $newLoan->fresh()->status);

        $approver = $this->userWithPermissions(['dashboard.view', 'pinjaman.approve']);
        $this->actingAs($approver)->post(route('pinjaman.approve', $newLoan->id))->assertRedirect();
        $this->assertSame('APPROVED', $newLoan->fresh()->status);

        // 4. Akses audit trail, rekonsiliasi, dan laporan sesuai permission.
        $auditor = $this->userWithPermissions(['dashboard.view', 'audit.view', 'laporan.view', 'rekonsiliasi.view']);

        $this->actingAs($auditor)->get(route('pengaturan.audit.index'))->assertOk();
        $this->actingAs($auditor)->get(route('rekonsiliasi.index'))->assertOk();
        $this->actingAs($auditor)->get(route('laporan.simpanan'))->assertOk();

        // Permission khusus diperlukan untuk menjalankan rekonsiliasi.
        $this->actingAs($auditor)->post(route('rekonsiliasi.runs.store'), [
            'period_start' => now()->subDay()->format('Y-m-d'),
            'period_end' => now()->addDay()->format('Y-m-d'),
            'modules' => ['savings'],
        ])->assertForbidden();

        // Audit tetap tercatat untuk operasi yang dilakukan pengguna berpermission.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'installment.payment.recorded',
            'actor_id' => $teller->id,
        ]);
    }
}
