<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MarketingActivity;
use App\Models\Member;
use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Ticket Marketing Activity Tracking & Titik Lokasi Nasabah.
 *
 * Menguji: pencatatan lokasi anggota, aktivitas/check-in marketing, batas
 * keamanan data lokasi, idempotensi check-in, dan monitoring.
 *
 * Ticket 63: halaman aktivitas/monitoring kini dirutekan melalui
 * shm.marketing.* (bounded context SHM); titik lokasi anggota tetap memakai
 * marketing.member-location.update di konteks koperasi.
 *
 * Ticket 71: subjek kunjungan HANYA Nasabah SHM (`shm_customers`). Kolom
 * member_id sudah dihapus dari marketing_activities dan tidak ada fallback ke
 * anggota koperasi; tes bagian bawah membatasi batas domain tersebut sementara
 * tes titik lokasi anggota di atasnya tetap menjamin modul Koperasi tidak
 * berubah.
 */
class MarketingActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $marketing;

    private Member $member;

    private ShmCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = User::factory()->create(['email_verified_at' => now()]);
        $this->superadmin->roles()->attach(Role::query()->where('name', 'superadmin')->value('id'));

        $this->marketing = $this->userWithRole('marketing');
        $this->member = Member::factory()->create();
        $this->customer = ShmCustomer::factory()->create();
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    private function checkInPayload(array $overrides = []): array
    {
        return array_merge([
            'shm_customer_id' => $this->customer->id,
            'latitude' => -6.7320000,
            'longitude' => 108.5520000,
            'result' => 'Kunjungan pertama',
            'notes' => 'Nasabah dapat dihubungi',
        ], $overrides);
    }

    private function propLacksKey(mixed $value, string $key): bool
    {
        if (is_array($value)) {
            return ! array_key_exists($key, $value);
        }

        if ($value instanceof Collection) {
            return ! $value->has($key);
        }

        return true;
    }

    // ---------------------------------------------------------------------
    // Data lokasi anggota
    // ---------------------------------------------------------------------

    public function test_member_can_have_location_coordinates(): void
    {
        $this->actingAs($this->superadmin)
            ->put(route('marketing.member-location.update', $this->member->id), [
                'latitude' => -6.7320,
                'longitude' => 108.5520,
            ])
            ->assertSessionHasNoErrors();

        $member = $this->member->fresh();
        $this->assertTrue($member->hasLocation());
        $this->assertEqualsWithDelta(-6.7320, (float) $member->latitude, 0.0001);
        $this->assertEqualsWithDelta(108.5520, (float) $member->longitude, 0.0001);
    }

    public function test_member_location_can_be_empty(): void
    {
        $this->assertFalse($this->member->fresh()->hasLocation());
        $this->assertNull($this->member->fresh()->latitude);
        $this->assertNull($this->member->fresh()->longitude);
    }

    public function test_invalid_coordinates_are_rejected(): void
    {
        $this->actingAs($this->superadmin)
            ->put(route('marketing.member-location.update', $this->member->id), [
                'latitude' => 999,
                'longitude' => 108.55,
            ])
            ->assertSessionHasErrors('latitude');

        $this->actingAs($this->superadmin)
            ->put(route('marketing.member-location.update', $this->member->id), [
                'latitude' => -6.73,
                'longitude' => 9999,
            ])
            ->assertSessionHasErrors('longitude');

        $this->assertFalse($this->member->fresh()->hasLocation());
    }

    public function test_member_location_requires_both_coordinates(): void
    {
        $this->actingAs($this->superadmin)
            ->put(route('marketing.member-location.update', $this->member->id), [
                'latitude' => -6.7320,
                'longitude' => '',
            ])
            ->assertSessionHasErrors('longitude');
    }

    public function test_member_location_change_is_audited(): void
    {
        $this->actingAs($this->superadmin)
            ->put(route('marketing.member-location.update', $this->member->id), [
                'latitude' => -6.7320,
                'longitude' => 108.5520,
            ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'member.location_updated']);
    }

    // ---------------------------------------------------------------------
    // Aktivitas & check-in
    // ---------------------------------------------------------------------

    public function test_marketing_can_check_in(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload())
            ->assertSessionHasNoErrors();

        $activity = MarketingActivity::query()->firstOrFail();

        $this->assertSame($this->marketing->id, $activity->marketing_id);
        $this->assertSame($this->customer->id, $activity->shm_customer_id);
        $this->assertTrue($activity->customer->is($this->customer));
        $this->assertNotNull($activity->checked_in_at);
        $this->assertEqualsWithDelta(-6.7320, (float) $activity->latitude, 0.0001);
        $this->assertEqualsWithDelta(108.5520, (float) $activity->longitude, 0.0001);
        $this->assertSame('Kunjungan pertama', $activity->result);
    }

    public function test_check_in_is_audited(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload());

        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing.activity.checked_in']);
    }

    public function test_marketing_cannot_check_in_on_behalf_of_another_user(): void
    {
        $other = $this->userWithRole('marketing');

        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload([
                'marketing_id' => $other->id,
            ]))
            ->assertSessionHasNoErrors();

        $activity = MarketingActivity::query()->firstOrFail();
        $this->assertSame($this->marketing->id, $activity->marketing_id);
        $this->assertNotSame($other->id, $activity->marketing_id);
    }

    public function test_check_in_without_coordinates_is_rejected(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload([
                'latitude' => '',
                'longitude' => '',
            ]))
            ->assertSessionHasErrors(['latitude', 'longitude']);

        $this->assertSame(0, MarketingActivity::query()->count());
    }

    public function test_check_in_requires_existing_shm_customer(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload([
                'shm_customer_id' => 999999,
            ]))
            ->assertSessionHasErrors('shm_customer_id');

        $this->assertSame(0, MarketingActivity::query()->count());
    }

    public function test_check_in_rejects_out_of_range_coordinates(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload([
                'latitude' => 91,
                'longitude' => 181,
            ]))
            ->assertSessionHasErrors(['latitude', 'longitude']);
    }

    public function test_check_in_time_comes_from_server(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload([
                'checked_in_at' => '1990-01-01 00:00:00',
            ]))
            ->assertSessionHasNoErrors();

        $activity = MarketingActivity::query()->firstOrFail();
        $this->assertTrue($activity->checked_in_at->isToday());
    }

    public function test_duplicate_check_in_does_not_create_extra_rows(): void
    {
        $key = 'test-idem-'.uniqid();

        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload([
                'idempotency_key' => $key,
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload([
                'idempotency_key' => $key,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, MarketingActivity::query()->count());
    }

    // ---------------------------------------------------------------------
    // Otorisasi & privasi data lokasi
    // ---------------------------------------------------------------------

    public function test_user_without_marketing_permission_is_forbidden(): void
    {
        $kasir = $this->userWithRole('kasir');

        $this->actingAs($kasir)->get(route('shm.marketing.activities.index'))->assertForbidden();
        $this->actingAs($kasir)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload())
            ->assertForbidden();
    }

    public function test_marketing_cannot_open_other_marketing_activity(): void
    {
        $other = $this->userWithRole('marketing');

        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload());

        $activity = MarketingActivity::query()->firstOrFail();

        $this->actingAs($other)->get(route('shm.marketing.activities.show', $activity->id))->assertForbidden();
        $this->actingAs($other)
            ->patch(route('shm.marketing.activities.result.update', $activity->id), ['result' => 'Diubah'])
            ->assertForbidden();
    }

    public function test_marketing_list_only_shows_own_activities(): void
    {
        $other = $this->userWithRole('marketing');

        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload());

        $this->actingAs($other)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload());

        $this->actingAs($this->marketing)
            ->get(route('shm.marketing.activities.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Marketing/Activities/Index')
                ->has('activities.data', 1)
                ->where('activities.data.0.marketing.id', $this->marketing->id)
            );
    }

    public function test_member_location_is_not_exposed_without_permission(): void
    {
        $this->member->forceFill(['latitude' => -6.7320, 'longitude' => 108.5520])->save();

        $kasir = $this->userWithRole('kasir');

        $this->actingAs($kasir)
            ->get(route('anggota.show', $this->member->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('location', null)
                ->where('can_view_location', false)
                ->where('can_update_location', false)
                ->where('member', fn ($member) => $this->propLacksKey($member, 'latitude'))
            );

        $this->actingAs($this->marketing)
            ->get(route('anggota.show', $this->member->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_view_location', true)
                ->where('location.has_location', true)
            );
    }

    public function test_only_authorized_user_can_update_member_location(): void
    {
        $kasir = $this->userWithRole('kasir');

        $this->actingAs($kasir)
            ->put(route('marketing.member-location.update', $this->member->id), [
                'latitude' => -6.7320,
                'longitude' => 108.5520,
            ])
            ->assertForbidden();

        $this->assertFalse($this->member->fresh()->hasLocation());
    }

    public function test_marketing_can_update_result_of_own_activity(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload());

        $activity = MarketingActivity::query()->firstOrFail();

        $this->actingAs($this->marketing)
            ->patch(route('shm.marketing.activities.result.update', $activity->id), [
                'result' => 'Hasil diperbarui',
                'notes' => 'Catatan tambahan',
            ])
            ->assertSessionHasNoErrors();

        $activity->refresh();
        $this->assertSame('Hasil diperbarui', $activity->result);
        $this->assertSame('Catatan tambahan', $activity->notes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing.activity.result_updated']);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'marketing.activity.result_updated')
            ->count());
    }

    // ---------------------------------------------------------------------
    // Monitoring
    // ---------------------------------------------------------------------

    public function test_monitoring_requires_permission(): void
    {
        $this->actingAs($this->marketing)->get(route('shm.marketing.monitoring.index'))->assertForbidden();
        $this->actingAs($this->superadmin)->get(route('shm.marketing.monitoring.index'))->assertOk();
    }

    public function test_admin_can_monitor_all_activities(): void
    {
        $other = $this->userWithRole('marketing');

        $this->actingAs($this->marketing)->post(route('shm.marketing.activities.store'), $this->checkInPayload());
        $this->actingAs($other)->post(route('shm.marketing.activities.store'), $this->checkInPayload([
            'shm_customer_id' => ShmCustomer::factory()->create()->id,
        ]));

        $this->actingAs($this->superadmin)
            ->get(route('shm.marketing.monitoring.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Marketing/Monitoring')
                ->has('activities.data', 2)
            );
    }

    public function test_monitoring_uses_pagination(): void
    {
        foreach (range(1, 30) as $index) {
            MarketingActivity::query()->create([
                'marketing_id' => $this->marketing->id,
                'shm_customer_id' => $this->customer->id,
                'checked_in_at' => now(),
                'latitude' => -6.7320,
                'longitude' => 108.5520,
            ]);
        }

        $response = $this->actingAs($this->superadmin)->get(route('shm.marketing.monitoring.index'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('activities.total', 30)
            ->has('activities.data', 25)
        );

        $this->actingAs($this->superadmin)
            ->get(route('shm.marketing.monitoring.index', ['per_page' => 10]))
            ->assertInertia(fn ($page) => $page->has('activities.data', 10));
    }

    public function test_monitoring_filter_by_customer_works(): void
    {
        $otherCustomer = ShmCustomer::factory()->create();

        MarketingActivity::query()->create([
            'marketing_id' => $this->marketing->id, 'shm_customer_id' => $this->customer->id, 'checked_in_at' => now(),
        ]);
        MarketingActivity::query()->create([
            'marketing_id' => $this->marketing->id, 'shm_customer_id' => $otherCustomer->id, 'checked_in_at' => now(),
        ]);

        $this->actingAs($this->superadmin)
            ->get(route('shm.marketing.monitoring.index', ['shm_customer_id' => $otherCustomer->id]))
            ->assertInertia(fn ($page) => $page
                ->has('activities.data', 1)
                ->where('activities.data.0.customer.id', $otherCustomer->id)
            );
    }

    public function test_monitoring_does_not_leak_customer_coordinates(): void
    {
        $this->customer->forceFill(['latitude' => -6.7320, 'longitude' => 108.5520])->save();

        $this->actingAs($this->superadmin)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload());

        $this->actingAs($this->superadmin)
            ->get(route('shm.marketing.monitoring.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activities.data.0.customer', fn ($customer) => $this->propLacksKey($customer, 'latitude'))
            );
    }

    // ---------------------------------------------------------------------
    // P1: Customer coordinate lock (Titik Lokasi Nasabah)
    // ---------------------------------------------------------------------

    public function test_first_check_in_locks_customer_coordinate(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload())
            ->assertSessionHasNoErrors();

        $customer = $this->customer->fresh();

        $this->assertTrue($customer->hasLocation());
        $this->assertEqualsWithDelta(-6.7320, (float) $customer->latitude, 0.0001);
        $this->assertEqualsWithDelta(108.5520, (float) $customer->longitude, 0.0001);
    }

    public function test_second_check_in_does_not_overwrite_locked_coordinate(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload())
            ->assertSessionHasNoErrors();

        // Check-in kedua dari lokasi berbeda.
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload([
                'latitude' => -6.9000000,
                'longitude' => 108.7000000,
                'result' => 'Kunjungan kedua',
                'idempotency_key' => 'second-checkin-'.uniqid(),
            ]))
            ->assertSessionHasNoErrors();

        $customer = $this->customer->fresh();

        // Koordinat master nasabah TIDAK berubah.
        $this->assertTrue($customer->hasLocation());
        $this->assertEqualsWithDelta(-6.7320, (float) $customer->latitude, 0.0001);
        $this->assertEqualsWithDelta(108.5520, (float) $customer->longitude, 0.0001);

        // Activity kedua menyimpan koordinat check-in kedua.
        $second = MarketingActivity::query()->where('result', 'Kunjungan kedua')->firstOrFail();
        $this->assertEqualsWithDelta(-6.9000000, (float) $second->latitude, 0.0001);
        $this->assertEqualsWithDelta(108.7000000, (float) $second->longitude, 0.0001);
    }

    public function test_customer_coordinate_lock_fails_when_check_in_fails(): void
    {
        // Check-in dengan koordinat kosong ditolak oleh validasi.
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload([
                'latitude' => '',
                'longitude' => '',
            ]))
            ->assertSessionHasErrors(['latitude', 'longitude']);

        $this->assertFalse($this->customer->fresh()->hasLocation());
    }

    public function test_customer_coordinate_is_not_locked_without_check_in(): void
    {
        $this->assertFalse($this->customer->fresh()->hasLocation());
    }

    // ---------------------------------------------------------------------
    // Batas domain: Nasabah SHM, bukan anggota koperasi (Ticket 71)
    // ---------------------------------------------------------------------

    public function test_activity_schema_has_no_cooperative_member_reference(): void
    {
        $this->assertFalse(Schema::hasColumn('marketing_activities', 'member_id'));
        $this->assertTrue(Schema::hasColumn('marketing_activities', 'shm_customer_id'));
    }

    public function test_check_in_succeeds_when_shm_customer_is_selected(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $activity = MarketingActivity::query()->firstOrFail();

        $this->assertSame($this->customer->id, $activity->shm_customer_id);
        $this->assertNotNull($activity->checked_in_at);
    }

    public function test_check_in_fails_when_shm_customer_is_not_selected(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), array_merge($this->checkInPayload(), [
                'shm_customer_id' => '',
            ]))
            ->assertSessionHasErrors('shm_customer_id');

        $this->assertSame(0, MarketingActivity::query()->count());
    }

    public function test_check_in_does_not_require_cooperative_member(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('marketing_activities', [
            'marketing_id' => $this->marketing->id,
            'shm_customer_id' => $this->customer->id,
        ]);
    }

    public function test_koperasi_member_id_cannot_be_used_as_shm_customer(): void
    {
        $member = Member::factory()->create();

        ShmCustomer::query()->whereKey($member->id)->delete();
        $this->assertNull(ShmCustomer::query()->find($member->id));

        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload([
                'shm_customer_id' => $member->id,
            ]))
            ->assertSessionHasErrors('shm_customer_id');

        $this->assertSame(0, MarketingActivity::query()->count());
    }

    public function test_activity_customer_selector_reads_from_shm_customers(): void
    {
        $this->actingAs($this->marketing)
            ->get(route('shm.marketing.activities.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Marketing/Activities/Index')
                ->has('customers', 1)
                ->where('customers.0.id', $this->customer->id)
                ->where('customers.0.customer_number', $this->customer->customer_number)
                ->where('customers.0.name', $this->customer->name)
            );
    }

    public function test_activity_detail_resolves_shm_customer(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload());

        $activity = MarketingActivity::query()->firstOrFail();

        $this->actingAs($this->marketing)
            ->get(route('shm.marketing.activities.show', $activity->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Marketing/Activities/Show')
                ->where('activity.customer.id', $this->customer->id)
                ->where('activity.customer.customer_number', $this->customer->customer_number)
                ->where('activity.customer.name', $this->customer->name)
                ->where('activity.marketing.name', $this->marketing->name)
            );
    }

    public function test_activity_detail_shows_locked_customer_coordinate(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload());

        $this->customer->refresh();

        $activity = MarketingActivity::query()->firstOrFail();

        $this->actingAs($this->marketing)
            ->get(route('shm.marketing.activities.show', $activity->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Marketing/Activities/Show')
                ->where('activity.customer.latitude', (string) $this->customer->latitude)
                ->where('activity.customer.longitude', (string) $this->customer->longitude)
            );
    }

    public function test_customer_coordinate_not_locked_before_first_check_in(): void
    {
        $this->assertFalse($this->customer->fresh()->hasLocation());
    }

    public function test_locked_coordinate_visible_on_activity_detail(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload());

        $this->customer->refresh();

        $activity = MarketingActivity::query()->firstOrFail();

        $response = $this->actingAs($this->marketing)
            ->get(route('shm.marketing.activities.show', $activity->id));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Marketing/Activities/Show')
                ->where('activity.customer.latitude', (string) $this->customer->latitude)
                ->where('activity.customer.longitude', (string) $this->customer->longitude)
            );
    }

    public function test_marketing_role_has_activity_permissions(): void
    {
        $permissions = Role::query()
            ->where('name', 'marketing')
            ->first()
            ->permissions()
            ->pluck('permissions.name')
            ->all();

        foreach (['marketing.view', 'marketing.create', 'marketing.result.update', 'marketing.member_location.view'] as $expected) {
            $this->assertContains($expected, $permissions);
        }

        foreach (['marketing.monitoring.view', 'marketing.member_location.update'] as $forbidden) {
            $this->assertNotContains($forbidden, $permissions);
            $this->assertFalse($this->marketing->hasPermission($forbidden));
        }
    }

    public function test_existing_modules_remain_untouched(): void
    {
        $this->actingAs($this->superadmin)->get(route('simpanan.index'))->assertOk();
        $this->actingAs($this->superadmin)->get(route('pinjaman.index'))->assertOk();
    }
}
