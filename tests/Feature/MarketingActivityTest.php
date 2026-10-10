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

    /**
     * Payload check-in yang valid.
     *
     * @return array<string, mixed>
     */
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

    /**
     * Apakah nilai prop tidak memuat kunci tertentu?
     */
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

    /** 1. Anggota dapat memiliki titik lokasi. */
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

    /** 2. Lokasi boleh kosong dan tidak pernah diisi koordinat palsu. */
    public function test_member_location_can_be_empty(): void
    {
        $this->assertFalse($this->member->fresh()->hasLocation());
        $this->assertNull($this->member->fresh()->latitude);
        $this->assertNull($this->member->fresh()->longitude);
    }

    /** 3. Koordinat di luar rentang ditolak. */
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

    /** 4. Latitude dan longitude harus berpasangan. */
    public function test_member_location_requires_both_coordinates(): void
    {
        $this->actingAs($this->superadmin)
            ->put(route('marketing.member-location.update', $this->member->id), [
                'latitude' => -6.7320,
                'longitude' => '',
            ])
            ->assertSessionHasErrors('longitude');
    }

    /** 5. Perubahan lokasi anggota tercatat di audit. */
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

    /** 6. Marketing dapat membuat aktivitas check-in. */
    public function test_marketing_can_check_in(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload())
            ->assertSessionHasNoErrors();

        $activity = MarketingActivity::query()->firstOrFail();

        $this->assertSame($this->marketing->id, $activity->marketing_id);
        $this->assertSame($this->customer->id, $activity->shm_customer_id);
        // Relasi kunjungan menyelesaikan ke Nasabah SHM, bukan ke anggota.
        $this->assertTrue($activity->customer->is($this->customer));
        $this->assertNotNull($activity->checked_in_at);
        $this->assertEqualsWithDelta(-6.7320, (float) $activity->latitude, 0.0001);
        $this->assertEqualsWithDelta(108.5520, (float) $activity->longitude, 0.0001);
        $this->assertSame('Kunjungan pertama', $activity->result);
    }

    /** 7. Check-in tercatat di audit. */
    public function test_check_in_is_audited(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload());

        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing.activity.checked_in']);
    }

    /** 8. marketing_id dari input diabaikan: Marketing tidak dapat check-in atas nama orang lain. */
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

    /** 9. Check-in tanpa koordinat ditolak: sistem tidak mengarang lokasi. */
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

    /** 10. Nasabah SHM tujuan harus benar-benar ada di shm_customers. */
    public function test_check_in_requires_existing_shm_customer(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload([
                'shm_customer_id' => 999999,
            ]))
            ->assertSessionHasErrors('shm_customer_id');

        $this->assertSame(0, MarketingActivity::query()->count());
    }

    /** 11. Koordinat di luar rentang ditolak pada check-in. */
    public function test_check_in_rejects_out_of_range_coordinates(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload([
                'latitude' => 91,
                'longitude' => 181,
            ]))
            ->assertSessionHasErrors(['latitude', 'longitude']);
    }

    /** 12. Waktu check-in berasal dari server, bukan dari input. */
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

    /** 13. Request duplikat dengan idempotency key tidak menggandakan data. */
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

    /** 14. Pengguna tanpa permission marketing tidak dapat membuka halaman. */
    public function test_user_without_marketing_permission_is_forbidden(): void
    {
        $kasir = $this->userWithRole('kasir');

        $this->actingAs($kasir)->get(route('shm.marketing.activities.index'))->assertForbidden();
        $this->actingAs($kasir)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload())
            ->assertForbidden();
    }

    /** 15. Marketing tidak dapat membuka aktivitas Marketing lain. */
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

    /** 16. Marketing hanya melihat aktivitasnya sendiri pada daftar. */
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

    /** 17. Titik lokasi anggota tidak dikirim kepada pengguna tanpa permission lokasi. */
    public function test_member_location_is_not_exposed_without_permission(): void
    {
        $this->member->forceFill(['latitude' => -6.7320, 'longitude' => 108.5520])->save();

        // Kasir boleh membuka detail anggota, tetapi tidak berhak atas lokasi.
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

        // Marketing berhak melihat titik lokasi.
        $this->actingAs($this->marketing)
            ->get(route('anggota.show', $this->member->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_view_location', true)
                ->where('location.has_location', true)
            );
    }

    /** 18. Hanya pemegang permission yang dapat mengubah titik lokasi. */
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

    /** 19. Marketing dapat melihat hasil dan catatan aktivitasnya sendiri. */
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

        // Satu aksi pengguna harus menghasilkan tepat satu baris audit;
        // logChanges() sudah memanggil log() secara internal.
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'marketing.activity.result_updated')
            ->count());
    }

    // ---------------------------------------------------------------------
    // Monitoring
    // ---------------------------------------------------------------------

    /** 20. Monitoring hanya untuk pemegang permission. */
    public function test_monitoring_requires_permission(): void
    {
        $this->actingAs($this->marketing)->get(route('shm.marketing.monitoring.index'))->assertForbidden();
        $this->actingAs($this->superadmin)->get(route('shm.marketing.monitoring.index'))->assertOk();
    }

    /** 21. Admin melihat seluruh aktivitas Marketing. */
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

    /** 22. Monitoring memakai pagination server-side, bukan memuat semua data. */
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

        // per_page khusus tetap dihormati (whitelist 10/25/50/100).
        $this->actingAs($this->superadmin)
            ->get(route('shm.marketing.monitoring.index', ['per_page' => 10]))
            ->assertInertia(fn ($page) => $page->has('activities.data', 10));
    }

    /** 23. Filter nasabah SHM bekerja pada monitoring. */
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

    /** 24. Lokasi nasabah SHM tidak ikut dikirim ke halaman monitoring. */
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
    // Batas domain: Nasabah SHM, bukan anggota koperasi (Ticket 71)
    // ---------------------------------------------------------------------

    /** 24a. Skema aktivitas tidak lagi menyimpan rujukan ke anggota koperasi. */
    public function test_activity_schema_has_no_cooperative_member_reference(): void
    {
        $this->assertFalse(Schema::hasColumn('marketing_activities', 'member_id'));
        $this->assertTrue(Schema::hasColumn('marketing_activities', 'shm_customer_id'));
    }

    /** 24b. Check-in dapat dibuat tanpa anggota koperasi apa pun. */
    public function test_check_in_does_not_require_cooperative_member(): void
    {
        // Payload tidak memuat anggota sama sekali — cukup nasabah SHM.
        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('marketing_activities', [
            'marketing_id' => $this->marketing->id,
            'shm_customer_id' => $this->customer->id,
        ]);
    }

    /** 24c. Id anggota koperasi tidak dapat dipakai sebagai nasabah SHM. */
    public function test_koperasi_member_id_cannot_be_used_as_shm_customer(): void
    {
        $member = Member::factory()->create();

        // Dua master memakai sekuens id sendiri-sendiri; pastikan id anggota
        // ini memang tidak terdaftar di shm_customers.
        ShmCustomer::query()->whereKey($member->id)->delete();
        $this->assertNull(ShmCustomer::query()->find($member->id));

        $this->actingAs($this->marketing)
            ->post(route('shm.marketing.activities.store'), $this->checkInPayload([
                'shm_customer_id' => $member->id,
            ]))
            ->assertSessionHasErrors('shm_customer_id');

        $this->assertSame(0, MarketingActivity::query()->count());
    }

    /** 24d. Pilihan nasabah pada halaman aktivitas bersumber dari shm_customers. */
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

    /** 24e. Detail aktivitas menyelesaikan Nasabah SHM, bukan anggota. */
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

    /** 25. Permission marketing melekat pada role marketing, bukan nama role hardcoded. */
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

        // Sensitive permissions TIDAK diberikan ke marketing.
        foreach (['marketing.monitoring.view', 'marketing.member_location.update'] as $forbidden) {
            $this->assertNotContains($forbidden, $permissions);
            $this->assertFalse($this->marketing->hasPermission($forbidden));
        }
    }

    /** 26. Feature Simpanan dan Pinjaman tidak berubah karena fitur ini. */
    public function test_existing_modules_remain_untouched(): void
    {
        $this->actingAs($this->superadmin)->get(route('simpanan.index'))->assertOk();
        $this->actingAs($this->superadmin)->get(route('pinjaman.index'))->assertOk();
    }
}
