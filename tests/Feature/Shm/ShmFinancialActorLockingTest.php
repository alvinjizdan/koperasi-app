<?php

namespace Tests\Feature\Shm;

use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\User;
use App\Services\Shm\ShmLoanDisbursementService;
use App\Services\Shm\ShmRegularPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ShmFinancialActorLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_disbursement_aborts_when_actor_is_inactive(): void
    {
        $inactiveActor = User::factory()->create(['is_active' => false]);
        $app = new ShmLoanApplication;
        $app->id = 99999;

        $service = app(ShmLoanDisbursementService::class);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Aktor tidak aktif atau tidak ditemukan.');

        $service->disburse($app, $inactiveActor->id);
    }

    public function test_regular_payment_aborts_when_actor_is_inactive(): void
    {
        $inactiveActor = User::factory()->create(['is_active' => false]);

        $service = app(ShmRegularPaymentService::class);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Aktor tidak aktif atau tidak ditemukan.');

        $service->pay(
            loan: new ShmLoan,
            data: ['amount' => 100000],
            actorId: $inactiveActor->id
        );
    }
}
