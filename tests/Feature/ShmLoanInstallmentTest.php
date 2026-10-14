<?php

namespace Tests\Feature;

use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanProduct;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T74 — Tests for SHM Loan Installment Data Model & Contract.
 */
class ShmLoanInstallmentTest extends TestCase
{
    use RefreshDatabase;

    private function createLoan(): ShmLoan
    {
        $customer = ShmCustomer::factory()->create();
        $product = ShmLoanProduct::factory()->create();

        return ShmLoan::query()->create([
            'loan_number' => 'L-SHM-'.uniqid(),
            'shm_customer_id' => $customer->id,
            'shm_loan_product_id' => $product->id,
            'principal' => '100000000.00',
            'tenor_months' => 12,
            'rate_percent' => '2.0000',
            'status' => ShmLoan::STATUS_ACTIVE,
        ]);
    }

    public function test_ac01_installment_belongs_to_valid_shm_loan(): void
    {
        $loan = $this->createLoan();

        $installment = ShmLoanInstallment::query()->create([
            'shm_loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => '2026-11-01',
            'principal_amount' => '8333333.33',
            'interest_amount' => '2000000.00',
            'total_amount' => '10333333.33',
            'paid_amount' => '0.00',
            'remaining_amount' => '10333333.33',
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        $this->assertInstanceOf(ShmLoan::class, $installment->loan);
        $this->assertSame($loan->id, $installment->loan->id);
    }

    public function test_ac02_loan_can_have_multiple_installments(): void
    {
        $loan = $this->createLoan();

        ShmLoanInstallment::query()->create([
            'shm_loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => '2026-11-01',
            'principal_amount' => '5000000.00',
            'interest_amount' => '1000000.00',
            'total_amount' => '6000000.00',
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        ShmLoanInstallment::query()->create([
            'shm_loan_id' => $loan->id,
            'installment_number' => 2,
            'due_date' => '2026-12-01',
            'principal_amount' => '5000000.00',
            'interest_amount' => '1000000.00',
            'total_amount' => '6000000.00',
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        $this->assertCount(2, $loan->installments);
        $this->assertSame(1, $loan->installments->first()->installment_number);
        $this->assertSame(2, $loan->installments->last()->installment_number);
    }

    public function test_ac03_installment_sequence_uniqueness_per_loan_is_enforced(): void
    {
        $loan = $this->createLoan();

        ShmLoanInstallment::query()->create([
            'shm_loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => '2026-11-01',
            'principal_amount' => '5000000.00',
            'interest_amount' => '1000000.00',
            'total_amount' => '6000000.00',
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        $this->expectException(QueryException::class);

        ShmLoanInstallment::query()->create([
            'shm_loan_id' => $loan->id,
            'installment_number' => 1, // Duplicate installment number for the same loan
            'due_date' => '2026-11-02',
            'principal_amount' => '5000000.00',
            'interest_amount' => '1000000.00',
            'total_amount' => '6000000.00',
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);
    }

    public function test_ac04_financial_fields_precision_and_types(): void
    {
        $loan = $this->createLoan();

        $installment = ShmLoanInstallment::query()->create([
            'shm_loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => '2026-11-01',
            'principal_amount' => '1234567.89',
            'interest_amount' => '987654.32',
            'total_amount' => '2222222.21',
            'paid_amount' => '500000.00',
            'remaining_amount' => '1722222.21',
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        $fresh = $installment->fresh();
        $this->assertSame('1234567.89', $fresh->principal_amount);
        $this->assertSame('987654.32', $fresh->interest_amount);
        $this->assertSame('2222222.21', $fresh->total_amount);
        $this->assertSame('500000.00', $fresh->paid_amount);
        $this->assertSame('1722222.21', $fresh->remaining_amount);
    }

    public function test_ac09_no_cooperative_member_relationship_introduced(): void
    {
        $installment = new ShmLoanInstallment;
        $this->assertFalse(method_exists($installment, 'member'));
        $this->assertFalse(method_exists($installment, 'cooperativeLoan'));
    }
}
