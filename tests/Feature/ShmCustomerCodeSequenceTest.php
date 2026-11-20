<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\User;
use Database\Seeders\MakroPhysicalRoleSeeder;
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
        $this->seed(MakroPhysicalRoleSeeder::class);

        $this->marketing = $this->userWithRole('marketing_makro');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /**
     * Buat nasabah lewat jalur HTTP resmi (service → generator sequence).
     */
    private function createCustomerViaHttp(?int $memberId = null, string $name = 'Nasabah Uji'): ShmCustomer
    {
        $payload = [
            'name' => $name,
            'nik' => '3501234567890123',
            'phone' => '081234567890',
            'address' => 'Jl. Contoh No. 1, Cirebon',
        ];
        if ($memberId !== null) {
            $payload['member_id'] = $memberId;
        }

        $this->actingAs($this->marketing)
            ->post(route('shm.customers.store'), $payload)
            ->assertSessionHasNoErrors();

        return ShmCustomer::query()->latest('id')->firstOrFail();
    }

    // -----------------------------------------------------------------
    // G. Urutan kode: 00001-ANG pertama, lalu berurutan
    // -----------------------------------------------------------------

    public function test_first_new_customer_gets_00001_then_increments(): void
    {
        $first = $this->createCustomerViaHttp(null, 'Budi');
        $second = $this->createCustomerViaHttp(null, 'Andi');
        $third = $this->createCustomerViaHttp(null, 'Citra');

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
        $customerA = $this->createCustomerViaHttp(null, 'Budi');
        $customerB = $this->createCustomerViaHttp(null, 'Citra');

        // Domain Nasabah SHM memakai sequence SENDIRI dan dimulai dari 00001
        $this->assertSame('00001-ANG', $customerA->customer_number);
        $this->assertSame('00002-ANG', $customerB->customer_number);
    }

    public function test_member_code_is_never_used_as_customer_code(): void
    {
        $customer = $this->createCustomerViaHttp(null);

        $this->assertSame('00001-ANG', $customer->customer_number);
    }

    // -----------------------------------------------------------------
    // I. Uniqueness dijaga database
    // -----------------------------------------------------------------

    public function test_duplicate_customer_code_is_rejected_by_database(): void
    {
        $this->createCustomerViaHttp(null);

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

        $fresh = $this->createCustomerViaHttp(null);

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
        $customer = $this->createCustomerViaHttp(null);

        $this->actingAs($this->marketing)
            ->put(route('shm.customers.update', $customer->id), [
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
        $customer = $this->createCustomerViaHttp(null);

        $this->actingAs($this->marketing)
            ->put(route('shm.customers.update', $customer->id), [
                'name' => 'Nama Baru',
                'nik' => '3501234567890123',
                'phone' => '081234567890',
                'address' => 'Jl. Contoh No. 1, Cirebon',
            ])
            ->assertSessionHasNoErrors();

        $customer->refresh();

        $this->assertNull($customer->member_id);
        $this->assertSame('Nama Baru', $customer->name);
        $this->assertSame('00001-ANG', $customer->customer_number);
    }
}
