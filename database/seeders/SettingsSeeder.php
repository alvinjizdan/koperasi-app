<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Menyemai nilai default pengaturan ke tabel settings.
 * Nilai yang sudah ada tidak pernah ditimpa ulang.
 */
class SettingsSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $service = app(SettingsService::class);

        foreach ($service->defaults() as $key => $value) {
            Setting::query()->firstOrCreate(
                ['key' => $key],
                [
                    'value' => $value,
                    'group' => $service->definitions()[$key]['group'] ?? 'general',
                ]
            );
        }
    }
}
