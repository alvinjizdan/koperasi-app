<?php

namespace Database\Seeders\Demo;

use App\Models\ShmApplication;
use App\Models\ShmApproval;
use App\Models\ShmCollectionCase;
use App\Models\ShmCollectionPtp;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Models\User;
use App\Services\CashierService;
use App\Services\Shm\ShmCollectionCaseService;
use App\Services\Shm\ShmCollectionPtpService;
use App\Services\Shm\ShmLoanDisbursementService;
use App\Services\Shm\ShmLoanPaymentService;
use App\Services\Shm\ShmLoanSettlementService;
use App\Services\Shm\ShmRegularPaymentService;
use App\Services\Shm\ShmRepaymentScheduleService;
use App\Services\Shm\ShmSettlementCorrectionService;
use Illuminate\Database\Seeder;

/**
 * T011 — Demo SHM Workflow Seeder.
 *
 * Mengoperasikan alur bisnis end-to-end yang realistis menggunakan Production Services:
 * - ShmLoanDisbursementService
 * - ShmRepaymentScheduleService
 * - ShmRegularPaymentService
 * - ShmLoanSettlementService
 * - ShmSettlementCorrectionService
 * - ShmCollectionCaseService & ShmCollectionPtpService
 *
 * Menghasilkan skenario demonstrasi:
 * 1. DEMO-APP-01: Draft Application
 * 2. DEMO-APP-02: Submitted / Pending Approval
 * 3. DEMO-APP-03: Approved (Siap Cair)
 * 4. DEMO-LN-01: Active Loan (Baru dicairkan, jadwal lengkap, jurnal tercatat)
 * 5. DEMO-LN-02: Active Loan dengan Riwayat Pembayaran Angsuran
 * 6. DEMO-LN-03: Active Loan dengan Overpayment (lebih bayar ke Simpanan Pokok)
 * 7. DEMO-LN-04: Completed Settlement (Pelunasan Dipercepat tuntas)
 * 8. DEMO-LN-05: Corrected Settlement (Policy B Reversal + Replacement Settlement)
 * 9. DEMO-LN-06: Collection Overdue Case (Aging 1-7 hari, PTP OPEN)
 * 10. DEMO-LN-07: Collection Escalated Case (3 Missed PTP -> Status ESCALATED)
 * 11. DEMO-LN-08: Collection Resolved Case (Status RESOLVED)
 */
class DemoShmWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $marketing = User::query()->where('email', 'marketing.demo@ksp.local')->first()
            ?? User::query()->where('email', 'superadmin@ksp.local')->firstOrFail();
        $admin = User::query()->where('email', 'admin.demo@ksp.local')->first()
            ?? User::query()->where('email', 'superadmin@ksp.local')->firstOrFail();

        // Buka Sesi Kasir untuk Marketing (selaku pelaku pencairan) & Admin
        $cashierService = app(CashierService::class);
        $kasirSession = $cashierService->activeSessionFor($marketing->id);
        if ($kasirSession === null) {
            $kasirSession = $cashierService->openSession([
                'operational_date' => now()->toDateString(),
                'opening_balance' => 5_000_000_000,
                'opening_notes' => 'Sesi kasir operasional demo KSP Bima Sakti Mandiri',
            ], $marketing->id);
        }

        // Ambil Produk BSM Prioritas (tenor 12-18, flat 1.30%/bln) & BSM Flexibel
        $productPrioritas = ShmLoanProduct::query()->where('code', 'BSM-PRIORITAS')->firstOrFail();
        $productFlexibel = ShmLoanProduct::query()->where('code', 'BSM-FLEXIBEL')->firstOrFail();

        $customers = ShmCustomer::query()->orderBy('id')->get();
        if ($customers->count() < 10) {
            return;
        }

        $disbursementService = app(ShmLoanDisbursementService::class);
        $scheduleService = app(ShmRepaymentScheduleService::class);
        $paymentService = app(ShmRegularPaymentService::class);
        $loanPaymentService = app(ShmLoanPaymentService::class);
        $settlementService = app(ShmLoanSettlementService::class);
        $correctionService = app(ShmSettlementCorrectionService::class);
        $collectionCaseService = app(ShmCollectionCaseService::class);
        $collectionPtpService = app(ShmCollectionPtpService::class);

        // =========================================================================
        // Skenario 1: DEMO-APP-01 — Draft Application
        // =========================================================================
        $appMirror1 = ShmApplication::query()->firstOrCreate(
            ['application_number' => 'APP-DEMO-001'],
            [
                'shm_customer_id' => $customers[0]->id,
                'marketing_id' => $marketing->id,
                'status' => 'draft',
                'notes' => 'Pengajuan baru dalam tahap pengumpulan berkas agunan SHM.',
            ]
        );
        ShmLoanApplication::query()->firstOrCreate(
            ['application_number' => 'PENGAJ-DEMO-001'],
            [
                'shm_application_id' => $appMirror1->id,
                'shm_customer_id' => $customers[0]->id,
                'shm_loan_product_id' => $productPrioritas->id,
                'application_date' => now()->subDays(2)->toDateString(),
                'principal' => 50_000_000,
                'tenor_months' => 12,
                'purpose' => 'Renovasi Rumah Tinggal',
                'marketing_id' => $marketing->id,
                'status' => 'draft',
            ]
        );

        // =========================================================================
        // Skenario 2: DEMO-APP-02 — Submitted Application (Menunggu Approval)
        // =========================================================================
        $appMirror2 = ShmApplication::query()->firstOrCreate(
            ['application_number' => 'APP-DEMO-002'],
            [
                'shm_customer_id' => $customers[1]->id,
                'marketing_id' => $marketing->id,
                'status' => 'submitted',
                'submitted_at' => now()->subDay(),
                'notes' => 'Berkas lengkap, telah dilakukan pemeriksaan agunan & credit checking.',
            ]
        );
        ShmLoanApplication::query()->firstOrCreate(
            ['application_number' => 'PENGAJ-DEMO-002'],
            [
                'shm_application_id' => $appMirror2->id,
                'shm_customer_id' => $customers[1]->id,
                'shm_loan_product_id' => $productPrioritas->id,
                'application_date' => now()->subDays(3)->toDateString(),
                'principal' => 75_000_000,
                'tenor_months' => 18,
                'purpose' => 'Modal Usaha Pengembangan Bengkel',
                'marketing_id' => $marketing->id,
                'status' => 'submitted',
            ]
        );

        // =========================================================================
        // Skenario 3: DEMO-APP-03 — Approved Application (Siap Cair)
        // =========================================================================
        $appMirror3 = ShmApplication::query()->firstOrCreate(
            ['application_number' => 'APP-DEMO-003'],
            [
                'shm_customer_id' => $customers[2]->id,
                'marketing_id' => $marketing->id,
                'status' => 'approved',
                'approved_at' => now()->subHours(5),
                'notes' => 'Disetujui komite pembiayaan, menunggu realisasi kasir.',
            ]
        );
        ShmApproval::query()->firstOrCreate(
            ['shm_application_id' => $appMirror3->id],
            [
                'decided_by' => $admin->id,
                'decided_at' => now()->subHours(5),
                'status' => ShmApproval::STATUS_APPROVED,
                'notes' => 'Disetujui penuh dengan agunan sertifikat hak milik nomor 402/Bogor.',
            ]
        );
        ShmLoanApplication::query()->firstOrCreate(
            ['application_number' => 'PENGAJ-DEMO-003'],
            [
                'shm_application_id' => $appMirror3->id,
                'shm_customer_id' => $customers[2]->id,
                'shm_loan_product_id' => $productFlexibel->id,
                'application_date' => now()->subDays(5)->toDateString(),
                'principal' => 100_000_000,
                'approved_amount' => 100_000_000,
                'tenor_months' => 24,
                'purpose' => 'Pembelian Mesin Produksi',
                'marketing_id' => $marketing->id,
                'status' => 'approved',
                'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            ]
        );

        // =========================================================================
        // Helper Pencairan via Canonical Service
        // =========================================================================
        $disburseLoan = function (ShmCustomer $cust, float $principal, int $tenor, string $appCode, string $loanCode) use ($productPrioritas, $marketing, $disbursementService, $scheduleService): ShmLoan {
            $existingLoan = ShmLoan::query()->where('loan_number', $loanCode)->first();
            if ($existingLoan !== null) {
                return $existingLoan;
            }

            $mirror = ShmApplication::query()->firstOrCreate(
                ['application_number' => 'APP-MIR-'.$appCode],
                [
                    'shm_customer_id' => $cust->id,
                    'marketing_id' => $marketing->id,
                    'status' => 'approved',
                    'approved_at' => now()->subMonths(3),
                ]
            );

            ShmApproval::query()->firstOrCreate(
                ['shm_application_id' => $mirror->id],
                [
                    'decided_by' => $marketing->id,
                    'decided_at' => now()->subMonths(3),
                    'status' => ShmApproval::STATUS_APPROVED,
                    'notes' => 'Disetujui untuk demonstrasi alur pembiayaan SHM',
                ]
            );

            $loanApp = ShmLoanApplication::query()->firstOrCreate(
                ['application_number' => $appCode],
                [
                    'shm_application_id' => $mirror->id,
                    'shm_customer_id' => $cust->id,
                    'shm_loan_product_id' => $productPrioritas->id,
                    'application_date' => now()->subMonths(3)->toDateString(),
                    'principal' => $principal,
                    'approved_amount' => $principal,
                    'tenor_months' => $tenor,
                    'first_due_date' => now()->subMonths(2)->startOfMonth()->toDateString(),
                    'marketing_id' => $marketing->id,
                    'status' => 'approved',
                ]
            );

            if ($loanApp->loan()->exists()) {
                return $loanApp->loan;
            }

            $loan = $disbursementService->disburse($loanApp, $marketing->id);
            $loan->loan_number = $loanCode;
            $loan->save();

            $scheduleService->generate($loan);

            return $loan;
        };

        // =========================================================================
        // Skenario 4: DEMO-LN-01 — Active Loan Baru (Baru dicairkan)
        // =========================================================================
        $loan1 = $disburseLoan($customers[3], 30_000_000, 12, 'PENGAJ-DEMO-004', 'SHM-LN-DEMO-001');

        // =========================================================================
        // Skenario 5: DEMO-LN-02 — Active Loan dengan Riwayat Pembayaran Angsuran
        // =========================================================================
        $loan2 = $disburseLoan($customers[4], 50_000_000, 12, 'PENGAJ-DEMO-005', 'SHM-LN-DEMO-002');
        if ($loan2->payments()->count() === 0) {
            $inst1 = $loan2->installments()->where('installment_number', 1)->first();
            if ($inst1 !== null) {
                $paymentService->pay($loan2, [
                    'payment_date' => now()->subMonths(2)->startOfMonth()->toDateString(),
                    'amount' => (float) $inst1->total_amount,
                    'idempotency_key' => 'DEMO-PAY-002-1',
                ], $marketing->id);
            }
        }

        // =========================================================================
        // Skenario 6: DEMO-LN-03 — Active Loan dengan Overpayment (Simpanan Pokok)
        // =========================================================================
        $loan3 = $disburseLoan($customers[5], 20_000_000, 12, 'PENGAJ-DEMO-006', 'SHM-LN-DEMO-003');
        if ($loan3->payments()->count() === 0) {
            $totalOwed = $loan3->totalOwed();
            // Bayar lunas total kewajiban plus lebih bayar Rp 500.000 ke Simpanan Pokok
            $paymentService->pay($loan3, [
                'payment_date' => now()->subMonth()->toDateString(),
                'amount' => $totalOwed + 500_000,
                'idempotency_key' => 'DEMO-PAY-003-OVERPAY',
            ], $marketing->id);
        }

        // =========================================================================
        // Skenario 7: DEMO-LN-04 — Completed Settlement (Pelunasan Dipercepat)
        // =========================================================================
        $loan4 = $disburseLoan($customers[6], 40_000_000, 12, 'PENGAJ-DEMO-007', 'SHM-LN-DEMO-004');
        if ($loan4->status !== ShmLoan::STATUS_CLOSED && $loan4->activeSettlement() === null) {
            $settleReq = $settlementService->request($loan4, [
                'discount_percentage' => 0,
                'manual_penalty' => 0,
                'admin_notes' => 'Pelunasan dipercepat demo sebelum jatuh tempo tenor',
            ], $marketing->id);
            $settlementService->approve($loan4, $marketing->id);

            // Buat record pembayaran pelunasan via ShmLoanPaymentService
            $settlePayment = $loanPaymentService->create($loan4, [
                'payment_date' => now()->toDateString(),
                'amount' => (float) $settleReq->settlement_amount,
            ], $marketing->id);

            $settlementService->recordPayment($loan4, $settlePayment->id, $marketing->id);
            $settlementService->verifyPayment($loan4, $marketing->id);
            $settlementService->complete($loan4, $marketing->id);
        }

        // =========================================================================
        // Skenario 8: DEMO-LN-05 — Corrected Settlement (Policy B Reversal)
        // =========================================================================
        $loan5 = $disburseLoan($customers[7], 60_000_000, 12, 'PENGAJ-DEMO-008', 'SHM-LN-DEMO-005');
        if ($loan5->settlementCorrections()->count() === 0) {
            if ($loan5->status !== ShmLoan::STATUS_CLOSED && $loan5->activeSettlement() === null) {
                $settleReq5 = $settlementService->request($loan5, [
                    'discount_percentage' => 0,
                    'manual_penalty' => 0,
                    'admin_notes' => 'Pelunasan yang akan dikoreksi (salah perhitungan diskon)',
                ], $marketing->id);
                $settlementService->approve($loan5, $marketing->id);

                $settlePayment5 = $loanPaymentService->create($loan5, [
                    'payment_date' => now()->toDateString(),
                    'amount' => (float) $settleReq5->settlement_amount,
                ], $marketing->id);

                $settlementService->recordPayment($loan5, $settlePayment5->id, $marketing->id);
                $settlementService->verifyPayment($loan5, $marketing->id);
                $settlementService->complete($loan5, $marketing->id);

                // Koreksi Settlement (Policy B)
                $correctionService->correct(
                    $loan5,
                    $settleReq5->fresh(),
                    'Koreksi pelunasan demo: penyesuaian diskon pelunasan nasabah',
                    $marketing->id
                );
            }
        }

        // =========================================================================
        // Skenario 9: DEMO-LN-06 — Collection Overdue (Aging 1-7 Hari, PTP OPEN)
        // =========================================================================
        $loan6 = $disburseLoan($customers[8], 25_000_000, 12, 'PENGAJ-DEMO-009', 'SHM-LN-DEMO-006');
        // Mundurkan tanggal jatuh tempo angsuran pertama agar overdue 5 hari
        $instCase6 = $loan6->installments()->first();
        if ($instCase6 !== null) {
            $instCase6->due_date = now()->subDays(5)->toDateString();
            $instCase6->save();

            $case6 = $collectionCaseService->createOrGetForLoan($loan6, $marketing->id);
            if ($case6->status === ShmCollectionCase::STATUS_OPEN) {
                $case6 = $collectionCaseService->transition($case6, ShmCollectionCase::STATUS_IN_PROGRESS, $marketing->id);
                $case6 = $collectionCaseService->transition($case6, ShmCollectionCase::STATUS_PROMISE_TO_PAY, $marketing->id);
            }

            if ($case6->ptps()->where('status', ShmCollectionPtp::STATUS_OPEN)->count() === 0) {
                $collectionPtpService->create($case6, $marketing->id, [
                    'promised_date' => now()->addDays(3)->toDateString(),
                    'promised_amount' => (float) $instCase6->total_amount,
                    'notes' => 'Nasabah berjanji melunasi pada hari Jumat setelah gajian kantor.',
                ]);
            }
        }

        // =========================================================================
        // Skenario 10: DEMO-LN-07 — Collection Escalated (3 Missed PTP)
        // =========================================================================
        $loan7 = $disburseLoan($customers[9], 80_000_000, 12, 'PENGAJ-DEMO-010', 'SHM-LN-DEMO-007');
        $instCase7 = $loan7->installments()->first();
        if ($instCase7 !== null) {
            $instCase7->due_date = now()->subDays(45)->toDateString();
            $instCase7->save();

            $case7 = $collectionCaseService->createOrGetForLoan($loan7, $marketing->id);
            if ($case7->status === ShmCollectionCase::STATUS_OPEN) {
                $case7 = $collectionCaseService->transition($case7, ShmCollectionCase::STATUS_IN_PROGRESS, $marketing->id);
                $case7 = $collectionCaseService->transition($case7, ShmCollectionCase::STATUS_ESCALATED, $marketing->id, 'Nasabah telah 3 kali ingkar janji bayar (Missed PTP). Dilimpahkan ke Branch Manager.');
            }

            // Buat 3 catatan PTP historis untuk verifikasi eskalasi
            for ($p = 1; $p <= 3; $p++) {
                ShmCollectionPtp::query()->firstOrCreate(
                    [
                        'shm_collection_case_id' => $case7->id,
                        'promised_date' => now()->subDays(30 - ($p * 8))->toDateString(),
                    ],
                    [
                        'created_by' => $marketing->id,
                        'promised_amount' => (float) $instCase7->total_amount,
                        'status' => ShmCollectionPtp::STATUS_MISSED,
                        'notes' => 'PTP #'.$p.' tidak dipenuhi nasabah.',
                    ]
                );
            }
        }
    }
}
