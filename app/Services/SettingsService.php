<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan aplikasi: profil koperasi dan operasional dasar.
 *
 * Nilai default diambil dari config/settings.php. Nilai pada tabel settings
 * selalu menimpa default; mengosongkan nilai mengembalikan ke default.
 */
class SettingsService
{
    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $stored = Setting::query()->pluck('value', 'key');
        $values = [];

        foreach ($this->defaults() as $key => $default) {
            $value = $stored->get($key);

            $values[$key] = ($value === null || $value === '')
                ? $default
                : $value;
        }

        return $values;
    }

    public function get(string $key): string
    {
        $value = Setting::query()->where('key', $key)->value('value');

        if ($value === null || $value === '') {
            return (string) ($this->defaults()[$key] ?? '');
        }

        return $value;
    }

    /**
     * Simpan beberapa pengaturan sekaligus dalam satu transaksi dan kembalikan
     * daftar key yang benar-benar berubah nilainya.
     *
     * @param  array<string, string|null>  $values
     * @return array<string, array{old: string, new: string}>
     */
    public function updateMany(array $values): array
    {
        $defaults = $this->defaults();
        $changes = [];

        DB::transaction(function () use ($values, $defaults, &$changes) {
            foreach ($values as $key => $value) {
                if (! array_key_exists($key, $defaults)) {
                    continue;
                }

                $newValue = $value === null ? '' : trim($value);
                $oldValue = $this->get($key);

                if ($oldValue === $newValue) {
                    continue;
                }

                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    [
                        'value' => $newValue,
                        'group' => $this->groupFor($key),
                    ]
                );

                $changes[$key] = ['old' => $oldValue, 'new' => $newValue];
            }
        });

        return $changes;
    }

    /**
     * Definisi seluruh kunci pengaturan beserta nilai defaultnya.
     *
     * @return array<string, string>
     */
    public function defaults(): array
    {
        return config('settings.keys', []);
    }

    /**
     * Metadata tampilan untuk setiap kunci (label, tipe input, grup).
     *
     * @return array<string, mixed>
     */
    public function definitions(): array
    {
        return config('settings.definitions', []);
    }

    private function groupFor(string $key): string
    {
        return (string) ($this->definitions()[$key]['group'] ?? 'general');
    }
}
