<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DecommissionedMikroRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_mikro_operational_route_names_are_not_registered(): void
    {
        $decommissionedNames = [
            'anggota.index',
            'anggota.create',
            'anggota.store',
            'anggota.show',
            'anggota.edit',
            'anggota.update',
            'anggota.deactivate',
            'simpanan.index',
            'simpanan-produk.index',
            'simpanan-rekening.index',
            'simpanan-transaksi.deposit.create',
            'pinjaman.index',
            'pinjaman.create',
            'pinjaman.show',
            'angsuran.index',
            'angsuran.pay.create',
            'kasir.index',
            'kasir.sesi.index',
            'kasir.transaksi.index',
            'akuntansi.coa.index',
            'akuntansi.jurnal.index',
            'akuntansi.buku-besar.index',
            'laporan.index',
            'laporan.anggota',
            'laporan.simpanan',
            'laporan.pinjaman',
            'rekonsiliasi.index',
            'rekonsiliasi.runs',
        ];

        foreach ($decommissionedNames as $name) {
            $this->assertFalse(Route::has($name), "Decommissioned route [{$name}] must not be registered in active router.");
        }
    }

    public function test_direct_url_to_mikro_routes_returns_404_for_guest(): void
    {
        $endpoints = [
            '/anggota',
            '/anggota/create',
            '/simpanan',
            '/simpanan/produk',
            '/simpanan/rekening',
            '/pinjaman',
            '/pinjaman/create',
            '/angsuran',
            '/kasir',
            '/kasir/sesi',
            '/kasir/transaksi',
            '/akuntansi/coa',
            '/akuntansi/jurnal',
            '/laporan',
            '/laporan/anggota',
            '/rekonsiliasi',
        ];

        foreach ($endpoints as $uri) {
            $this->get($uri)->assertNotFound();
        }
    }

    public function test_direct_url_to_mikro_routes_returns_404_even_for_superadmin(): void
    {
        $superadmin = User::factory()->create(['is_active' => true]);
        $superRole = Role::query()->firstOrCreate(
            ['name' => Role::SYSTEM_SUPERADMIN],
            ['display_name' => 'Superadmin']
        );
        $superadmin->roles()->attach($superRole);

        $endpoints = [
            '/anggota',
            '/simpanan',
            '/pinjaman',
            '/angsuran',
            '/kasir',
            '/akuntansi/coa',
            '/akuntansi/jurnal',
            '/laporan',
            '/rekonsiliasi',
        ];

        foreach ($endpoints as $uri) {
            $this->actingAs($superadmin)
                ->get($uri)
                ->assertNotFound();
        }
    }

    public function test_direct_url_to_mikro_routes_returns_404_for_legacy_role_holder(): void
    {
        $legacyUser = User::factory()->create(['is_active' => true]);
        $legacyRole = Role::query()->firstOrCreate(
            ['name' => 'teller'],
            ['display_name' => 'Teller Kasir']
        );
        $legacyUser->roles()->attach($legacyRole);

        $endpoints = [
            '/anggota',
            '/simpanan',
            '/pinjaman',
            '/kasir',
            '/kasir/transaksi',
        ];

        foreach ($endpoints as $uri) {
            $this->actingAs($legacyUser)
                ->get($uri)
                ->assertNotFound();
        }
    }

    public function test_shm_makro_routes_remain_registered_and_accessible(): void
    {
        $makroRoutes = [
            'shm.dashboard',
            'shm.customers.index',
            'shm.customers.create',
            'shm.loans.index',
            'shm.loans.pengajuan',
            'shm.loans.pelunasan.index',
            'shm.loans.refunds.index',
            'shm.cash-inflow.index',
            'shm.cash-outflow.index',
            'shm.accounting-import.index',
            'shm.daily-journal.index',
        ];

        foreach ($makroRoutes as $name) {
            $this->assertTrue(Route::has($name), "SHM Makro route [{$name}] must remain active and registered.");
        }
    }
}
