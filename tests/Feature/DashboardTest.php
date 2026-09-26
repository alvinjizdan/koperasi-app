<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

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
        $user = User::factory()->create();

        // Modul Kasir dan Laporan tidak lagi berupa placeholder karena sudah diimplementasikan.
        // Modul Pengaturan juga sudah diimplementasikan melalui SettingsController.
        $modules = ['pengaturan'];

        foreach ($modules as $module) {
            $response = $this->actingAs($user)->get('/'.$module);

            $response->assertStatus(200);
            $response->assertInertia(fn ($page) => $page
                ->component('Pengaturan/Index')
                ->has('can')
            );
        }
    }
}
