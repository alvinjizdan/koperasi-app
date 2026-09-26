<?php

namespace Database\Factories;

use App\Models\ReconciliationFinding;
use App\Models\ReconciliationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReconciliationFinding>
 */
class ReconciliationFindingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reconciliation_run_id' => ReconciliationRun::factory(),
            'finding_type' => ReconciliationFinding::TYPE_BALANCE_MISMATCH,
            'module' => 'savings',
            'object_type' => 'App\\Models\\SavingsAccount',
            'object_id' => 1,
            'object_label' => 'REK-TEST-000001',
            'expected_value' => 100000,
            'actual_value' => 90000,
            'variance' => -10000,
            'priority' => ReconciliationFinding::PRIORITY_MEDIUM,
            'status' => ReconciliationFinding::STATUS_OPEN,
            'found_at' => now(),
        ];
    }
}
