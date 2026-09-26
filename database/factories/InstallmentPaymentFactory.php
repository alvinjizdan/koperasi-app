<?php

namespace Database\Factories;

use App\Models\InstallmentPayment;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstallmentPayment>
 */
class InstallmentPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payment_number' => InstallmentPayment::generatePaymentNumber(),
            'loan_id' => Loan::factory(),
            'installment_schedule_id' => null,
            'payment_date' => now()->format('Y-m-d'),
            'amount' => 500000,
            'processed_by' => User::factory(),
            'notes' => fake()->optional()->sentence(),
            'status' => 'COMPLETED',
            'idempotency_key' => null,
        ];
    }
}
