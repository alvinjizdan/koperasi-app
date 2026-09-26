<?php

namespace Tests\Feature;

use App\Models\InstallmentPayment;
use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanAndInstallmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Superadmin untuk melewati permission middleware pada route pinjaman.
        $this->user = User::factory()->superadmin()->create();
    }

    /** 1. Pengajuan pinjaman berhasil */
    public function test_can_submit_loan_application(): void
    {
        $member = Member::factory()->create(['status' => 'active']);

        $response = $this->actingAs($this->user)
            ->post(route('pinjaman.store'), [
                'member_id' => $member->id,
                'amount' => 5000000,
                'tenor_months' => 12,
                'submission_date' => now()->format('Y-m-d'),
                'notes' => 'Pinjaman modal usaha',
            ]);

        $loan = Loan::where('member_id', $member->id)->first();

        $response->assertRedirect(route('pinjaman.show', $loan->id));
        $this->assertNotNull($loan);
        $this->assertEquals('PENDING', $loan->status);
        $this->assertEquals(5000000.00, (float) $loan->amount);
        $this->assertEquals(12, $loan->tenor_months);
    }

    /** 2. Pengajuan dengan anggota tidak valid ditolak */
    public function test_loan_submission_with_invalid_member_is_rejected(): void
    {
        $inactiveMember = Member::factory()->inactive()->create();

        // Anggota nonaktif
        $response1 = $this->actingAs($this->user)
            ->post(route('pinjaman.store'), [
                'member_id' => $inactiveMember->id,
                'amount' => 5000000,
                'tenor_months' => 12,
                'submission_date' => now()->format('Y-m-d'),
            ]);
        $response1->assertSessionHasErrors(['member_id']);

        // ID Anggota tidak ditemukan
        $response2 = $this->actingAs($this->user)
            ->post(route('pinjaman.store'), [
                'member_id' => 99999,
                'amount' => 5000000,
                'tenor_months' => 12,
                'submission_date' => now()->format('Y-m-d'),
            ]);
        $response2->assertSessionHasErrors(['member_id']);
    }

    /** 3. Nominal atau tenor tidak valid ditolak */
    public function test_loan_submission_with_invalid_amount_or_tenor_is_rejected(): void
    {
        $member = Member::factory()->create(['status' => 'active']);

        // Nominal <= 0
        $response1 = $this->actingAs($this->user)
            ->post(route('pinjaman.store'), [
                'member_id' => $member->id,
                'amount' => 0,
                'tenor_months' => 12,
                'submission_date' => now()->format('Y-m-d'),
            ]);
        $response1->assertSessionHasErrors(['amount']);

        // Tenor 0
        $response2 = $this->actingAs($this->user)
            ->post(route('pinjaman.store'), [
                'member_id' => $member->id,
                'amount' => 5000000,
                'tenor_months' => 0,
                'submission_date' => now()->format('Y-m-d'),
            ]);
        $response2->assertSessionHasErrors(['tenor_months']);
    }

    /** 4. Persetujuan dan penolakan hanya dapat dilakukan untuk pinjaman PENDING */
    public function test_loan_approval_and_rejection(): void
    {
        // Approve
        $loanToApprove = Loan::factory()->create(['status' => 'PENDING']);
        $responseApprove = $this->actingAs($this->user)
            ->post(route('pinjaman.approve', $loanToApprove->id));

        $responseApprove->assertRedirect(route('pinjaman.show', $loanToApprove->id));
        $this->assertEquals('APPROVED', $loanToApprove->fresh()->status);
        $this->assertEquals($this->user->id, $loanToApprove->fresh()->approved_by);

        // Reject
        $loanToReject = Loan::factory()->create(['status' => 'PENDING']);
        $responseReject = $this->actingAs($this->user)
            ->post(route('pinjaman.reject', $loanToReject->id), [
                'rejection_reason' => 'Syarat dokumen tidak lengkap.',
            ]);

        $responseReject->assertRedirect(route('pinjaman.show', $loanToReject->id));
        $this->assertEquals('REJECTED', $loanToReject->fresh()->status);
        $this->assertEquals('Syarat dokumen tidak lengkap.', $loanToReject->fresh()->rejection_reason);
    }

    /** 5. Pinjaman yang belum disetujui tidak dapat dicairkan */
    public function test_unapproved_loan_cannot_be_disbursed(): void
    {
        $pendingLoan = Loan::factory()->create(['status' => 'PENDING']);

        $response = $this->actingAs($this->user)
            ->post(route('pinjaman.disburse.store', $pendingLoan->id), [
                'disbursement_date' => now()->format('Y-m-d'),
                'first_due_date' => now()->addMonth()->format('Y-m-d'),
                'method' => 'CASH',
            ]);

        $response->assertSessionHasErrors(['status']);
        $this->assertEquals('PENDING', $pendingLoan->fresh()->status);
    }

    /** 6. Pencairan berhasil dan tidak dapat dilakukan dua kali */
    public function test_loan_disbursement_success_and_prevents_duplicate(): void
    {
        $approvedLoan = Loan::factory()->approved()->create(['amount' => 6000000, 'tenor_months' => 6]);

        // Pencairan ke-1
        $response = $this->actingAs($this->user)
            ->post(route('pinjaman.disburse.store', $approvedLoan->id), [
                'disbursement_date' => now()->format('Y-m-d'),
                'first_due_date' => now()->addMonth()->format('Y-m-d'),
                'method' => 'CASH',
            ]);

        $response->assertRedirect(route('pinjaman.show', $approvedLoan->id));
        $this->assertEquals('ACTIVE', $approvedLoan->fresh()->status);
        $this->assertDatabaseHas('loan_disbursements', ['loan_id' => $approvedLoan->id]);

        // Pencairan ke-2 (Duplicate disbursement attempt)
        $response2 = $this->actingAs($this->user)
            ->post(route('pinjaman.disburse.store', $approvedLoan->id), [
                'disbursement_date' => now()->format('Y-m-d'),
                'first_due_date' => now()->addMonth()->format('Y-m-d'),
                'method' => 'CASH',
            ]);

        $response2->assertSessionHasErrors(['status']);
    }

    /** 7. Jadwal angsuran tersimpan dan terhubung dengan pinjaman */
    public function test_installment_schedules_are_generated_on_disbursement(): void
    {
        $approvedLoan = Loan::factory()->approved()->create(['amount' => 12000000, 'tenor_months' => 12]);

        $this->actingAs($this->user)
            ->post(route('pinjaman.disburse.store', $approvedLoan->id), [
                'disbursement_date' => now()->format('Y-m-d'),
                'first_due_date' => now()->addMonth()->format('Y-m-d'),
                'method' => 'CASH',
            ]);

        $schedules = $approvedLoan->schedules;
        $this->assertCount(12, $schedules);
        $this->assertEquals(1000000.00, (float) $schedules->first()->total_amount);
        $this->assertEquals('UPCOMING', $schedules->first()->status);
    }

    /** 8. Pembayaran angsuran berhasil dicatat */
    public function test_installment_payment_success(): void
    {
        $loan = Loan::factory()->approved()->create(['amount' => 6000000, 'tenor_months' => 6]);
        $this->actingAs($this->user)->post(route('pinjaman.disburse.store', $loan->id), [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'CASH',
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('angsuran.pay.store', $loan->id), [
                'amount' => 1000000,
                'payment_date' => now()->format('Y-m-d'),
                'notes' => 'Pembayaran angsuran 1',
            ]);

        $response->assertRedirect(route('angsuran.schedule', $loan->id));
        $this->assertDatabaseHas('installment_payments', [
            'loan_id' => $loan->id,
            'amount' => 1000000.00,
            'processed_by' => $this->user->id,
        ]);
        $this->assertEquals(5000000.00, $loan->fresh()->remaining_balance);
        $this->assertEquals('PAID', $loan->fresh()->schedules->first()->status);
    }

    /** 9. Pembayaran dengan nominal tidak valid ditolak */
    public function test_invalid_payment_amount_is_rejected(): void
    {
        $loan = Loan::factory()->approved()->create(['amount' => 3000000, 'tenor_months' => 3]);
        $this->actingAs($this->user)->post(route('pinjaman.disburse.store', $loan->id), [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'CASH',
        ]);

        // Nominal <= 0
        $response1 = $this->actingAs($this->user)
            ->post(route('angsuran.pay.store', $loan->id), [
                'amount' => 0,
                'payment_date' => now()->format('Y-m-d'),
            ]);
        $response1->assertSessionHasErrors(['amount']);

        // Nominal melebihi sisa kewajiban
        $response2 = $this->actingAs($this->user)
            ->post(route('angsuran.pay.store', $loan->id), [
                'amount' => 5000000,
                'payment_date' => now()->format('Y-m-d'),
            ]);
        $response2->assertSessionHasErrors(['amount']);
    }

    /** 10. Request pembayaran berulang tidak menghasilkan transaksi ganda */
    public function test_duplicate_payment_with_same_idempotency_key_is_ignored(): void
    {
        $loan = Loan::factory()->approved()->create(['amount' => 6000000, 'tenor_months' => 6]);
        $this->actingAs($this->user)->post(route('pinjaman.disburse.store', $loan->id), [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'CASH',
        ]);

        $key = 'idempotency-key-payment-999';

        // Payment 1
        $this->actingAs($this->user)
            ->post(route('angsuran.pay.store', $loan->id), [
                'amount' => 1000000,
                'payment_date' => now()->format('Y-m-d'),
                'idempotency_key' => $key,
            ]);

        // Payment 2 (Duplicate)
        $this->actingAs($this->user)
            ->post(route('angsuran.pay.store', $loan->id), [
                'amount' => 1000000,
                'payment_date' => now()->format('Y-m-d'),
                'idempotency_key' => $key,
            ]);

        $this->assertEquals(1, InstallmentPayment::where('idempotency_key', $key)->count());
        $this->assertEquals(5000000.00, $loan->fresh()->remaining_balance);
    }

    /** 11. Transaksi gagal tidak mengubah saldo atau status secara parsial */
    public function test_failed_payment_does_not_mutate_balance_or_status(): void
    {
        $loan = Loan::factory()->approved()->create(['amount' => 2000000, 'tenor_months' => 2]);
        $this->actingAs($this->user)->post(route('pinjaman.disburse.store', $loan->id), [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'CASH',
        ]);

        // Attempt invalid payment
        $this->actingAs($this->user)
            ->post(route('angsuran.pay.store', $loan->id), [
                'amount' => 9999999,
                'payment_date' => now()->format('Y-m-d'),
            ]);

        $this->assertEquals(2000000.00, $loan->fresh()->remaining_balance);
        $this->assertEquals('ACTIVE', $loan->fresh()->status);
    }

    /** 12. Riwayat pembayaran konsisten dengan data transaksi */
    public function test_payment_history_is_consistent(): void
    {
        $loan = Loan::factory()->approved()->create(['amount' => 3000000, 'tenor_months' => 3]);
        $this->actingAs($this->user)->post(route('pinjaman.disburse.store', $loan->id), [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'CASH',
        ]);

        // Bayar 1.000.000
        $this->actingAs($this->user)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => 1000000,
            'payment_date' => now()->format('Y-m-d'),
        ]);

        // Bayar 1.000.000
        $this->actingAs($this->user)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => 1000000,
            'payment_date' => now()->format('Y-m-d'),
        ]);

        $totalPaid = $loan->fresh()->total_paid;
        $this->assertEquals(2000000.00, $totalPaid);
        $this->assertEquals(1000000.00, $loan->fresh()->remaining_balance);
    }

    /** 13. Pinjaman lunas hanya ditetapkan ketika seluruh kewajiban yang sah terpenuhi */
    public function test_loan_marked_paid_off_only_when_fully_paid(): void
    {
        $loan = Loan::factory()->approved()->create(['amount' => 2000000, 'tenor_months' => 2]);
        $this->actingAs($this->user)->post(route('pinjaman.disburse.store', $loan->id), [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'CASH',
        ]);

        // Pelunasan bertahap 1 (belum lunas)
        $this->actingAs($this->user)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => 1000000,
            'payment_date' => now()->format('Y-m-d'),
        ]);
        $this->assertEquals('ACTIVE', $loan->fresh()->status);

        // Pelunasan bertahap 2 (lunas penuh)
        $this->actingAs($this->user)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => 1000000,
            'payment_date' => now()->format('Y-m-d'),
        ]);
        $this->assertEquals('PAID_OFF', $loan->fresh()->status);
        $this->assertEquals(0.00, $loan->fresh()->remaining_balance);
    }

    /** 14. Pengguna tanpa izin (unauthenticated) tidak dapat mengakses proses sensitif */
    public function test_unauthenticated_users_cannot_access_loan_actions(): void
    {
        $loan = Loan::factory()->create(['status' => 'PENDING']);

        $response1 = $this->get(route('pinjaman.index'));
        $response1->assertRedirect(route('login'));

        $response2 = $this->post(route('pinjaman.approve', $loan->id));
        $response2->assertRedirect(route('login'));

        $response3 = $this->post(route('angsuran.pay.store', $loan->id), [
            'amount' => 1000000,
            'payment_date' => now()->format('Y-m-d'),
        ]);
        $response3->assertRedirect(route('login'));
    }
}
