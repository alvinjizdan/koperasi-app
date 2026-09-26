<?php

namespace Tests\Feature;

use App\Models\User;
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

    public function test_authenticated_user_opening_root_is_redirected_to_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_root_route_does_not_loop_for_guest(): void
    {
        // Guest: / harus berakhir di halaman login yang tampil penuh, bukan redirect berantai.
        $response = $this->followingRedirects()->get('/');

        $response->assertOk();
    }

    public function test_root_route_does_not_loop_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->followingRedirects()->get('/');

        $response->assertOk();
    }
}
