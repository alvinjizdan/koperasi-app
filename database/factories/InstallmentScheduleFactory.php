<?php

namespace Database\Factories;

use App\Models\InstallmentSchedule;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstallmentSchedule>
 */
class InstallmentScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory(),
            'installment_number' => 1,
            'due_date' => now()->addMonth()->format('Y-m-d'),
            'principal_amount' => 500000,
            'interest_amount' => 0,
            'total_amount' => 500000,
            'paid_amount' => 0,
            'remaining_amount' => 500000,
            'status' => 'UPCOMING',
        ];
    }
}
