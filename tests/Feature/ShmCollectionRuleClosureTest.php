<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmApproval;
use App\Models\ShmCollectionCase;
use App\Models\ShmCollectionPtp;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanProduct;
use App\Models\User;
use App\Services\Shm\ShmCollectionCaseService;
use App\Services\Shm\ShmCollectionPtpService;
use App\Services\Shm\ShmCollectionQueryService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithShmDisbursement;
use Tests\TestCase;

class ShmCollectionRuleClosureTest extends TestCase
{
    use InteractsWithShmDisbursement;
    use RefreshDatabase;

    private User $marketing;

    private User $otherMarketing;

    private User $branchManager;

    private ShmLoanProduct $product;

    private ShmCustomer $customer;

    private ShmCollectionCaseService $caseService;

    private ShmCollectionPtpService $ptpService;

    private ShmCollectionQueryService $queryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->caseService = app(ShmCollectionCaseService::class);
        $this->ptpService = app(ShmCollectionPtpService::class);
        $this->queryService = app(ShmCollectionQueryService::class);

        // Marketing user
        $this->marketing = User::factory()->create();
        $marketingRole = Role::where('name', 'marketing')->first();
        if ($marketingRole) {
            $this->marketing->roles()->attach($marketingRole->id);
        }

        // Another Marketing user
        $this->otherMarketing = User::factory()->create();
        if ($marketingRole) {
            $this->otherMarketing->roles()->attach($marketingRole->id);
        }

        // Branch Manager user
        $this->branchManager = User::factory()->create();
        $bmRole = Role::where('name', 'branch_manager')->first();
        if ($bmRole) {
            $this->branchManager->roles()->attach($bmRole->id);
        }

        $this->seedShmSavingsProducts();
        $this->openCashierSessionFor($this->marketing);

        $this->customer = $this->createShmCustomerWithMember();

