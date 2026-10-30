<?php

namespace Database\Seeders\Demo;

use App\Models\Member;
use App\Models\SavingProduct;
use App\Models\SavingsAccount;
use Illuminate\Database\Seeder;

/**
 * T011 — Demo Member & Savings Seeder.
 *
 * Menyiapkan master produk simpanan resmi (Pokok, Wajib, Sukarela) dan 12 anggota koperasi
 * representatif yang realistis dengan rekening simpanan.
 */
class DemoMemberSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Pastikan Produk Simpanan Koperasi Lengkap
        $spPokok = SavingProduct::query()->firstOrCreate(
            ['category' => SavingProduct::CATEGORY_PRINCIPAL],
            [
                'code' => 'SP-POKOK',
                'name' => 'Simpanan Pokok',
                'description' => 'Simpanan Pokok Anggota KSP Bima Sakti Mandiri',
                'is_active' => true,
            ]
        );

        $spWajib = SavingProduct::query()->firstOrCreate(
            ['category' => SavingProduct::CATEGORY_MANDATORY],
            [
                'code' => 'SW-WAJIB',
                'name' => 'Simpanan Wajib',
                'description' => 'Simpanan Wajib Anggota Bulanan',
                'is_active' => true,
            ]
        );

        $spSukarela = SavingProduct::query()->firstOrCreate(
            ['category' => SavingProduct::CATEGORY_VOLUNTARY],
            [
                'code' => 'SS-SUKARELA',
                'name' => 'Simpanan Sukarela',
                'description' => 'Simpanan Sukarela Fleksibel',
                'is_active' => true,
            ]
        );

        // 2. Daftar 12 Anggota Realistis
        $membersData = [
            ['name' => 'Hendro Prasetyo', 'nik' => '3271011504820001', 'phone' => '081234567801'],
            ['name' => 'Ratna Dewi Kusuma', 'nik' => '3271012508850002', 'phone' => '081234567802'],
            ['name' => 'Bambang Sutrisno', 'nik' => '3271011003800003', 'phone' => '081234567803'],
            ['name' => 'Dewi Lestari', 'nik' => '3271012011880004', 'phone' => '081234567804'],
            ['name' => 'Agus Gunawan', 'nik' => '3271010507830005', 'phone' => '081234567805'],
            ['name' => 'Sri Wahyuni', 'nik' => '3271011409870006', 'phone' => '081234567806'],
            ['name' => 'Ahmad Fauzi', 'nik' => '3271011812840007', 'phone' => '081234567807'],
            ['name' => 'Siti Nurhaliza', 'nik' => '3271012201900008', 'phone' => '081234567808'],
            ['name' => 'Eko Prabowo', 'nik' => '3271013006810009', 'phone' => '081234567809'],
            ['name' => 'Nurul Hidayah', 'nik' => '3271011102890010', 'phone' => '081234567810'],
            ['name' => 'Joko Widodo Santoso', 'nik' => '3271010908820011', 'phone' => '081234567811'],
            ['name' => 'Maya Indrawati', 'nik' => '3271011705860012', 'phone' => '081234567812'],
        ];

        foreach ($membersData as $idx => $m) {
            $memberNumber = sprintf('%05d-ANG', $idx + 101); // 00101-ANG, dst.
            $member = Member::query()->firstOrCreate(
                ['identity_number' => $m['nik']],
                [
                    'member_number' => $memberNumber,
                    'full_name' => $m['name'],
                    'phone' => $m['phone'],
                    'status' => 'active',
                    'joined_at' => now()->subMonths(6)->toDateString(),
                    'address' => 'Jl. Merdeka No. '.($idx + 1).', Bogor, Jawa Barat',
                ]
            );

            // Buka rekening Simpanan Pokok & Wajib awal bila belum ada
            SavingsAccount::query()->firstOrCreate(
                ['member_id' => $member->id, 'saving_product_id' => $spPokok->id],
                [
                    'account_number' => 'SA-POKOK-'.str_pad((string) $member->id, 6, '0', STR_PAD_LEFT),
                    'opened_at' => now()->subMonths(6)->toDateString(),
                    'status' => 'active',
                    'balance' => 100_000,
                ]
            );

            SavingsAccount::query()->firstOrCreate(
                ['member_id' => $member->id, 'saving_product_id' => $spWajib->id],
                [
                    'account_number' => 'SA-WAJIB-'.str_pad((string) $member->id, 6, '0', STR_PAD_LEFT),
                    'opened_at' => now()->subMonths(6)->toDateString(),
                    'status' => 'active',
                    'balance' => 150_000,
                ]
            );
        }
    }
}
