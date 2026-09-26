<?php

namespace Database\Factories;

use App\Models\CashierSession;
use App\Models\CashTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashTransaction>
 */
class CashTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'transaction_number' => CashTransaction::generateTransactionNumber(CashTransaction::TYPE_INCOME),
            'cashier_session_id' => CashierSession::factory(),
            'type' => CashTransaction::TYPE_INCOME,
            'category' => 'Pendapatan Lain-lain',
            'amount' => 100000,
            'transaction_date' => now()->format('Y-m-d'),
            'transacted_at' => now(),
            'description' => 'Transaksi kas uji',
            'processed_by' => User::factory(),
            'status' => CashTransaction::STATUS_POSTED,
        ];
    }

    public function income(float $amount = 100000): static
    {
        return $this->state([
            'type' => CashTransaction::TYPE_INCOME,
            'category' => 'Pendapatan Lain-lain',
            'amount' => $amount,
        ]);
    }

    public function expense(float $amount = 100000): static
    {
        return $this->state([
            'type' => CashTransaction::TYPE_EXPENSE,
            'category' => 'Biaya Operasional',
            'amount' => $amount,
        ]);
    }

    public function deposit(float $amount = 100000): static
    {
        return $this->state([
            'type' => CashTransaction::TYPE_DEPOSIT,
            'category' => 'Setoran Kasir',
            'amount' => $amount,
        ]);
    }
}
