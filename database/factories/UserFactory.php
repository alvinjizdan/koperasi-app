<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Akun dalam status nonaktif (tidak dapat login).
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Akun dengan role Superadmin (akses penuh, untuk test modul lain).
     */
    public function superadmin(): static
    {
        return $this->afterCreating(function (User $user) {
            $role = Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->first();

            if ($role === null) {
                $role = Role::query()->create([
                    'name' => Role::SYSTEM_SUPERADMIN,
                    'display_name' => 'Superadmin',
                    'description' => 'Akses penuh ke seluruh modul dan pengaturan sistem.',
                    'is_system' => true,
                ]);
            }

            $user->roles()->syncWithoutDetaching([$role->id]);
        });
    }
}
