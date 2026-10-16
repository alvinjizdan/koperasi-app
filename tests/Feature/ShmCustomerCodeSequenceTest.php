<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T102 — Kode Nasabah SHM: format `NNNNN-ANG`, sequence per domain.
 *
 * Mengunci business rule wajib Phase 3:
 * - Nasabah pertama format baru `00001-ANG`, lalu berurutan `00002-ANG`, ...
 * - Sequence Nasabah SHM INDEPENDEN dari nomor anggota koperasi: kode anggota
 *   `00001-ANG` tidak dikonsumsi maupun disalin oleh domain nasabah (dan
 *   sebaliknya) — literal yang sama antar-dua-tabel diperbolehkan.
 * - Uniqueness `shm_customers.customer_number` dijaga database (unique index)
 *   sebagai safety net mekanisme sequence.
 * - Data legacy `NSB-...` TIDAK di-reset/di-overwrite dan TIDAK ikut dihitung
 *   oleh sequence format baru.
 * - Kode immutable: klien tidak dapat mengubah kode maupun asosiasi anggota
 *   lewat update.
 *
 * Konkurensi paralel (proses PHP bersamaan di PostgreSQL) diuji terpisah di
 * ConcurrencyAndFailureTest::test_parallel_customer_creations_get_unique_codes.
 */
class ShmCustomerCodeSequenceTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->marketing = $this->userWithRole('marketing');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    private function activeMember(array $overrides = []): Member
    {
        return Member::factory()->create(array_merge(['status' => 'active'], $overrides));
    }

    /**
     * Buat nasabah lewat jalur HTTP resmi (service → generator sequence).
     */
    private function createCustomerViaHttp(Member $member, string $name = 'Nasabah Uji'): ShmCustomer
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.customers.store'), [
                'member_id' => $member->id,
                'name' => $name,
                'nik' => '3501234567890123',
                'phone' => '081234567890',
                'address' => 'Jl. Contoh No. 1, Cirebon',
            ])
            ->assertSessionHasNoErrors();

        return ShmCustomer::query()->latest('id')->firstOrFail();
    }

    // -----------------------------------------------------------------
    // G. Urutan kode: 00001-ANG pertama, lalu berurutan
    // -----------------------------------------------------------------

    public function test_first_new_customer_gets_00001_then_increments(): void
    {
        $first = $this->createCustomerViaHttp($this->activeMember(), 'Budi');
        $second = $this->createCustomerViaHttp($this->activeMember(), 'Andi');
        $third = $this->createCustomerViaHttp($this->activeMember(), 'Citra');

        $this->assertSame('00001-ANG', $first->customer_number);
        $this->assertSame('00002-ANG', $second->customer_number);
        $this->assertSame('00003-ANG', $third->customer_number);

        // Format wajib NNNNN-ANG (5 digit + suffix).
        foreach ([$first, $second, $third] as $customer) {
            $this->assertMatchesRegularExpression('/^\d{5}-ANG$/', $customer->customer_number);
        }
    }

    // -----------------------------------------------------------------
    // H. Sequence independen dari nomor anggota
    // -----------------------------------------------------------------

    public function test_sequence_is_independent_from_member_codes(): void
    {
        // Anggota sudah memegang kode 00001-ANG dan 00002-ANG lebih dulu.
        $memberA = $this->activeMember(['member_number' => '00001-ANG', 'full_name' => 'Budi']);
        $memberB = $this->activeMember(['member_number' => '00002-ANG', 'full_name' => 'Citra']);

        $customerA = $this->createCustomerViaHttp($memberA, 'Budi');
        $customerB = $this->createCustomerViaHttp($memberB, 'Citra');

        // Domain Nasabah SHM memakai sequence SENDIRI dan dimulai dari
        // 00001: literal kode boleh sama dengan kode anggota karena berada
        // pada tabel berbeda (aturan Phase 3).
        $this->assertSame('00001-ANG', $customerA->customer_number);
        $this->assertSame('00002-ANG', $customerB->customer_number);

        // Kode anggota TIDAK berubah/dikonsumsi oleh pembuatan nasabah.
        $this->assertSame('00001-ANG', $memberA->refresh()->member_number);
        $this->assertSame('00002-ANG', $memberB->refresh()->member_number);

        // Relasi internal tetap memakai FK, bukan kode.
        $this->assertSame($memberA->id, $customerA->member_id);
        $this->assertSame($memberB->id, $customerB->member_id);
    }

    public function test_member_code_is_never_used_as_customer_code(): void
    {
        // Member dengan format anggota yang berbeda tetap menghasilkan kode
        // nasabah format NNNNN-ANG (bukan menyalin kode anggota).
        $member = $this->activeMember(['member_number' => 'ANG-0001']);

        $customer = $this->createCustomerViaHttp($member);

        $this->assertSame('00001-ANG', $customer->customer_number);
        $this->assertNotSame($member->member_number, $customer->customer_number);
    }

    // -----------------------------------------------------------------
    // I. Uniqueness dijaga database
    // -----------------------------------------------------------------

    public function test_duplicate_customer_code_is_rejected_by_database(): void
    {
        $this->createCustomerViaHttp($this->activeMember());

        $this->assertSame('00001-ANG', ShmCustomer::query()->value('customer_number'));

        // Safety net terakhir: index unique menolak duplikat apa pun jalur
        // pembuatannya.
        $this->expectException(QueryException::class);

        ShmCustomer::factory()->create(['customer_number' => '00001-ANG']);
    }

    // -----------------------------------------------------------------
    // Data existing: format legacy dipertahankan, tidak ikut sequence
    // -----------------------------------------------------------------

    public function test_legacy_nsb_codes_are_preserved_and_ignored_by_sequence(): void
    {
        $legacy = ShmCustomer::factory()->create([
            'customer_number' => 'NSB-20261001-DEADBEE',
            'member_id' => null,
        ]);

        $fresh = $this->createCustomerViaHttp($this->activeMember());

        // Sequence format baru dimulai dari 00001 — kode legacy tidak
        // dihitung, tidak di-reset, tidak di-overwrite.
        $this->assertSame('00001-ANG', $fresh->customer_number);
        $this->assertSame('NSB-20261001-DEADBEE', $legacy->refresh()->customer_number);
        $this->assertSame(2, ShmCustomer::query()->count());
    }

    // -----------------------------------------------------------------
    // N. Kode & asosiasi anggota immutable saat update
    // -----------------------------------------------------------------

    public function test_client_cannot_change_customer_code_on_update(): void
    {
        $customer = $this->createCustomerViaHttp($this->activeMember());

        $this->actingAs($this->marketing)
            ->put(route('shm.customers.update', $customer->id), [
                'member_id' => $customer->member_id,
                'customer_number' => '00009-ANG',
                'name' => 'Nama Baru',
                'nik' => '3501234567890123',
                'phone' => '081234567890',
                'address' => 'Jl. Contoh No. 1, Cirebon',
            ])
            ->assertSessionHasErrors('customer_number');

        $customer->refresh();

        $this->assertSame('00001-ANG', $customer->customer_number);
        $this->assertSame('Nasabah Uji', $customer->name, 'Update gagal utuh saat kode dikirim klien.');
    }

    public function test_member_relation_cannot_be_changed_on_update(): void
    {
        $member = $this->activeMember();
        $otherMember = $this->activeMember();
        $customer = $this->createCustomerViaHttp($member);

        $this->actingAs($this->marketing)
            ->put(route('shm.customers.update', $customer->id), [
                // Klien mencoba mengganti asosiasi anggota sumber.
                'member_id' => $otherMember->id,
                'name' => 'Nama Baru',
                'nik' => '3501234567890123',
                'phone' => '081234567890',
                'address' => 'Jl. Contoh No. 1, Cirebon',
            ])
            ->assertSessionHasNoErrors();

        $customer->refresh();

        $this->assertSame($member->id, $customer->member_id, 'Asosiasi anggota tidak boleh berubah dari form edit.');
        $this->assertSame('Nama Baru', $customer->name);
        $this->assertSame('00001-ANG', $customer->customer_number);
    }
}
