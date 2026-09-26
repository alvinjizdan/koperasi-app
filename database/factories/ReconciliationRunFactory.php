<?php

namespace Database\Factories;

use App\Models\ReconciliationRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReconciliationRun>
 */
class ReconciliationRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'run_number' => ReconciliationRun::generateRunNumber(),
            'period_start' => now()->startOfMonth()->format('Y-m-d'),
            'period_end' => now()->format('Y-m-d'),
            'modules' => ['savings'],
            'run_by' => User::factory(),
            'status' => ReconciliationRun::STATUS_COMPLETED,
            'checked_rows' => 0,
            'findings_count' => 0,
            'summary' => null,
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
