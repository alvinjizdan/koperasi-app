<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RootRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_opening_root_is_redirected_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login', absolute: false));
    }

    public function test_authenticated_user_opening_root_is_redirected_to_shm_dashboard(): void
    {
        $this->seed(PermissionSeeder::class);
        $role = Role::query()->firstOrCreate(['name' => 'test_role'], ['display_name' => 'Test Role']);
        $permission = Permission::query()->where('name', 'shm.view')->first();
        if ($permission) {
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create();
        $user->roles()->attach($role);

        $response = $this->actingAs($user)->get('/');

        $response->assertRedirect(route('shm.dashboard', absolute: false));
    }

    public function test_root_route_does_not_loop_for_authenticated_user(): void
    {
        $this->seed(PermissionSeeder::class);
        $role = Role::query()->firstOrCreate(['name' => 'test_role'], ['display_name' => 'Test Role']);
        $permission = Permission::query()->where('name', 'shm.view')->first();
        if ($permission) {
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create();
        $user->roles()->attach($role);

        $response = $this->actingAs($user)->followingRedirects()->get('/');

        $response->assertOk();
    }

    public function test_authenticated_makro_user_opening_root_is_redirected_to_shm_dashboard(): void
    {
        // Seed permissions base
        $this->seed(PermissionSeeder::class);

        // Create the three users in SQLite
        User::factory()->create(['id' => 10, 'name' => 'Presiden Direktur', 'email_verified_at' => now(), 'is_active' => true]);
        User::factory()->create(['id' => 11, 'name' => 'Branch Manager', 'email_verified_at' => now(), 'is_active' => true]);
        User::factory()->create(['id' => 12, 'name' => 'Finance Operational', 'email_verified_at' => now(), 'is_active' => true]);

        $this->seed(MakroPhysicalRoleSeeder::class);

        // User 10 (PD Makro)
        $user10 = User::findOrFail(10);
        $res10 = $this->actingAs($user10)->get('/');
        $res10->assertRedirect(route('shm.dashboard', absolute: false));
        $this->actingAs($user10)->followingRedirects()->get('/')->assertOk();

        // User 11 (BM Makro)
        $user11 = User::findOrFail(11);
        $res11 = $this->actingAs($user11)->get('/');
        $res11->assertRedirect(route('shm.dashboard', absolute: false));
        $this->actingAs($user11)->followingRedirects()->get('/')->assertOk();

        // User 12 (Finance Operational Makro)
        $user12 = User::findOrFail(12);
        $res12 = $this->actingAs($user12)->get('/');
        $res12->assertRedirect(route('shm.dashboard', absolute: false));
        $this->actingAs($user12)->followingRedirects()->get('/')->assertOk();
    }
}
