<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanDisbursement>
 */
class LoanDisbursementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'disbursement_number' => LoanDisbursement::generateDisbursementNumber(),
            'loan_id' => Loan::factory(),
            'amount' => 5000000,
            'disbursement_date' => now()->format('Y-m-d'),
            'method' => 'CASH',
            'processed_by' => User::factory(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
