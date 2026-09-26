<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_cannot_access_member_routes(): void
    {
        $response = $this->get('/anggota');
        $response->assertRedirect('/login');

        $responseCreate = $this->get('/anggota/create');
        $responseCreate->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_member_index(): void
    {
        $user = User::factory()->superadmin()->create();
        Member::factory()->count(5)->create();

        $response = $this->actingAs($user)->get('/anggota');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Anggota/Index')
            ->has('members.data', 5)
            ->has('filters')
        );
    }

    public function test_authenticated_users_can_search_and_filter_members(): void
    {
        $user = User::factory()->superadmin()->create();

        Member::factory()->create([
            'full_name' => 'Budi Santoso',
            'member_number' => 'ANG-0001',
            'status' => 'active',
        ]);

        Member::factory()->create([
            'full_name' => 'Siti Rahma',
            'member_number' => 'ANG-0002',
            'status' => 'inactive',
        ]);

        // Search test
        $responseSearch = $this->actingAs($user)->get('/anggota?search=Budi');
        $responseSearch->assertStatus(200);
        $responseSearch->assertInertia(fn ($page) => $page
            ->component('Anggota/Index')
            ->has('members.data', 1)
            ->where('members.data.0.full_name', 'Budi Santoso')
        );

        // Filter status test
        $responseFilter = $this->actingAs($user)->get('/anggota?status=inactive');
        $responseFilter->assertStatus(200);
        $responseFilter->assertInertia(fn ($page) => $page
            ->component('Anggota/Index')
            ->has('members.data', 1)
            ->where('members.data.0.full_name', 'Siti Rahma')
        );
    }

    public function test_authenticated_users_can_create_new_member(): void
    {
        $user = User::factory()->superadmin()->create();

        $payload = [
            'member_number' => 'ANG-0099',
            'full_name' => 'Ahmad Dahlan',
            'identity_number' => '3501234567890001',
            'phone' => '08123456789',
            'address' => 'Jl. Pahlawan No. 12 Surabaya',
            'joined_at' => '2026-09-25',
            'status' => 'active',
        ];

        $response = $this->actingAs($user)->post('/anggota', $payload);

        $this->assertDatabaseHas('members', [
            'member_number' => 'ANG-0099',
            'full_name' => 'Ahmad Dahlan',
            'status' => 'active',
        ]);

        $newMember = Member::where('member_number', 'ANG-0099')->first();
        $response->assertRedirect(route('anggota.show', $newMember->id));
    }

    public function test_duplicate_member_number_is_rejected(): void
    {
        $user = User::factory()->superadmin()->create();

        Member::factory()->create([
            'member_number' => 'ANG-0001',
        ]);

        $payload = [
            'member_number' => 'ANG-0001',
            'full_name' => 'Anggota Duplikat',
            'joined_at' => '2026-09-25',
            'status' => 'active',
        ];

        $response = $this->actingAs($user)->post('/anggota', $payload);

        $response->assertSessionHasErrors(['member_number']);
        $this->assertDatabaseMissing('members', [
            'full_name' => 'Anggota Duplikat',
        ]);
    }

    public function test_authenticated_users_can_view_member_detail(): void
    {
        $user = User::factory()->superadmin()->create();
        $member = Member::factory()->create(['full_name' => 'Joko Widodo']);

        $response = $this->actingAs($user)->get(route('anggota.show', $member->id));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Anggota/Show')
            ->where('member.full_name', 'Joko Widodo')
        );
    }

    public function test_authenticated_users_can_update_member_info(): void
    {
        $user = User::factory()->superadmin()->create();
        $member = Member::factory()->create([
            'member_number' => 'ANG-0005',
            'full_name' => 'Nama Lama',
        ]);

        $payload = [
            'member_number' => 'ANG-0005',
            'full_name' => 'Nama Baru Diperbarui',
            'identity_number' => '3501234567890002',
            'phone' => '08987654321',
            'address' => 'Jl. Merdeka No. 45',
            'joined_at' => '2026-09-25',
            'status' => 'active',
        ];

        $response = $this->actingAs($user)->put(route('anggota.update', $member->id), $payload);

        $response->assertRedirect(route('anggota.show', $member->id));
        $this->assertDatabaseHas('members', [
            'id' => $member->id,
            'full_name' => 'Nama Baru Diperbarui',
            'phone' => '08987654321',
        ]);
    }

    public function test_authenticated_users_can_deactivate_member(): void
    {
        $user = User::factory()->superadmin()->create();
        $member = Member::factory()->create(['status' => 'active']);

        $response = $this->actingAs($user)->patch(route('anggota.deactivate', $member->id));

        $response->assertRedirect();
        $this->assertDatabaseHas('members', [
            'id' => $member->id,
            'status' => 'inactive',
        ]);
    }
}
