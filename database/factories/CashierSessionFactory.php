<?php

namespace Database\Factories;

use App\Models\CashierSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashierSession>
 */
class CashierSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'session_number' => CashierSession::generateSessionNumber(),
            'operational_date' => now()->format('Y-m-d'),
            'opened_by' => User::factory(),
            'opening_balance' => 500000,
            'opening_notes' => null,
            'status' => CashierSession::STATUS_OPEN,
            'opened_at' => now(),
            'total_income' => 0,
            'total_expense' => 0,
            'total_deposit' => 0,
        ];
    }

    /**
     * Sesi kasir yang sudah ditutup beserta snapshot saldo akhirnya.
     */
    public function closed(): static
    {
        return $this->state(function (array $attributes) {
            $openingBalance = (float) ($attributes['opening_balance'] ?? 500000);

            return [
                'status' => CashierSession::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => User::factory(),
                'system_closing_balance' => $openingBalance,
                'physical_closing_balance' => $openingBalance,
                'difference' => 0,
            ];
        });
    }

    public function withOpeningBalance(float $amount): static
    {
        return $this->state(['opening_balance' => $amount]);
    }
}
