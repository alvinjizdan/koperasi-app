<?php

namespace Database\Seeders;

use App\Models\Shm\ShmSavingProduct;
use Illuminate\Database\Seeder;

class ShmSavingProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = [
            [
                'code' => 'SHM-SIMP-POKOK',
                'name' => 'Simpanan Pokok SHM',
                'category' => ShmSavingProduct::CATEGORY_PRINCIPAL,
                'description' => 'Simpanan pokok nasabah pembiayaan SHM',
                'is_active' => true,
            ],
            [
                'code' => 'SHM-SIMP-WAJIB',
                'name' => 'Simpanan Wajib SHM',
                'category' => ShmSavingProduct::CATEGORY_MANDATORY,
                'description' => 'Simpanan wajib bulanan nasabah pembiayaan SHM',
                'is_active' => true,
            ],
            [
                'code' => 'SHM-SIMP-SUKARELA',
                'name' => 'Simpanan Sukarela SHM',
                'category' => ShmSavingProduct::CATEGORY_VOLUNTARY,
                'description' => 'Simpanan sukarela nasabah pembiayaan SHM',
                'is_active' => true,
            ],
        ];

        foreach ($products as $data) {
            ShmSavingProduct::query()->updateOrCreate(
                ['code' => $data['code']],
                $data
            );
        }
    }
}
