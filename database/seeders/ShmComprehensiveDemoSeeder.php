<?php

namespace Database\Seeders;

use App\Enums\ShmApplicationStatus;
use App\Enums\ShmCreditCheckStatus;
use App\Models\ShmApplication;
use App\Models\ShmCreditCheck;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanPaymentAllocation;
use App\Models\ShmLoanProduct;
use App\Models\ShmMemorandum;
use App\Models\ShmProperty;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder data demo komprehensif dan idempoten untuk KSP Bima Sakti Mandiri (BSM Makro) - Ticket DATA-02.
 *
 * Mengakomodasi:
 * 1. 5 Nasabah Utama Demo (CUST-DEMO-001 s.d. CUST-DEMO-005)
 * 2. 5 Pengajuan SHGB/SHM Utama Demo (APP-DEMO-SHM-01 s.d. APP-DEMO-SHM-05) dengan 3 variasi:
 *    - Variasi status/tahap: Credit Checking (Drafted, Approve, Completed)
 *    - Variasi jenis agunan SHGB/SHM
 * 3. 5 Pengajuan Buyback Utama Demo (APP-DEMO-BB-01 s.d. APP-DEMO-BB-05) dengan 3 variasi status:
 *    - Draft, Submitted, Completed
 * 4. 5 Pengajuan Pinjaman SHM (ShmLoanApplication) dengan 3 variasi produk pinjaman:
 *    - BSM-PRIORITAS (ID 1)
 *    - BSM-FLEXIBEL (ID 2)
 *    - BSM-PERKAMPUNGAN (ID 3)
 * 5. 5 Pinjaman SHM Aktif (ShmLoan) beserta jadwal angsuran (ShmLoanInstallment) dan pembayaran (ShmLoanPayment)
 * 6. 5 Credit Checking & 5 Memorandum SHGB/SHM dan Buyback untuk verifikasi visual CC-11
 */
class ShmComprehensiveDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $marketing = User::where('email', 'marketing@ksp.local')->first()
                ?? User::whereHas('roles', fn ($q) => $q->where('name', 'marketing_makro'))->first()
                ?? User::firstOrFail();

            $branchManager = User::where('email', 'branch-manager@ksp.local')->first()
                ?? User::whereHas('roles', fn ($q) => $q->where('name', 'branch_manager_makro'))->first();

            $direkturUtama = User::where('email', 'direktur@ksp.local')->first()
                ?? User::whereHas('roles', fn ($q) => $q->where('name', 'direktur_utama_makro'))->first();

            // Produk Pinjaman yang digunakan (3 jenis variasi)
            $prodPrioritas = ShmLoanProduct::where('code', 'BSM-PRIORITAS')->firstOrFail();
            $prodFlexibel = ShmLoanProduct::where('code', 'BSM-FLEXIBEL')->firstOrFail();
            $prodPerkampungan = ShmLoanProduct::where('code', 'BSM-PERKAMPUNGAN')->firstOrFail();

            // =========================================================================
            // 1. Modul Nasabah: 5 Record Utama Demo
            // =========================================================================
            $customersData = [
                [
                    'customer_number' => 'CUST-DEMO-001',
                    'name' => 'Bambang Sutrisno (Demo 1)',
                    'nik' => '3271012304850001',
                    'phone' => '081289001001',
                    'email' => 'bambang.demo@example.com',
                    'address' => 'Jl. Merdeka No. 10, Bogor Tengah, Kota Bogor',
                ],
                [
                    'customer_number' => 'CUST-DEMO-002',
                    'name' => 'Siti Aminah (Demo 2)',
                    'nik' => '3271015607900002',
                    'phone' => '081289001002',
                    'email' => 'siti.demo@example.com',
                    'address' => 'Jl. Sudirman No. 25, Bogor Barat, Kota Bogor',
                ],
                [
                    'customer_number' => 'CUST-DEMO-003',
                    'name' => 'Hendra Gunawan (Demo 3)',
                    'nik' => '3271011508820003',
                    'phone' => '081289001003',
                    'email' => 'hendra.demo@example.com',
                    'address' => 'Jl. Pajajaran No. 44, Bogor Timur, Kota Bogor',
                ],
                [
                    'customer_number' => 'CUST-DEMO-004',
                    'name' => 'Dewi Sartika (Demo 4)',
                    'nik' => '3271016812880004',
                    'phone' => '081289001004',
                    'email' => 'dewi.demo@example.com',
                    'address' => 'Jl. Raya Tajur No. 112, Bogor Selatan, Kota Bogor',
                ],
                [
                    'customer_number' => 'CUST-DEMO-005',
                    'name' => 'Agus Priyanto (Demo 5)',
                    'nik' => '3271012903790005',
                    'phone' => '081289001005',
                    'email' => 'agus.demo@example.com',
                    'address' => 'Jl. Sholeh Iskandar No. 88, Tanah Sareal, Kota Bogor',
                ],
            ];

            $customers = [];
            foreach ($customersData as $c) {
                $customers[$c['customer_number']] = ShmCustomer::query()->updateOrCreate(
                    ['customer_number' => $c['customer_number']],
                    [
                        'name' => $c['name'],
                        'nik' => $c['nik'],
                        'phone' => $c['phone'],
                        'email' => $c['email'],
                        'address' => $c['address'],
                        'is_active' => true,
                    ]
                );
            }

            // =========================================================================
            // 2. Modul Pengajuan SHGB/SHM: 5 Record Utama Demo (Variasi SHGB & SHM)
            // =========================================================================
            $shmAppsData = [
                // Variasi 1: Status Credit Checking (Drafted) - Target verifikasi BM Approve/Reject
                [
                    'app_number' => 'APP-DEMO-SHM-01',
                    'customer_key' => 'CUST-DEMO-001',
                    'type' => ShmApplication::TYPE_SHGB,
                    'status' => ShmApplicationStatus::CreditChecking,
                    'cc_status' => ShmCreditCheckStatus::Drafted,
                    'cert_type' => 'SHM',
                    'cert_number' => 'SHM-BGR-2026-001',
                    'notes' => 'Pengajuan Demo SHM 01: Status Credit Checking Drafted (siap diuji oleh Branch Manager).',
                ],
                // Variasi 2: Status Credit Checking (Approve) - Target verifikasi BM Selesai/Complete
                [
                    'app_number' => 'APP-DEMO-SHM-02',
                    'customer_key' => 'CUST-DEMO-002',
                    'type' => ShmApplication::TYPE_SHGB,
                    'status' => ShmApplicationStatus::CreditChecking,
                    'cc_status' => ShmCreditCheckStatus::Approve,
                    'cert_type' => 'SHM',
                    'cert_number' => 'SHM-BGR-2026-002',
                    'notes' => 'Pengajuan Demo SHM 02: Status Credit Checking Approved BM (siap diselesaikan ke Completed).',
                ],
                // Variasi 3: Status Credit Checking (Rejected) - Target verifikasi Marketing Kembalikan ke Draft
                [
                    'app_number' => 'APP-DEMO-SHM-03',
                    'customer_key' => 'CUST-DEMO-003',
                    'type' => ShmApplication::TYPE_SHGB,
                    'status' => ShmApplicationStatus::CreditChecking,
                    'cc_status' => ShmCreditCheckStatus::Rejected,
                    'cert_type' => 'SHGB',
                    'cert_number' => 'SHGB-BGR-2026-003',
                    'notes' => 'Pengajuan Demo SHM 03: Status Credit Checking Rejected (siap diuji Marketing Owner).',
                ],
                // Variasi 4: Status Memorandum Draft dengan CC Incomplete (Target Verifikasi Completion Bypass Modal)
                [
                    'app_number' => 'APP-DEMO-SHM-04',
                    'customer_key' => 'CUST-DEMO-004',
                    'type' => ShmApplication::TYPE_SHGB,
                    'status' => ShmApplicationStatus::Memorandum,
                    'cc_status' => ShmCreditCheckStatus::Drafted,
                    'cert_type' => 'SHGB',
                    'cert_number' => 'SHGB-BGR-2026-004',
                    'create_memo' => true,
                    'memo_type' => ShmMemorandum::TYPE_SHM_SHGB,
                    'notes' => 'Pengajuan Demo SHM 04: Status Memorandum Draft, CC belum selesai (Target uji Completion Bypass UI).',
                ],
                // Variasi 5: Status Selesai (Completed)
                [
                    'app_number' => 'APP-DEMO-SHM-05',
                    'customer_key' => 'CUST-DEMO-005',
                    'type' => ShmApplication::TYPE_SHGB,
                    'status' => ShmApplicationStatus::Completed,
                    'cc_status' => ShmCreditCheckStatus::Completed,
                    'cert_type' => 'SHM',
                    'cert_number' => 'SHM-BGR-2026-005',
                    'create_memo' => true,
                    'memo_type' => ShmMemorandum::TYPE_SHM_SHGB,
                    'notes' => 'Pengajuan Demo SHM 05: Status Completed penuh.',
                ],
            ];

            foreach ($shmAppsData as $row) {
                $cust = $customers[$row['customer_key']];
                $app = ShmApplication::query()->updateOrCreate(
                    ['application_number' => $row['app_number']],
                    [
                        'shm_customer_id' => $cust->id,
                        'marketing_id' => $marketing->id,
                        'type' => $row['type'],
                        'status' => $row['status'],
                        'notes' => $row['notes'],
                    ]
                );

                // Agunan Properti
                ShmProperty::query()->updateOrCreate(
                    ['shm_application_id' => $app->id],
                    [
                        'certificate_type' => $row['cert_type'],
                        'certificate_number' => $row['cert_number'],
                        'owner_name' => $cust->name,
                        'address' => $cust->address,
                        'notes' => 'Agunan Demo terverifikasi.',
                    ]
                );

                // Credit Checking Record
                $cc = ShmCreditCheck::query()->updateOrCreate(
                    ['shm_application_id' => $app->id],
                    [
                        'filled_by' => $marketing->id,
                        'status' => $row['cc_status']->value,
                        'outcome' => $row['cc_status'] === ShmCreditCheckStatus::Completed ? 'pass' : null,
                        'notes' => 'Catatan pemeriksaan Credit Checking Demo.',
                        'started_at' => now()->subDays(2),
                        'checked_at' => in_array($row['cc_status'], [ShmCreditCheckStatus::Completed, ShmCreditCheckStatus::Approve, ShmCreditCheckStatus::Rejected]) ? now() : null,
                    ]
                );

                // Memorandum jika dikonfigurasi
                if (! empty($row['create_memo'])) {
                    ShmMemorandum::query()->updateOrCreate(
                        ['shm_application_id' => $app->id, 'type' => $row['memo_type']],
                        [
                            'created_by' => $marketing->id,
                            'status' => ShmMemorandum::STATUS_DRAFT,
                            'notes' => 'Memorandum Demo SHM/SHGB untuk pengujian aplikasi.',
                            'shgb_start_date' => now()->toDateString(),
                            'shgb_end_date' => now()->addYears(20)->toDateString(),
                            'shm_credit_check_id' => $cc->id,
                        ]
                    );
                }
            }

            // =========================================================================
            // 3. Modul Pengajuan Buyback: 5 Record Utama Demo (Variasi Buyback)
            // =========================================================================
            $buybackAppsData = [
                // Variasi 1: Buyback Status Draft
                [
                    'app_number' => 'APP-DEMO-BB-01',
                    'customer_key' => 'CUST-DEMO-001',
                    'status' => ShmApplicationStatus::Draft,
                    'notes' => 'Pengajuan Buyback Demo 01: Status Draft.',
                ],
                // Variasi 2: Buyback Status Submitted
                [
                    'app_number' => 'APP-DEMO-BB-02',
                    'customer_key' => 'CUST-DEMO-002',
                    'status' => ShmApplicationStatus::Submitted,
                    'notes' => 'Pengajuan Buyback Demo 02: Status Submitted.',
                ],
                // Variasi 3: Buyback Status Credit Checking
                [
                    'app_number' => 'APP-DEMO-BB-03',
                    'customer_key' => 'CUST-DEMO-003',
                    'status' => ShmApplicationStatus::CreditChecking,
                    'notes' => 'Pengajuan Buyback Demo 03: Status Credit Checking.',
                ],
                // Variasi 4: Buyback Status Memorandum Draft (Target uji Completion Bypass Buyback)
                [
                    'app_number' => 'APP-DEMO-BB-04',
                    'customer_key' => 'CUST-DEMO-004',
                    'status' => ShmApplicationStatus::Memorandum,
                    'create_memo' => true,
                    'notes' => 'Pengajuan Buyback Demo 04: Status Memorandum Draft (Target uji Completion Bypass Buyback).',
                ],
                // Variasi 5: Buyback Status Completed
                [
                    'app_number' => 'APP-DEMO-BB-05',
                    'customer_key' => 'CUST-DEMO-005',
                    'status' => ShmApplicationStatus::Completed,
                    'create_memo' => true,
                    'notes' => 'Pengajuan Buyback Demo 05: Status Completed.',
                ],
            ];

            foreach ($buybackAppsData as $row) {
                $cust = $customers[$row['customer_key']];
                $app = ShmApplication::query()->updateOrCreate(
                    ['application_number' => $row['app_number']],
                    [
                        'shm_customer_id' => $cust->id,
                        'marketing_id' => $marketing->id,
                        'type' => ShmApplication::TYPE_BUYBACK,
                        'status' => $row['status'],
                        'notes' => $row['notes'],
                    ]
                );

                // Agunan Properti Buyback
                ShmProperty::query()->updateOrCreate(
                    ['shm_application_id' => $app->id],
                    [
                        'certificate_type' => 'SHGB',
                        'certificate_number' => 'BUYBACK/PROP/'.substr($row['app_number'], -2),
                        'owner_name' => $cust->name,
                        'address' => $cust->address,
                        'notes' => 'Agunan Buyback Demo terverifikasi.',
                    ]
                );

                // Credit Checking
                $cc = ShmCreditCheck::query()->updateOrCreate(
                    ['shm_application_id' => $app->id],
                    [
                        'filled_by' => $marketing->id,
                        'status' => ShmCreditCheckStatus::Drafted->value,
                        'notes' => 'Credit Checking Buyback Demo.',
                    ]
                );

                // Memorandum Buyback jika ada
                if (! empty($row['create_memo'])) {
                    ShmMemorandum::query()->updateOrCreate(
                        ['shm_application_id' => $app->id, 'type' => ShmMemorandum::TYPE_BUYBACK],
                        [
                            'created_by' => $marketing->id,
                            'status' => ShmMemorandum::STATUS_DRAFT,
                            'notes' => 'Memorandum Buyback Demo untuk pengujian dispensasi.',
                            'shm_credit_check_id' => $cc->id,
                        ]
                    );
                }
            }

            // =========================================================================
            // 4. Modul Pengajuan Pinjaman & Pinjaman Aktif: 5 Record Utama Demo
            //    (Menggunakan 3 Variasi Master Produk: BSM-PRIORITAS, BSM-FLEXIBEL, BSM-PERKAMPUNGAN)
            // =========================================================================
            $loansData = [
                // Variasi Produk 1: BSM-PRIORITAS
                [
                    'loan_app_number' => 'PENGAJ-DEMO-001',
                    'loan_number' => 'SHM-PIN-DEMO-001',
                    'customer_key' => 'CUST-DEMO-001',
                    'product' => $prodPrioritas,
                    'principal' => 50000000.00,
                    'tenor' => 12,
                    'rate' => 1.5,
                ],
                // Variasi Produk 2: BSM-FLEXIBEL
                [
                    'loan_app_number' => 'PENGAJ-DEMO-002',
                    'loan_number' => 'SHM-PIN-DEMO-002',
                    'customer_key' => 'CUST-DEMO-002',
                    'product' => $prodFlexibel,
                    'principal' => 30000000.00,
                    'tenor' => 12,
                    'rate' => 1.75,
                ],
                // Variasi Produk 3: BSM-PERKAMPUNGAN
                [
                    'loan_app_number' => 'PENGAJ-DEMO-003',
                    'loan_number' => 'SHM-PIN-DEMO-003',
                    'customer_key' => 'CUST-DEMO-003',
                    'product' => $prodPerkampungan,
                    'principal' => 20000000.00,
                    'tenor' => 12,
                    'rate' => 2.0,
                ],
                // Variasi Produk 1 (Ulang): BSM-PRIORITAS
                [
                    'loan_app_number' => 'PENGAJ-DEMO-004',
                    'loan_number' => 'SHM-PIN-DEMO-004',
                    'customer_key' => 'CUST-DEMO-004',
                    'product' => $prodPrioritas,
                    'principal' => 75000000.00,
                    'tenor' => 24,
                    'rate' => 1.5,
                ],
                // Variasi Produk 2 (Ulang): BSM-FLEXIBEL
                [
                    'loan_app_number' => 'PENGAJ-DEMO-005',
                    'loan_number' => 'SHM-PIN-DEMO-005',
                    'customer_key' => 'CUST-DEMO-005',
                    'product' => $prodFlexibel,
                    'principal' => 40000000.00,
                    'tenor' => 12,
                    'rate' => 1.75,
                ],
            ];

            foreach ($loansData as $idx => $ld) {
                $cust = $customers[$ld['customer_key']];

                // Pengajuan Pinjaman (ShmLoanApplication)
                $loanApp = ShmLoanApplication::query()->updateOrCreate(
                    ['application_number' => $ld['loan_app_number']],
                    [
                        'shm_customer_id' => $cust->id,
                        'shm_loan_product_id' => $ld['product']->id,
                        'marketing_id' => $marketing->id,
                        'application_date' => now()->subMonths(2)->toDateString(),
                        'principal' => $ld['principal'],
                        'tenor_months' => $ld['tenor'],
                        'purpose' => 'Modal Usaha Pengembangan Usaha Demo '.$cust->name,
                        'status' => 'disbursed',
                        'approved_amount' => $ld['principal'],
                    ]
                );

                // Pinjaman Aktif (ShmLoan)
                $loan = ShmLoan::query()->updateOrCreate(
                    ['loan_number' => $ld['loan_number']],
                    [
                        'shm_loan_application_id' => $loanApp->id,
                        'shm_customer_id' => $cust->id,
                        'shm_loan_product_id' => $ld['product']->id,
                        'principal' => $ld['principal'],
                        'tenor_months' => $ld['tenor'],
                        'rate_percent' => $ld['rate'],
                        'rate_period' => 'monthly',
                        'calculation_method' => 'flat',
                        'purpose' => $loanApp->purpose,
                        'status' => 'active',
                        'disbursed_at' => now()->subMonths(2),
                        'first_due_date' => now()->subMonths(1)->startOfMonth()->addDays(9)->toDateString(),
                        'note' => 'Pinjaman aktif demo deterministik.',
                    ]
                );

                // Jadwal Angsuran (12 bulan)
                $monthlyPrincipal = round($ld['principal'] / $ld['tenor'], 2);
                $monthlyInterest = round($ld['principal'] * ($ld['rate'] / 100), 2);
                $monthlyTotal = $monthlyPrincipal + $monthlyInterest;

                for ($m = 1; $m <= $ld['tenor']; $m++) {
                    $dueDate = Carbon::parse($loan->first_due_date)->addMonths($m - 1)->toDateString();
                    ShmLoanInstallment::query()->updateOrCreate(
                        [
                            'shm_loan_id' => $loan->id,
                            'installment_number' => $m,
                        ],
                        [
                            'due_date' => $dueDate,
                            'principal_amount' => $monthlyPrincipal,
                            'interest_amount' => $monthlyInterest,
                            'total_amount' => $monthlyTotal,
                            'paid_amount' => $m === 1 ? $monthlyTotal : 0.00,
                            'status' => $m === 1 ? 'paid' : 'pending',
                        ]
                    );
                }

                // 1 Pembayaran Angsuran per pinjaman
                $paymentRef = 'SHM-PAY-DEMO-00'.($idx + 1);
                $payment = ShmLoanPayment::query()->updateOrCreate(
                    ['payment_reference' => $paymentRef],
                    [
                        'shm_loan_id' => $loan->id,
                        'payment_date' => now()->subDays(15)->toDateString(),
                        'amount' => $monthlyTotal,
                        'payment_channel' => 'cash',
                        'status' => 'allocated',
                        'notes' => 'Pembayaran angsuran pertama demo.',
                        'created_by' => $marketing->id,
                        'idempotency_key' => 'IDEM-PAY-DEMO-00'.($idx + 1),
                    ]
                );

                $firstInst = ShmLoanInstallment::where('shm_loan_id', $loan->id)->where('installment_number', 1)->first();
                if ($firstInst) {
                    ShmLoanPaymentAllocation::query()->updateOrCreate(
                        [
                            'shm_loan_payment_id' => $payment->id,
                            'shm_loan_installment_id' => $firstInst->id,
                        ],
                        [
                            'principal_amount' => $monthlyPrincipal,
                            'interest_amount' => $monthlyInterest,
                            'penalty_amount' => 0.00,
                            'total_amount' => $monthlyTotal,
                        ]
                    );
                }
            }
        });
    }
}
