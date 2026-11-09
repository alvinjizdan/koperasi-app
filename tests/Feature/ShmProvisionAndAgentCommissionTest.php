<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShmAgent;
use App\Models\ShmApplication;
use App\Models\ShmApproval;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Models\ShmLoanProductRateTier;
use App\Models\User;
use App\Services\Shm\ShmDisbursementCostService;
use App\Services\Shm\ShmLoanDisbursementService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithShmDisbursement;
use Tests\TestCase;

class ShmProvisionAndAgentCommissionTest extends TestCase
{
    use InteractsWithShmDisbursement;
    use RefreshDatabase;

    private User $admin;

    private ShmLoanProduct $product;

    private ShmCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id);
        }

        $this->product = ShmLoanProduct::query()->firstOrCreate(
            ['code' => 'KPR-01'],
            [
                'name' => 'KPR SHM',
                'category' => ShmLoanProduct::CATEGORY_PERUMAHAN,
                'is_active' => true,
            ]
        );

        ShmLoanProductRateTier::firstOrCreate(
            [
                'shm_loan_product_id' => $this->product->id,
                'min_tenor_months' => 12,
                'max_tenor_months' => 60,
            ],
            [
                'rate_percent' => 1.5,
                'rate_period' => 'monthly',
                'calculation_method' => 'flat',
            ]
        );

        $this->seedShmSavingsProducts();
        $this->openCashierSessionFor($this->admin);
        $this->customer = $this->createShmCustomerWithMember();
    }

    /**
     * Test Case A: Loan with Agent
     * Principal: Rp100.000.000
     * Expected: Total Provisi = 2M, Bagian Kantor = 1M, Komisi Agen = 1M.
     */
    public function test_provision_and_commission_calculation_with_agent(): void
    {
        $costService = app(ShmDisbursementCostService::class);
        $breakdown = $costService->calculate(100_000_000, 12, 0, true);

        $this->assertSame(2_000_000.0, $breakdown['provision_fee']);
        $this->assertSame(1_000_000.0, $breakdown['office_provision_fee']);
        $this->assertSame(1_000_000.0, $breakdown['agent_commission_fee']);
        $this->assertSame(
            $breakdown['provision_fee'],
            $breakdown['office_provision_fee'] + $breakdown['agent_commission_fee'],
            'Invariant holds: total_provision = office_provision + agent_commission'
        );
    }

    /**
     * Test Case B: Loan without Agent
     * Principal: Rp100.000.000
     * Expected: Total Provisi = 2M, Bagian Kantor = 2M, Komisi Agen = 0.
     */
    public function test_provision_and_commission_calculation_without_agent(): void
    {
        $costService = app(ShmDisbursementCostService::class);
        $breakdown = $costService->calculate(100_000_000, 12, 0, false);

        $this->assertSame(2_000_000.0, $breakdown['provision_fee']);
        $this->assertSame(2_000_000.0, $breakdown['office_provision_fee']);
        $this->assertSame(0.0, $breakdown['agent_commission_fee']);
        $this->assertSame(
            $breakdown['provision_fee'],
            $breakdown['office_provision_fee'] + $breakdown['agent_commission_fee'],
            'Invariant holds: total_provision = office_provision + agent_commission'
        );
    }

    /**
     * Test Case C: Snapshot and Invariance across Master Agent Lifecycle
     */
    public function test_agent_and_commission_are_snapshotted_to_loan_and_immutable(): void
    {
        $agent = ShmAgent::create([
            'code' => 'AG-TEST-001',
            'name' => 'Agen Khusus Provisi',
            'nik' => '3201999988880001',
            'is_active' => true,
        ]);

        $mirror = ShmApplication::create([
            'application_number' => 'APP-MIRROR-'.Str::upper(Str::random(6)),
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => $this->admin->id,
            'status' => 'approved',
        ]);

        ShmApproval::create([
            'shm_application_id' => $mirror->id,
            'decided_by' => $this->admin->id,
            'decided_at' => now(),
            'status' => ShmApproval::STATUS_APPROVED,
            'notes' => 'Disetujui untuk uji provisi',
        ]);

        $application = ShmLoanApplication::create([
            'application_number' => 'PENGAJ-TEST-AG-01',
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $this->product->id,
            'shm_application_id' => $mirror->id,
            'agent_id' => $agent->id,
            'marketing_id' => $this->admin->id,
            'principal' => 100_000_000,
            'approved_amount' => 100_000_000,
            'tenor_months' => 12,
            'status' => 'approved',
            'application_date' => now()->toDateString(),
            'first_due_date' => now()->addMonth()->toDateString(),
            'purpose' => 'Modal Usaha',
            'notary_fee' => 0,
        ]);

        $disbursementService = app(ShmLoanDisbursementService::class);
        $loan = $disbursementService->disburse($application, $this->admin->id);

        $this->assertNotNull($loan->id);
        $this->assertSame($agent->id, $loan->agent_id);
        $this->assertEquals('2000000.00', (string) $loan->provision_fee);
        $this->assertEquals('1000000.00', (string) $loan->office_provision_fee);
        $this->assertEquals('1000000.00', (string) $loan->agent_commission_fee);

        // Ubah data master agent (misal nama, NIK, atau nonaktifkan)
        $agent->update([
            'name' => 'Nama Agen Berubah Total',
            'nik' => '3201999988880099',
            'is_active' => false,
        ]);

        $refreshedLoan = $loan->fresh();
        $this->assertSame($agent->id, $refreshedLoan->agent_id);
        $this->assertEquals('2000000.00', (string) $refreshedLoan->provision_fee);
        $this->assertEquals('1000000.00', (string) $refreshedLoan->office_provision_fee);
        $this->assertEquals('1000000.00', (string) $refreshedLoan->agent_commission_fee);
        $this->assertSame('Nama Agen Berubah Total', $refreshedLoan->agent->name);
    }

    /**
     * Test Case D: Disbursement without Agent keeps total provision 2%
     * (office 2%, agent commission 0%) and propagates null agent_id.
     */
    public function test_loan_without_agent_is_disbursed_with_full_office_provision(): void
    {
        $mirror = ShmApplication::create([
            'application_number' => 'APP-MIRROR-'.Str::upper(Str::random(6)),
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => $this->admin->id,
            'status' => 'approved',
        ]);

        ShmApproval::create([
            'shm_application_id' => $mirror->id,
            'decided_by' => $this->admin->id,
            'decided_at' => now(),
            'status' => ShmApproval::STATUS_APPROVED,
            'notes' => 'Disetujui untuk uji provisi tanpa agen',
        ]);

        $application = ShmLoanApplication::create([
            'application_number' => 'PENGAJ-TEST-NO-AG-01',
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $this->product->id,
            'shm_application_id' => $mirror->id,
            'agent_id' => null,
            'marketing_id' => $this->admin->id,
            'principal' => 100_000_000,
            'approved_amount' => 100_000_000,
            'tenor_months' => 12,
            'status' => 'approved',
            'application_date' => now()->toDateString(),
            'first_due_date' => now()->addMonth()->toDateString(),
            'purpose' => 'Modal Usaha',
            'notary_fee' => 0,
        ]);

        $loan = app(ShmLoanDisbursementService::class)->disburse($application, $this->admin->id);

        $this->assertNull($loan->agent_id);
        $this->assertEquals('2000000.00', (string) $loan->provision_fee);
        $this->assertEquals('2000000.00', (string) $loan->office_provision_fee);
        $this->assertEquals('0.00', (string) $loan->agent_commission_fee);
    }

    /**
     * Test Case E: Historical Loan 3% is NOT modified by migration or new code
     */
    public function test_historical_loan_provision_fee_is_preserved(): void
    {
        // Simulasikan loan historis yang dibuat saat rule 3%
        $historicalLoan = ShmLoan::create([
            'loan_number' => 'SHM-PIN-HISTORICAL-3PCT',
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $this->product->id,
            'principal' => 100_000_000,
            'tenor_months' => 12,
            'admin_fee' => 2_000_000,
            'provision_fee' => 3_000_000, // 3%
            'insurance_fee' => 1_000_000,
            'notary_fee' => 0,
            'principal_savings' => 100_000,
            'mandatory_savings' => 300_000,
            'total_deduction' => 6_400_000,
            'net_disbursement' => 93_600_000,
            'rate_percent' => 2.0,
            'rate_period' => 'monthly',
            'calculation_method' => 'flat',
            'first_due_date' => now()->addMonth()->toDateString(),
            'status' => ShmLoan::STATUS_ACTIVE,
            'disbursed_at' => now()->subMonths(2)->toDateString(),
        ]);

        $fetched = ShmLoan::findOrFail($historicalLoan->id);
        $this->assertEquals('3000000.00', (string) $fetched->provision_fee);
        $this->assertNull($fetched->office_provision_fee);
        $this->assertNull($fetched->agent_commission_fee);
    }
}
