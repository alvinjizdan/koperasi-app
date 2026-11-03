<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShmAccountingReportTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $unauthorizedUser;

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

        $this->unauthorizedUser = User::factory()->create();
    }

    public function test_trial_balance_template_download(): void
    {
        $response = $this->actingAs($this->admin)->get(route('shm.trial-balance.template'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_balance_sheet_template_download(): void
    {
        $response = $this->actingAs($this->admin)->get(route('shm.balance-sheet.template'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_cumulative_profit_loss_template_download(): void
    {
        $response = $this->actingAs($this->admin)->get(route('shm.cumulative-profit-loss.template'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_period_profit_loss_template_download(): void
    {
        $response = $this->actingAs($this->admin)->get(route('shm.period-profit-loss.template'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_unauthorized_user_cannot_download_templates(): void
    {
        $this->actingAs($this->unauthorizedUser)->get(route('shm.trial-balance.template'))->assertForbidden();
        $this->actingAs($this->unauthorizedUser)->get(route('shm.balance-sheet.template'))->assertForbidden();
        $this->actingAs($this->unauthorizedUser)->get(route('shm.cumulative-profit-loss.template'))->assertForbidden();
        $this->actingAs($this->unauthorizedUser)->get(route('shm.period-profit-loss.template'))->assertForbidden();
    }
}
