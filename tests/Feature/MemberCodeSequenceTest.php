<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use App\Services\ShmCustomerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T102-FIX — Kode anggota: format `NNNNN-ANG`, sequence per domain.
 *
 * Mengunci business rule wajib:
 * - Anggota baru pertama format baru `00001-ANG`, lalu berurutan `00002-ANG`.
 * - Sequence anggota (`members_code_seq`) INDEPENDEN dari sequence Nasabah
 *   SHM (`shm_customers_code_seq`) — literal yang sama antar-dua-tabel
 *   diperbolehkan (mis. anggota `00001-ANG` dan nasabah `00001-ANG`).
 * - Uniqueness `members.member_number` dijaga database (unique index)
 *   sebagai safety net mekanisme sequence.
 * - Kode legacy berformat lain (mis. `ANG-0001`) TIDAK di-reset/di-overwrite,
 *   tidak ikut dihitung sequence, dan tetap dapat dicari.
 * - Resolusi tetap berbasis `member_id` (PK) — tidak ada relasi code-based.
 *
 * Konkurensi paralel (proses PHP bersamaan di PostgreSQL) diuji terpisah di
 * ConcurrencyAndFailureTest::test_parallel_member_creations_get_unique_sequential_codes.
 */
class MemberCodeSequenceTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superadmin = User::factory()->superadmin()->create();
    }

    /**
     * Buat anggota lewat jalur HTTP resmi (controller → generator sequence).
     */
    private function createMemberViaHttp(string $name): Member
    {
        $this->actingAs($this->superadmin)->post(route('anggota.store'), [
            'full_name' => $name,
            'joined_at' => '2026-10-15',
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        return Member::query()->latest('id')->firstOrFail();
    }

    public function test_first_new_member_gets_sequence_code_00001_ang(): void
    {
        $member = $this->createMemberViaHttp('Budi Santoso');

        $this->assertSame('00001-ANG', $member->member_number);
    }

    public function test_next_new_member_gets_next_sequence_number(): void
    {
        $first = $this->createMemberViaHttp('Budi Santoso');
        $second = $this->createMemberViaHttp('Andi Wijaya');

        $this->assertSame('00001-ANG', $first->member_number);
        $this->assertSame('00002-ANG', $second->member_number);
        $this->assertNotSame($first->member_number, $second->member_number);
    }

    public function test_member_and_shm_customer_sequences_are_independent(): void
    {
        // Kedua sequence start di 1 — literal yang sama antar-tabel
        // diperbolehkan karena domain/entity berbeda.
        $member = $this->createMemberViaHttp('Budi Santoso');
        $this->assertSame('00001-ANG', $member->member_number);

        $customer = app(ShmCustomerService::class)->create([
            'member_id' => $member->id,
            'name' => 'Nasabah Uji Kemerdekaan',
            'nik' => '3501234567890123',
            'phone' => '081234567890',
            'address' => 'Jl. Contoh No. 1, Cirebon',
        ]);

        $this->assertSame('00001-ANG', $customer->customer_number);

        // Resolusi nasabah → anggota tetap berbasis member_id (PK),
        // bukan kode.
        $this->assertSame($member->id, $customer->member_id);
        $this->assertSame('00001-ANG', $customer->member->member_number);
    }

    public function test_member_number_is_unique_at_database_level(): void
    {
        $this->createMemberViaHttp('Budi Santoso');

        $this->expectException(QueryException::class);

        // Safety net: unique index menolak kode format baru duplikat,
        // termasuk yang disisipkan langsung ke database.
        Member::factory()->create(['member_number' => '00001-ANG']);
    }

    public function test_legacy_member_codes_are_preserved(): void
    {
        $legacy = Member::factory()->create([
            'member_number' => 'ANG-0001',
            'full_name' => 'Anggota Legacy',
        ]);

        $new = $this->createMemberViaHttp('Anggota Baru');

        // Kode legacy tidak di-reset/di-overwrite dan tidak menghalangi
        // format baru (unique index per literal — `ANG-0001` ≠ `00001-ANG`).
        $this->assertSame('ANG-0001', $legacy->fresh()->member_number);
        $this->assertSame('00001-ANG', $new->member_number);
    }

    public function test_member_search_works_for_legacy_and_new_formats(): void
    {
        Member::factory()->create([
            'member_number' => 'ANG-0001',
            'full_name' => 'Legacy Warga',
        ]);
        $new = $this->createMemberViaHttp('Anggota Baru');

        // Pencarian LIKE lama tetap bekerja untuk kode legacy...
        $this->actingAs($this->superadmin)
            ->get('/anggota?search=ANG-0001')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Anggota/Index')
                ->has('members.data', 1)
                ->where('members.data.0.member_number', 'ANG-0001')
            );

        // ...dan untuk kode format baru.
        $this->actingAs($this->superadmin)
            ->get('/anggota?search='.$new->member_number)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Anggota/Index')
                ->has('members.data', 1)
                ->where('members.data.0.member_number', '00001-ANG')
            );
    }
}
