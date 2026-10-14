<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Models\User;
use App\Services\Shm\ShmDisbursementCostService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Ticket 85 — SHM Disbursement Cost Persistence.
 *
 * Mengunci bahwa seluruh komponen biaya pencairan (admin, provisi, asuransi,
 * notaris, simpanan pokok, simpanan wajib, total potongan, dana bersih)
 * tersimpan sebagai SNAPSHOT immutable pada ShmLoan saat pencairan, dan
 * seluruh nilai derived dihitung OLEH BACKEND — bukan dari frontend.
 */
class ShmDisbursementCostPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const AUDIT_ACTION = 'shm.loan_application.disbursed';

    private User $admin;

    private User $marketing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /** Produk dengan tier rate eksak (T82) — tanpa fallback universal 2%. */
    private function productWithTiers(): ShmLoanProduct
    {
        $product = ShmLoanProduct::factory()->create();
        $product->rateTiers()->delete();

        foreach ([[12, 18, 1.30], [24, 30, 1.40], [36, 42, 1.50], [48, 52, 1.60]] as [$min, $max, $rate]) {
            $product->rateTiers()->create([
                'min_tenor_months' => $min,
                'max_tenor_months' => $max,
                'rate_percent' => $rate,
                'rate_period' => 'monthly',
                'calculation_method' => 'flat',
            ]);
        }

        return $product;
    }

    /**
     * Payload 11 section valid — kasus minimum kontrak T85:
     * principal Rp450.000.000, tenor 36, notary Rp2.000.000.
     *
     * @return array<string, mixed>
     */
    private function validPayload(int $customerId, array $override = []): array
    {
        $payload = [
            'pribadi' => ['customer_id' => $customerId],
            'pengajuan' => [
                'product_id' => $this->productWithTiers()->id,
                'principal' => '450000000',
                'tenor_months' => 36,
                'first_due_date' => now()->addMonths(2)->startOfMonth()->toDateString(),
                'purpose' => 'UNTUK MODAL USAHA',
                'loan_sequence' => 1,
                'disbursement_method' => 'Tunai',
            ],
            'income' => ['tetap' => '5000000', 'pasangan' => '', 'usaha' => ''],
            'expense' => [
                'potongan' => '1000000',
                'rumahTangga' => '2000000',
                'pendidikan' => '',
                'sewa' => '',
                'listrik' => '',
                'transportasi' => '',
                'lain' => '',
            ],
            'tambahan' => [
                'koperasi_loan_balance' => '0',
                'other_facility' => 'Tidak ada',
                'institution_name' => '',
            ],
            'agunan' => [],
            'jaminan' => [],
            'catatan' => ['date' => now()->format('Y-m-d'), 'name' => 'Kasir', 'notes' => 'Catatan uji T85.'],
            'analisa' => ['analyst_code' => 'AN-01', 'date' => now()->format('Y-m-d'), 'value' => '450000000'],
            'kolektor' => ['name' => ''],
            'persetujuan' => [
                'approved_amount' => '450000000',
                'provision_fee' => '',
                'administration_fee' => '',
                'notary_fee' => '2000000',
                'method' => 'flat',
                'notes' => 'Menunggu keputusan.',
            ],
            'persyaratan' => [],
        ];

        foreach ($override as $group => $values) {
            if (isset($payload[$group]) && is_array($payload[$group]) && is_array($values)) {
                $payload[$group] = array_merge($payload[$group], $values);
            } else {
                $payload[$group] = $values;
            }
        }

        return $payload;
    }

    private function storeAs(User $user, array $payload): TestResponse
    {
        return $this->actingAs($user)->post(route('shm.loans.pengajuan.store'), $payload);
    }

    private function makeApplication(array $override = []): ShmLoanApplication
    {
        $customer = ShmCustomer::factory()->create();

        $this->storeAs($this->marketing, $this->validPayload($customer->id, $override))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return ShmLoanApplication::query()->latest('id')->firstOrFail();
    }

    private function submit(ShmLoanApplication $application): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.submit-approval', $application))
            ->assertSessionHasNoErrors();
    }

    private function decide(ShmLoanApplication $application, string $decision): void
    {
        $mirrorId = $application->refresh()->shm_application_id;

        $this->actingAs($this->admin)
            ->post(route('shm.applications.'.$decision, $mirrorId), ['notes' => 'Keputusan uji T85'])
            ->assertSessionHasNoErrors();
    }

    private function approvedApplication(array $override = []): ShmLoanApplication
    {
        $application = $this->makeApplication($override);
        $this->submit($application);
        $this->decide($application, 'approve');

        return $application->refresh();
    }

    private function disburse(ShmLoanApplication $application, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)
            ->post(route('shm.loans.pengajuan.disburse', $application));
    }

    private function disburseAuditCount(): int
    {
        return AuditLog::query()->where('action', self::AUDIT_ACTION)->count();
    }

    // -----------------------------------------------------------------
    // A. SNAPSHOT PERSISTENCE (contract example T85)
    // -----------------------------------------------------------------

    public function test_full_cost_breakdown_is_persisted_on_disbursement(): void
    {
        $application = $this->approvedApplication();

        $this->disburse($application)->assertSessionHasNoErrors();

        $loan = ShmLoan::query()->sole();

        // Principal Rp450.000.000, tenor 36, notary Rp2.000.000.
        $this->assertEquals('450000000.00', (string) $loan->principal);
        $this->assertEquals('9000000.00', (string) $loan->admin_fee, 'Admin 2% × 450jt = 9.000.000.');
        $this->assertEquals('13500000.00', (string) $loan->provision_fee, 'Provisi 3% × 450jt = 13.500.000.');
        $this->assertEquals('4500000.00', (string) $loan->insurance_fee, 'Asuransi 1% × 450jt = 4.500.000.');
        $this->assertEquals('2000000.00', (string) $loan->notary_fee, 'Notaris custom dari input pengajuan.');
        $this->assertEquals('100000.00', (string) $loan->principal_savings, 'Simpanan pokok Rp100.000.');
        $this->assertEquals('900000.00', (string) $loan->mandatory_savings, 'Rp25.000 × 36 = 900.000.');
        $this->assertEquals('30000000.00', (string) $loan->total_deduction, 'Total potongan Rp30.000.000.');
        $this->assertEquals('420000000.00', (string) $loan->net_disbursement, 'Dana bersih Rp420.000.000.');
    }

    public function test_reconciliation_invariants_hold_on_persisted_snapshot(): void
    {
        $application = $this->approvedApplication();
        $this->disburse($application)->assertSessionHasNoErrors();

        $loan = ShmLoan::query()->sole();

        $admin = (float) $loan->admin_fee;
        $provision = (float) $loan->provision_fee;
        $insurance = (float) $loan->insurance_fee;
        $notary = (float) $loan->notary_fee;
        $principalSavings = (float) $loan->principal_savings;
        $mandatorySavings = (float) $loan->mandatory_savings;
        $total = (float) $loan->total_deduction;
        $net = (float) $loan->net_disbursement;
        $principal = (float) $loan->principal;

        // 1. total = seluruh komponen.
        $this->assertEqualsWithDelta(
            $admin + $provision + $insurance + $notary + $principalSavings + $mandatorySavings,
            $total,
            0.001,
        );

        // 2. net = principal - total.
        $this->assertEqualsWithDelta($principal - $total, $net, 0.001);

        // 3–5. batas non-negatif.
        $this->assertGreaterThan(0, $principal);
        $this->assertGreaterThanOrEqual(0, $total);
        $this->assertGreaterThanOrEqual(0, $net);

        // 7–11. formula persentase & simpanan.
        $this->assertEqualsWithDelta($principal * 0.02, $admin, 0.001);
        $this->assertEqualsWithDelta($principal * 0.03, $provision, 0.001);
        $this->assertEqualsWithDelta($principal * 0.01, $insurance, 0.001);
        $this->assertEqualsWithDelta(100000, $principalSavings, 0.001);
        $this->assertEqualsWithDelta(25000 * 36, $mandatorySavings, 0.001);
    }

    public function test_principal_is_preserved_and_net_disbursement_is_not_principal(): void
    {
        $application = $this->approvedApplication();
        $this->disburse($application)->assertSessionHasNoErrors();

        $loan = ShmLoan::query()->sole();

        // 6. Principal tidak berubah karena deduction.
        $this->assertEquals('450000000.00', (string) $loan->principal);

        // 10. Net disbursement BUKAN principal (420jt ≠ 450jt).
        $this->assertEquals('420000000.00', (string) $loan->net_disbursement);
        $this->assertNotEquals((string) $loan->net_disbursement, (string) $loan->principal);
    }

    // -----------------------------------------------------------------
    // B. NOTARY FEE
    // -----------------------------------------------------------------

    public function test_negative_notary_fee_is_rejected_on_store(): void
    {
        $customer = ShmCustomer::factory()->create();

        $this->storeAs(
            $this->marketing,
            $this->validPayload($customer->id, ['persetujuan' => ['notary_fee' => '-1000']])
        )->assertSessionHasErrors('persetujuan.notary_fee');

        $this->assertSame(0, ShmLoanApplication::query()->count());
    }

    public function test_zero_notary_fee_is_allowed(): void
    {
        $application = $this->approvedApplication(['persetujuan' => ['notary_fee' => '0']]);

        $this->disburse($application)->assertSessionHasNoErrors();

        $loan = ShmLoan::query()->sole();

        $this->assertEquals('0.00', (string) $loan->notary_fee);
        $this->assertEquals('28000000.00', (string) $loan->total_deduction, 'Tanpa notaris: 9jt+13,5jt+4,5jt+100rb+900rb.');
        $this->assertEquals('422000000.00', (string) $loan->net_disbursement);
    }

    // -----------------------------------------------------------------
    // C. BACKEND AUTHORITATIVENESS
    // -----------------------------------------------------------------

    public function test_frontend_cannot_override_total_deduction_or_net_disbursement(): void
    {
        $customer = ShmCustomer::factory()->create();

        // Kunci derived dikirim paksa oleh klien — harus DIABAIKAN.
        $payload = $this->validPayload($customer->id);
        $payload['total_deduction'] = '1';
        $payload['net_disbursement'] = '999999999';
        $payload['pengajuan']['total_deduction'] = '1';
        $payload['pengajuan']['net_disbursement'] = '999999999';
        $payload['persetujuan']['total_deduction'] = '1';
        $payload['persetujuan']['net_disbursement'] = '999999999';
        // Komponen derived juga tidak boleh dipercaya dari klien.
        $payload['persetujuan']['administration_fee'] = '777777777';

        $this->storeAs($this->marketing, $payload)->assertSessionHasNoErrors();

        $application = ShmLoanApplication::query()->latest('id')->firstOrFail();
        $this->submit($application);
        $this->decide($application, 'approve');

        $this->disburse($application->refresh())->assertSessionHasNoErrors();

        $loan = ShmLoan::query()->sole();

        $this->assertEquals('30000000.00', (string) $loan->total_deduction, 'Backend menghitung ulang, bukan payload.');
        $this->assertEquals('420000000.00', (string) $loan->net_disbursement);
        $this->assertEquals('9000000.00', (string) $loan->admin_fee);
    }

    // -----------------------------------------------------------------
    // D. NEGATIVE NET DISBURSEMENT
    // -----------------------------------------------------------------

    public function test_negative_net_disbursement_is_rejected(): void
    {
        // total deduction > principal → dana bersih negatif.
        $application = $this->approvedApplication([
            'pengajuan' => ['principal' => '1000000', 'tenor_months' => 12],
            'analisa' => ['value' => '1000000'],
            'persetujuan' => ['approved_amount' => '1000000', 'notary_fee' => '600000'],
        ]);

        $this->disburse($application)->assertSessionHasErrors('application');

        $this->assertSame(0, ShmLoan::query()->count(), 'Pinjaman aktif dengan net negatif tidak boleh dibuat.');
        $this->assertSame(0, $this->disburseAuditCount());
    }

    // -----------------------------------------------------------------
    // E. IDEMPOTENCY & AUDIT
    // -----------------------------------------------------------------

    public function test_duplicate_disbursement_creates_no_second_snapshot_or_audit(): void
    {
        $application = $this->approvedApplication();

        $this->disburse($application)->assertSessionHasNoErrors();
        $this->disburse($application)->assertSessionHasErrors('application');

        $this->assertSame(1, ShmLoan::query()->count());
        $this->assertSame(1, $this->disburseAuditCount());
    }

    public function test_audit_remains_exactly_once_and_carries_financial_result(): void
    {
        $application = $this->approvedApplication();

        $this->disburse($application)->assertSessionHasNoErrors();

        $logs = AuditLog::query()->where('action', self::AUDIT_ACTION)->get();

        $this->assertCount(1, $logs, 'Tepat SATU audit pencairan.');
        $this->assertSame('30000000.00', data_get($logs->first()->changes, 'total_deduction'));
        $this->assertSame('420000000.00', data_get($logs->first()->changes, 'net_disbursement'));
    }

    // -----------------------------------------------------------------
    // F. ATOMICITY
    // -----------------------------------------------------------------

    public function test_failed_disbursement_rolls_back_financial_snapshot(): void
    {
        $application = $this->approvedApplication();

        $this->withoutExceptionHandling();

        ShmLoan::creating(function (): void {
            throw new RuntimeException('Loan creation gagal (uji T85).');
        });

        try {
            $this->disburse($application);
            $this->fail('Exception pembuatan loan seharusnya muncul.');
        } catch (RuntimeException) {
            // Diharapkan — transaksi rollback penuh.
        }

        $this->assertSame(0, ShmLoan::query()->count(), 'Snapshot finansial harus ikut rollback.');
        $this->assertSame(0, $this->disburseAuditCount());
    }

    // -----------------------------------------------------------------
    // G. IMMUTABILITY AFTER DISBURSEMENT
    // -----------------------------------------------------------------

    public function test_historical_loan_keeps_snapshot_after_rate_tier_change(): void
    {
        $application = $this->approvedApplication();
        $this->disburse($application)->assertSessionHasNoErrors();

        $loan = ShmLoan::query()->sole();
        $before = [
            (string) $loan->rate_percent,
            (string) $loan->admin_fee,
            (string) $loan->total_deduction,
            (string) $loan->net_disbursement,
        ];

        // Perubahan business rule / tier produk TIDAK boleh mengubah histori.
        $loan->product->rateTiers()->update(['rate_percent' => 9.99]);
        $loan->product->update(['name' => 'Produk Ganti Nama']);

        $loan->refresh();

        $this->assertSame($before, [
            (string) $loan->rate_percent,
            (string) $loan->admin_fee,
            (string) $loan->total_deduction,
            (string) $loan->net_disbursement,
        ], 'Snapshot finansial & rate loan aktif adalah historical, bukan dihitung ulang.');
    }

    // -----------------------------------------------------------------
    // H. RATE / FIRST DUE DATE CONTRACT (T82/T83 dipertahankan)
    // -----------------------------------------------------------------

    public function test_rate_stays_tier_sourced_monthly_flat_with_first_due_date(): void
    {
        $application = $this->approvedApplication();
        $this->disburse($application)->assertSessionHasNoErrors();

        $loan = ShmLoan::query()->sole();

        // Tenor 36 jatuh di tier 36–42 → 1.50% (bukan universal 2%).
        $this->assertEquals('1.5000', (string) $loan->rate_percent);
        $this->assertSame('monthly', $loan->rate_period);
        $this->assertSame('flat', $loan->calculation_method);
        $this->assertSame(
            $application->first_due_date->toDateString(),
            $loan->first_due_date->toDateString(),
        );
    }

    // -----------------------------------------------------------------
    // I. COST SERVICE UNIT GUARDS
    // -----------------------------------------------------------------

    public function test_cost_service_rejects_invalid_inputs(): void
    {
        $service = app(ShmDisbursementCostService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->calculate(-1, 12, 0);
    }

    public function test_cost_service_rejects_negative_notary_fee(): void
    {
        $service = app(ShmDisbursementCostService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->calculate(450000000, 36, -1);
    }

    // -----------------------------------------------------------------
    // J. LEGACY ROW SAFETY
    // -----------------------------------------------------------------

    public function test_legacy_loan_without_cost_snapshot_is_nullable_safe(): void
    {
        $customer = ShmCustomer::factory()->create();
        $product = ShmLoanProduct::factory()->create();

        // Pinjaman historis dibuat di luar alur pencairan (tanpa snapshot).
        $loan = ShmLoan::create([
            'loan_number' => 'SHM-PIN-LEGACY-0001',
            'shm_customer_id' => $customer->id,
            'shm_loan_product_id' => $product->id,
            'principal' => '100000000',
            'tenor_months' => 12,
            'status' => ShmLoan::STATUS_ACTIVE,
        ]);

        $this->assertNull($loan->admin_fee);
        $this->assertNull($loan->total_deduction);
        $this->assertNull($loan->net_disbursement);
        $this->assertEquals('100000000.00', (string) $loan->principal);
    }
}
