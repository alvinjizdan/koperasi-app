<?php

namespace Database\Seeders\Demo;

use App\Models\Member;
use App\Models\ShmCustomer;
use Illuminate\Database\Seeder;

/**
 * T011 — Demo SHM Customer Seeder.
 *
 * Mengaitkan 10 anggota koperasi menjadi nasabah resmi SHM dengan nomor nasabah berformat
 * mandiri NNNNN-ANG (misal: 00201-ANG s.d. 00210-ANG) independen dari member_number.
 */
class DemoShmCustomerSeeder extends Seeder
{
    public function run(): void
    {
        $members = Member::query()->orderBy('id')->take(10)->get();

        foreach ($members as $idx => $member) {
            $customerNumber = sprintf('%05d-ANG', $idx + 201); // 00201-ANG, dst.

            ShmCustomer::query()->updateOrCreate(
                ['member_id' => $member->id],
                [
                    'customer_number' => $customerNumber,
                    'name' => $member->full_name ?? ('Nasabah Demo '.($idx + 1)),
                    'nik' => $member->identity_number ?? sprintf('327101000000%04d', $idx + 1),
                    'phone' => $member->phone ?? '081234567899',
                    'email' => 'customer.'.($idx + 1).'@ksp.demo',
                    'address' => $member->address ?? 'Jl. Pajajaran No. 10, Bogor',
                    'is_active' => true,
                ]
            );
        }
    }
}
