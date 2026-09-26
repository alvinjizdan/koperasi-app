<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Loan>
 */
class LoanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'loan_number' => Loan::generateLoanNumber(),
            'member_id' => Member::factory(),
            'amount' => fake()->numberBetween(1000000, 10000000),
            'tenor_months' => fake()->randomElement([6, 12, 18, 24]),
            'submission_date' => now()->format('Y-m-d'),
            'status' => 'PENDING',
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }

    public function approved(): static
    {
        return $this->state([
            'status' => 'APPROVED',
            'approved_at' => now()->format('Y-m-d'),
            'approved_by' => User::factory(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state([
            'status' => 'REJECTED',
            'rejected_at' => now()->format('Y-m-d'),
            'rejected_by' => User::factory(),
            'rejection_reason' => 'Persyaratan belum lengkap.',
        ]);
    }

    public function disbursed(): static
    {
        return $this->state([
            'status' => 'DISBURSED',
            'approved_at' => now()->subDays(2)->format('Y-m-d'),
            'approved_by' => User::factory(),
            'disbursed_at' => now()->format('Y-m-d'),
            'disbursed_by' => User::factory(),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
    }

    public function active(): static
    {
        return $this->state([
            'status' => 'ACTIVE',
            'approved_at' => now()->subDays(5)->format('Y-m-d'),
            'approved_by' => User::factory(),
            'disbursed_at' => now()->subDays(2)->format('Y-m-d'),
            'disbursed_by' => User::factory(),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
    }
}
