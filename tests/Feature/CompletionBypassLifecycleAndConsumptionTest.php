<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCreditCheck;
use App\Models\ShmCreditCheckException;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\User;
use App\Services\Shm\ShmCreditCheckExceptionService;
use App\Services\Shm\ShmMemorandumApprovalService;
use Carbon\Carbon;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\MakroSystemRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompletionBypassLifecycleAndConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;

    private User $branchManager;

    private User $direkturUtama;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(MakroPhysicalRoleSeeder::class);
        $this->seed(MakroSystemRoleSeeder::class);

        $marketingRole = Role::where('name', 'marketing_makro')->first() ?? Role::where('name', 'marketing')->firstOrFail();
        $bmRole = Role::where('name', 'branch_manager_makro')->first() ?? Role::where('name', 'branch_manager')->firstOrFail();
        $dirutRole = Role::where('name', 'direktur_utama_makro')->first() ?? Role::where('name', 'direktur_utama')->firstOrFail();
        $superadminRole = Role::where('name', 'superadmin')->firstOrFail();

        $this->marketing = User::factory()->create(['name' => 'Marketing Officer']);
        $this->marketing->roles()->attach($marketingRole);

        $this->branchManager = User::factory()->create(['name' => 'Branch Manager']);
        $this->branchManager->roles()->attach($bmRole);

        $this->direkturUtama = User::factory()->create(['name' => 'President Director']);
        $this->direkturUtama->roles()->attach($dirutRole);

        $this->superadmin = User::factory()->create(['name' => 'Superadmin Admin']);
        $this->superadmin->roles()->attach($superadminRole);
    }

    private function makeApplication(string $type = 'shm_shgb', string $ccStatus = 'drafted'): array
    {
        $customer = ShmCustomer::factory()->create();
        $app = ShmApplication::create([
            'application_number' => 'APP-EXP-'.uniqid(),
            'shm_customer_id' => $customer->id,
            'marketing_id' => $this->marketing->id,
            'type' => $type,
            'status' => 'memorandum',
        ]);

        $cc = ShmCreditCheck::create([
            'shm_application_id' => $app->id,
            'status' => $ccStatus,
            'checker_id' => $this->marketing->id,
        ]);

        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => $type === 'buyback' ? ShmMemorandum::TYPE_BUYBACK : ShmMemorandum::TYPE_SHM_SHGB,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'created_by' => $this->marketing->id,
        ]);

        return [$app, $cc, $memo];
    }

    // =========================================================================
    // SECTION 4: EXPIRY VERIFICATION (14 CALENDAR DAYS & BOUNDARY BEHAVIOR)
    // =========================================================================

    public function test_exp_01_and_02_newly_issued_exception_is_available_within_14_days(): void
    {
        [$app, $cc, $memo] = $this->makeApplication();
        $service = app(ShmCreditCheckExceptionService::class);

        $now = Carbon::create(2026, 11, 19, 10, 0, 0, 'UTC');
        Carbon::setTestNow($now);

        $exception = $service->authorizeException(
            $app,
            ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            $this->direkturUtama,
            'Justifikasi bisnis mendesak Direktur Utama untuk pembiayaan prioritas.',
            'Dispensasi Completion Bypass oleh Direktur Utama',
            'Credit checking drafted'
        );

        $this->assertTrue($exception->isAvailable());
        $this->assertSame(ShmCreditCheckException::STATUS_AUTHORIZED, $exception->status);
        $this->assertNull($exception->consumed_at);

        // Day 13 (within 14 days) -> Still available
        Carbon::setTestNow($now->copy()->addDays(13));
        $this->assertTrue($exception->fresh()->isAvailable());

        Carbon::setTestNow();
    }

    public function test_exp_03_boundary_behavior_exact_14_days(): void
    {
        [$app, $cc, $memo] = $this->makeApplication();
        $service = app(ShmCreditCheckExceptionService::class);

        $now = Carbon::create(2026, 11, 19, 10, 0, 0, 'UTC');
        Carbon::setTestNow($now);

        $exception = $service->authorizeException(
            $app,
            ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            $this->direkturUtama,
            'Justifikasi bisnis mendesak Direktur Utama untuk pembiayaan prioritas.',
            'Dispensasi Completion Bypass oleh Direktur Utama',
            'Credit checking drafted'
        );

        // At 14 days minus 1 second: Still available
        Carbon::setTestNow($now->copy()->addDays(14)->subSecond());
        $this->assertTrue($exception->fresh()->isAvailable(), 'Exception must be available at 13 days 23:59:59');

        // At 14 days plus 1 second: isPast() triggers, expired
        Carbon::setTestNow($now->copy()->addDays(14)->addSecond());
        $this->assertFalse($exception->fresh()->isAvailable(), 'Exception must expire when 14 days has passed');

        Carbon::setTestNow();
    }

    public function test_exp_04_and_05_expired_exception_cannot_be_consumed_and_submission_rejected(): void
    {
        [$app, $cc, $memo] = $this->makeApplication();
        $service = app(ShmCreditCheckExceptionService::class);

        $now = Carbon::create(2026, 11, 19, 10, 0, 0, 'UTC');
        Carbon::setTestNow($now);

        $exception = $service->authorizeException(
            $app,
            ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            $this->direkturUtama,
            'Justifikasi bisnis mendesak Direktur Utama untuk pembiayaan prioritas.',
            'Dispensasi Completion Bypass oleh Direktur Utama',
            'Credit checking drafted'
        );

        // Advance time to 15 days later (expired)
        Carbon::setTestNow($now->copy()->addDays(15));
        $this->assertFalse($exception->fresh()->isAvailable());

        // 1. Direct consumption attempt throws ValidationException
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Dispensasi {$exception->exception_number} sudah tidak tersedia atau telah dikonsumsi.");
        $service->consumeException($app, ShmCreditCheckException::TYPE_COMPLETION_BYPASS, $memo);

        Carbon::setTestNow();
    }

    public function test_exp_05b_expired_exception_blocks_memorandum_submission_end_to_end(): void
    {
        [$app, $cc, $memo] = $this->makeApplication();
        $service = app(ShmCreditCheckExceptionService::class);

        $now = Carbon::create(2026, 11, 19, 10, 0, 0, 'UTC');
        Carbon::setTestNow($now);

        $exception = $service->authorizeException(
            $app,
            ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            $this->direkturUtama,
            'Justifikasi bisnis mendesak Direktur Utama untuk pembiayaan prioritas.',
            'Dispensasi Completion Bypass oleh Direktur Utama',
            'Credit checking drafted'
        );

        // Advance time to 15 days later
        Carbon::setTestNow($now->copy()->addDays(15));

        $approvalService = app(ShmMemorandumApprovalService::class);

        // Submission must fail because the exception is expired
        $this->expectException(ValidationException::class);
        $approvalService->submit($memo, $this->marketing, 'Submit attempt with expired exception');

        Carbon::setTestNow();
    }

    // =========================================================================
    // SECTION 5: EXCEPTION CONSUMPTION & ONE-TIME USE
    // =========================================================================

    public function test_con_01_and_02_authorized_exception_is_consumed_and_records_metadata(): void
    {
        [$app, $cc, $memo] = $this->makeApplication();
        $service = app(ShmCreditCheckExceptionService::class);
        $approvalService = app(ShmMemorandumApprovalService::class);

        $exception = $service->authorizeException(
            $app,
            ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            $this->direkturUtama,
            'Justifikasi bisnis mendesak Direktur Utama untuk pembiayaan prioritas.',
            'Dispensasi Completion Bypass oleh Direktur Utama',
            'Credit checking drafted'
        );

        $this->assertSame(ShmCreditCheckException::STATUS_AUTHORIZED, $exception->status);
        $this->assertNull($exception->consumed_at);
        $this->assertNull($exception->consumed_memorandum_id);

        // Marketing submits memorandum
        $submittedMemo = $approvalService->submit($memo, $this->marketing, 'Submit catatan');

        $this->assertSame(ShmMemorandum::STATUS_SUBMITTED, $submittedMemo->status);

        $exception->refresh();
        $this->assertSame(ShmCreditCheckException::STATUS_CONSUMED, $exception->status);
        $this->assertNotNull($exception->consumed_at);
        $this->assertSame($memo->id, $exception->consumed_memorandum_id);
        $this->assertFalse($exception->isAvailable());
    }

    public function test_con_03_consumed_exception_cannot_be_reused(): void
    {
        [$app, $cc, $memo] = $this->makeApplication();
        $service = app(ShmCreditCheckExceptionService::class);
        $approvalService = app(ShmMemorandumApprovalService::class);

        $exception = $service->authorizeException(
            $app,
            ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            $this->direkturUtama,
            'Justifikasi bisnis mendesak Direktur Utama untuk pembiayaan prioritas.',
            'Dispensasi Completion Bypass oleh Direktur Utama',
            'Credit checking drafted'
        );

        // First consumption succeeds
        $approvalService->submit($memo, $this->marketing, 'First submission');
        $this->assertSame(ShmCreditCheckException::STATUS_CONSUMED, $exception->fresh()->status);

        // Second consumption attempt on another memorandum or same memorandum throws
        $secondMemo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'created_by' => $this->marketing->id,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Pengajuan memerlukan dispensasi aktif tipe 'completion_bypass' yang telah diotorisasi oleh Direktur Utama.");
        $service->consumeException($app, ShmCreditCheckException::TYPE_COMPLETION_BYPASS, $secondMemo);
    }

    public function test_con_06_and_07_failed_request_does_not_leave_consumed_state(): void
    {
        [$app, $cc, $memo] = $this->makeApplication();
        $service = app(ShmCreditCheckExceptionService::class);

        $exception = $service->authorizeException(
            $app,
            ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            $this->direkturUtama,
            'Justifikasi bisnis mendesak Direktur Utama untuk pembiayaan prioritas.',
            'Dispensasi Completion Bypass oleh Direktur Utama',
            'Credit checking drafted'
        );

        // Simulate a transaction failure during consumption
        try {
            DB::transaction(function () use ($service, $app, $memo) {
                $service->consumeException($app, ShmCreditCheckException::TYPE_COMPLETION_BYPASS, $memo);
                throw new \RuntimeException('Simulated processing failure after consumption step');
            });
        } catch (\RuntimeException $e) {
            // Expected simulation
        }

        $exception->refresh();
        $this->assertSame(ShmCreditCheckException::STATUS_AUTHORIZED, $exception->status, 'Failed outer transaction must roll back consumption');
        $this->assertNull($exception->consumed_at);
        $this->assertNull($exception->consumed_memorandum_id);
        $this->assertTrue($exception->isAvailable());
    }

    public function test_revocation_prevents_consumption(): void
    {
        [$app, $cc, $memo] = $this->makeApplication();
        $service = app(ShmCreditCheckExceptionService::class);

        $exception = $service->authorizeException(
            $app,
            ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            $this->direkturUtama,
            'Justifikasi bisnis mendesak Direktur Utama untuk pembiayaan prioritas.',
            'Dispensasi Completion Bypass oleh Direktur Utama',
            'Credit checking drafted'
        );

        $service->revokeException($exception, $this->direkturUtama, 'Pencabutan dispensasi karena pembatalan pengajuan');

        $this->assertSame(ShmCreditCheckException::STATUS_REVOKED, $exception->fresh()->status);
        $this->assertFalse($exception->fresh()->isAvailable());

        $this->expectException(ValidationException::class);
        $service->consumeException($app, ShmCreditCheckException::TYPE_COMPLETION_BYPASS, $memo);
    }
}
