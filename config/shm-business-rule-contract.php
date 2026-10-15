<?php

return [

    /*
    |-----------------------------------------------------------------------
    | Hak atas kontrak ini
    |-----------------------------------------------------------------------
    */
    'meta' => [
        'contract_name' => 'SHM Business Rule Contract',
        'scope' => 'Sarana Hunian Mandiri (SHM)',
        'version' => '1.3.0-T91',
        'status' => 'reference_contract_only',
        'purpose' => 'T79: general/standard loan business rules diadopsi sebagai CONFIRMED BASELINE untuk SHM, kecuali ada aturan khusus KSP yang menggantikan. T91: business rule & workflow contract Collection (internal koperasi) — role structure, lifecycle, aging, activity/result, promise-to-pay, escalation authority, permission konseptual, audit, dan daftar pending rule — tanpa implementasi action/UI/database.',
        'baseline_source' => 'Keputusan user (T79): SHM memakai GENERAL/STANDARD LOAN BUSINESS RULES sebagai baseline, sampai ada aturan khusus KSP yang menggantikan. Setiap rule di bawah ini dilacak keputusan tersebut, BUKAN ke asumsi industri.',
        'related_documents' => [
            'docs/SHM-BUSINESS-DECISION-PACKAGE.md',
            'docs/BUSINESS-RULES-INVENTORY.md',
            'docs/BUSINESS-RULES-DECISION-REGISTER.md',
            'docs/BUSINESS-DATA-REQUEST.md',
            'config/shm.php',
            'config/business-rules.php (referensi umum koperasi; BUKAN source of truth SHM — nilai provisional tidak diadopsi)',
        ],
        'changed_by' => 'T79 — baseline rule contract; no financial behavior change, no schema change, no UI. T91 — collection business rule & workflow contract (ADDITIVE: key baru collection_workflow + assert guard; tidak ada key lama dihapus/diubah, tanpa perubahan behavior runtime, schema, UI, payment, settlement, atau loan status).',
        'vocabulary' => [
            'CONFIRMED BASELINE' => 'Rule diputuskan (keputusan user T79) dan mengikat ticket implementasi berikutnya.',
            'PENDING BUSINESS CONFIRMATION' => 'Rule belum diputuskan; sistem tidak boleh menebak atau mengimplementasikan.',
        ],
    ],

    /*
    |-----------------------------------------------------------------------
    | T79 CONFIRMED BASELINE — ringkasan rule yang mengikat
    |
    | Sumber keputusan: user decision T79 (general/standard loan business
    | rules sebagai baseline SHM). config/business-rules.php HANYA referensi;
    | nilai provisional di dalamnya tidak diadopsi kecuali kebetulan sama.
    |-----------------------------------------------------------------------
    */
    't79_baseline' => [
        'status' => 'CONFIRMED BASELINE',
        'source' => 'User decision T79 — general/standard loan business rules sebagai baseline SHM.',

        'interest' => [
            'rate_percent' => 2.0,
            'period' => 'monthly',
            'method' => 'flat',
            'basis' => 'original_principal',
            'decreases_with_principal' => false,
            'monthly_formula' => 'original_principal × 2%',
            'note' => 'Flat: bunga dihitung dari pokok AWAL dan TIDAK berkurang saat pokok tersisa menurun. Bunga dihitung untuk setiap periode angsuran terjadwal.',
            'superseded_by_t82' => 'NILAI RATE 2% adalah catatan historis keputusan T79. Sejak T82 rate per bulan ditentukan matriks produk (product + tenor eksak; lihat rate.product_driven_rate) — universal 2% TIDAK dipakai lagi. Period monthly, metode flat, dan basis original_principal TETAP berlaku.',
        ],

        'tenor' => [
            'unit' => 'month',
            'installments_equals_tenor' => true,
            'note' => 'Tenor dinyatakan dalam bulan. 12 bulan = 12 angsuran terjadwal. Tidak ada batas minimum/maksimum institusional yang diadopsi di ticket ini.',
        ],

        'installment_frequency' => [
            'value' => 'monthly',
            'installments_per_month' => 1,
            'number_of_installments' => 'tenor_months',
        ],

        'principal_installment' => [
            'allocation' => 'equal_principal',
            'formula' => 'original_principal / tenor_months',
            'note' => 'Pembagian dapat menghasilkan pecahan rupiah; pembulatan & penyesuaian angsuran terakhir mengikuti aturan rounding di bawah.',
        ],

        'monthly_installment' => [
            'composition' => ['principal_component', 'monthly_flat_interest'],
            'interest_is_constant' => true,
            'note' => 'Angsuran bulanan = komponen pokok + bunga flat bulanan. Bunga flat konstan selama pinjaman karena berbasis pokok awal.',
        ],

        'rounding' => [
            'monetary_unit' => 'rupiah',
            'persist_fractional_rupiah' => false,
            'method' => 'nearest_rupiah',
            'accumulate_error' => false,
            'difference_location' => 'final_installment',
            'note' => 'Nilai moneter yang disimpan/ditagihkan tidak boleh berisi pecahan rupiah. Selisih pembulatan tidak diakumulasikan; diselesaikan pada angsuran terakhir.',
        ],

        'final_installment' => [
            'role' => 'balancing_installment',
            'must_satisfy' => [
                'total_principal_paid_equals_original_principal',
                'remaining_principal_zero_after_completion',
            ],
            'may_differ_from_regular' => true,
        ],

        'due_date' => [
            'first_due' => 'one_month_after_disbursement',
            'progression' => 'monthly',
            'preserve_day_of_month' => true,
            'missing_day_fallback' => 'last_valid_calendar_day_of_target_month',
            'grace_period' => 'TIDAK diperkenalkan (none) tanpa konfigurasi terpisah',
            'note' => 'Referensi teknis pemetaan: Carbon addMonthsNoOverflow memenuhi aturan ini (12 Okt → 12 Nov → 12 Des; 31 Jan → hari terakhir Feb).',
        ],

        'repayment_schedule' => [
            'generated_from' => [
                'loan_principal',
                'tenor',
                'rate',
                'interest_period',
                'calculation_method',
                'disbursement_date',
            ],
            'eligible_loan' => 'active AND disbursed_at IS NOT NULL',
            'exactly_tenor_installments' => true,
            'installment_number_starts_at' => 1,
            'installment_number_unique_per_loan' => true,
            'existing_schedule_idempotent' => true,
            'duplicate_installments_forbidden' => true,
            'silent_overwrite_forbidden' => true,
        ],

        'payment_allocation' => [
            'chronological_order' => true,
            'note' => 'Kewajiban yang jatuh tempo lebih awal diselesaikan sebelum yang lebih akhir.',
            'within_installment_order' => ['penalty', 'interest', 'principal'],
            'penalty_position_note' => 'Urutan ini MENGRESERVASI posisi penalty; perhitungan penalty TIDAK diimplementasikan di T79 (rule penalty masih PENDING).',
            'partial_payment_support_required' => true,
            'unpaid_remainder_stays_outstanding' => true,
        ],

        'partial_payment' => [
            'allowed' => true,
            'reduces' => 'oldest_applicable_obligation',
            'auto_mark_paid_only_when' => 'total_outstanding_of_installment_reaches_zero',
        ],

        'overpayment' => [
            'silently_discarded' => false,
            'representation' => 'explicit unapplied/overpayment balance (or equivalent financial state)',
            'accounting_treatment' => 'PENDING BUSINESS CONFIRMATION (ticket Payment)',
            'note' => 'Kelebihan bayar tidak boleh dibuang diam-diam. Perlakuan akuntansi persisnya milik ticket Payment; T79 tidak mengarang jurnal.',
        ],

        'payment_reversal' => [
            'explicit' => true,
            'auditable' => true,
            'delete_history' => false,
            'restores_obligation' => true,
            'implementation' => 'PENDING (ticket Payment)',
        ],

        'settlement' => [
            'customer_may_request_early_settlement' => true,
            'amount_components' => [
                'outstanding_principal',
                'applicable_obligations_already_due_or_accrued_according_to_schedule',
            ],
            'future_flat_interest_not_auto_charged' => true,
            'tolerance' => 'PENDING BUSINESS CONFIRMATION (TIDAK mengadopsi settlement_tolerance 0.01 dari config/business-rules.php)',
            'discount_or_rebate' => 'PENDING BUSINESS CONFIRMATION',
            'implementation' => 'PENDING (ticket Settlement/Pelunasan)',
        ],

        'penalty' => [
            'rule_status' => 'PENDING BUSINESS CONFIRMATION',
            'calculation_implemented' => false,
            'note' => 'Tidak ada persentase, grace period, atau formula harian/bulanan yang ditetapkan. Posisi penalty pada urutan alokasi dikreservasi tanpa perhitungan.',
        ],

        'collection' => [
            'basis' => 'overdue/outstanding installments berdasarkan repayment status aktual',
            'fabricated_status_forbidden' => true,
            'detailed_rules' => 'PENDING BUSINESS CONFIRMATION (ticket Collection): contact cadence, collector assignment, escalation, visit rules.',
            'percentages_or_schedules_invented' => false,
        ],

        'credit_checking' => [
            'role' => 'pre_approval_assessment',
            'may_affect_progression_to_approval' => true,
            'numeric_scoring_formula' => 'PENDING BUSINESS CONFIRMATION (TIDAK mengarang formula skor numerik)',
            'workflow_source_of_truth' => 'existing credit-checking workflow (ShmCreditCheckStatus/ShmWorkflowService) sampai implementasi lebih lanjut',
        ],

        'document_requirement' => [
            'gate' => 'existing document completeness gate tetap berlaku untuk disbursement',
            'taxonomy' => 'PENDING BUSINESS CONFIRMATION (TIDAK membuat daftar dokumen baru di T79)',
        ],
    ],

    /*
    |-----------------------------------------------------------------------
    | Contoh finansial kontraktual (contract regression example)
    |
    | HANYA contoh perhitungan pada level contract untuk mengunci semantik
    | baseline. T79 TIDAK mengimplementasikan pembangkitan jadwal.
    |-----------------------------------------------------------------------
    */
    'financial_example' => [
        'note' => 'Contoh kontraktual. BUKAN implementasi pembangkitan jadwal.',
        'principal' => 100000000,
        'rate_percent' => 2.0,
        'rate_period' => 'monthly',
        'method' => 'flat',
        'tenor_months' => 12,
        'monthly_interest' => 2000000,
        'principal_divided_unrounded' => '100000000 / 12',
        'regular_installment_before_final_rounding' => 'approximately 10333333.33',
        'schedule_must' => [
            'contain_12_installments',
            'total_principal_exactly_100000000',
            'monthly_interest_2000000',
            'monthly_due_dates',
            'adjust_final_installment_for_rounding',
            'never_persist_fractional_rupiah',
        ],
    ],

    /*
    |-----------------------------------------------------------------------
    | Decision register
    |
    | Setiap domain terdaftar secara eksplisit dengan status:
    |   CONFIRMED BASELINE / CONFIRMED
    |   PENDING BUSINESS CONFIRMATION
    |   NOT APPLICABLE
    |
    | T79 mengubah rule finansial umum dari PENDING menjadi CONFIRMED BASELINE
    | berdasarkan keputusan user (general/standard loan business rules).
    | Rule yang memang belum diputuskan (penalty formula, collection detail,
    | credit scoring, document taxonomy, akuntansi overpayment, settlement
    | tolerance/discount) TETAP PENDING.
    |-----------------------------------------------------------------------
    */

    'decision_domains' => [

        /*
        |-------------------------------------------------------------------
        | 1. INTEREST — rate, period, method, application
        |-------------------------------------------------------------------
        */
        'interest' => [
            'status' => 'CONFIRMED BASELINE (period, method, basis); rate SUPERSEDED by T82 → product-driven',

            'official_rate_percent' => [
                'status' => 'SUPERSEDED by T82 → product-driven rate (CONFIRMED)',
                'value' => '2% (legacy T73/T79 — BUKAN lagi rate pinjaman)',
                'source' => 'Keputusan T82: rate = f(product, tenor) dari shm_loan_product_rate_tiers (ShmProductRateService). app/Models/ShmLoan.php::OFFICIAL_RATE_PERCENT hanya legacy simulasi.',
                'rationale' => 'T82 mengganti rate tunggal 2% dengan matriks 5 produk resmi (rate 1.10–1.70 per bulan per tenor EXAKT). Universal 2% / fallback rate DILARANG.',
            ],

            'interest_period' => [
                'status' => 'CONFIRMED BASELINE',
                'value' => 'monthly',
                'source' => 'User decision T79 — general/standard loan business rules: official rate 2% PER MONTH.',
                'rationale' => 'Sebelumnya PENDING (T73/T78); keputusan user T79 menetapkan periodisasi monthly.',
                'replaces' => 'T78: PENDING BUSINESS CONFIRMATION (interest_period)',
            ],

            'calculation_method' => [
                'status' => 'CONFIRMED BASELINE',
                'value' => 'flat',
                'source' => 'User decision T79 — metode flat (bunga dihitung dari pokok awal).',
                'rationale' => 'Sebelumnya PENDING (T73/T78); keputusan user T79 menetapkan metode flat. Effective/annuity BUKAN rule SHM.',
                'replaces' => 'T78: PENDING BUSINESS CONFIRMATION (calculation_method)',
            ],

            'rate_application' => [
                'status' => 'CONFIRMED BASELINE',
                'value' => 'original_principal',
                'decreases_with_outstanding_principal' => false,
                'monthly_formula' => 'original_principal × rate_produk% (T82; sebelumnya × 2%)',
                'source' => 'User decision T79 — interest basis = original loan principal; T82 — rate dari tier produk. Interest does NOT decrease as principal decreases.',
                'rationale' => 'Flat interest: bunga bulanan konstan selama pinjaman. BUKAN reducing-balance/effective.',
                'worked_example' => 'BSM Prioritas 12–18 bulan, principal Rp100.000.000 → bunga bulanan Rp1.300.000 (1.30%).',
            ],

            'interest_engine_present' => [
                'status' => 'CONFIRMED (capability); baseline method = flat',
                'note' => 'InterestCalculator (flat/effective/annuity/none) adalah capability kode. Rule SHM kini memilih flat; metode lain BUKAN baseline SHM.',
            ],
        ],

        /*
        |-------------------------------------------------------------------
        | 2. TENOR — arti tenor_months, batas, perubahan setelah approval
        |-------------------------------------------------------------------
        */
        'tenor' => [
            'status' => 'CONFIRMED BASELINE for meaning/unit; bounds & perubahan pasca-approval tetap PENDING',

            'tenor_months_meaning' => [
                'status' => 'CONFIRMED BASELINE',
                'value' => 'jumlah periode angsuran bulanan',
                'unit' => 'month',
                'installments_equals_tenor' => true,
                'example' => '12 bulan = 12 angsuran terjadwal.',
                'source' => 'User decision T79 — tenor expressed in months; tenor determines number of monthly installment periods.',
                'replaces' => 'T78: meaning PENDING (structural only)',
            ],

            'tenor_bounds' => [
                'status' => 'PENDING BUSINESS CONFIRMATION',
                'value' => 'TIDAK ADA batas institusional yang diadopsi di T79',
                'why_pending' => 'T79: "Do not introduce special tenor bounds unless already explicitly required elsewhere." config/business-rules.php (min 1 / max 120) adalah nilai PROVISIONAL MVP koperasi umum dan TIDAK diadopsi sebagai rule SHM.',
                'if_needed_later' => 'Batas validasi harus configurable; jangan mengarang batas institusional.',
                'cannot_guess' => true,
            ],

            'tenor_after_approval' => [
                'status' => 'PENDING BUSINESS CONFIRMATION',
                'why_pending' => 'T79 tidak memutuskan apakah tenor dapat diubah setelah approval, siapa yang berwenang, atau dalam kondisi apa.',
                'cannot_guess' => true,
            ],

            'integer_required' => [
                'status' => 'CONFIRMED (technical storage rule)',
                'value' => 'tenor_months disimpan sebagai integer',
                'source' => 'casts() pada ShmLoan/ShmLoanApplication: tenor_months => integer',
            ],
        ],

        /*
        |-------------------------------------------------------------------
        | 3. INSTALLMENT FREQUENCY
        |-------------------------------------------------------------------
        */
        'installment_frequency' => [
            'status' => 'CONFIRMED BASELINE',

            'value' => 'monthly',
            'installments_per_month' => 1,
            'number_of_installments' => 'tenor_months',
            'one_tenor_month_equals_one_installment' => true,
            'source' => 'User decision T79 — monthly; one scheduled installment per month; number of installments = tenor in months.',
            'replaces' => 'T78: PENDING BUSINESS CONFIRMATION (installment_frequency)',
        ],

        /*
        |-------------------------------------------------------------------
        | 4. REPAYMENT SCHEDULE — due date rules, generation, idempotency
        |-------------------------------------------------------------------
        */
        'repayment_schedule' => [
            'status' => 'CONFIRMED BASELINE for generation inputs, eligibility, due-date rules, idempotency; regeneration/imutabilitas pasca-payment tetap PENDING',

            'what_is_confirmed' => [
                'Input pembangkitan: principal, tenor, rate produk (T82 — f(product, tenor)), interest period, calculation method, first_due_date sebagai anchor (T83; disbursed_at BUKAN anchor).',
                'Pinjaman eligible: active DAN disbursed_at IS NOT NULL (boundary T75 tetap berlaku).',
                'Jadwal berisi tepat tenor angsuran; installment_number mulai 1 dan unik per pinjaman.',
                'Jadwal eksisting diperlakukan idempoten: tidak duplikat, tidak overwrite diam-diam.',
                'Due-date rule (lihat due_date_rule di bawah).',
                'Metode flat + pokok equal + pembulatan rupiah (lihat domain interest/rounding).',
            ],

            'due_date_rule' => [
                'status' => 'CONFIRMED BASELINE',
                'first_due_date' => 'ditentukan Marketing pada pengajuan (shm_loan_applications.first_due_date; T82) — BUKAN dihitung otomatis dari tanggal pencairan',
                'progression' => 'bulanan',
                'preserve_day_of_month' => true,
                'missing_day_fallback' => 'hari kalender terakhir yang valid pada bulan tersebut',
                'grace_period' => 'TIDAK diperkenalkan (none) kecuali dikonfigurasi terpisah',
                'generator_anchor' => [
                    'status' => 'IMPLEMENTED (T83)',
                    'rule' => 'Installment #1 jatuh tepat pada first_due_date; angsuran berikutnya = first_due_date + i bulan dari anchor ASLI (addMonthsNoOverflow) sehingga tanpa drift; TIDAK memakai disbursed_at; tanpa fallback bila first_due_date null (ditolak eksplisit).',
                ],
                'examples' => [
                    'First due date 12 November → jatuh tempo berikutnya 12 Desember.',
                    'First due date 31 Januari → jatuh tempo berikutnya = hari terakhir Februari.',
                ],
                'source' => 'User decision T79 (progresi/fallback) + T82 (first due date dipilih Marketing, disimpan sebagai kolom date).',
            ],

            'what_is_pending' => [
                'Apakah schedule dapat diregenerasi setelah pembayaran/ubah data (policy regenerasi).',
                'Apakah schedule immutable setelah payment dan siapa yang berwenang melakukan perubahan.',
            ],

            'cannot_guess' => true,
            'implementation_note' => 'T80 mengimplementasikan pembangkitan pada ShmRepaymentScheduleService (boundary T75 dipertahankan: eligibility + idempotency + lockForUpdate + atomik). Pembangkitan tetap MENOLAK pinjaman tanpa kontrak baseline eksplisit (rate produk dari tier resmi T82 + rate_period monthly + calculation_method flat). Pengisian kontrak tersebut saat pencairan DIIMPLEMENTASIKAN T81: ShmLoanDisbursementService menstempel monthly+flat+rate produk hasil resolusi tier (T82; stempel 2% pada T81 SUPERSEDED) bersama pembuatan loan dalam transaksi yang sama; pembangkitan jadwal TETAP terpisah dari pencairan.',
        ],

        /*
        |-------------------------------------------------------------------
        | 5. ROUNDING
        |-------------------------------------------------------------------
        */
        'rounding' => [
            'status' => 'CONFIRMED BASELINE',

            'monetary_unit' => 'rupiah',
            'persist_fractional_rupiah' => false,
            'method' => 'nearest_rupiah',
            'apply_to' => [
                'interest',
                'installment',
                'outstanding',
                'total_payment',
            ],
            'accumulate_error' => false,
            'difference_handling' => 'selisih pembulatan diselesaikan pada angsuran terakhir (final installment)',
            'source' => 'User decision T79 — rounding rules.',
            'note' => 'Kolom desimal pada schema menyimpan nilai rupiah utuh (mis. 10333333.00); TIDAK ada pecahan rupiah yang disimpan. T79 TIDAK mengubah schema.',
            'replaces' => 'T78: PENDING BUSINESS CONFIRMATION (rounding)',
        ],

        /*
        |-------------------------------------------------------------------
        | 6. FINAL INSTALLMENT — balancing installment
        |-------------------------------------------------------------------
        */
        'final_installment' => [
            'status' => 'CONFIRMED BASELINE',

            'role' => 'balancing installment',
            'must_satisfy' => [
                'total principal paid = original principal',
                'remaining principal = 0 setelah pelunasan berhasil',
            ],
            'may_differ_from_regular_installments' => true,
            'reason' => 'penyesuaian pembulatan',
            'source' => 'User decision T79 — final installment rules.',
            'replaces' => 'T78: PENDING BUSINESS CONFIRMATION (final_installment)',
        ],

        /*
        |-------------------------------------------------------------------
        | 7. PAYMENT — allocation order (channel/fields tetap PENDING)
        |-------------------------------------------------------------------
        */
        'payment' => [
            'status' => 'CONFIRMED BASELINE for allocation semantics; channel & mandatory fields tetap PENDING BUSINESS CONFIRMATION',

            'allocation_order' => [
                'status' => 'CONFIRMED BASELINE',
                'chronological' => 'Kewajiban jatuh tempo lebih awal diselesaikan sebelum yang lebih akhir.',
                'within_installment' => ['penalty', 'interest', 'principal'],
                'penalty_note' => 'Posisi penalty dikreservasi dalam urutan; perhitungan penalty TIDAK diimplementasikan (rule penalty masih PENDING).',
                'source' => 'User decision T79 — payment allocation order.',
                'replaces' => 'T78: PENDING BUSINESS CONFIRMATION (payment allocation)',
            ],

            'payment_channel' => [
                'status' => 'PENDING BUSINESS CONFIRMATION',
                'why_pending' => 'T79 tidak memutuskan channel (cash/bank transfer/lain) maupun field wajib payment (date, amount, reference, collector, notes).',
                'cannot_guess' => true,
            ],

            'note' => 'T79 BUKAN implementasi payment: tidak ada tabel, model, endpoint, atau UI payment yang dibuat.',
        ],

        /*
        |-------------------------------------------------------------------
        | 8. PARTIAL PAYMENT
        |-------------------------------------------------------------------
        */
        'partial_payment' => [
            'status' => 'CONFIRMED BASELINE',

            'allowed' => true,
            'reduces' => 'outstanding obligation tertua yang applicable',
            'auto_mark_paid' => 'hanya ketika total outstanding angsuran mencapai nol',
            'source' => 'User decision T79 — partial payment rules.',
            'replaces' => 'T78: PENDING BUSINESS CONFIRMATION (partial payment)',
        ],

        /*
        |-------------------------------------------------------------------
        | 9. OVERPAYMENT
        |-------------------------------------------------------------------
        */
        'overpayment' => [
            'status' => 'CONFIRMED BASELINE for representation; accounting treatment tetap PENDING BUSINESS CONFIRMATION',

            'silently_discarded' => false,
            'representation' => 'explicit unapplied/overpayment balance atau state finansial setara',
            'accounting_treatment' => [
                'status' => 'PENDING BUSINESS CONFIRMATION',
                'why_pending' => 'Perlakuan akuntansi persis (credit balance, auto-apply, refund) milik ticket Payment. T79 tidak mengarang jurnal.',
                'cannot_guess' => true,
            ],
            'source' => 'User decision T79 — overpayment must not be silently discarded.',
            'replaces' => 'T78: PENDING BUSINESS CONFIRMATION (overpayment)',
        ],

        /*
        |-------------------------------------------------------------------
        | 10. PAYMENT REVERSAL
        |-------------------------------------------------------------------
        */
        'payment_reversal' => [
            'status' => 'CONFIRMED BASELINE for principles; implementation tetap PENDING (ticket Payment)',

            'explicit' => true,
            'auditable' => true,
            'delete_history' => false,
            'restores_obligation' => true,
            'implementation_detail' => [
                'status' => 'PENDING BUSINESS CONFIRMATION',
                'why_pending' => 'Siapa yang boleh melakukan, alasan wajib, dampak setelah settlement, dan mekanisme persistensi milik ticket Payment.',
                'cannot_guess' => true,
            ],
            'source' => 'User decision T79 — reversal must be explicit, auditable, must not delete historical records, restores the affected obligation.',
            'replaces' => 'T78: PENDING BUSINESS CONFIRMATION (payment reversal)',
        ],

        /*
        |-------------------------------------------------------------------
        | 11. PENALTY
        |-------------------------------------------------------------------
        */
        'penalty' => [
            'status' => 'PENDING BUSINESS CONFIRMATION',

            'what_is_pending' => [
                'Apakah penalty ada.',
                'Persentase/nominal penalty.',
                'Grace period.',
                'Formula harian/bulanan.',
                'Basis perhitungan.',
                'Frequency.',
                'Maximum penalty.',
                'Waive policy dan kewenangan waiver.',
            ],

            'confirmed_in_t79' => 'PENALTY TIDAK DIIMPLEMENTASIKAN dan TIDAK memiliki nilai default. Urutan alokasi pembayaran mengreservasi posisi penalty tanpa perhitungan.',
            'note' => 'T79 tidak mengadopsi nilai penalty dari config/business-rules.php (provisional). Tidak ada persentase yang dikarang.',
            'cannot_guess' => true,
            'source' => 'T79 §2P — penalty calculation NOT defined by this ticket.',
        ],

        /*
        |-------------------------------------------------------------------
        | 12. SETTLEMENT / PELUNASAN
        |-------------------------------------------------------------------
        */
        'settlement' => [
            'status' => 'CONFIRMED BASELINE for amount composition; discount/tolerance/implementation tetap PENDING BUSINESS CONFIRMATION',

            'early_settlement_allowed' => true,
            'amount_components' => [
                'status' => 'CONFIRMED BASELINE',
                'value' => [
                    'outstanding principal',
                    'applicable obligations yang sudah jatuh tempo/terakru menurut jadwal yang ditetapkan',
                ],
            ],

            'future_flat_interest_not_auto_charged' => [
                'status' => 'CONFIRMED BASELINE',
                'value' => true,
                'note' => 'Bunga flat masa depan yang belum jatuh tempo TIDAK otomatis diperlakukan sebagai bunga historis yang harus dibayar.',
            ],

            'discount_or_rebate' => [
                'status' => 'PENDING BUSINESS CONFIRMATION',
                'cannot_guess' => true,
                // T89A — kelayakan diputus, besarAN/formula tetap PENDING.
                'scope' => 'early_settlement_interest_discount',
                'threshold_principal_gt' => '50000000',
                'threshold_basis' => 'principal/plafon awal loan',
                'eligible_above_threshold' => true,
                'eligible_at_or_below_threshold' => false,
                'note' => 'T89A: pinjaman > Rp50.000.000 eligible potongan bunga saat pelunasan; <= Rp50.000.000 bunga flat tanpa potongan. Besaran/formula potongan TETAP PENDING BUSINESS CONFIRMATION di domain ini (tidak ditebak, lihat settlement.interest_discount). T89B mengimplementasikan mekanisme persentase data-driven (master policy, snapshot immutable) tanpa mengubah status domain ini — bentuk rebate lain di luar potongan bunga pelunasan tetap PENDING.',
            ],

            'tolerance' => [
                'status' => 'PENDING BUSINESS CONFIRMATION',
                'note' => 'T79 TIDAK mengadopsi settlement_tolerance 0.01 dari config/business-rules.php sebagai rule SHM.',
                'cannot_guess' => true,
            ],

            'implementation' => [
                'status' => 'PENDING BUSINESS CONFIRMATION',
                'why_pending' => 'Implementasi settlement/pelunasan milik ticket terpisah; T79 hanya menetapkan komposisi amount.',
            ],

            'source' => 'User decision T79 — early settlement baseline.',
            'replaces' => 'T78: PENDING BUSINESS CONFIRMATION (settlement)',
        ],

        /*
        |-------------------------------------------------------------------
        | 13. COLLECTION
        |-------------------------------------------------------------------
        */
        'collection' => [
            'status' => 'CONFIRMED BASELINE for operational basis; detailed rules tetap PENDING BUSINESS CONFIRMATION',

            'basis' => [
                'status' => 'CONFIRMED BASELINE',
                'value' => 'collection beroperasi pada angsuran overdue/outstanding berdasarkan repayment status aktual',
                'fabricated_status_forbidden' => true,
            ],

            'detailed_rules' => [
                'status' => 'PENDING BUSINESS CONFIRMATION',
                'what_is_pending' => [
                    'Contact cadence (reminder).',
                    'Collector assignment.',
                    'Escalation rules.',
                    'Visit rules.',
                    'Promise-to-pay handling.',
                    'Aging bucket jika diperlukan.',
                ],
                'cannot_guess' => true,
            ],

            'percentages_or_schedules_invented' => false,
            'note' => 'T79 tidak membuat collection engine, menu, atau automation. Tidak ada persentase/jadwal collection yang dikarang.',
            'source' => 'User decision T79 — collection operational baseline.',
            'replaces' => 'T78: PENDING BUSINESS CONFIRMATION (collection)',
        ],

        /*
        |-------------------------------------------------------------------
        | 14. CREDIT CHECKING — outcome semantics
        |-------------------------------------------------------------------
        */
        'credit_checking' => [
            'status' => 'CONFIRMED BASELINE for role; scoring formula tetap PENDING BUSINESS CONFIRMATION',

            'role' => [
                'status' => 'CONFIRMED BASELINE',
                'value' => 'pre-approval assessment',
                'may_affect_progression_to_approval' => true,
            ],

            'outcome_values_exist' => [
                'status' => 'CONFIRMED',
                'values' => ['pass', 'review', 'fail'],
                'source' => 'app/Enums/ShmCreditCheckOutcome.php; config/shm.php credit_checking_outcomes',
                'note' => 'Enum & label PASS/REVIEW/FAIL ada sebagai hasil pencatatan manual petugas.',
            ],

            'scoring_formula' => [
                'status' => 'PENDING BUSINESS CONFIRMATION',
                'why_pending' => 'T79: exact scoring formula tidak diperlukan; TIDAK mengarang formula skor numerik. Threshold, weighting, dan ambang angka tidak boleh dibuat.',
                'cannot_guess' => true,
            ],

            'workflow_source_of_truth' => [
                'status' => 'CONFIRMED',
                'value' => 'existing credit-checking workflow (Pending → InProgress → Completed; outcome direkam manual; keputusan tetap di Approval)',
                'source' => 'app/Enums/ShmCreditCheckStatus.php; app/Services/ShmWorkflowService.php',
            ],

            'source' => 'User decision T79 — credit checking sebagai pre-approval assessment.',
            'replaces' => 'T78: outcome business effect PENDING (kini role confirmed, mechanics tetap mengikuti workflow existing)',
        ],

        /*
        |-------------------------------------------------------------------
        | 15. DOCUMENT REQUIREMENT
        |-------------------------------------------------------------------
        */
        'document_requirement' => [
            'status' => 'CONFIRMED GATE only; required document list tetap PENDING BUSINESS CONFIRMATION',

            'existing_gate' => [
                'status' => 'CONFIRMED',
                'rule' => 'Disbursement hanya dapat dilakukan jika seluruh item dokumen berstatus complete atau not_applicable (bukan pending). Gate ditegakkan di server melalui ShmWorkflowService::guardDocumentCompleteness() dan ShmApplication::documentsComplete().',
                'source' => 'app/Services/ShmWorkflowService.php; app/Models/ShmApplication.php; app/Models/ShmDocument.php',
            ],

            'required_document_list_decision' => [
                'status' => 'PENDING BUSINESS CONFIRMATION',
                'why_pending' => 'Daftar dokumen wajib/opsional/kondisional belum ada di repo. document_name disimpan bebas. T79 tidak membuat daftar dokumen rekaan (taxonomy tetap PENDING). Keputusan daftar TIDAK disimpan di sini sebagai list — hanya status keputusannya.',
                'cannot_guess' => true,
                'source' => 'T79 §2S — do not invent a new document requirement list; config/shm.php document_requirement_context status pending.',
            ],

            'dependencies_not_decided' => [
                'status' => 'PENDING BUSINESS CONFIRMATION',
                'what_is_pending' => [
                    'Dependency terhadap SHM/SHGB.',
                    'Dependency terhadap Buyback.',
                    'Dependency terhadap loan amount.',
                    'Dependency terhadap customer type.',
                    'Dependency terhadap kondisi bisnis lain.',
                ],
            ],
        ],

        /*
        |-------------------------------------------------------------------
        | 16. CUSTOMER LIFECYCLE
        |-------------------------------------------------------------------
        */
        'customer_lifecycle' => [
            'status' => 'PENDING BUSINESS CONFIRMATION',

            'what_is_pending' => [
                'Apakah SHM customer dapat dinonaktifkan.',
                'Apakah customer dengan active loan boleh dinonaktifkan.',
                'Apakah customer dapat dihapus.',
                'Apakah customer dengan application history boleh dihapus.',
                'Bagaimana historical applications dipertahankan.',
            ],

            'note' => 'T79 tidak memutuskan lifecycle customer. Prefrensi teknis tidak dianggap sebagai rule bisnis.',
            'cannot_guess' => true,
            'source' => 'TIDAK ADA di repo / T79 tidak membahas lifecycle customer.',
        ],

        /*
        |-------------------------------------------------------------------
        | 17. APPROVAL (existing flow)
        |-------------------------------------------------------------------
        */
        'approval' => [
            'status' => 'CONFIRMED FLOW; authority/role tetap PENDING BUSINESS CONFIRMATION',

            'existing_flow' => [
                'status' => 'CONFIRMED',
                'rule' => 'Approval menggunakan workflow existing ShmApplicationStatus + ShmApproval + ShmWorkflowService. Approval harus ada sebelum pencairan.',
                'source' => 'app/Enums/ShmApplicationStatus.php; app/Models/ShmApproval.php; app/Services/ShmWorkflowService.php; T79 §2T',
            ],

            'approval_before_disbursement' => [
                'status' => 'CONFIRMED',
                'value' => true,
                'enforcement' => 'ShmLoanDisbursementService::disburse() menolak pengajuan tanpa approval approved.',
            ],

            'authority_and_role' => [
                'status' => 'PENDING BUSINESS CONFIRMATION',
                'why_pending' => 'T79 tidak mengubah kewenangan approval: siapa yang berhak, multi-level, pagu nominal tetap belum diputuskan.',
                'cannot_guess' => true,
                'source' => 'config/shm.php pending_decisions; T79 §2T (do not alter approval authority).',
            ],
        ],

        /*
        |-------------------------------------------------------------------
        | 18. DISBURSEMENT (existing gate)
        |-------------------------------------------------------------------
        */
        'disbursement' => [
            'status' => 'CONFIRMED GATE & flow; detailed financial rule tetap PENDING',

            'existing_gate' => [
                'status' => 'CONFIRMED',
                'rule' => 'Hanya pengajuan dengan approval approved yang dapat dicairkan; disbursement sukses membuat pinjaman SHM aktif. ShmLoanDisbursementService tetap source of truth; T79 tidak mengubah behavior-nya.',
                'source' => 'app/Services/ShmLoanDisbursementService.php; T79 §2U',
            ],

            'detailed_rule' => [
                'status' => 'PENDING BUSINESS CONFIRMATION',
                'why_pending' => 'Perlakuan provisi, administrasi, dan blokir dana belum diputuskan/diimplementasikan di T79 (biaya resmi dikunci pada T84 di bawah domain disbursement_costs).',
                'cannot_guess' => true,
                'note' => 'Disbursement service menyalin principal, tenor, dan (sejak T81/T82) menstempel kontrak finansial — rate hasil resolusi EXAKT product+tenor + rate_period monthly + calculation_method flat + first_due_date (Marketing-pilih) — pada record pinjaman dalam transaksi pencairan yang sama; tenor di luar range eksak produk MENOLAK pencairan; pinjaman historis tanpa kontrak TIDAK di-backfill.',
                'source' => 'app/Services/ShmLoanDisbursementService.php',
            ],
        ],

        /*
        |-------------------------------------------------------------------
        | 19. COLLATERAL (existing scope)
        |-------------------------------------------------------------------
        */
        'collateral' => [
            'status' => 'CONFIRMED as application-owned section; financial treatment tetap PENDING',

            'existing_scope' => [
                'status' => 'CONFIRMED',
                'rule' => 'Collateral application merupakan bagian dari loan application, disimpan di shm_loan_application_collaterals, memiliki foto jaminan di storage privat, dan disajikan lewat route terotorisasi.',
                'source' => 'app/Models/ShmLoanApplicationCollateral.php; app/Services/ShmLoanApplicationService.php; T77',
            ],

            'financial_treatment' => [
                'status' => 'PENDING BUSINESS CONFIRMATION',
                'why_pending' => 'T79 tidak memutuskan apakah nilai jaminan mengikat pinjaman, binding rule, shortfall treatment, atau pengaruh otomatis terhadap approval.',
                'cannot_guess' => true,
            ],
        ],

        /*
        |-------------------------------------------------------------------
        | 20. RUNNING LOAN — rate surface & status
        |-------------------------------------------------------------------
        */
        'running_loan' => [
            'status' => 'CONFIRMED',

            'official_rate_surface' => [
                'status' => 'SUPERSEDED by T82 → product-driven rate (CONFIRMED)',
                'value' => 'rate tier produk (product + tenor eksak)',
                'source' => 'app/Services/Shm/ShmProductRateService.php; shm_loans.rate_percent distempel pencairan',
            ],

            'status_values' => [
                'status' => 'CONFIRMED',
                'values' => ['active', 'closed'],
                'note' => 'T79 tidak memperkenalkan status pinjaman tambahan.',
                'source' => 'app/Models/ShmLoan.php (STATUS_ACTIVE / STATUS_CLOSED); T79 §2V',
            ],

            'application_to_loan_separation' => [
                'status' => 'CONFIRMED',
                'rule' => 'shm_loan_applications adalah application/pre-decision aggregate; shm_loans adalah running loan setelah disbursement. Konversi satu pengajuan → tepat satu pinjaman (idempotent, unique di database).',
                'source' => 'app/Models/ShmLoanApplication.php; app/Models/ShmLoan.php; app/Services/ShmLoanDisbursementService.php',
            ],

            'rate_period_and_method_on_loan' => [
                'status' => 'CONFIRMED BASELINE rule; IMPLEMENTED (T81 stempel monthly+flat; T82 rate produk)',
                'baseline_rule' => 'Periode rate = monthly; metode = flat (dari baseline T79); rate = hasil resolusi EXAKT product+tenor (T82) — bukan universal 2%.',
                'note' => 'ShmLoanDisbursementService menstempel rate_period/calculation_method/rate produk pada record pinjaman dalam transaksi pencairan yang sama (T81/T82); pinjaman historis TIDAK di-backfill.',
                'cannot_guess' => true,
            ],
        ],

        /*
        |-------------------------------------------------------------------
        | 21. MARKETING ACTIVITY
        |-------------------------------------------------------------------
        */
        'marketing_activity' => [
            'status' => 'CONFIRMED',

            'rule' => [
                'status' => 'CONFIRMED',
                'rule' => 'SHM marketing activity menggunakan shm_customers, bukan cooperative members.',
                'source' => 'app/Models/MarketingActivity.php; config/shm.php pending_decisions; T73 existing confirmed rules',
            ],
        ],

        /*
        |-------------------------------------------------------------------
        | 22. APPLICATION PERSONAL DATA
        |-------------------------------------------------------------------
        */
        'application_personal_data' => [
            'status' => 'CONFIRMED',

            'rule' => [
                'status' => 'CONFIRMED',
                'rule' => 'Personal data pengajuan berada pada shm_loan_applications, bukan pada shm_customers.',
                'source' => 'T76-FIX; app/Models/ShmLoanApplication.php (unit_kerja, nip, telepon_rumah, tempat_lahir, tanggal_lahir, jumlah_tanggungan, saldo_modal, saldo_non_modal, batas_hutang)',
            ],

            'source_semantics_for_saldo_batas' => [
                'status' => 'PENDING BUSINESS CONFIRMATION',
                'why_pending' => 'Semantik bisnis resmi saldo_modal, saldo_non_modal, dan batas_hutang belum ditetapkan.',
                'cannot_guess' => true,
            ],
        ],

        /*
        |-------------------------------------------------------------------
        | 23. SHM CUSTOMER vs MEMBER
        |-------------------------------------------------------------------
        */
        'shm_customer_separaton' => [
            'status' => 'CONFIRMED',

            'rule' => [
                'status' => 'CONFIRMED',
                'rule' => 'SHM customer berbeda dari cooperative member. Tidak boleh auto-convert, tidak boleh mencampur data member, dan tidak boleh menggunakan members.id untuk SHM customer. SHM loan hanya menggunakan shm_customers.',
                'source' => 'shm_customers; app/Models/ShmCustomer.php; ShmLoan::scopeSearch hanya menggabungkan shm_customers; T73/T79 §2A',
            ],
        ],

        /*
        |-------------------------------------------------------------------
        | 24. SHGB vs SHM vs BUYBACK (taxonomy, not finansial)
        |-------------------------------------------------------------------
        */
        'certificate_type' => [
            'status' => 'CONFIRMED as technical taxonomy only',

            'what_is_confirmed' => [
                'certificate_type membedakan jenis sertifikat (SHM / SHGB).',
                'Memorandum dapat membedakan SHM/SHGB vs Buyback (kategori naskah).',
            ],
            'what_is_pending' => [
                'Apakah SHM dan SHGB diperlakukan berbeda secara bisnis.',
                'Aturan buyback dan pencairan.',
            ],
            'source' => 'app/Models/ShmMemorandum.php; config/shm.php certificate_types & pending_decisions',
            'cannot_guess' => true,
        ],

        /*
        |-------------------------------------------------------------------
        | 25. REOPEN / ROLLBACK / CANCELLATION
        |-------------------------------------------------------------------
        */
        'control_flow_reopen' => [
            'status' => 'PARTIALLY CONFIRMED (existing technical reopen exists); business rule tetap PENDING',

            'existing' => [
                'status' => 'CONFIRMED',
                'rule' => 'Rejected → Draft sudah ada di state machine. ReopenDocumentCheck ada sebagai mekanisme teknis dari ready_for_disbursement bila dokumen tidak lengkap lagi.',
                'source' => 'app/Enums/ShmApplicationStatus.php; app/Services/ShmWorkflowService.php (returnToDraft / reopenDocumentCheck)',
            ],

            'business_rule' => [
                'status' => 'PENDING BUSINESS CONFIRMATION',
                'why_pending' => 'Belum diputuskan siapa yang boleh membatalkan/memperbaiki pengajuan berjalan, apakah pembatalan pasca-approval diizinkan, dan kondisi pemulihan.',
                'cannot_guess' => true,
            ],
        ],

        /*
        |-------------------------------------------------------------------
        | 26. AUDIT / TRACEABILITY
        |-------------------------------------------------------------------
        */
        'audit_trail' => [
            'status' => 'CONFIRMED as existing implementation capability only',

            'what_is_confirmed' => [
                'Workflow existing menulis satu baris audit per aksi (status change, credit check, memorandum, document, approval).',
                'Actor selalu dari pengguna yang benar-benar menjalankan aksi, bukan dari payload.',
            ],
            'what_is_pending' => [
                'Apakah audit berfungsi sebagai rekam keputusan finansial resmi.',
                'Retensi & akses audit.',
            ],
            'source' => 'app/Services/AuditLogger.php usage di ShmWorkflowService & ShmLoan*Service',
            'cannot_guess' => true,
        ],

        /*
        |-------------------------------------------------------------------
        | 27. DISBURSEMENT COSTS (T84 CONFIRMED)
        |-------------------------------------------------------------------
        */
        'disbursement_costs' => [
            'status' => 'CONFIRMED (T84)',
            'admin_fee_percent' => 2.0,
            'admin_fee_rule' => '2% × plafond pinjaman',
            'provision_fee_percent' => 3.0,
            'provision_fee_rule' => '3% × plafond pinjaman',
            'insurance_fee_percent' => 1.0,
            'insurance_fee_rule' => '1% × plafond pinjaman',
            'notary_fee_type' => 'custom',
            'notary_fee_rule' => 'custom/fillable nominal (bukan fixed %, diinput authorized user, tanpa preset nominal)',
            'principal_savings_amount' => 100000,
            'principal_savings_rule' => 'Rp100.000 tetap',
            'mandatory_savings_monthly_amount' => 25000,
            'mandatory_savings_rule' => 'Rp25.000 × tenor dalam bulan',
            'deduction_timing' => 'initial_disbursement',
            'deduction_timing_rule' => 'Seluruh komponen biaya/potongan dipotong langsung pada awal pencairan',
            'total_deduction_formula' => 'admin_fee + provision_fee + insurance_fee + notary_fee + principal_savings + mandatory_savings',
            'net_disbursement_formula' => 'principal_amount - total_deduction',
            'source' => 'T84 Official Disbursement Cost Contract',
        ],

        /*
        |-------------------------------------------------------------------
        | 28. NET DISBURSEMENT (T84 CONFIRMED)
        |-------------------------------------------------------------------
        */
        'net_disbursement' => [
            'status' => 'CONFIRMED (T84)',
            'formula' => 'principal_amount - total_deduction',
            'original_principal_role' => 'Plafond / original principal adalah pokok pinjaman sebenarnya, basis perhitungan bunga flat bulanan, dan basis pokok pada jadwal angsuran.',
            'net_disbursement_role' => 'Dana bersih yang benar-benar diterima nasabah setelah potongan awal.',
            'principal_preservation_rule' => 'Original principal TIDAK boleh diubah oleh biaya pencairan. Net disbursement TIDAK boleh digunakan sebagai pokok pinjaman (loan principal) atau dasar perhitungan bunga.',
            'source' => 'T84 Loan Principal vs Net Disbursement Contract',
        ],

        /*
        |-------------------------------------------------------------------
        | 29. PAYMENT ALLOCATION & CONTRACT (T84 CONFIRMED BASELINE)
        |-------------------------------------------------------------------
        */
        'payment_allocation' => [
            'status' => 'CONFIRMED BASELINE (T84)',
            'order' => ['penalty', 'interest', 'principal'],
            'chronological_order' => true,
            'rule' => 'Kewajiban jatuh tempo paling awal diselesaikan lebih dulu (kronologis). Dalam satu angsuran: penalty → interest → principal. Jika penalty = 0, alokasi langsung ke interest; jika interest lunas, lanjut ke principal.',
            'penalty_slot' => 'Slot alokasi penalty dikreservasi tanpa formula (formula penalty PENDING BUSINESS CONFIRMATION)',
            'source' => 'T84 Payment Allocation Contract',
        ],

        /*
        |-------------------------------------------------------------------
        | 30. PAYMENT IDEMPOTENCY (T84 CONFIRMED)
        |-------------------------------------------------------------------
        */
        'payment_idempotency' => [
            'status' => 'CONFIRMED (T84)',
            'rule' => 'Request/transaksi pembayaran yang sama tidak boleh menghasilkan dua record finansial pembayaran. Duplicate submission harus ditolak deterministik. Retry request tidak boleh menggandakan pembayaran.',
            'protection' => 'Unique key / idempotency token pada transaksi pembayaran',
            'source' => 'T84 Payment Idempotency Contract',
        ],

        /*
        |-------------------------------------------------------------------
        | 31. PAYMENT CONCURRENCY (T84 CONFIRMED)
        |-------------------------------------------------------------------
        */
        'payment_concurrency' => [
            'status' => 'CONFIRMED (T84)',
            'rule' => 'Loan dan installment financial state wajib dikunci secara transaksional (row locking / SELECT FOR UPDATE). Outstanding obligation tidak boleh dihitung berdasarkan stale state. Dua pembayaran konkuren tidak boleh mendistorsi sisa kewajiban. Alokasi harus atomik dalam satu transaksi database.',
            'mechanism' => 'Database transaction + row-level locking',
            'source' => 'T84 Payment Concurrency Contract',
        ],

        /*
        |-------------------------------------------------------------------
        | 32. SETTLEMENT DEPENDENCY (T84 CONFIRMED BASELINE)
        |-------------------------------------------------------------------
        */
        'settlement_dependency' => [
            'status' => 'CONFIRMED BASELINE (T84)',
            'early_settlement_composition' => 'outstanding principal + obligations due/accrued according to schedule',
            'future_flat_interest' => 'Bunga flat masa depan yang belum jatuh tempo TIDAK otomatis dibebankan seluruhnya',
            'workflow_separation' => 'Pelunasan dipercepat (settlement) merupakan workflow terpisah (T89). Pembayaran angsuran normal TIDAK otomatis menutup pinjaman (active → closed).',
            'source' => 'T84 Settlement Dependency Contract',
        ],

        /*
        |-------------------------------------------------------------------
        | 33. COLLECTION DEPENDENCY (T84 CONFIRMED BASELINE)
        |-------------------------------------------------------------------
        */
        'collection_dependency' => [
            'status' => 'CONFIRMED BASELINE (T84)',
            'source_of_truth' => 'Collection membaca kondisi repayment aktual dari Loan → Installment → Payment Allocation → Outstanding / Overdue State. Collection TIDAK memiliki source of truth pembayaran tersendiri.',
            'workflow_separation' => 'Collection implementation ditangani terpisah pada T90.',
            'source' => 'T84 Collection Dependency Contract',
        ],
    ],

    /*
    |-----------------------------------------------------------------------
    | Top-level ringkasan (kompatibel pembaca T73/T75)
    |
    | Bagian ini mempertahankan bentuk kontrak T73 yang dibaca
    | ShmRepaymentScheduleService (installment.status / blocked_by,
    | repayment_schedule.status / blocked_by). Status diperbarui sesuai
    | baseline T79.
    |-----------------------------------------------------------------------
    */
    'rate' => [
        // T82 (user decision): rate SHM = f(PRODUCT, TENOR) dari matriks
        // produk resmi. Universal 2% SUPERSEDED — tidak ada rate global,
        // tidak ada fallback 2%, tidak ada interpolasi/nearest/default.
        'status' => 'SUPERSEDED by T82 → product-driven rate (CONFIRMED)',

        'product_driven_rate' => [
            'status' => 'CONFIRMED (T82)',
            'rule' => 'Rate = tier rate produk untuk tenor EXAKT (min_tenor_months <= tenor <= max_tenor_months); gap antar range INVALID; tanpa interpolasi/fallback/nearest/default; produk nonaktif ditolak.',
            'value' => 'shm_loan_product_rate_tiers (5 produk resmi; rate 1.10–1.70 per bulan)',
            'source' => 'app/Services/Shm/ShmProductRateService.php; database/seeders/ShmLoanProductSeeder.php; keputusan user T82',
        ],

        // TERCATAT sebagai historical value lama (T73/T79): angka tunggal
        // resmi SHM = 2%. BUKAN lagi rate yang dipakai pinjaman sejak T82
        // (hanya simulasi legacy yang masih menyebutnya).
        'legacy_official_rate_percent' => 2.0,

        'interest_period' => [
            'status' => 'CONFIRMED BASELINE',
            'value' => 'monthly',
            'note' => 'T79 (user decision): rate PER BULAN. T82 mempertahankan periodisasi monthly untuk seluruh tier rate produk.',
        ],

        'calculation_method' => [
            'status' => 'CONFIRMED BASELINE',
            'value' => 'flat',
            'note' => 'T79 (user decision): metode flat — bunga dari pokok awal, tidak menurun. T82 mempertahankan flat untuk seluruh produk. Effective/annuity BUKAN baseline SHM.',
        ],

        'rate_application' => [
            'status' => 'CONFIRMED BASELINE',
            'value' => 'original_principal',
            'note' => 'Bunga bulanan = original_principal × rate_produk%, konstan selama pinjaman (bukan reducing balance).',
        ],

        'interest_engine_present' => [
            'status' => 'DERIVED',
            'note' => 'InterestCalculator tersedia di kode dan digunakan simulasi. Ini capability, bukan rule. Baseline rule kini flat.',
        ],
    ],

    'installment' => [
        'status' => 'CONFIRMED BASELINE',
        'formula' => 'Equal principal (original_principal / tenor) + flat monthly interest; pembulatan rupiah nearest; selisih pada angsuran terakhir.',
        'frequency' => 'monthly',
        'number_of_installments' => 'tenor_months (sama banyaknya)',
        'data_model_status' => 'ESTABLISHED (T74) — structural schema only',
        'implementation_status' => 'IMPLEMENTED (T80 pembangkitan + T81 stempel kontrak pencairan) — pembangkitan jadwal tersedia pada ShmRepaymentScheduleService; ShmLoanDisbursementService menstempel kontrak baseline eksplisit (rate produk hasil resolusi tier T82, rate_period monthly, calculation_method flat) pada setiap pinjaman hasil pencairan',
        'remaining_implementation_gaps' => [],
        'note' => 'Rule finansial (formula, frekuensi, pembulatan, angsuran terakhir) CONFIRMED BASELINE sejak T79; implementasi pembangkitan sejak T80.',
    ],

    'repayment_schedule' => [
        'status' => 'CONFIRMED BASELINE',
        'rule' => 'Jadwal = tepat tenor angsuran bulanan; jatuh tempo pertama mengikuti first_due_date pengajuan (dipilih Marketing — T82; anchor generator IMPLEMENTED T83: installment #1 = first_due_date); progresi bulanan; pertahankan hari/bulan; fallback hari terakhir bulan; idempoten; tidak duplikat; tidak overwrite diam-diam.',
        'eligibility' => 'active AND disbursed_at IS NOT NULL',
        'remaining_implementation_gaps' => [
            'schedule regeneration policy setelah payment/perubahan data (tetap PENDING)',
            'immutability schedule setelah payment & kewenangan perubahan (tetap PENDING)',
        ],
        'implementation_status' => 'IMPLEMENTED (T80 pembangkitan + T81/T82 kontrak pencairan + T83 anchor first_due_date & action eksplisit) — generator memakai first_due_date sebagai anchor installment #1 (addMonthsNoOverflow dari anchor asli, tanpa drift); pembangkitan HANYA melalui action eksplisit pada detail pinjaman, tidak pernah otomatis saat pencairan.',
        'note' => 'Rule due-date CONFIRMED BASELINE sejak T79; pembangkitan diimplementasikan T80 pada ShmRepaymentScheduleService dengan guard eligibility/idempotency/lockForUpdate/atomik T75 tetap berlaku.',
    ],

    'payment' => [
        'status' => 'CONFIRMED BASELINE for allocation; channel/fields tetap PENDING BUSINESS CONFIRMATION',
        'allocation_order' => ['penalty', 'interest', 'principal'],
        'allocation_scope' => 'Kronologis: kewajiban jatuh tempo paling awal dulu; dalam satu angsuran: penalty → interest → principal (posisi penalty dikreservasi, tanpa perhitungan).',
        'partial_payment' => 'allowed — mengurangi obligation tertua; auto-paid hanya saat outstanding angsuran = 0.',
        'blocked_by' => [
            'payment source/channel',
            'payment mandatory fields (date, amount, reference number, collector, notes)',
            'exact accounting treatment overpayment',
            'reversal implementation details',
        ],
        'note' => 'T79 BUKAN implementasi payment. Tidak ada tabel/model/endpoint payment yang dibuat.',
    ],

    'penalty' => [
        'status' => 'PENDING BUSINESS CONFIRMATION',
        'blocked_by' => [
            'penalty enabled/disabled',
            'basis calculation',
            'rate/amount',
            'grace period',
            'frequency',
            'maximum penalty',
            'rounding',
            'waiver policy',
        ],
        'note' => 'TIDAK ada nilai penalty yang diadopsi (termasuk dari config/business-rules.php). Posisi alokasi dikreservasi tanpa perhitungan.',
    ],

    'settlement' => [
        'status' => 'CONFIRMED BASELINE for amount composition (T79) + Model B dan business decision T89A; tolerance/discount formula/approval authority/implementation tetap PENDING BUSINESS CONFIRMATION',
        'amount' => 'outstanding principal + applicable obligations yang sudah jatuh tempo/terakru menurut jadwal',
        'future_interest' => 'bunga flat masa depan yang belum jatuh tempo TIDAK otomatis ditagih',
        'blocked_by' => [
            'discount/potongan rule',
            'settlement tolerance',
            'approval requirement',
            'payment confirmation rule',
            'status transition rule',
            'implementation (ticket Settlement/Pelunasan)',
        ],
        'note' => 'T79 tidak mengadopsi settlement_tolerance 0.01 dari config/business-rules.php. ShmLoan::outstanding() tetap representasi teknis sementara, bukan formula pelunasan.',

        /*
        |-------------------------------------------------------------------
        | T89A — FINALISASI BUSINESS RULE SETTLEMENT (contract ticket).
        |
        | Murni ADDITIVE pada contract: tidak ada key lama yang dihapus/diubah
        | nilainya (blocked_by TETAP — gerbang runtime ShmLoanSettlementService
        | tidak boleh berubah pada ticket ini), tidak ada migration, dan tidak
        | ada perubahan behavior runtime payment/allocation/settlement.
        */

        // AC1/AC2 — Model B: Settlement ≠ Payment.
        'model' => [
            'type' => 'separate_settlement_and_payment',
            'description' => 'Settlement adalah proses penyelesaian kewajiban pinjaman; Payment tetap SATU-SATUNYA transaksi uang masuk. Settlement TIDAK otomatis membuat loan closed.',
            'flow' => [
                'loan_active',
                'settlement_requested',
                'settlement_calculated',
                'settlement_pending',
                'settlement_approved',
                'settlement_payment_recorded',
                'settlement_payment_verified',
                'settlement_completed',
                'loan_closed',
            ],
            'source' => 'T89A business decision — Model B (settlement dan payment dipisah).',
        ],

        // AC1 — sumber perhitungan nominal pelunasan = simulasi pinjaman.
        'calculation' => [
            'source' => 'loan_simulation',
            'persisted_loan_parameters' => true,
            'product_rate_matrix_source_of_truth' => true,
            'universal_rate_forbidden' => true,
            'note' => 'Nominal pelunasan mengikuti perhitungan simulasi pinjaman yang berlaku untuk loan tersebut: parameter loan/product/rate/method/period YANG SUDAH PERSISTED dipakai apa adanya; rate tetap dari product rate matrix (ShmProductRateService); TIDAK menghitung ulang dengan angka berbeda dari loan/simulation contract dan TIDAK memakai universal rate.',
        ],

        // AC4/AC5 — early settlement interest discount (T89A + finalisasi T89B).
        'interest_discount' => [
            'threshold_principal_gt' => '50000000',
            'threshold_basis' => 'principal/plafon awal loan',
            'eligible_above_threshold' => true,
            'eligible_at_or_below_threshold' => false,
            'below_threshold_rule' => 'bunga tetap mengikuti perhitungan flat — TIDAK mendapat potongan bunga',
            // T89B: formula DIKONFIRMASI — persentase tetap DATA-DRIVEN
            // (master policy), tanpa default seed dan tanpa universal rate.
            'formula' => 'discount_amount = remaining_interest × policy.discount_percent / 100 (pembulatan rupiah terdekat, konsisten jadwal)',
            'base' => 'remaining/future interest menurut jadwal persisted — bunga jatuh tempo/terutang TIDAK pernah didiskon',
            'percent_source' => 'master policy data-driven (shm_settlement_discount_policies) — TIDAK ada default percentage yang di-seed',
            'cannot_guess' => true,
            'note' => 'Kelayakan diputus (loan > Rp50.000.000 = eligible). Persentase diatur admin per rentang sisa bulan (remaining months) melalui master policy; tanpa policy yang cocok, settlement DITOLAK eksplisit — dilarang diam-diam memakai 0%. Snapshot policy per settlement (immutable).',
        ],

        // AC6 — penalty: custom fillable, TANPA formula hard-coded (T89A).
        'penalty' => [
            'mode' => 'custom_fillable',
            'input' => 'manual',
            'auto_calculated' => false,
            'formula' => 'PENDING BUSINESS CONFIRMATION',
            'grace_period' => 'PENDING BUSINESS CONFIRMATION',
            'max_penalty' => 'PENDING BUSINESS CONFIRMATION',
            'note' => 'Penalty diisi manual sesuai kebijakan saat settlement; TIDAK dihitung otomatis dengan persentase/harian yang diinvent sendiri.',
        ],

        // AC1 — payment terpisah & wajib terverifikasi sebelum completion.
        'payment' => [
            'separate_from_settlement' => true,
            'mechanism' => 'existing_payment_flow',
            'must_be_verified_before_completion' => true,
            'nominal_must_meet_settlement_obligation' => true,
            'channel' => 'PENDING BUSINESS CONFIRMATION',
            'mandatory_fields' => 'PENDING BUSINESS CONFIRMATION',
            'note' => 'Menggunakan Payment T86–T88 yang sudah ada — TIDAK membuat payment mechanism baru; channel/field wajib masih PENDING.',
        ],

        // AC7 — overpayment pelunasan → Simpanan Pokok koperasi.
        'overpayment' => [
            'destination' => 'koperasi_principal_savings',
            'destination_detail' => 'Simpanan Pokok koperasi existing (SavingProduct category `principal`); BUKAN saldo unallocated permanen, BUKAN pengurangan principal loan, BUKAN SHM Savings baru.',
            'silently_discarded' => false,
            'implementation_dependency' => 'BLOCKER BUSINESS DATA untuk T89B: nasabah SHM TIDAK memiliki hubungan member koperasi (keputusan KSP: nasabah SHM bukan anggota; savings_accounts wajib member_id). Posting ke Simpanan Pokok ditolak eksplisit sampai hubungan penerima tersedia — TIDAK membuat member, TIDAK mengkonversi nasabah SHM, TIDAK membuat SHM Savings, TIDAK mengarang jurnal.',
        ],

        // AC8 — approval disiapkan; role authority TIDAK di-hard-code.
        'approval' => [
            'required' => true,
            'status_lifecycle' => ['pending', 'approved', 'rejected'],
            'authority' => 'PENDING BUSINESS CONFIRMATION',
            'implementation' => 'prepare workflow only',
            'note' => 'Permission `shm.settlement.create` tetap dipertahankan sebagai technical capability sampai role mapping ditetapkan; tidak ada role final yang dikunci sebagai business rule.',
        ],

        // AC3 — penutupan pinjaman HANYA setelah pembayaran pelunasan selesai.
        'closure' => [
            'transition' => 'active_to_closed',
            'condition' => 'settlement_payment_completed',
            'not_before' => [
                'preview',
                'settlement_pending',
                'settlement_approved_without_payment',
                'confirmation_button_press',
            ],
            'note' => 'Settlement dibuat ATAU approved TIDAK cukup untuk menutup loan; wajib ada pembayaran pelunasan yang tercatat dan terverifikasi.',
        ],

        // AC9 — definisi Collection untuk T90 (implementasi di luar T89A).
        'collection' => [
            'trigger' => [
                'loan_status' => 'active',
                'repayment_state' => 'overdue',
            ],
            'purpose' => 'collection_of_outstanding_obligation',
            'note' => 'Collection adalah penagihan terhadap loan yang sudah dicairkan, masih active, dan belum lunas (tunggakan) — BUKAN proses setelah loan closed. Definisi/dependency saja: TIDAK diimplementasikan di T89A/T89B.',
        ],

        /*
        |-------------------------------------------------------------------
        | T89B — IMPLEMENTASI WORKFLOW MODEL B (settlement completion).
        | Additive: tidak mengubah key T89A di atas.
        */
        'workflow' => [
            'status' => 'IMPLEMENTED (T89B)',
            'statuses' => [
                'pending',
                'approved',
                'payment_recorded',
                'payment_verified',
                'completed',
                'rejected',
            ],
            'rejected_allows_resubmission' => true,
            'completion_requires' => [
                'approved',
                'payment_recorded',
                'payment_verified',
                'payment_may_cover_overpayment_or_exact',
            ],
            'loan_closed_only_after' => 'settlement completed',
        ],

        'payment_verification' => [
            'explicit' => true,
            'posted_status_is_not_verification' => true,
            'recorded_vs_verified_separated' => true,
            'mechanism' => 'reuse Payment T86 (shm_loan_payments) — TIDAK ada tabel/kanal pembayaran baru; status pembayaran TIDAK ditambah',
        ],

        'discount_policy' => [
            'table' => 'shm_settlement_discount_policies',
            'data_driven' => true,
            'selected_by' => 'remaining_months (angsuran dengan due_date > settlement date dan bunga outstanding > 0)',
            'effective_dating' => true,
            'overlap_active_forbidden' => true,
            'no_matching_policy' => 'explicit business configuration error (settlement ditolak) — dilarang diam-diam memakai 0%',
            'snapshot_per_settlement_immutable' => true,
            'seeded_default_percentages' => false,
        ],
    ],

    'collection' => [
        'status' => 'CONFIRMED BASELINE for operational basis; detailed rules tetap PENDING BUSINESS CONFIRMATION',
        'basis' => 'Overdue/outstanding installments menurut repayment status aktual; status fabricated dilarang.',
        'blocked_by' => [
            'contact cadence (reminder)',
            'collector assignment rule',
            'escalation rule',
            'visit rules',
            'promise-to-pay handling',
            'aging bucket (jika diperlukan)',
        ],
        'note' => 'T79 tidak membuat collection engine/menu/automation; tidak ada persentase atau jadwal collection yang dikarang.',
    ],

    /*
    |-----------------------------------------------------------------------
    | T91 — COLLECTION BUSINESS RULE & WORKFLOW CONTRACT (ADDITIVE)
    |
    | Sumber keputusan: user decision T91 (business rule Collection
    | dikonfirmasi pihak koperasi, disesuaikan role KSP Bima Sakti Mandiri).
    | Section ini MELANJUTKAN domain collection T79/T84/T89A/T90 tanpa
    | menghapus/mengubah key lama — decision_domains.collection dan
    | collection.di atas dipertahankan apa adanya untuk traceability.
    |
    | SCOPE: contract saja. TIDAK ada UI action, TIDAK ada tabel/case
    | collection, TIDAK ada permission baru, TIDAK ada perubahan T90
    | monitoring, payment, settlement, loan status, atau approval workflow.
    |-----------------------------------------------------------------------
    */
    'collection_workflow' => [
        'status' => 'CONFIRMED BASELINE (T91) — business rule & workflow contract; IMPLEMENTASI action Collection = ticket terpisah',
        'relation_to_t79' => 'Pengembangan domain collection T79 (basis CONFIRMED, detailed_rules PENDING) + T84 (collection_dependency) + T89A (trigger/purpose) + T90 (monitoring read-only). Bagian yang dijawab T91 dijabarkan DI SINI; key lama TIDAK diubah.',
        'contract_only' => [
            'ui_action_created' => false,
            'db_collection_case_created' => false,
            'permission_created' => false,
            't90_monitoring_changed' => false,
            'payment_settlement_loan_status_changed' => false,
            'source' => 'T91 implementation scope (WAJIB/TIDAK BOLEH)',
        ],

        // ---------------------------------------------------------------
        // Konteks: aplikasi INTERNAL koperasi (Rule 15 + business context)
        // ---------------------------------------------------------------
        'context' => [
            'application_type' => 'internal_koperasi',
            'customer_facing' => false,
            'performed_by' => 'user_internal_koperasi (aktivitas Collection dilakukan pegawai, bukan otomasi ke nasabah)',
            'external_automation' => [
                'whatsapp' => false,
                'sms' => false,
                'email_reminder' => false,
                'customer_portal' => false,
                'customer_login' => false,
                'external_notification_gateway' => false,
            ],
            'internal_notification_future' => 'Jika notification internal diperlukan: TICKET TERPISAH (Rule 15) — tidak diimplementasikan di T91.',
            'loan_application_workflow_context' => [
                'status' => 'CONTEXT ONLY — T91 TIDAK mengimplementasikan perubahan workflow pengajuan pinjaman',
                'sequence' => [
                    'marketing_input_pengajuan',
                    'branch_manager_analisis',
                    'president_director_final_approval',
                    'finance_manager_pencairan',
                    'loan_active',
                ],
                'technical_change' => 'PENDING TICKET TERPISAH (perubahan teknis workflow pengajuan bukan scope T91)',
                'purpose' => 'Dicatat untuk memastikan Collection tidak bertentangan dengan role structure baru.',
            ],
            'source' => 'T91 business context + Rule 15 + Loan Application Workflow',
        ],

        // ---------------------------------------------------------------
        // RULE 3 — ELIGIBILITY (predicate T90 dipertahankan apa adanya)
        // ---------------------------------------------------------------
        'eligibility' => [
            'status' => 'CONFIRMED BASELINE (T90 predicate; TIDAK diubah T91)',
            'requires' => [
                'loan.status = active',
                'loan.disbursed_at IS NOT NULL',
                'installment.due_date < today (today()->toDateString())',
                '(principal_amount - paid_principal_amount) + (interest_amount - paid_interest_amount) > 0',
            ],
            'excluded' => [
                'closed',
                'undisbursed (belum dicairkan)',
                'future_only (hanya installment jatuh tempo masa depan)',
                'overdue_fully_paid (tunggakan sudah lunas semua)',
            ],
            'grace_period' => 'none (tanpa grace period)',
            'predicate_source' => 'T90 app/Services/Shm/ShmCollectionQueryService.php (applyOverdueCondition) — T91 TIDAK menyentuhnya',
            'change_forbidden_without_explicit_new_rule' => true,
            'source' => 'T91 Rule 3 + T90 eligibility contract',
        ],

        // ---------------------------------------------------------------
        // RULE 1 — OWNER (Marketing; tanpa role Collector khusus)
        // ---------------------------------------------------------------
        'owner' => [
            'initial_owner_role' => 'marketing',
            'dedicated_collector_role' => 'PENDING BUSINESS CONFIRMATION (T91 TIDAK membuat role Collector; collection awal ditangani Marketing)',
            'marketing_responsibilities' => [
                'monitoring_loan_overdue',
                'follow_up',
                'record_activity',
                'record_result',
                'escalate_if_needed',
            ],
            'ownership_technical' => 'Mengikuti mekanisme ownership SHM existing (shm.view + ownedBy/shm.view_all dari T90) — tidak ada mekanisme kepemilikan baru di T91.',
            'source' => 'T91 Rule 1',
        ],

        // ---------------------------------------------------------------
        // RULE 4 — COLLECTION STATUS LIFECYCLE (contract values)
        // ---------------------------------------------------------------
        'statuses' => [
            'values' => ['OPEN', 'IN_PROGRESS', 'PROMISE_TO_PAY', 'ESCALATED', 'RESOLVED'],
            'meanings' => [
                'OPEN' => 'Memenuhi Collection eligibility tetapi belum ada tindakan Collection.',
                'IN_PROGRESS' => 'Marketing sedang menangani/follow-up loan.',
                'PROMISE_TO_PAY' => 'Customer memberikan komitmen pembayaran yang dicatat Marketing.',
                'ESCALATED' => 'Kasus membutuhkan penanganan Branch Manager atau President Director.',
                'RESOLVED' => 'Kewajiban overdue yang menjadi target Collection sudah diselesaikan.',
            ],
            'persistence' => 'PENDING (struktur tabel/case Collection & status database BELUM dirancang — T91 contract terlebih dahulu; tidak ada kolom status baru yang dibuat)',
            'transition_table' => 'PENDING TICKET IMPLEMENTASI (siapa boleh memindahkan status mana — tidak ditebak di T91)',
            'loan_status_untouched' => true,
            'installment_db_status_untouched' => true,
            'presentation_state_only' => 'Status Collection BUKAN status pinjaman dan BUKAN kolom installment; T90 tetap memakai presentation state turunan tanpa DB status baru.',
            'source' => 'T91 Rule 4',
        ],

        // ---------------------------------------------------------------
        // COLLECTION WORKFLOW (baseline + escalation branch)
        // ---------------------------------------------------------------
        'workflow' => [
            'baseline' => [
                'loan_active_overdue',
                'collection_open',
                'marketing_follow_up',
                'resolved',
            ],
            'escalation' => [
                'marketing',
                'escalation',
                'branch_manager_or_president_director',
                'decision_follow_up',
                'collection_continues_or_resolved',
            ],
            'auto_escalation' => false,
            'auto_escalation_note' => 'TIDAK ada escalation otomatis berdasarkan angka/bucket — threshold belum ditentukan (Rule 9).',
            'source' => 'T91 Collection Workflow baseline',
        ],

        // ---------------------------------------------------------------
        // RULE 5 — AGING (dihitung dari days overdue T90)
        // ---------------------------------------------------------------
        'aging' => [
            'calculation' => 'days_overdue dari repayment schedule (T90 — earliest overdue due_date vs today)',
            'buckets' => [
                ['label' => '1-7 hari', 'min_days' => 1, 'max_days' => 7],
                ['label' => '8-30 hari', 'min_days' => 8, 'max_days' => 30],
                ['label' => '31-60 hari', 'min_days' => 31, 'max_days' => 60],
                ['label' => '61-90 hari', 'min_days' => 61, 'max_days' => 90],
                ['label' => '>90 hari', 'min_days' => 91, 'max_days' => null],
            ],
            'purpose' => [
                'monitoring',
                'tingkat_perhatian_collection',
                'membantu_keputusan_escalation',
            ],
            'auto_escalation_by_bucket' => false,
            'source' => 'T91 Rule 5 (baseline aging operasional yang dikonfirmasi; BUKAN hasil tebakan)',

        ],

        // ---------------------------------------------------------------
        // RULE 6 — COLLECTION ACTIVITY
        // ---------------------------------------------------------------
        'activities' => [
            'types' => ['phone_call', 'visit', 'follow_up', 'other'],
            'fields' => [
                'datetime',
                'user_marketing',
                'activity',
                'result',
                'notes',
                'next_action_optional',
            ],
            'recorded_by' => 'marketing (user internal yang menangani collection miliknya)',
            'automation' => 'PENDING — TIDAK ada integrasi WhatsApp/SMS/email otomatis (Rule 6 + 15)',
            'source' => 'T91 Rule 6',
        ],

        // ---------------------------------------------------------------
        // RULE 7 — COLLECTION RESULT
        // ---------------------------------------------------------------
        'results' => [
            'values' => ['CONTACTED', 'NOT_REACHED', 'PROMISE_TO_PAY', 'PAID', 'UNABLE_TO_PAY', 'OTHER'],
            'enum_mapping' => 'PENDING SAAT IMPLEMENTASI — nilai di atas adalah contract values; pemetaan ke enum/kolom project mengikuti convention existing dan WAJIB didokumentasikan (Rule 7). T91 TIDAK membuat enum/database value.',
            'source' => 'T91 Rule 7',
        ],

        // ---------------------------------------------------------------
        // RULE 8 — PROMISE TO PAY
        // ---------------------------------------------------------------
        'promise_to_pay' => [
            'recordable' => true,
            'fields' => ['promised_date', 'promised_amount', 'notes'],
            'status_effect' => 'collection status menjadi PROMISE_TO_PAY',

            // -----------------------------------------------------------
            // CONFIRMED T99 — aturan yang sudah diputuskan bisnis & dijaga
            // server-side (ShmCollectionPtpService + scheduler
            // `collection:ptp-missed`); guard ada di domain, bukan UI.
            // -----------------------------------------------------------
            'eligibility' => [
                'overdue_min_days' => 7,
                'basis' => 'CONFIRMED (T99): ada angsuran dengan due_date nyata ≤ hari ini − 7 hari dan outstanding obligation > 0 — bukan created_at pinjaman/case maupun tanggal frontend.',
                'still_collection_candidate' => 'CONFIRMED (T99): loan juga harus tetap memenuhi predikat kelayakan Collection (isCandidate) saat pencatatan.',
            ],
            'quota' => [
                'max_per_period' => 3,
                'period' => 'CONFIRMED (T99): periode bulanan BERLABUH — anchor = tanggal pembuatan PTP pertama pada case; periode berikutnya maju per bulan kalender (anchor 10 Okt: 10 Okt–9 Nov, lalu 10 Nov–9 Des). BUKAN bulan kalender murni & BUKAN rolling 30 hari; akhir bulan ditangani addMonthsNoOverflow (anchor 31 Jan → periode berikutnya mulai 28/29 Feb) sehingga periode saling menyambung tanpa celah maupun tumpang tindih.',
                'counts_toward_quota' => ['OPEN', 'FULFILLED', 'MISSED', 'CANCELLED'],
                'failed_validation_counts' => false,
                'failed_validation_note' => 'Guard dievaluasi dalam transaksi yang sama SEBELUM insert — validasi gagal tidak pernah menulis baris sehingga tidak terhitung.',
            ],
            'grace_period' => 'PENDING BUSINESS CONFIRMATION',
            'max_attempts' => 'CONFIRMED (T99): maksimal 3 PTP per periode bulanan berlabuh (lihat quota.max_per_period) — melepas pending T91 "maximum_ptp_attempts".',
            'broken_ptp_detection' => 'CONFIRMED (T99): scheduler `collection:ptp-missed` menandai PTP OPEN dengan promised_date < hari ini menjadi MISSED — idempoten, actor system (audit actor_id null, tanpa user palsu); tanpa grace period (grace period tetap PENDING terpisah).',
            'broken_ptp_penalty' => 'PENDING BUSINESS CONFIRMATION (lihat juga domain penalty — TIDAK diimplementasikan)',
            'manual_missed_escalation_effect' => 'PENDING BUSINESS CONFIRMATION (transisi MISSED manual milik pemilik tetap diizinkan T97 dan TIDAK dapat membypass kuota/kelayakan — keduanya dijaga saat pembuatan; dampak eskalasi dari MISSED manual belum dikonfirmasi bisnis, evaluasi eskalasi saat ini hanya di jalur scheduler).',
            'cannot_guess' => true,
            'source' => 'T91 Rule 8 + T99',
        ],

        // ---------------------------------------------------------------
        // RULE 9 + RULE 12 — ESCALATION & AUTHORITY
        // ---------------------------------------------------------------
        'escalation' => [
            'initial_level' => 'marketing',
            'authorities' => ['branch_manager', 'president_director'],
            'workflow' => 'Marketing → Escalation → Branch Manager / President Director → Decision/Follow-up → Collection continues / Resolved',
            'automatic_escalation' => 'CONDITIONAL',
            'automatic_escalation_condition' => 'CONFIRMED (T99): HANYA setelah 3 PTP MISSED dalam periode anchored yang sama — dievaluasi scheduler `collection:ptp-missed` (idempoten; case RESOLVED/ESCALATED dilewati tanpa audit ganda). 3 pengajuan PTP tanpa MISSED TIDAK PERNAH mengeskalasi; FULFILLED/CANCELLED tidak pernah mengeskalasi.',
            'missed_ptp_threshold' => 3,
            'threshold' => 'CONFIRMED (T99): 3 PTP MISSED dalam periode anchored yang sama (jalur PTP — satu-satunya threshold otomatis). Threshold non-PTP (aging, nominal, dsb.) TIDAK ditetapkan — faktor tersebut tetap bahan keputusan MANUSIA (potential_factors), bukan angka otomatis.',
            'timing' => 'PENDING BUSINESS CONFIRMATION (timing eskalasi di luar aturan 3 MISSED — kapan faktor non-PTP memicu eskalasi — belum ditentukan)',
            'potential_factors' => [
                'aging',
                'nominal_outstanding',
                'repeated_unsuccessful_follow_up',
                'broken_promise_to_pay',
                'kondisi_customer',
            ],
            'potential_factors_note' => 'Faktor POTENSIAL yang membantu keputusan MANUSIA — bukan algoritma otomatis; threshold non-PTP belum ditentukan (threshold otomatis yang ada = jalur PTP: 3 MISSED per periode anchored, lihat missed_ptp_threshold).',
            'assignment_authority' => ['branch_manager', 'president_director'],
            'assignment_algorithm' => 'PENDING BUSINESS CONFIRMATION (Rule 2 — JANGAN menebak struktur assignment; tidak ada automatic collector assignment)',
            'approval_workflow_unchanged' => true,
            'source' => 'T91 Rule 9 + Rule 12 + Rule 2',
        ],

        // ---------------------------------------------------------------
        // RULE 10 — PAYMENT INTERACTION (workflow payment existing T86-T88)
        // ---------------------------------------------------------------
        'payment_resolution' => [
            'payment_workflow' => 'existing SHM payment workflow (T86-T88 — shm_loan_payments + allocation): SATU-SATUNYA transaksi uang masuk',
            'collection_creates_payment' => false,
            'partial_payment_effect' => 'Jika customer membayar sebagian: outstanding dihitung ulang; loan TETAP berada dalam Collection selama masih memiliki overdue outstanding.',
            'resolution_condition' => 'Seluruh kewajiban overdue yang menjadi target Collection diselesaikan → Collection RESOLVED.',
            'payment_allocation_unchanged' => true,
            'loan_closed_by_collection' => false,
            'loan_closed_note' => 'Collection TIDAK menutup loan; penutupan loan mengikuti settlement workflow T89 (lihat settlement_interaction).',
            'payment_workflow_unchanged_by_t91' => true,
            'source' => 'T91 Rule 10 + T84 payment contracts',
        ],

        // ---------------------------------------------------------------
        // RULE 11 — SETTLEMENT INTERACTION (workflow T89 tidak diubah)
        // ---------------------------------------------------------------
        'settlement_interaction' => [
            'separate_workflows' => true,
            'settlement_statuses' => ['pending', 'approved', 'payment_recorded', 'payment_verified', 'completed'],
            'settlement_result' => 'loan closed (T89 — setelah settlement completed)',
            'collection_creates_payment_or_settlement' => false,
            'collection_during_settlement' => 'Collection TIDAK boleh membuat payment atau settlement baru selama settlement berjalan.',
            'settlement_stage_specific_rules' => 'PENDING BUSINESS CONFIRMATION (aturan khusus per tahap settlement pending/approved/payment_recorded/payment_verified BELUM ditentukan — JANGAN ditebak; Rule 11)',
            'settlement_unchanged_by_t91' => true,
            'cannot_guess' => true,
            'source' => 'T91 Rule 11 + T89 settlement contract',
        ],

        // ---------------------------------------------------------------
        // RULE 13 — PERMISSION (konseptual; implementasi ticket berikutnya)
        // ---------------------------------------------------------------
        'permissions' => [
            'status' => 'CONCEPTUAL ONLY — implementasi pada ticket implementasi berikutnya (Rule 13)',
            'existing_basis' => [
                'shm.view (route middleware Collection T90)',
                'ownership mechanism existing (ownedBy / shm.view_all — canViewAllShm)',
            ],
            'marketing' => [
                'view_collection',
                'handle_own_collection',
                'record_activity',
                'change_operational_status_allowed',
            ],
            'branch_manager' => [
                'monitor_collection',
                'handle_escalation',
                'assignment_reassignment_if_implemented',
            ],
            'president_director' => [
                'monitor_collection',
                'handle_escalation_high_level',
            ],
            'finance_manager' => [
                'financial_transactions_existing_workflow',
                'not_initial_collection_owner',
            ],
            'new_permissions_added_by_t91' => false,
            'detailed_matrix' => 'PENDING BUSINESS CONFIRMATION (pending #16 — matrix permission detail TIDAK diimplementasikan/ditebak di T91)',
            'source' => 'T91 Rule 13',
        ],

        // ---------------------------------------------------------------
        // ROLE STRUCTURE (T91) — termasuk nomenklatur Finance Manager
        // ---------------------------------------------------------------
        'roles' => [
            'marketing' => [
                'business_role' => 'MARKETING',
                'technical_role_key' => 'marketing',
                'technical_role_exists' => true,
                'collection_role' => 'initial Collection owner (Rule 1)',
            ],
            'branch_manager' => [
                'business_role' => 'BRANCH MANAGER',
                'technical_role_key' => null,
                'technical_role_exists' => false,
                'collection_role' => 'escalation authority + monitoring + assignment/reassignment (Rule 2/9/12)',
                'implementation_note' => 'Role teknis dibuat pada ticket implementasi permission berikutnya — T91 hanya contract.',
            ],
            'president_director' => [
                'business_role' => 'PRESIDENT DIRECTOR',
                'technical_role_key' => null,
                'technical_role_exists' => false,
                'collection_role' => 'escalation authority tingkat tinggi + monitoring (Rule 9/12)',
                'implementation_note' => 'Role teknis dibuat pada ticket implementasi permission berikutnya — T91 hanya contract.',
            ],
            'finance_manager' => [
                'business_role' => 'FINANCE MANAGER',
                'nomenclature_replaces' => 'Kasir',
                'new_workflow_design_uses' => 'Finance Manager',
                'legacy_term_policy' => 'Istilah "Kasir" HANYA untuk compatibility/legacy code yang belum dimigrasikan; desain workflow baru (termasuk contract ini) memakai Finance Manager.',
                'technical_role_key' => null,
                'technical_role_exists' => false,
                'responsibilities' => [
                    'fungsi_keuangan',
                    'pencairan',
                    'transaksi_pembayaran_sesuai_workflow_finansial',
                ],
                'collection_initial_owner' => false,
            ],
            'collector' => [
                'created' => false,
                'status' => 'PENDING BUSINESS CONFIRMATION (Rule 1 — T91 TIDAK membuat role Collector khusus; collection awal = Marketing)',
            ],
            'source' => 'T91 Role Structure + Rule 1 + Rule 12',
        ],

        // ---------------------------------------------------------------
        // RULE 14 — AUDIT TRAIL (mekanisme audit existing)
        // ---------------------------------------------------------------
        'audit' => [
            'mechanism' => 'existing audit mechanism (AuditLogger + AuditLog) — TIDAK membuat arsitektur audit baru',
            'must_trace' => [
                'collection_dibuka_open',
                'perubahan_status',
                'assignment_reassignment',
                'aktivitas_follow_up',
                'hasil_follow_up',
                'promise_to_pay',
                'escalation',
                'resolution',
            ],
            'read_only_get_writes_no_audit' => true,
            'read_only_note' => 'GET Collection (T90 monitoring) TIDAK menulis audit record — audit hanya untuk aksi operasional Collection di ticket implementasi.',
            'source' => 'T91 Rule 14 + T90 read-only guarantee',
        ],

        // ---------------------------------------------------------------
        // PENDING BUSINESS RULE — daftar resmi T91 (15 item; JANGAN menebak)
        // T99 melepas 2 item yang sudah CONFIRMED: `exact_escalation_threshold`
        // & `maximum_ptp_attempts` (lihat bagian promise_to_pay & escalation).
        // ---------------------------------------------------------------
        'pending_rules' => [
            'status' => 'PENDING BUSINESS CONFIRMATION',
            'items' => [
                // Catatan T99: `automatic_scheduler_or_job` & `collection_cadence`
                // tetap pending untuk automation Collection LAIN selain sweep PTP
                // `collection:ptp-missed` (keberadaan & frekuensi sweep PTP =
                // pilihan teknis yang didokumentasikan di routes/console.php).
                'automatic_escalation_timing',
                'exact_ptp_grace_period',
                'penalty_formula',
                'penalty_grace_period',
                'penalty_maximum',
                'collection_cadence',
                'automatic_scheduler_or_job',
                'collector_role',
                'exact_assignment_algorithm',
                'settlement_vs_collection_temporary_suspension_rule',
                'restructuring',
                'write_off',
                'collection_closure_tolerance',
                'detailed_permission_matrix_implementation',
                'notification_mechanism',
            ],
            'cannot_guess' => true,
            'invention_forbidden' => true,
            'if_needed_during_implementation' => 'Tandai PENDING BUSINESS CONFIRMATION dan lanjutkan pekerjaan Collection yang aman — jangan mengarang aturan.',
            'source' => 'T91 PENDING BUSINESS RULE (daftar eksplisit dari ticket)',
        ],
    ],

    'credit_checking' => [
        'status' => 'CONFIRMED BASELINE for role; scoring formula tetap PENDING BUSINESS CONFIRMATION',
        'role' => 'Pre-approval assessment; hasil dapat memengaruhi kelanjutan ke Approval.',
        'blocked_by' => [
            'numeric scoring formula / threshold / weighting',
            'outcome effect detail ke application flow (mechanics mengikuti workflow existing)',
        ],
        'note' => 'Tidak ada skor numerik, ambang angka, atau blacklist yang dikarang. Workflow existing tetap source of truth.',
    ],

    'document_requirement' => [
        'status' => 'CONFIRMED GATE only; required document list tetap PENDING BUSINESS CONFIRMATION',
        'gate' => 'Kelengkapan dokumen (complete/not_applicable semua) adalah prasyarat pencairan — gate existing TIDAK diubah.',
        'blocked_by' => [
            'required document list per jenis (SHM/SHGB, Buyback)',
            'optional/conditional classification',
            'original vs salinan rule',
            'expiry rule',
            'verifikasi order rule',
        ],
        'note' => 'T79 tidak membuat daftar dokumen rekaan; taxonomy resmi tetap PENDING.',
    ],

    /*
    |-----------------------------------------------------------------------
    | T84 CONFIRMED FINANCIAL & PAYMENT DOMAINS (top-level)
    |-----------------------------------------------------------------------
    */
    'disbursement_costs' => [
        'status' => 'CONFIRMED (T84)',
        'admin_fee' => ['percent' => 2.0, 'rule' => '2% × plafond pinjaman', 'status' => 'CONFIRMED'],
        'provision_fee' => ['percent' => 3.0, 'rule' => '3% × plafond pinjaman', 'status' => 'CONFIRMED'],
        'insurance_fee' => ['percent' => 1.0, 'rule' => '1% × plafond pinjaman', 'status' => 'CONFIRMED'],
        'notary_fee' => ['type' => 'custom', 'rule' => 'custom/fillable nominal diinput authorized user, tanpa preset', 'status' => 'CONFIRMED'],
        'principal_savings' => ['amount' => 100000, 'rule' => 'Rp100.000', 'status' => 'CONFIRMED'],
        'mandatory_savings' => ['monthly_amount' => 25000, 'rule' => 'Rp25.000 × tenor dalam bulan', 'status' => 'CONFIRMED'],
        'deduction_timing' => ['timing' => 'initial_disbursement', 'rule' => 'Semua potongan dipotong langsung pada awal pencairan', 'status' => 'CONFIRMED'],
        'total_deduction_formula' => 'admin_fee + provision_fee + insurance_fee + notary_fee + principal_savings + mandatory_savings',
        'net_disbursement_formula' => 'principal_amount - total_deduction',
        'source' => 'T84 Official Disbursement Cost Contract',
    ],

    'net_disbursement' => [
        'status' => 'CONFIRMED (T84)',
        'formula' => 'principal_amount - total_deduction',
        'principal_preservation' => 'Original principal / plafond tetap menjadi basis pokok pinjaman & perhitungan bunga flat; net disbursement adalah dana bersih yang diterima nasabah dan TIDAK boleh menjadi basis pokok pinjaman.',
        'source' => 'T84 Loan Principal vs Net Disbursement Contract',
    ],

    'payment_allocation' => [
        'status' => 'CONFIRMED BASELINE (T84)',
        'order' => ['penalty', 'interest', 'principal'],
        'chronological' => true,
        'rule' => 'Kewajiban jatuh tempo lebih awal diselesaikan lebih dulu. Urutan dalam angsuran: penalty → interest → principal. Slot penalty dikreservasi tanpa formula (formula penalty PENDING).',
        'source' => 'T84 Payment Allocation Contract',
    ],

    'partial_payment' => [
        'status' => 'CONFIRMED BASELINE (T84)',
        'allowed' => true,
        'rule' => 'Partial payment diperbolehkan; pembayaran tidak ditolak karena kurang dari nilai angsuran; mengurangi kewajiban tertua; remaining obligation dapat dihitung secara deterministik.',
        'source' => 'T84 Partial Payment Contract',
    ],

    'overpayment' => [
        'status' => 'CONFIRMED BASELINE (T84)',
        'silently_discarded' => false,
        'rule' => 'Overpayment tidak boleh dibuang secara diam-diam; excess amount direpresentasikan secara eksplisit dan auditable; perlakuan akuntansi persis PENDING BUSINESS CONFIRMATION.',
        'source' => 'T84 Overpayment Contract',
    ],

    'payment_reversal' => [
        'status' => 'CONFIRMED BASELINE (T84)',
        'explicit' => true,
        'auditable' => true,
        'delete_history' => false,
        'rule' => 'Reversal harus eksplisit, auditable, menyimpan alasan, actor, dan timestamp; TIDAK boleh melakukan hard delete terhadap record historis pembayaran; memulihkan kewajiban yang terdampak.',
        'source' => 'T84 Payment Reversal Contract',
    ],

    'payment_idempotency' => [
        'status' => 'CONFIRMED (T84)',
        'rule' => 'Request yang sama tidak boleh menghasilkan dua record pembayaran. Duplicate submission harus ditolak deterministik. Retry tidak menggandakan pembayaran.',
        'source' => 'T84 Idempotency Contract',
    ],

    'payment_concurrency' => [
        'status' => 'CONFIRMED (T84)',
        'rule' => 'Row locking transaksional wajib digunakan saat alokasi pembayaran; outstanding obligation tidak boleh dihitung dari stale state; concurrent payments aman.',
        'source' => 'T84 Concurrency Contract',
    ],

    'settlement_dependency' => [
        'status' => 'CONFIRMED BASELINE (T84)',
        'rule' => 'Early settlement = outstanding principal + obligations due/accrued; bunga flat masa depan belum jatuh tempo tidak otomatis dibebankan; settlement adalah workflow terpisah (T89); payment biasa tidak menutup loan otomatis.',
        'source' => 'T84 Settlement Dependency Contract',
    ],

    'collection_dependency' => [
        'status' => 'CONFIRMED BASELINE (T84)',
        'rule' => 'Collection membaca kondisi repayment aktual dari Loan → Installment → Payment Allocation → Outstanding/Overdue State, bukan memiliki source of truth pembayaran sendiri.',
        'source_of_truth' => 'Loan → Installment → Payment Allocation → Outstanding / Overdue State',
        'source' => 'T84 Collection Dependency Contract',
    ],

    /*
    |-----------------------------------------------------------------------
    | Status yang ADA / CONFIRMED di dalam repositori
    |-----------------------------------------------------------------------
    */
    'status_existing' => [
        'shm_application' => [
            'note' => 'Status transisi pengajuan yang ada di repo: Draft, Submitted, CreditChecking, Memorandum, Approval, Approved, DocumentCheck, Rejected, ReadyForDisbursement, Disbursed, Completed, Cancelled.',
            'source' => 'app/Models/ShmApplication.php / app/Enums/ShmApplicationStatus.php',
            'status' => 'CONFIRMED as workflow technical status; business SOP rule tetap PENDING BUSINESS CONFIRMATION',
        ],
        'shm_loan' => [
            'note' => 'Status pinjaman yang ada: active / closed. Rate resmi SHM = f(produk, tenor) dari tier rate produk (T82); DEFAULT 2 pada kolom rate_percent adalah nilai historis T79, bukan fallback. Baseline T79: monthly flat.',
            'source' => 'app/Models/ShmLoan.php; app/Services/Shm/ShmProductRateService.php (T82); OFFICIAL_RATE_PERCENT = 2.0 hanya legacy simulasi',
            'status' => 'CONFIRMED (rate surface, status, baseline rule); financial formula implementation tetap PENDING',
        ],
        'shm_credit_check' => [
            'note' => 'Status existing: pending/in_progress/completed. Outcome dicatat manual: pass/review/fail. Role = pre-approval assessment (baseline T79). Scoring formula PENDING.',
            'source' => 'app/Enums/ShmCreditCheckStatus.php; app/Enums/ShmCreditCheckOutcome.php; app/Services/ShmWorkflowService.php',
            'status' => 'CONFIRMED for presence, manual recording & role; scoring formula = PENDING BUSINESS CONFIRMATION',
        ],
        'shm_memo' => [
            'note' => 'Jenis yang ada: SHM/SHGB dan Buyback. Status draft/submitted ada.',
            'source' => 'app/Models/ShmMemorandum.php',
            'status' => 'CONFIRMED for existence & categories; financial treatment = PENDING BUSINESS CONFIRMATION',
        ],
        'shm_document' => [
            'note' => 'Status existing: pending/complete/not_applicable. Gate: dokumen harus complete/not_applicable sebelum ready_for_disbursement & disbursed. Daftar dokumen wajib belum final.',
            'source' => 'app/Models/ShmDocument.php; app/Services/ShmWorkflowService.php; config/shm.php document_requirement_context',
            'status' => 'GATE CONFIRMED; required-document-list = PENDING BUSINESS CONFIRMATION',
        ],
        'shm_approval' => [
            'note' => 'Approval existing adalah satu tahap, disimpan di shm_approvals. Keputusan approve/reject ada. Kewenangan/role belum final.',
            'source' => 'app/Models/ShmApproval.php; app/Services/ShmWorkflowService.php; config/shm.php pending_decisions',
            'status' => 'FLOW CONFIRMED; authority/role = PENDING BUSINESS CONFIRMATION',
        ],
        'shm_loan_installment' => [
            'note' => 'Tabel jadwal angsuran SHM (T74): scheduled/paid; kolom keuangan desimal; unik (loan, installment_number). Tidak ada status overdue/partially_paid (tetap PENDING).',
            'source' => 'app/Models/ShmLoanInstallment.php',
            'status' => 'CONFIRMED (data model)',
        ],
    ],

    /*
    |-----------------------------------------------------------------------
    | Historical snapshot T78 (dipertahankan untuk traceability;
    | menggantikan T79 untuk domain yang kini CONFIRMED BASELINE)
    |-----------------------------------------------------------------------
    */
    't78_summary' => [
        '_note' => 'Snapshot historis T78. Untuk domain finansial yang kini CONFIRMED BASELINE, nilai di bawah ini SUDAH DIGANTI oleh t79_baseline.',
        'official_rate' => ['status' => 'CONFIRMED', 'value' => '2%', 'source' => 'ShmLoan::OFFICIAL_RATE_PERCENT'],
        'rate_period' => ['status' => 'SUPERSEDED by T79 → monthly (CONFIRMED BASELINE)'],
        'calculation_method' => ['status' => 'SUPERSEDED by T79 → flat (CONFIRMED BASELINE)'],
        'installment_frequency' => ['status' => 'SUPERSEDED by T79 → monthly (CONFIRMED BASELINE)'],
        'tenor_meaning' => ['status' => 'SUPERSEDED by T79 → bulan = jumlah angsuran (CONFIRMED BASELINE)'],
        'due_date_rule' => ['status' => 'SUPERSEDED by T79 → anniversary monthly + end-of-month fallback (CONFIRMED BASELINE)'],
        'rounding' => ['status' => 'SUPERSEDED by T79 → rupiah nearest, selisih di angsuran terakhir (CONFIRMED BASELINE)'],
        'final_installment' => ['status' => 'SUPERSEDED by T79 → balancing installment (CONFIRMED BASELINE)'],
        'payment_allocation' => ['status' => 'SUPERSEDED by T79 → chronological; penalty → interest → principal (CONFIRMED BASELINE)'],
        'partial_payment' => ['status' => 'SUPERSEDED by T79 → allowed (CONFIRMED BASELINE)'],
        'overpayment' => ['status' => 'PARTIALLY SUPERSEDED by T79 → representation confirmed; accounting treatment tetap PENDING'],
        'payment_reversal' => ['status' => 'PARTIALLY SUPERSEDED by T79 → principles confirmed; implementation tetap PENDING'],
        'payment_channel' => ['status' => 'STILL PENDING BUSINESS CONFIRMATION'],
        'payment_mandatory_fields' => ['status' => 'STILL PENDING BUSINESS CONFIRMATION'],
        'penalty' => ['status' => 'STILL PENDING BUSINESS CONFIRMATION'],
        'settlement_formula' => ['status' => 'PARTIALLY SUPERSEDED by T79 → composition confirmed; tolerance/discount/implementation tetap PENDING'],
        'collection' => ['status' => 'PARTIALLY SUPERSEDED by T79 → operational basis confirmed; detailed rules tetap PENDING'],
        'credit_scoring' => ['status' => 'STILL PENDING BUSINESS CONFIRMATION'],
        'document_requirement_list' => ['status' => 'STILL PENDING BUSINESS CONFIRMATION'],
        'customer_lifecycle' => ['status' => 'STILL PENDING BUSINESS CONFIRMATION'],
        'tenor_bounds' => ['status' => 'STILL PENDING BUSINESS CONFIRMATION'],
    ],

    /*
    |-----------------------------------------------------------------------
    | Matrix ringkas sumber kebenaran (updated T79)
    |-----------------------------------------------------------------------
    */
    'source_of_truth_matrix' => [
        'customer' => 'CONFIRMED — shm_customers, bukan members',
        'interest' => 'CONFIRMED BASELINE (T79) — 2% monthly flat atas original principal; rate value CONFIRMED 2%',
        'installment_frequency' => 'CONFIRMED BASELINE (T79) — monthly; jumlah angsuran = tenor',
        'tenor_meaning' => 'CONFIRMED BASELINE (T79) — bulan = jumlah periode angsuran; bounds tetap PENDING',
        'due_date' => 'CONFIRMED BASELINE (T79) — anniversary monthly + end-of-month fallback; tanpa grace period',
        'rounding' => 'CONFIRMED BASELINE (T79) — rupiah nearest; selisih di angsuran terakhir',
        'schedule' => 'CONFIRMED BASELINE rules (T79) — boundary T75 tetap; generation IMPLEMENTATION tetap out of scope',
        'payment_allocation' => 'CONFIRMED BASELINE (T79) — chronological; penalty → interest → principal; channel/fields tetap PENDING',
        'penalty' => 'PENDING BUSINESS CONFIRMATION — TIDAK diimplementasikan, TIDAK ada nilai default',
        'settlement' => 'CONFIRMED BASELINE composition (T79) — tolerance/discount/implementation tetap PENDING',
        'collection' => 'CONFIRMED BASELINE basis (T79) — detailed rules tetap PENDING',
        'collection_workflow' => 'CONFIRMED BASELINE (T91) — owner Marketing, escalation authority BM/PD, 5 status lifecycle, 5 aging bucket, activity/result/PTP contract, payment & settlement terpisah, audit existing; threshold/PTP limits/assignment algorithm/permission matrix/notification = PENDING BUSINESS CONFIRMATION',
        'credit_checking' => 'CONFIRMED BASELINE role (T79) — scoring formula tetap PENDING',
        'document' => 'CONFIRMED GATE + PENDING BUSINESS CONFIRMATION (required list)',
        'disbursement' => 'CONFIRMED GATE & flow — detailed financial rule = PENDING BUSINESS CONFIRMATION',
        'approval' => 'CONFIRMED FLOW — authority/role = PENDING BUSINESS CONFIRMATION',
        'collateral' => 'CONFIRMED application-owned scope — financial treatment = PENDING BUSINESS CONFIRMATION',
        'loan_reporting' => 'PARTIAL — monitoring/export bisa jalan dengan data existing; financial reporting menunggu payment/settlement',
    ],

    /*
    |-----------------------------------------------------------------------
    | Penegasan (assert) — snapshot guard ticket
    |-----------------------------------------------------------------------
    */
    'assert' => [
        // Rate & interest baseline (T79; rate kini product-driven T82)
        'product_driven_rate_no_universal_fallback' => true,
        'interest_period_confirmed_monthly' => true,
        'calculation_method_confirmed_flat' => true,
        'interest_basis_original_principal' => true,
        'interest_not_reducing_balance' => true,
        'no_interest_period_guessing' => true,
        'no_calculation_method_guessing' => true,

        // Tenor & frekuensi baseline (T79)
        'tenor_unit_is_month' => true,
        'installments_equal_tenor' => true,
        'installment_frequency_confirmed_monthly' => true,
        'no_installment_frequency_guessing' => true,
        'no_speculative_tenor_limits' => true,
        'tenor_bounds_not_adopted_from_mvp' => true,
        'tenor_bounds_not_adopted_from_business_rules_config' => true,

        // Pokok, pembulatan, angsuran terakhir (T79)
        'principal_allocation_equal' => true,
        'rounding_confirmed_nearest_rupiah' => true,
        'no_fractional_rupiah_persisted' => true,
        'rounding_error_not_accumulated' => true,
        'final_installment_balancing_enabled' => true,
        'no_rounding_from_business_rules_config_adopted_as_shm_rule' => true,

        // Due date & jadwal (T79; first due date kini Marketing-pilih T82)
        'first_due_date_selected_by_marketing' => true,
        'monthly_due_date_progression' => true,
        'end_of_month_due_date_fallback' => true,
        'no_grace_period_introduced' => true,
        'no_due_date_rule_guessing' => true,
        'schedule_generation_implemented_t80' => true,
        'no_schedule_generation_without_confirmed_rule' => true,

        // Payment semantics (T79) — contract only, bukan implementasi
        'payment_allocation_order_confirmed' => true,
        'no_payment_allocation_priority_guessing' => true,
        'partial_payment_supported' => true,
        'no_partial_payment_rule_guessing' => true,
        'overpayment_explicitly_represented' => true,
        'overpayment_never_silently_discarded' => true,
        'no_overpayment_rule_guessing' => true,
        'payment_reversal_auditable' => true,
        'payment_reversal_never_deletes_history' => true,
        'no_payment_reversal_rule_guessing' => true,
        'no_payment_channel_guessing' => true,
        'no_payment_mandatory_fields_guessing' => true,

        // Penalty / settlement / collection / credit / dokumen (tetap guard)
        'no_penalty_formula' => true,
        'no_penalty_percentage_invented' => true,
        'no_settlement_formula' => true,
        'no_settlement_discount_formula' => true,
        'no_settlement_tolerance_from_business_rules_config_adopted_as_shm_rule' => true,
        'settlement_future_interest_not_auto_charged' => true,
        'no_collection_aging_rule' => true,
        'no_collection_percentages_invented' => true,
        'collection_based_on_actual_repayment_status' => true,
        'no_credit_outcome_threshold' => true,
        'no_credit_scoring_formula' => true,
        'no_fake_required_document_list' => true,

        // Arsitektur & scope guard
        'shm_savings_not_restored' => true,
        'no_migration_created' => true,
        'no_schema_change_by_t79' => true,
        'no_installment_payment_settlement_collection_implementation' => true,
        'application_personal_data_stays_on_application' => true,
        'shm_customer_separaton_maintained' => true,
        'rate_surface_stays_single_source' => true,
        'disbursement_does_not_invent_financial_rules' => true,
        'approval_workflow_not_changed' => true,
        'credit_check_workflow_not_changed' => true,
        'document_gate_not_changed' => true,
        'collateral_architecture_not_changed' => true,
        'no_scope_creep_payment_settlement_collection_reporting' => true,
        'no_loan_status_added' => true,

        // T84 Financial & Payment Contract Assertions
        'disbursement_admin_fee_two_percent' => true,
        'disbursement_provision_fee_three_percent' => true,
        'disbursement_insurance_fee_one_percent' => true,
        'disbursement_notary_fee_custom' => true,
        'disbursement_principal_savings_one_hundred_thousand' => true,
        'disbursement_mandatory_savings_twenty_five_thousand_per_month' => true,
        'disbursement_deductions_at_initial_disbursement' => true,
        'total_deduction_formula_confirmed' => true,
        'net_disbursement_formula_confirmed' => true,
        'original_principal_unchanged_by_deductions' => true,
        'net_disbursement_not_used_as_principal' => true,
        'payment_requires_active_disbursed_loan' => true,
        'payment_allocation_order_penalty_interest_principal' => true,
        'partial_payment_confirmed' => true,
        'overpayment_not_silently_discarded' => true,
        'payment_reversal_explicit_auditable_no_delete' => true,
        'payment_idempotency_required' => true,
        'payment_concurrency_safe_required' => true,
        'payment_does_not_automatically_close_loan' => true,
        'settlement_is_separate_workflow' => true,
        'collection_reads_repayment_state_not_separate_source' => true,

        // T91 Collection Business Rule & Workflow Contract Assertions
        'collection_initial_owner_marketing' => true,
        'collection_escalation_authority_branch_manager' => true,
        'collection_escalation_authority_president_director' => true,
        'collection_dedicated_collector_role_not_created' => true,
        'finance_manager_replaces_kasir_nomenclature_in_new_contract' => true,
        'collection_status_lifecycle_documented' => true,
        'collection_aging_buckets_documented' => true,
        'collection_activity_contract_documented' => true,
        'collection_result_contract_documented' => true,
        'promise_to_pay_contract_documented_but_limits_pending' => true,
        'collection_uses_existing_payment_workflow' => true,
        'settlement_remains_separate_from_collection' => true,
        'collection_permission_is_conceptual_only' => true,
        'collection_audit_uses_existing_mechanism' => true,
        'collection_internal_only_no_external_automation' => true,
        'no_escalation_threshold_invented' => true,
        'no_ptp_grace_or_attempt_limit_invented' => true,
        'no_automatic_notification' => true,
        'automatic_ptp_missed_scheduler_t99' => true,
        't90_collection_eligibility_predicate_unchanged' => true,
        't91_contract_additive_no_existing_key_removed' => true,
    ],

    /*
    |-----------------------------------------------------------------------
    | Out of scope T79
    |-----------------------------------------------------------------------
    */
    'out_of_scope' => [
        'Payment implementation (tabel, model, endpoint, UI, alokasi runtime)',
        'Settlement implementation',
        'Collection implementation',
        'Reporting implementation',
        'Schedule regeneration / generation di luar kontrak baseline (pinjaman tanpa kontrak baseline eksplisit tetap ditolak; regenerasi jadwal)',
        'Penalty engine implementation',
        'Interest engine baru (baseline memakai metode flat pada engine existing)',
        'Credit scoring',
        'Document requirement engine / verification / taxonomy baru',
        'Notification',
        'Autodebit',
        'Gateway',
        'Accounting journals',
        'Overpayment accounting journals',
        'Loan product CRUD',
        'SHM Savings',
        'Koperasi Savings',
        'Any new financial column speculated for SHM',
        'Menu/placeholder activation (sidebar Collection dll.)',
    ],
];