        $this->product = ShmLoanProduct::firstOrCreate(
            ['code' => 'PJS'],
            ['name' => 'Pinjaman Siaga SHM', 'category' => ShmLoanProduct::CATEGORY_PERUMAHAN, 'is_active' => true]
        );
        $this->product->rateTiers()->delete();
        $this->product->rateTiers()->create([
            'min_tenor_months' => 12,
            'max_tenor_months' => 18,
            'rate_percent' => 1.30,
            'rate_period' => 'monthly',
            'calculation_method' => 'flat',
        ]);
    }

    private function createLoanWithOverdueDays(int $overdueDays, ?User $marketingUser = null): array
    {
        $owner = $marketingUser ?? $this->marketing;

        $mirror = ShmApplication::create([
            'application_number' => 'APP-T007-'.Str::upper(Str::random(6)),
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => $owner->id,
            'status' => 'approved',
        ]);

        ShmApproval::create([
            'shm_application_id' => $mirror->id,
            'decided_by' => $owner->id,
            'decided_at' => now(),
            'status' => ShmApproval::STATUS_APPROVED,
            'notes' => 'Disetujui untuk uji collection rule closure',
        ]);

        $loanApp = ShmLoanApplication::create([
            'application_number' => 'PENGAJ-T007-'.Str::upper(Str::random(8)),
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $this->product->id,
            'shm_application_id' => $mirror->id,
            'application_date' => now()->toDateString(),
            'principal' => 12000000,
            'approved_amount' => 12000000,
            'tenor_months' => 12,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $owner->id,
            'status' => 'approved',
        ]);

        $disbursedAt = now()->subDays($overdueDays + 30)->toDateString();
        $dueDate = now()->subDays($overdueDays)->toDateString();

        $loan = ShmLoan::create([
            'loan_number' => 'SHM-LOAN-'.Str::upper(Str::random(6)),
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $this->product->id,
            'shm_loan_application_id' => $loanApp->id,
            'principal' => 12000000,
            'tenor_months' => 12,
            'rate_percent' => 1.30,
            'rate_period' => 'monthly',
            'calculation_method' => 'flat',
            'disbursed_at' => $disbursedAt,
            'first_due_date' => $dueDate,
            'status' => ShmLoan::STATUS_ACTIVE,
        ]);

        $installment = ShmLoanInstallment::create([
            'shm_loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => $dueDate,
            'principal_amount' => 1000000,
            'interest_amount' => 200000,
            'total_amount' => 1200000,
            'paid_principal_amount' => 0,
            'paid_interest_amount' => 0,
            'remaining_amount' => 1200000,
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        return [$loan, $installment, $owner];
    }

    /**
     * Rule 3.1: Collection Ownership by Marketing (No dedicated Collector role)
     */
    public function test_collection_ownership_is_marketing_and_rejects_unowned_action(): void
    {
        [$loan, $installment, $owner] = $this->createLoanWithOverdueDays(10, $this->marketing);

        // Owner creates case
        $case = $this->caseService->createOrGetForLoan($loan, $this->marketing->id);
        $this->assertSame($this->marketing->id, $case->marketing_id);

        // Another marketing cannot transition or create PTP on this case
        $this->expectException(ValidationException::class);
        $this->caseService->transition($case, ShmCollectionCase::STATUS_IN_PROGRESS, $this->otherMarketing->id);
    }

    /**
     * Rule 3.2: Lifecycle transitions
     * OPEN -> IN_PROGRESS -> PROMISE_TO_PAY -> ESCALATED -> RESOLVED
     */
    public function test_collection_lifecycle_transitions_and_rejects_invalid(): void
    {
        [$loan, $installment, $owner] = $this->createLoanWithOverdueDays(10, $this->marketing);
        $case = $this->caseService->createOrGetForLoan($loan, $this->marketing->id);
        $this->assertSame(ShmCollectionCase::STATUS_OPEN, $case->status);

        // Invalid: OPEN -> PROMISE_TO_PAY (must be IN_PROGRESS first)
        try {
            $this->caseService->transition($case, ShmCollectionCase::STATUS_PROMISE_TO_PAY, $this->marketing->id);
            $this->fail('Should reject invalid transition OPEN -> PROMISE_TO_PAY');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('collection.status', $e->errors());
        }

        // OPEN -> IN_PROGRESS
        $case = $this->caseService->transition($case, ShmCollectionCase::STATUS_IN_PROGRESS, $this->marketing->id);
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->status);

        // IN_PROGRESS -> PROMISE_TO_PAY
        $case = $this->caseService->transition($case, ShmCollectionCase::STATUS_PROMISE_TO_PAY, $this->marketing->id);
        $this->assertSame(ShmCollectionCase::STATUS_PROMISE_TO_PAY, $case->status);

        // PROMISE_TO_PAY -> ESCALATED
        $case = $this->caseService->transition($case, ShmCollectionCase::STATUS_ESCALATED, $this->marketing->id);
        $this->assertSame(ShmCollectionCase::STATUS_ESCALATED, $case->status);

        // ESCALATED -> IN_PROGRESS
        $case = $this->caseService->transition($case, ShmCollectionCase::STATUS_IN_PROGRESS, $this->marketing->id);
        $this->assertSame(ShmCollectionCase::STATUS_IN_PROGRESS, $case->status);
    }

    /**
     * Rule 3.3: Aging calculation and exact boundary test
     * 1-7, 8-30, 31-60, 61-90, >90
     */
    public function test_aging_buckets_boundary_evaluation(): void
    {
        $bucketsContract = config('shm-business-rule-contract.collection_workflow.aging.buckets');

        $this->assertCount(5, $bucketsContract);
        $this->assertSame(1, $bucketsContract[0]['min_days']);
        $this->assertSame(7, $bucketsContract[0]['max_days']);

        $this->assertSame(8, $bucketsContract[1]['min_days']);
        $this->assertSame(30, $bucketsContract[1]['max_days']);

        $this->assertSame(31, $bucketsContract[2]['min_days']);
        $this->assertSame(60, $bucketsContract[2]['max_days']);

        $this->assertSame(61, $bucketsContract[3]['min_days']);
        $this->assertSame(90, $bucketsContract[3]['max_days']);

        $this->assertSame(91, $bucketsContract[4]['min_days']);
        $this->assertNull($bucketsContract[4]['max_days']);

        $classifyDays = function (int $days) use ($bucketsContract): string {
            foreach ($bucketsContract as $bucket) {
                if ($days >= $bucket['min_days'] && ($bucket['max_days'] === null || $days <= $bucket['max_days'])) {
                    return $bucket['label'];
                }
            }

            return 'none';
        };

        // Test boundary values
        $this->assertSame('1-7 hari', $classifyDays(1));
        $this->assertSame('1-7 hari', $classifyDays(7));
        $this->assertSame('8-30 hari', $classifyDays(8));
        $this->assertSame('8-30 hari', $classifyDays(30));
        $this->assertSame('31-60 hari', $classifyDays(31));
        $this->assertSame('31-60 hari', $classifyDays(60));
        $this->assertSame('61-90 hari', $classifyDays(61));
        $this->assertSame('61-90 hari', $classifyDays(90));
        $this->assertSame('>90 hari', $classifyDays(91));
        $this->assertSame('>90 hari', $classifyDays(150));
        $this->assertSame('none', $classifyDays(0));
    }

    /**
     * Rule 3.4: PTP Eligibility overdue >= 7 days
     */
    public function test_ptp_eligibility_boundary_at_seven_days(): void
    {
        // 6 days overdue: Not eligible
        [$loan6] = $this->createLoanWithOverdueDays(6, $this->marketing);
        $case6 = $this->caseService->createOrGetForLoan($loan6, $this->marketing->id);
        $this->caseService->transition($case6, ShmCollectionCase::STATUS_IN_PROGRESS, $this->marketing->id);
        $this->caseService->transition($case6, ShmCollectionCase::STATUS_PROMISE_TO_PAY, $this->marketing->id);

        $eligibility6 = $this->ptpService->eligibility($case6);
        $this->assertFalse($eligibility6['eligible']);

        try {
            $this->ptpService->create($case6, $this->marketing->id, [
                'promised_date' => now()->addDays(3)->toDateString(),
                'promised_amount' => 500000,
            ]);
            $this->fail('Should reject PTP creation when overdue is only 6 days');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('collection.ptp.kelayakan', $e->errors());
        }

        // 7 days overdue: Eligible
        [$loan7] = $this->createLoanWithOverdueDays(7, $this->marketing);
        $case7 = $this->caseService->createOrGetForLoan($loan7, $this->marketing->id);
        $this->caseService->transition($case7, ShmCollectionCase::STATUS_IN_PROGRESS, $this->marketing->id);
        $this->caseService->transition($case7, ShmCollectionCase::STATUS_PROMISE_TO_PAY, $this->marketing->id);

        $eligibility7 = $this->ptpService->eligibility($case7);
        $this->assertTrue($eligibility7['eligible']);

        $ptp7 = $this->ptpService->create($case7, $this->marketing->id, [
            'promised_date' => now()->addDays(3)->toDateString(),
            'promised_amount' => 500000,
        ]);
        $this->assertNotNull($ptp7->id);
        $this->assertSame(ShmCollectionPtp::STATUS_OPEN, $ptp7->status);
    }

    /**
     * Rule 3.5: Maximum 3 PTP per anchored monthly period
     */
    public function test_maximum_three_ptp_per_anchored_period(): void
    {
        [$loan] = $this->createLoanWithOverdueDays(15, $this->marketing);
        $case = $this->caseService->createOrGetForLoan($loan, $this->marketing->id);
        $this->caseService->transition($case, ShmCollectionCase::STATUS_IN_PROGRESS, $this->marketing->id);
        $this->caseService->transition($case, ShmCollectionCase::STATUS_PROMISE_TO_PAY, $this->marketing->id);

        // PTP 1
        $ptp1 = $this->ptpService->create($case, $this->marketing->id, [
            'promised_date' => now()->addDays(2)->toDateString(),
            'promised_amount' => 400000,
        ]);
        $this->ptpService->transition($ptp1, ShmCollectionPtp::STATUS_MISSED, $this->marketing->id);

        // PTP 2
        $ptp2 = $this->ptpService->create($case, $this->marketing->id, [
            'promised_date' => now()->addDays(4)->toDateString(),
            'promised_amount' => 400000,
        ]);
        $this->ptpService->transition($ptp2, ShmCollectionPtp::STATUS_MISSED, $this->marketing->id);

        // PTP 3
        $ptp3 = $this->ptpService->create($case, $this->marketing->id, [
            'promised_date' => now()->addDays(6)->toDateString(),
            'promised_amount' => 400000,
        ]);
        $this->ptpService->transition($ptp3, ShmCollectionPtp::STATUS_MISSED, $this->marketing->id);

        // 4th PTP in same anchored period MUST be rejected
        $quota = $this->ptpService->quotaState($case);
        $this->assertSame(3, $quota['used']);
        $this->assertSame(3, $quota['limit']);
        $this->assertFalse($quota['available']);

        $this->expectException(ValidationException::class);
        $this->ptpService->create($case, $this->marketing->id, [
            'promised_date' => now()->addDays(8)->toDateString(),
            'promised_amount' => 400000,
        ]);
    }

    /**
     * Rule 3.6: Scheduler target is 00:05
     */
    public function test_scheduler_is_registered_at_zero_zero_five(): void
    {
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $ptpEvent = $events->first(function ($event) {
            return str_contains($event->command, 'collection:ptp-missed');
        });

        $this->assertNotNull($ptpEvent, 'Command collection:ptp-missed must be scheduled');
        $this->assertSame('5 0 * * *', $ptpEvent->expression, 'Must be scheduled at 00:05 (5 0 * * *)');
    }

    /**
     * Rule 3.7: 3 MISSED -> ESCALATED, and No automatic FULFILLED
     */
    public function test_three_missed_ptp_escalates_case_via_scheduler(): void
    {
        [$loan] = $this->createLoanWithOverdueDays(20, $this->marketing);
        $case = $this->caseService->createOrGetForLoan($loan, $this->marketing->id);
        $this->caseService->transition($case, ShmCollectionCase::STATUS_IN_PROGRESS, $this->marketing->id);
        $this->caseService->transition($case, ShmCollectionCase::STATUS_PROMISE_TO_PAY, $this->marketing->id);

        // Create 2 MISSED PTPs
        $ptp1 = $this->ptpService->create($case, $this->marketing->id, [
            'promised_date' => now()->subDays(5)->toDateString(),
            'promised_amount' => 300000,
        ]);
        $this->ptpService->transition($ptp1, ShmCollectionPtp::STATUS_MISSED, $this->marketing->id);

        $ptp2 = $this->ptpService->create($case, $this->marketing->id, [
            'promised_date' => now()->subDays(3)->toDateString(),
            'promised_amount' => 300000,
        ]);
        $this->ptpService->transition($ptp2, ShmCollectionPtp::STATUS_MISSED, $this->marketing->id);

        // Case is still PROMISE_TO_PAY after 2 MISSED
        $this->assertSame(ShmCollectionCase::STATUS_PROMISE_TO_PAY, $case->fresh()->status);

        // Create 3rd PTP that is OPEN with promised_date in the past
        $ptp3 = $this->ptpService->create($case, $this->marketing->id, [
            'promised_date' => now()->subDay()->toDateString(),
            'promised_amount' => 300000,
        ]);
        $this->assertSame(ShmCollectionPtp::STATUS_OPEN, $ptp3->status);

        // Run scheduler
        $exitCode = Artisan::call('collection:ptp-missed');
        $this->assertSame(0, $exitCode);

        // PTP 3 transitioned to MISSED
        $this->assertSame(ShmCollectionPtp::STATUS_MISSED, $ptp3->fresh()->status);

        // Case escalated automatically because 3 MISSED reached
        $this->assertSame(ShmCollectionCase::STATUS_ESCALATED, $case->fresh()->status);
    }

    /**
     * Rule 3.8: Resolution requires remaining overdue = 0
     */
    public function test_resolution_guard_enforces_zero_remaining_overdue(): void
    {
        [$loan, $installment] = $this->createLoanWithOverdueDays(10, $this->marketing);
        $case = $this->caseService->createOrGetForLoan($loan, $this->marketing->id);
        $this->caseService->transition($case, ShmCollectionCase::STATUS_IN_PROGRESS, $this->marketing->id);

        // Attempting to resolve when overdue remaining > 0 MUST fail
        try {
            $this->caseService->transition($case, ShmCollectionCase::STATUS_RESOLVED, $this->marketing->id);
            $this->fail('Should reject RESOLVED transition when remaining overdue is > 0');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('collection.resolusi', $e->errors());
        }

        // Simulate canonical payment completing the installment
        $installment->update([
            'paid_principal_amount' => 1000000,
            'paid_interest_amount' => 200000,
            'remaining_amount' => 0,
            'status' => ShmLoanInstallment::STATUS_PAID,
        ]);

        // Now resolution succeeds
        $case = $this->caseService->transition($case, ShmCollectionCase::STATUS_RESOLVED, $this->marketing->id);
        $this->assertSame(ShmCollectionCase::STATUS_RESOLVED, $case->status);
        $this->assertNotNull($case->resolved_at);
        $this->assertFalse($case->isActive());
    }
}
