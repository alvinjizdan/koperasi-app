<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingProduct;
use App\Models\SavingsTransaction;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Ticket 86 — Transaksi Pembayaran Pinjaman SHM.
 *
 * Mengunci: sumber pembayaran (hanya pinjaman SHM aktif yang sudah dicairkan),
 * nominal > 0, referensi & idempotensi server-side, transaksionalitas, audit
 * tepat satu, serta BATAS keras bahwa T86 TIDAK mengubah angsuran, saldo
 * pokok, maupun status pinjaman saat dipanggil sebagai service.
 *
 * T151 — endpoint `store` kini menjadi SATU operasi reguler atomik
 * (validasi underpayment → pembayaran → alokasi → lebih bayar ke Simpanan
 * Pokok → penutupan otomatis). Karena itu test file ini memakai fixture
 * jadwal angsuran yang valid; perilaku underpayment/lebih bayar/penutupan
 * diuji fokus di `ShmRegularPaymentOverpaymentClosureTest`.
 */
class ShmLoanPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const AUDIT_ACTION = 'shm.loan.payment.created';

    private User $admin;

    private User $marketing;

    private User $marketing2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->marketing2 = $this->userWithRole('marketing');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /**
     * Pengguna dengan permission eksplisit tanpa mengubah role bawaan
     * (pola ShmLoanDisbursementTest) — memiliki `shm.payment.create` tetapi
     * TANPA `shm.view_all`, sehingga scope kepemilikan tetap teruji.
     *
     * @param  array<int, string>  $permissionNames
     */
    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $role = Role::query()->create([
            'name' => 'payment-tester-'.Str::uuid(),
            'display_name' => 'Payment Tester',
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id')
        );

        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * Pengajuan fixture milik $user (langsung ke model, tanpa alur form).
     *
     * T151 — `$member` dipakai untuk skenario lebih bayar yang wajib
     * memindahkan kelebihan ke Simpanan Pokok anggota sumber.
     */
    private function applicationOwnedBy(User $user, ?Member $member = null): ShmLoanApplication
    {
        return ShmLoanApplication::query()->create([
            'application_number' => 'PENGAJ-PIN-'.Str::upper(Str::random(10)),
            'shm_customer_id' => ShmCustomer::factory()->create([
                'member_id' => $member?->id,
            ])->id,
            'application_date' => now()->toDateString(),
            'principal' => '100000000',
            'tenor_months' => 12,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $user->id,
            'status' => 'approved',
        ]);
    }

    /**
     * Pinjaman aktif & sudah dicairkan yang berasal dari pengajuan $user.
     *
     * T151 — pinjaman selalu memiliki jadwal angsuran karena kewajiban yang
     * harus dibayar TIDAK boleh ditebak dari saldo pokok.
     */
    private function activeLoanOf(User $user, ?Member $member = null): ShmLoan
    {
        $application = $this->applicationOwnedBy($user, $member);

        $loan = ShmLoan::factory()->create([
            'shm_customer_id' => $application->shm_customer_id,
            'shm_loan_application_id' => $application->id,
            'principal' => $application->principal,
            'tenor_months' => $application->tenor_months,
        ]);

        $this->scheduleOf($loan, 3);

        return $loan;
    }

    /**
     * Jadwal angsuran sederhana: 3 angsuran, masing-masing Rp10.000.000
     * (bunga Rp1.666.666,67 + pokok Rp8.333.333,33).
     *
     * @return array<int, ShmLoanInstallment>
     */
    private function scheduleOf(ShmLoan $loan, int $count = 3): array
    {
        $installments = [];

        for ($index = 0; $index < $count; $index++) {
            $installments[] = $loan->installments()->create([
                'installment_number' => $index + 1,
                'due_date' => now()->addMonths($index + 1)->startOfMonth()->toDateString(),
                'principal_amount' => 8333333.33,
                'interest_amount' => 1666666.67,
                'total_amount' => 10000000,
                'paid_amount' => 0,
                'paid_interest_amount' => 0,
                'paid_principal_amount' => 0,
                'remaining_amount' => 10000000,
                'status' => ShmLoanInstallment::STATUS_SCHEDULED,
            ]);
        }

        return $installments;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pay(ShmLoan $loan, array $payload = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->post(
            route('shm.loans.payments.store'),
            array_merge([
                'loan_id' => $loan->id,
                'payment_date' => now()->toDateString(),
                // T151 — nominal default menutup satu angsuran (tidak underpayment).
                'amount' => '10000000',
                'idempotency_key' => (string) Str::uuid(),
            ], $payload),
        );
    }

    private function paymentAuditCount(): int
    {
        return AuditLog::query()->where('action', self::AUDIT_ACTION)->count();
    }

    // -----------------------------------------------------------------
    // A. AUTHORIZATION & SCOPE
    // -----------------------------------------------------------------

    public function test_authorized_user_can_create_payment(): void
    {
        $loan = $this->activeLoanOf($this->marketing);

        $this->pay($loan, [], $this->admin)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('shm.loans.payments.index'));

        $this->assertSame(1, ShmLoanPayment::query()->count());
        $this->assertSame(ShmLoanPayment::STATUS_POSTED, ShmLoanPayment::query()->sole()->status);
        $this->assertSame($this->admin->id, ShmLoanPayment::query()->sole()->created_by);
    }

    public function test_user_without_payment_permission_is_forbidden(): void
    {
        $loan = $this->activeLoanOf($this->marketing);

        // Marketing memegang shm.view tetapi TIDAK shm.payment.create.
        $this->assertFalse($this->marketing->hasPermission('shm.payment.create'));

        $this->pay($loan, [], $this->marketing)->assertForbidden();

        $this->assertSame(0, ShmLoanPayment::query()->count());
        $this->assertSame(0, $this->paymentAuditCount());
    }

    public function test_ownership_scope_is_enforced_for_payment(): void
    {
        $foreignLoan = $this->activeLoanOf($this->marketing2);

        $ownerless = $this->userWithPermissions(['shm.view', 'shm.payment.create']);

        $this->assertTrue($ownerless->hasPermission('shm.payment.create'));
        $this->assertFalse($ownerless->hasPermission('shm.view_all'));

        $this->pay($foreignLoan, [], $ownerless)->assertForbidden();

        $this->assertSame(0, ShmLoanPayment::query()->count());
    }

    public function test_guest_is_redirected_and_no_payment_is_created(): void
    {
        $loan = $this->activeLoanOf($this->marketing);

        $this->app['auth']->forgetGuards();

        $this->post(route('shm.loans.payments.store'), [
            'loan_id' => $loan->id,
            'payment_date' => now()->toDateString(),
            'amount' => '100000',
        ])->assertRedirect(route('shm.login'));

        $this->assertSame(0, ShmLoanPayment::query()->count());
    }

    // -----------------------------------------------------------------
    // B. SUMBER PEMBAYARAN
    // -----------------------------------------------------------------

    public function test_payment_requires_existing_loan(): void
    {
        $this->pay($this->activeLoanOf($this->marketing), ['loan_id' => 999999])
            ->assertSessionHasErrors('loan_id');

        $this->assertSame(0, ShmLoanPayment::query()->count());
    }

    public function test_undisbursed_loan_cannot_receive_payment(): void
    {
        $loan = ShmLoan::factory()->create([
            'shm_loan_application_id' => null,
            'disbursed_at' => null,
            'status' => ShmLoan::STATUS_ACTIVE,
        ]);

        $this->pay($loan)->assertSessionHasErrors('loan_id');

        $this->assertSame(0, ShmLoanPayment::query()->count());
    }

    public function test_closed_loan_cannot_receive_payment(): void
    {
        $loan = ShmLoan::factory()->closed()->create([
            'shm_loan_application_id' => $this->applicationOwnedBy($this->marketing)->id,
        ]);

        $this->pay($loan)->assertSessionHasErrors('loan_id');

        $this->assertSame(0, ShmLoanPayment::query()->count());
    }

    public function test_pending_application_cannot_receive_payment_directly(): void
    {
        // Pembayaran hanya menerima pinjaman (loan_id terhadap `shm_loans`);
        // tidak ada endpoint yang menerima pengajuan, sehingga pengajuan
        // pending tidak pernah dapat dibayar.
        $application = ShmLoanApplication::query()->create([
            'application_number' => 'PENGAJ-PIN-'.Str::upper(Str::random(10)),
            'shm_customer_id' => ShmCustomer::factory()->create()->id,
            'application_date' => now()->toDateString(),
            'principal' => '50000000',
            'tenor_months' => 12,
            'marketing_id' => $this->marketing->id,
            'status' => 'pending',
        ]);

        $this->assertFalse($application->loan()->exists(), 'Pengajuan pending tidak boleh memiliki pinjaman.');

        // id di luar `shm_loans` (mengikuti pola id pengajuan yang bukan pinjaman).
        $notALoanId = ((int) ShmLoan::query()->max('id')) + 1000;

        $this->pay($this->activeLoanOf($this->marketing), [
            'loan_id' => $notALoanId,
        ])->assertSessionHasErrors('loan_id');

        $this->assertSame(0, ShmLoanPayment::query()->count());
    }

    // -----------------------------------------------------------------
    // C. NOMINAL
    // -----------------------------------------------------------------

    public function test_zero_amount_is_rejected(): void
    {
        $loan = $this->activeLoanOf($this->marketing);

        $this->pay($loan, ['amount' => '0'])->assertSessionHasErrors('amount');

        $this->assertSame(0, ShmLoanPayment::query()->count());
    }

    public function test_negative_amount_is_rejected(): void
    {
        $loan = $this->activeLoanOf($this->marketing);

        $this->pay($loan, ['amount' => '-500000'])->assertSessionHasErrors('amount');

        $this->assertSame(0, ShmLoanPayment::query()->count());
    }

    public function test_invalid_amount_is_rejected(): void
    {
        $loan = $this->activeLoanOf($this->marketing);

        $this->pay($loan, ['amount' => 'bukan-angka'])->assertSessionHasErrors('amount');

        $this->assertSame(0, ShmLoanPayment::query()->count());
    }

    public function test_amount_is_persisted_exactly(): void
    {
        $loan = $this->activeLoanOf($this->marketing);

        $this->pay($loan, ['amount' => '12345000.25'])->assertSessionHasNoErrors();

        $payment = ShmLoanPayment::query()->sole();

        $this->assertSame('12345000.25', (string) $payment->amount);
    }

    public function test_overpayment_is_persisted_without_truncation(): void
    {
        $member = Member::factory()->create();
        SavingProduct::factory()->category(SavingProduct::CATEGORY_PRINCIPAL)->create();

        $loan = $this->activeLoanOf($this->marketing, $member);

        // Jauh melebihi sisa angsuran (3 × Rp10jt) dan melebihi plafon.
        $this->pay($loan, ['amount' => '150000000'])->assertSessionHasNoErrors();

        $payment = ShmLoanPayment::query()->sole();

        $this->assertSame('150000000.00', (string) $payment->amount, 'Kelebihan nominal tidak boleh ditrunk.');

        // T151 — kelebihan TIDAK dibuang: sisanya dipindahkan ke Simpanan
        // Pokok anggota sumber (bukan dibiarkan unallocated).
        $this->assertSame(1, SavingsTransaction::query()->count());
        $this->assertEquals(
            150000000 - 30000000,
            (float) SavingsTransaction::query()->sole()->amount,
        );
    }

    // -----------------------------------------------------------------
    // D. TANGGAL & KANAL
    // -----------------------------------------------------------------

    public function test_payment_date_is_persisted_as_submitted(): void
    {
        $loan = $this->activeLoanOf($this->marketing);
        $backdated = now()->subDays(10)->toDateString();

        $this->pay($loan, ['payment_date' => $backdated])->assertSessionHasNoErrors();

        $this->assertSame($backdated, ShmLoanPayment::query()->sole()->payment_date->toDateString());
    }

    public function test_invalid_payment_date_is_rejected(): void
    {
        $loan = $this->activeLoanOf($this->marketing);

        $this->pay($loan, ['payment_date' => 'bukan-tanggal'])
            ->assertSessionHasErrors('payment_date');

        $this->assertSame(0, ShmLoanPayment::query()->count());
    }

    public function test_payment_channel_is_optional_without_invented_enum(): void
    {
        $loan = $this->activeLoanOf($this->marketing);

        // 1. Tanpa kanal (daftar resmi PENDING) → null tersimpan.
        $this->pay($loan, ['payment_channel' => null])->assertSessionHasNoErrors();
        $this->assertNull(ShmLoanPayment::query()->sole()->payment_channel);

        // 2. Tidak ada validasi enum buatan: teks bebas diterima apa adanya
        //    karena KSP belum menetapkan kanal resmi.
        $this->pay($loan, ['payment_channel' => 'Tunai di loket'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ShmLoanPayment::query()->count());
        $this->assertSame(
            'Tunai di loket',
            ShmLoanPayment::query()->orderByDesc('id')->first()->payment_channel,
        );
    }

    // -----------------------------------------------------------------
    // E. REFERENSI & IDEMPOTENSI
    // -----------------------------------------------------------------

    public function test_payment_reference_is_generated_server_side(): void
    {
        $loan = $this->activeLoanOf($this->marketing);

        // Payload mengirim referensi palsu — backend mengabaikannya.
        $this->pay($loan, ['payment_reference' => 'FAKE-CLIENT-REF'])
            ->assertSessionHasNoErrors();

        $payment = ShmLoanPayment::query()->sole();

        $this->assertNotSame('FAKE-CLIENT-REF', $payment->payment_reference);
        $this->assertMatchesRegularExpression(
            '/^SHM-PAY-'.preg_quote(now()->format('Ymd'), '/').'-[A-F0-9]{8}$/',
            $payment->payment_reference,
        );
    }

    public function test_payment_references_are_unique(): void
    {
        $loan = $this->activeLoanOf($this->marketing);

        $this->pay($loan)->assertSessionHasNoErrors();
        $this->pay($loan)->assertSessionHasNoErrors();

        $references = ShmLoanPayment::query()->pluck('payment_reference');

        $this->assertCount(2, $references);
        $this->assertCount(2, $references->unique(), 'Referensi pembayaran tidak boleh sama.');
    }

    public function test_duplicate_submission_does_not_create_second_payment(): void
    {
        $loan = $this->activeLoanOf($this->marketing);
        $key = (string) Str::uuid();

        $this->pay($loan, ['idempotency_key' => $key])->assertSessionHasNoErrors();
        $this->pay($loan, ['idempotency_key' => $key])->assertSessionHasNoErrors();

        $this->assertSame(1, ShmLoanPayment::query()->count(), 'Request ulang tidak boleh membuat pembayaran kedua.');
        $this->assertSame(1, $this->paymentAuditCount(), 'Request ulang tidak boleh membuat audit kedua.');
    }

    public function test_database_uniqueness_protects_reference_and_idempotency_key(): void
    {
        $loan = $this->activeLoanOf($this->marketing);
        $this->pay($loan, ['idempotency_key' => 'kunci-unik-1'])->assertSessionHasNoErrors();

        $existing = ShmLoanPayment::query()->sole();

        // 1. Referensi duplikat ditolak database.
        $this->expectException(QueryException::class);

        try {
            ShmLoanPayment::query()->create([
                'shm_loan_id' => $loan->id,
                'payment_reference' => $existing->payment_reference,
                'payment_date' => now()->toDateString(),
                'amount' => '1000',
                'status' => ShmLoanPayment::STATUS_POSTED,
                'created_by' => $this->admin->id,
                'idempotency_key' => 'kunci-unik-2',
            ]);
        } finally {
            // 2. Idempotency key duplikat juga ditolak database.
            try {
                ShmLoanPayment::query()->create([
                    'shm_loan_id' => $loan->id,
                    'payment_reference' => 'SHM-PAY-DUP-KEY-0001',
                    'payment_date' => now()->toDateString(),
                    'amount' => '1000',
                    'status' => ShmLoanPayment::STATUS_POSTED,
                    'created_by' => $this->admin->id,
                    'idempotency_key' => 'kunci-unik-1',
                ]);
            } catch (QueryException) {
                // Diharapkan — constraint UNIQUE bekerja.
            }
        }
    }

    // -----------------------------------------------------------------
    // F. BATAS FINANSIAL (T86 ≠ T87)
    // -----------------------------------------------------------------

    public function test_payment_does_not_change_loan_principal_or_status(): void
    {
        $loan = $this->activeLoanOf($this->marketing);
        $principalBefore = (string) $loan->principal;

        $this->pay($loan)->assertSessionHasNoErrors();

        $loan->refresh();

        $this->assertSame($principalBefore, (string) $loan->principal, 'Pokok tidak boleh berubah.');
        // T151 — pinjaman tetap aktif selama masih ada angsuran yang belum
        // lunas; penutupan hanya terjadi bila total kewajiban = 0.
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->status);
        $this->assertEquals($loan->outstanding(), (float) $loan->principal, 'Saldo pokok tidak berubah oleh pembayaran.');
        $this->assertNotNull($loan->disbursed_at);
    }

    public function test_payment_updates_installment_state(): void
    {
        $loan = $this->activeLoanOf($this->marketing);

        $this->pay($loan)->assertSessionHasNoErrors();

        // T151 — endpoint `store` adalah satu operasi reguler atomik:
        // pembayaran dialokasikan ke angsuran pada saat yang sama.
        $installment = $loan->installments()->orderBy('installment_number')->first();

        $this->assertSame('10000000.00', (string) $installment->paid_amount);
        $this->assertSame('0.00', (string) $installment->remaining_amount);
        $this->assertSame(ShmLoanInstallment::STATUS_PAID, $installment->status);
    }

    // -----------------------------------------------------------------
    // G. TRANSAKSI & AUDIT
    // -----------------------------------------------------------------

    public function test_payment_audit_is_created_exactly_once_with_metadata(): void
    {
        $loan = $this->activeLoanOf($this->marketing);

        $this->pay($loan, ['amount' => '12000000'])->assertSessionHasNoErrors();

        $logs = AuditLog::query()->where('action', self::AUDIT_ACTION)->get();

        $this->assertCount(1, $logs, 'Tepat SATU audit pembayaran.');
        $this->assertSame($this->admin->id, $logs->first()->actor_id);
        $this->assertSame(ShmLoanPayment::class, $logs->first()->object_type);

        $payment = ShmLoanPayment::query()->sole();
        $this->assertSame($payment->id, $logs->first()->object_id);
        $this->assertSame($payment->payment_reference, $logs->first()->object_label);
        $this->assertSame('12000000.00', data_get($logs->first()->changes, 'amount'));
        $this->assertSame($payment->payment_reference, data_get($logs->first()->changes, 'payment_reference'));
        $this->assertSame($loan->id, data_get($logs->first()->changes, 'shm_loan_id'));
    }

    public function test_failed_payment_leaves_no_orphan_payment_or_audit(): void
    {
        $loan = $this->activeLoanOf($this->marketing);

        $this->withoutExceptionHandling();

        ShmLoanPayment::creating(function (): void {
            throw new RuntimeException('Pembuatan pembayaran gagal (uji T86).');
        });

        try {
            $this->pay($loan);
            $this->fail('Exception pembuatan pembayaran seharusnya muncul.');
        } catch (RuntimeException) {
            // Diharapkan — transaksi rollback penuh.
        }

        $this->assertSame(0, ShmLoanPayment::query()->count(), 'Pembayaran tidak boleh tertulis bila gagal.');
        $this->assertSame(0, $this->paymentAuditCount(), 'Audit tidak boleh tertulis bila pembayaran gagal.');
    }

    // -----------------------------------------------------------------
    // H. HALAMAN (DAFTAR & DETAIL)
    // -----------------------------------------------------------------

    public function test_payment_index_page_lists_payments_and_payable_loans(): void
    {
        $loan = $this->activeLoanOf($this->marketing);
        $this->pay($loan, [], $this->admin);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.payments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Loans/Payments')
                ->has('payments.data', 1)
                ->where('payments.data.0.amount', ShmLoanPayment::query()->sole()->amount)
                ->has('loans', 1)
                // Relasi nasabah HARUS terisi (FK ikut dipilih pada query pinjaman).
                ->whereNot('payments.data.0.customer.name', null)
                ->whereNot('loans.0.customer.name', null)
            );
    }

    public function test_payment_detail_page_shows_foundation(): void
    {
        $loan = $this->activeLoanOf($this->marketing);
        $this->pay($loan, ['amount' => '10000000'], $this->admin);

        $payment = ShmLoanPayment::query()->sole();

        $this->actingAs($this->admin)
            ->get(route('shm.loans.payments.show', $payment->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Loans/PaymentShow')
                ->where('payment.payment_reference', $payment->payment_reference)
                ->where('payment.loan.loan_number', $loan->loan_number)
                ->where('payment.creator.name', $this->admin->name)
            );
    }

    public function test_payment_index_is_scoped_by_ownership(): void
    {
        $ownLoan = $this->activeLoanOf($this->marketing);
        $this->pay($ownLoan, [], $this->admin);

        $foreignLoan = $this->activeLoanOf($this->marketing2);
        $this->pay($foreignLoan, [], $this->admin);

        $viewer = $this->userWithPermissions(['shm.view', 'shm.payment.create']);

        $this->actingAs($viewer->fresh())
            ->get(route('shm.loans.payments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Loans/Payments')
                ->has('payments.data', 0)
                ->has('loans', 0)
            );
    }

    public function test_payment_detail_of_foreign_loan_is_forbidden(): void
    {
        $foreignLoan = $this->activeLoanOf($this->marketing2);
        $this->pay($foreignLoan, [], $this->admin);

        $payment = ShmLoanPayment::query()->sole();
        $viewer = $this->userWithPermissions(['shm.view', 'shm.payment.create']);

        $this->actingAs($viewer)
            ->get(route('shm.loans.payments.show', $payment->id))
            ->assertForbidden();
    }
}
