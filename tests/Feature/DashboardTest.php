<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $userWithPermission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->userWithPermission = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'custom_pengaturan_'.uniqid(),
            'display_name' => 'Custom',
            'is_system' => false,
        ]);
        $role->permissions()->attach(
            Permission::query()->where('name', 'pengaturan.view')->pluck('id')
        );
        $this->userWithPermission->roles()->attach($role->id);
    }

    public function test_unauthenticated_users_are_redirected_to_login(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('today_formatted')
            ->has('period')
            ->has('metrics.members')
            ->has('metrics.savings')
            ->has('metrics.loans')
            ->has('metrics.cash')
            ->has('metrics.activities')
            ->has('system_status')
        );
    }

    public function test_authenticated_users_can_view_module_placeholders(): void
    {
        // Ticket 17 BUG-01: halaman pengaturan kini memerlukan permission
        // pengaturan.view; pengguna tanpa permission ditolak di backend.
        $user = User::factory()->create();
        $this->actingAs($user)->get('/pengaturan')->assertForbidden();

        $modules = ['pengaturan'];

        foreach ($modules as $module) {
            $response = $this->actingAs($this->userWithPermission)->get('/'.$module);

            $response->assertStatus(200);
            $response->assertInertia(fn ($page) => $page
                ->component('Pengaturan/Index')
                ->has('can')
            );
        }
    }
}
