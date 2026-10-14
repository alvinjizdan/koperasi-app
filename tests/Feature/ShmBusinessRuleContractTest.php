<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * T73/T78/T79 — verify SHM business rule contract is internally consistent.
 *
 * T79: general/standard loan business rules diadopsi sebagai CONFIRMED
 * BASELINE (keputusan user). Rule yang memang belum diputuskan (penalty
 * formula, collection detail, credit scoring, document taxonomy, akuntansi
 * overpayment, settlement tolerance/discount) tetap PENDING.
 *
 * Ticket ini TIDAK mengimplementasikan payment, settlement, collection,
 * reporting, schedule generation, penalty, credit scoring, atau document
 * verification.
 */
class ShmBusinessRuleContractTest extends TestCase
{
    // ------------------------------------------------------------------
    // T73-era contract surface (top-level keys; T79 baseline semantics)
    // ------------------------------------------------------------------

    public function test_rate_is_product_driven_not_universal_two_percent(): void
    {
        $rate = Config::get('shm-business-rule-contract.rate');

        $this->assertIsArray($rate);
        // T82: universal 2% SUPERSEDED — rate = f(product, tenor), tanpa
        // fallback ke angka tunggal mana pun.
        $this->assertSame('SUPERSEDED by T82 → product-driven rate (CONFIRMED)', $rate['status']);
        $this->assertArrayNotHasKey('official_rate_percent', $rate);
        $this->assertSame('CONFIRMED (T82)', $rate['product_driven_rate']['status']);
        $this->assertSame(2.0, $rate['legacy_official_rate_percent']);
    }

    public function test_interest_period_is_confirmed_monthly(): void
    {
        $period = Config::get('shm-business-rule-contract.rate.interest_period');

        $this->assertSame('CONFIRMED BASELINE', $period['status']);
        $this->assertSame('monthly', $period['value']);
        $this->assertArrayHasKey('note', $period);
    }

    public function test_calculation_method_is_confirmed_flat(): void
    {
        $method = Config::get('shm-business-rule-contract.rate.calculation_method');

        $this->assertSame('CONFIRMED BASELINE', $method['status']);
        $this->assertSame('flat', $method['value']);
        $this->assertArrayHasKey('note', $method);
    }

    public function test_rate_application_is_confirmed_original_principal(): void
    {
        $application = Config::get('shm-business-rule-contract.rate.rate_application');

        $this->assertSame('CONFIRMED BASELINE', $application['status']);
        $this->assertSame('original_principal', $application['value']);
        $this->assertStringContainsString('bukan reducing balance', $application['note']);
    }

    public function test_installment_contract_is_confirmed_baseline_with_t80_implementation(): void
    {
        $installment = Config::get('shm-business-rule-contract.installment');

        $this->assertSame('CONFIRMED BASELINE', $installment['status']);
        $this->assertSame('monthly', $installment['frequency']);
        $this->assertStringContainsString('Equal principal', $installment['formula']);
        $this->assertStringContainsString('flat monthly interest', $installment['formula']);
        $this->assertStringContainsString('IMPLEMENTED (T80', $installment['implementation_status']);
        $this->assertSame(
            [],
            $installment['remaining_implementation_gaps'],
            'Gap stempel kontrak saat pencairan tertutup sejak T81 — rate_period/calculation_method diisi oleh ShmLoanDisbursementService.',
        );
    }

    public function test_repayment_schedule_contract_is_confirmed_baseline(): void
    {
        $schedule = Config::get('shm-business-rule-contract.repayment_schedule');

        $this->assertSame('CONFIRMED BASELINE', $schedule['status']);
        $this->assertStringContainsString('jatuh tempo pertama mengikuti first_due_date pengajuan', $schedule['rule']);
        $this->assertSame('active AND disbursed_at IS NOT NULL', $schedule['eligibility']);
        $this->assertContains(
            'schedule regeneration policy setelah payment/perubahan data (tetap PENDING)',
            $schedule['remaining_implementation_gaps'],
        );
    }

    public function test_payment_contract_confirms_allocation_but_channel_stays_pending(): void
    {
        $payment = Config::get('shm-business-rule-contract.payment');

        $this->assertStringContainsString('CONFIRMED BASELINE', $payment['status']);
        $this->assertStringContainsString('PENDING BUSINESS CONFIRMATION', $payment['status']);
        $this->assertSame(['penalty', 'interest', 'principal'], $payment['allocation_order']);
        $this->assertContains('payment source/channel', $payment['blocked_by']);
    }

    public function test_penalty_contract_is_pending(): void
    {
        $this->assertSame(
            'PENDING BUSINESS CONFIRMATION',
            Config::get('shm-business-rule-contract.penalty.status')
        );
    }

    public function test_settlement_contract_confirms_composition_but_tolerance_stays_pending(): void
    {
        $settlement = Config::get('shm-business-rule-contract.settlement');

        $this->assertStringContainsString('CONFIRMED BASELINE', $settlement['status']);
        $this->assertStringContainsString('PENDING BUSINESS CONFIRMATION', $settlement['status']);
        $this->assertContains('settlement tolerance', $settlement['blocked_by']);
    }

    public function test_collection_contract_confirms_basis_but_details_stay_pending(): void
    {
        $collection = Config::get('shm-business-rule-contract.collection');

        $this->assertStringContainsString('CONFIRMED BASELINE', $collection['status']);
        $this->assertStringContainsString('PENDING BUSINESS CONFIRMATION', $collection['status']);
        $this->assertContains('escalation rule', $collection['blocked_by']);
    }

    public function test_credit_role_confirmed_but_scoring_stays_pending(): void
    {
        $credit = Config::get('shm-business-rule-contract.credit_checking');

        $this->assertStringContainsString('CONFIRMED BASELINE', $credit['status']);
        $this->assertStringContainsString('PENDING BUSINESS CONFIRMATION', $credit['status']);
        $this->assertContains('numeric scoring formula / threshold / weighting', $credit['blocked_by']);
    }

    public function test_document_requirement_has_no_fake_required_list(): void
    {
        $document = Config::get('shm-business-rule-contract.document_requirement');

        $this->assertStringContainsString('CONFIRMED GATE', $document['status']);
        $this->assertStringContainsString('PENDING BUSINESS CONFIRMATION', $document['status']);
        $this->assertArrayNotHasKey('required_document_list', $document);
    }

    public function test_contract_does_not_expose_speculative_financial_defaults(): void
    {
        $this->assertArrayNotHasKey('monthly', Config::get('shm-business-rule-contract.rate.interest_period'));
        $this->assertArrayNotHasKey('flat', Config::get('shm-business-rule-contract.rate.calculation_method'));

        $installment = Config::get('shm-business-rule-contract.installment');
        $this->assertArrayNotHasKey('tenor_default_months', $installment);

        $penalty = Config::get('shm-business-rule-contract.penalty');
        $this->assertArrayNotHasKey('percent', $penalty);
        $this->assertArrayNotHasKey('grace_days', $penalty);
    }

    public function test_asserted_constraints_are_enabled(): void
    {
        $assertions = Config::get('shm-business-rule-contract.assert');

        $this->assertTrue($assertions['product_driven_rate_no_universal_fallback']);
        $this->assertTrue($assertions['interest_period_confirmed_monthly']);
        $this->assertTrue($assertions['calculation_method_confirmed_flat']);
        $this->assertTrue($assertions['interest_basis_original_principal']);
        $this->assertTrue($assertions['interest_not_reducing_balance']);
        $this->assertTrue($assertions['installment_frequency_confirmed_monthly']);
        $this->assertTrue($assertions['principal_allocation_equal']);
        $this->assertTrue($assertions['rounding_confirmed_nearest_rupiah']);
        $this->assertTrue($assertions['final_installment_balancing_enabled']);
        $this->assertTrue($assertions['first_due_date_selected_by_marketing']);
        $this->assertTrue($assertions['monthly_due_date_progression']);
        $this->assertTrue($assertions['end_of_month_due_date_fallback']);
        $this->assertTrue($assertions['no_grace_period_introduced']);
        $this->assertTrue($assertions['payment_allocation_order_confirmed']);
        $this->assertTrue($assertions['partial_payment_supported']);
        $this->assertTrue($assertions['overpayment_explicitly_represented']);
        $this->assertTrue($assertions['overpayment_never_silently_discarded']);
        $this->assertTrue($assertions['payment_reversal_auditable']);
        $this->assertTrue($assertions['payment_reversal_never_deletes_history']);
        $this->assertTrue($assertions['settlement_future_interest_not_auto_charged']);
        $this->assertTrue($assertions['no_penalty_formula']);
        $this->assertTrue($assertions['no_penalty_percentage_invented']);
        $this->assertTrue($assertions['no_settlement_tolerance_from_business_rules_config_adopted_as_shm_rule']);
        $this->assertTrue($assertions['no_collection_aging_rule']);
        $this->assertTrue($assertions['no_collection_percentages_invented']);
        $this->assertTrue($assertions['no_credit_scoring_formula']);
        $this->assertTrue($assertions['no_fake_required_document_list']);
        $this->assertTrue($assertions['shm_savings_not_restored']);
        $this->assertTrue($assertions['no_migration_created']);
        $this->assertTrue($assertions['no_schema_change_by_t79']);
        $this->assertTrue($assertions['no_installment_payment_settlement_collection_implementation']);
        $this->assertTrue($assertions['no_scope_creep_payment_settlement_collection_reporting']);
        $this->assertTrue($assertions['no_loan_status_added']);
        $this->assertTrue($assertions['schedule_generation_implemented_t80']);
        $this->assertTrue($assertions['no_schedule_generation_without_confirmed_rule']);
        $this->assertTrue($assertions['no_speculative_tenor_limits']);
        $this->assertTrue($assertions['tenor_bounds_not_adopted_from_business_rules_config']);
    }

    // ------------------------------------------------------------------
    // T79 §5 — the 22 baseline assertions
    // ------------------------------------------------------------------

    public function test_t79_ac01_contract_contains_general_baseline_rules(): void
    {
        $baseline = Config::get('shm-business-rule-contract.t79_baseline');

        $this->assertIsArray($baseline);
        $this->assertSame('CONFIRMED BASELINE', $baseline['status']);
        $this->assertStringContainsString('decision T79', $baseline['source']);

        foreach ([
            'interest', 'tenor', 'installment_frequency', 'principal_installment',
            'monthly_installment', 'rounding', 'final_installment', 'due_date',
            'repayment_schedule', 'payment_allocation', 'partial_payment',
            'overpayment', 'payment_reversal', 'settlement', 'penalty',
            'collection', 'credit_checking', 'document_requirement',
        ] as $section) {
            $this->assertArrayHasKey($section, $baseline, "Baseline section {$section} harus ada.");
        }
    }

    public function test_t82_rate_is_product_driven_period_and_method_stay_baseline(): void
    {
        $interest = Config::get('shm-business-rule-contract.decision_domains.interest');

        $this->assertSame('SUPERSEDED by T82 → product-driven rate (CONFIRMED)', $interest['official_rate_percent']['status']);
        $this->assertSame(2.0, Config::get('shm-business-rule-contract.rate.legacy_official_rate_percent'));
        $this->assertSame('monthly', $interest['interest_period']['value']);
        $this->assertSame('flat', $interest['calculation_method']['value']);
        $this->assertSame('original_principal', $interest['rate_application']['value']);
        $this->assertFalse($interest['rate_application']['decreases_with_outstanding_principal']);
    }

    public function test_t79_ac03_tenor_is_monthly_and_equals_installment_count(): void
    {
        $tenor = Config::get('shm-business-rule-contract.decision_domains.tenor.tenor_months_meaning');

        $this->assertSame('CONFIRMED BASELINE', $tenor['status']);
        $this->assertSame('month', $tenor['unit']);
        $this->assertTrue($tenor['installments_equals_tenor']);
        $this->assertSame('jumlah periode angsuran bulanan', $tenor['value']);
    }

    public function test_t79_ac04_installment_frequency_is_monthly(): void
    {
        $frequency = Config::get('shm-business-rule-contract.decision_domains.installment_frequency');

        $this->assertSame('CONFIRMED BASELINE', $frequency['status']);
        $this->assertSame('monthly', $frequency['value']);
        $this->assertSame(1, $frequency['installments_per_month']);
        $this->assertSame('tenor_months', $frequency['number_of_installments']);
        $this->assertTrue($frequency['one_tenor_month_equals_one_installment']);
    }

    public function test_t79_ac05_principal_equal_allocation_with_final_adjustment(): void
    {
        $allocation = Config::get('shm-business-rule-contract.t79_baseline.principal_installment');

        $this->assertSame('equal_principal', $allocation['allocation']);
        $this->assertSame('original_principal / tenor_months', $allocation['formula']);

        $final = Config::get('shm-business-rule-contract.decision_domains.final_installment');
        $this->assertSame('CONFIRMED BASELINE', $final['status']);
        $this->assertContains('total principal paid = original principal', $final['must_satisfy']);
        $this->assertContains('remaining principal = 0 setelah pelunasan berhasil', $final['must_satisfy']);
        $this->assertTrue($final['may_differ_from_regular_installments']);
    }

    public function test_t79_ac06_due_date_rule_is_explicit_including_end_of_month(): void
    {
        $dueDate = Config::get('shm-business-rule-contract.decision_domains.repayment_schedule.due_date_rule');

        $this->assertSame('CONFIRMED BASELINE', $dueDate['status']);
        $this->assertStringContainsString('ditentukan Marketing', $dueDate['first_due_date']);
        $this->assertStringContainsString('BUKAN dihitung otomatis', $dueDate['first_due_date']);
        $this->assertSame('bulanan', $dueDate['progression']);
        $this->assertTrue($dueDate['preserve_day_of_month']);
        $this->assertSame('hari kalender terakhir yang valid pada bulan tersebut', $dueDate['missing_day_fallback']);
        $this->assertStringContainsString('TIDAK diperkenalkan', $dueDate['grace_period']);
    }

    public function test_t79_ac06_due_date_rule_matches_reference_calendar_mapping(): void
    {
        // Contract-level example: rule due-date harus sama dengan pemetaan
        // teknis referensi (Carbon addMonthsNoOverflow). BUKAN implementasi
        // pembangkitan jadwal.
        $this->assertSame(
            '2026-11-12',
            Carbon::parse('2026-10-12')->addMonthsNoOverflow(1)->toDateString(),
        );
        $this->assertSame(
            '2026-12-12',
            Carbon::parse('2026-10-12')->addMonthsNoOverflow(2)->toDateString(),
        );
        // 31 Januari → hari terakhir Februari.
        $this->assertSame(
            '2026-02-28',
            Carbon::parse('2026-01-31')->addMonthsNoOverflow(1)->toDateString(),
        );
    }

    public function test_t79_ac07_payment_allocation_order_is_explicit(): void
    {
        $allocation = Config::get('shm-business-rule-contract.decision_domains.payment.allocation_order');

        $this->assertSame('CONFIRMED BASELINE', $allocation['status']);
        // Alokasi kronologis: kewajiban jatuh tempo paling awal dulu.
        $this->assertStringContainsString('lebih awal', $allocation['chronological']);
        $this->assertTrue(
            Config::get('shm-business-rule-contract.t79_baseline.payment_allocation.chronological_order')
        );
        $this->assertSame(['penalty', 'interest', 'principal'], $allocation['within_installment']);
        // Posisi penalty dikreservasi tanpa perhitungan (penalty tetap PENDING).
        $this->assertStringContainsString('TIDAK diimplementasikan', $allocation['penalty_note']);
    }

    public function test_t79_ac08_partial_overpayment_reversal_are_contractually_defined(): void
    {
        $partial = Config::get('shm-business-rule-contract.decision_domains.partial_payment');
        $this->assertSame('CONFIRMED BASELINE', $partial['status']);
        $this->assertTrue($partial['allowed']);
        $this->assertSame('outstanding obligation tertua yang applicable', $partial['reduces']);
        $this->assertStringContainsString('mencapai nol', $partial['auto_mark_paid']);

        $overpayment = Config::get('shm-business-rule-contract.decision_domains.overpayment');
        $this->assertFalse($overpayment['silently_discarded']);
        $this->assertStringContainsString('unapplied/overpayment balance', $overpayment['representation']);
        // Perlakuan akuntansi persis tetap PENDING (ticket Payment).
        $this->assertSame('PENDING BUSINESS CONFIRMATION', $overpayment['accounting_treatment']['status']);

        $reversal = Config::get('shm-business-rule-contract.decision_domains.payment_reversal');
        $this->assertTrue($reversal['explicit']);
        $this->assertTrue($reversal['auditable']);
        $this->assertFalse($reversal['delete_history']);
        $this->assertTrue($reversal['restores_obligation']);
        $this->assertSame('PENDING BUSINESS CONFIRMATION', $reversal['implementation_detail']['status']);
    }

    public function test_t79_ac09_penalty_formula_stays_pending(): void
    {
        $penalty = Config::get('shm-business-rule-contract.decision_domains.penalty');

        $this->assertSame('PENDING BUSINESS CONFIRMATION', $penalty['status']);
        $this->assertFalse($penalty['confirmed_in_t79'] === true);
        $this->assertStringContainsString('TIDAK DIIMPLEMENTASIKAN', $penalty['confirmed_in_t79']);
        $this->assertContains('Persentase/nominal penalty.', $penalty['what_is_pending']);
        $this->assertContains('Grace period.', $penalty['what_is_pending']);
        // Tidak ada nilai penalty yang dipaksakan.
        $this->assertArrayNotHasKey('percent', $penalty);
        $this->assertArrayNotHasKey('rate_percent', $penalty);
        $this->assertArrayNotHasKey('amount_per_day', $penalty);
        $this->assertArrayNotHasKey('grace_days', $penalty);
    }

    public function test_t79_ac10_collection_detailed_rules_stay_pending(): void
    {
        $collection = Config::get('shm-business-rule-contract.decision_domains.collection');

        $this->assertSame('CONFIRMED BASELINE', $collection['basis']['status']);
        $this->assertTrue($collection['basis']['fabricated_status_forbidden']);
        $this->assertFalse($collection['percentages_or_schedules_invented']);

        $details = $collection['detailed_rules'];
        $this->assertSame('PENDING BUSINESS CONFIRMATION', $details['status']);
        $this->assertContains('Contact cadence (reminder).', $details['what_is_pending']);
        $this->assertContains('Escalation rules.', $details['what_is_pending']);
        $this->assertContains('Visit rules.', $details['what_is_pending']);
    }

    public function test_t79_ac11_credit_scoring_formula_stays_pending(): void
    {
        $credit = Config::get('shm-business-rule-contract.decision_domains.credit_checking');

        $this->assertSame('CONFIRMED BASELINE', $credit['role']['status']);
        $this->assertSame('pre-approval assessment', $credit['role']['value']);
        $this->assertTrue($credit['role']['may_affect_progression_to_approval']);

        $scoring = $credit['scoring_formula'];
        $this->assertSame('PENDING BUSINESS CONFIRMATION', $scoring['status']);
        $this->assertStringContainsString('TIDAK mengarang formula skor numerik', $scoring['why_pending']);
        // Top-level credit_checking tidak memuat formula skor/threshold/weighting.
        $topLevel = Config::get('shm-business-rule-contract.credit_checking');
        $this->assertArrayNotHasKey('scoring_formula', $topLevel);
        $this->assertArrayNotHasKey('pass_threshold', $topLevel);
        $this->assertArrayNotHasKey('weighting', $topLevel);
    }

    public function test_t79_ac12_document_taxonomy_stays_pending(): void
    {
        $document = Config::get('shm-business-rule-contract.decision_domains.document_requirement');

        $this->assertSame('CONFIRMED', $document['existing_gate']['status']);
        $this->assertSame('PENDING BUSINESS CONFIRMATION', $document['required_document_list_decision']['status']);
        $this->assertTrue($document['required_document_list_decision']['cannot_guess']);
        $this->assertArrayNotHasKey('required_document_list', $document);
        $this->assertArrayNotHasKey('optional_document_list', $document);
    }

    // ------------------------------------------------------------------
    // T79 §3 — financial example (contract regression example only)
    // ------------------------------------------------------------------

    public function test_t79_financial_example_is_internally_consistent(): void
    {
        $example = Config::get('shm-business-rule-contract.financial_example');

        $this->assertSame(100000000, $example['principal']);
        $this->assertSame(2.0, $example['rate_percent']);
        $this->assertSame('monthly', $example['rate_period']);
        $this->assertSame('flat', $example['method']);
        $this->assertSame(12, $example['tenor_months']);

        // Bunga bulanan = pokok awal × 2% = Rp2.000.000 (konstan).
        $monthlyInterest = $example['principal'] * $example['rate_percent'] / 100;
        $this->assertSame((float) $example['monthly_interest'], $monthlyInterest);
        $this->assertSame(2000000.0, $monthlyInterest);

        // Pokok equal + pembulatan rupiah nearest + selisih di angsuran
        // terakhir: total pokok tetap tepat Rp100.000.000.
        $regularPrincipal = (int) round($example['principal'] / $example['tenor_months'], 0);
        $finalPrincipal = $example['principal'] - ($regularPrincipal * ($example['tenor_months'] - 1));

        $this->assertSame(8333333, $regularPrincipal);
        $this->assertSame(8333337, $finalPrincipal);
        $this->assertSame(
            $example['principal'],
            $regularPrincipal * ($example['tenor_months'] - 1) + $finalPrincipal,
        );

        // Tidak ada pecahan rupiah pada nilai tersimpan.
        $this->assertSame(floor((float) $regularPrincipal), (float) $regularPrincipal);
        $this->assertSame(floor((float) $finalPrincipal), (float) $finalPrincipal);

        // Anuitas kasar sebelum final rounding: 8.333.333,33 + 2.000.000.
        $this->assertSame('approximately 10333333.33', $example['regular_installment_before_final_rounding']);

        $this->assertContains('adjust_final_installment_for_rounding', $example['schedule_must']);
        $this->assertContains('never_persist_fractional_rupiah', $example['schedule_must']);
    }

    // ------------------------------------------------------------------
    // Decision register structure (T78, updated for T79 vocabulary)
    // ------------------------------------------------------------------

    public function test_t78_decision_register_is_present_and_complete(): void
    {
        $register = Config::get('shm-business-rule-contract.decision_domains');

        $this->assertIsArray($register);
        $this->assertArrayHasKey('interest', $register);
        $this->assertArrayHasKey('tenor', $register);
        $this->assertArrayHasKey('installment_frequency', $register);
        $this->assertArrayHasKey('repayment_schedule', $register);
        $this->assertArrayHasKey('rounding', $register);
        $this->assertArrayHasKey('final_installment', $register);
        $this->assertArrayHasKey('payment', $register);
        $this->assertArrayHasKey('partial_payment', $register);
        $this->assertArrayHasKey('overpayment', $register);
        $this->assertArrayHasKey('payment_reversal', $register);
        $this->assertArrayHasKey('penalty', $register);
        $this->assertArrayHasKey('settlement', $register);
        $this->assertArrayHasKey('collection', $register);
        $this->assertArrayHasKey('credit_checking', $register);
        $this->assertArrayHasKey('document_requirement', $register);
        $this->assertArrayHasKey('customer_lifecycle', $register);
    }

    public function test_t78_each_decision_domain_has_explicit_status(): void
    {
        $register = Config::get('shm-business-rule-contract.decision_domains');

        $allowedPrefixes = ['CONFIRMED', 'PENDING BUSINESS CONFIRMATION', 'NOT APPLICABLE', 'PARTIALLY CONFIRMED'];

        foreach ($register as $domain => $definition) {
            $this->assertArrayHasKey('status', $definition, "Domain {$domain} harus memiliki status eksplisit.");
            $status = (string) $definition['status'];

            $matched = array_filter(
                $allowedPrefixes,
                static fn (string $prefix): bool => str_starts_with($status, $prefix),
            );

            $this->assertNotEmpty(
                $matched,
                "Domain {$domain} harus berstatus CONFIRMED / PENDING BUSINESS CONFIRMATION / NOT APPLICABLE / PARTIALLY CONFIRMED. Ditemukan: {$status}",
            );
        }
    }

    public function test_t78_credit_checking_outcome_values_are_confirmed(): void
    {
        $outcome = Config::get('shm-business-rule-contract.decision_domains.credit_checking.outcome_values_exist');

        $this->assertSame('CONFIRMED', $outcome['status']);
        $this->assertContains('pass', $outcome['values']);
        $this->assertContains('review', $outcome['values']);
        $this->assertContains('fail', $outcome['values']);
    }

    public function test_t78_customer_lifecycle_is_pending(): void
    {
        $lifecycle = Config::get('shm-business-rule-contract.decision_domains.customer_lifecycle');

        $this->assertSame('PENDING BUSINESS CONFIRMATION', $lifecycle['status']);
        $this->assertTrue($lifecycle['cannot_guess']);
    }

    public function test_t78_application_personal_data_stays_on_application(): void
    {
        $personal = Config::get('shm-business-rule-contract.decision_domains.application_personal_data');

        $this->assertSame('CONFIRMED', $personal['status']);
        $this->assertStringContainsString('shm_loan_applications', $personal['rule']['rule']);
        $this->assertStringContainsString('shm_customers', $personal['rule']['rule']);
    }

    public function test_t78_saldo_batas_semantics_are_pending(): void
    {
        $semantics = Config::get('shm-business-rule-contract.decision_domains.application_personal_data.source_semantics_for_saldo_batas');

        $this->assertSame('PENDING BUSINESS CONFIRMATION', $semantics['status']);
        $this->assertTrue($semantics['cannot_guess']);
    }

    public function test_t78_shm_customer_separation_is_confirmed(): void
    {
        $separation = Config::get('shm-business-rule-contract.decision_domains.shm_customer_separaton');

        $this->assertSame('CONFIRMED', $separation['status']);
        $this->assertStringContainsString('cooperative member', $separation['rule']['rule']);
        $this->assertStringContainsString('shm_customers', $separation['rule']['rule']);
        $this->assertStringContainsString('auto-convert', $separation['rule']['rule']);
        $this->assertStringContainsString('members.id', $separation['rule']['rule']);
    }

    public function test_t78_running_loan_rate_surface_is_product_driven_since_t82(): void
    {
        $rate = Config::get('shm-business-rule-contract.decision_domains.running_loan.official_rate_surface');

        $this->assertSame('SUPERSEDED by T82 → product-driven rate (CONFIRMED)', $rate['status']);
        $this->assertStringContainsString('tenor eksak', $rate['value']);
        $this->assertSame('app/Services/Shm/ShmProductRateService.php; shm_loans.rate_percent distempel pencairan', $rate['source']);
    }

    public function test_t79_loan_status_values_remain_active_and_closed(): void
    {
        $statuses = Config::get('shm-business-rule-contract.decision_domains.running_loan.status_values');

        $this->assertSame('CONFIRMED', $statuses['status']);
        $this->assertSame(['active', 'closed'], $statuses['values']);
    }

    public function test_t78_marketing_activity_uses_shm_customers_not_members(): void
    {
        $marketing = Config::get('shm-business-rule-contract.decision_domains.marketing_activity');

        $this->assertSame('CONFIRMED', $marketing['status']);
        $this->assertStringContainsString('shm_customers', $marketing['rule']['rule']);
        $this->assertStringContainsString('bukan cooperative members', $marketing['rule']['rule']);
    }

    public function test_t78_out_of_scope_is_explicit(): void
    {
        $outOfScope = Config::get('shm-business-rule-contract.out_of_scope');

        $this->assertContains('Payment implementation (tabel, model, endpoint, UI, alokasi runtime)', $outOfScope);
        $this->assertContains('Settlement implementation', $outOfScope);
        $this->assertContains('Collection implementation', $outOfScope);
        $this->assertContains('Reporting implementation', $outOfScope);
        $this->assertContains('Schedule regeneration / generation di luar kontrak baseline (pinjaman tanpa kontrak baseline eksplisit tetap ditolak; regenerasi jadwal)', $outOfScope);
        $this->assertContains('Penalty engine implementation', $outOfScope);
        $this->assertContains('Credit scoring', $outOfScope);
        $this->assertContains('Accounting journals', $outOfScope);
        $this->assertContains('Loan product CRUD', $outOfScope);
        $this->assertContains('Menu/placeholder activation (sidebar Collection dll.)', $outOfScope);
    }

    // ------------------------------------------------------------------
    // Anti-revert regression guards (T79 §5)
    // ------------------------------------------------------------------

    public function test_t79_guard_interest_cannot_revert_to_annual(): void
    {
        $period = Config::get('shm-business-rule-contract.rate.interest_period');

        $this->assertSame('monthly', $period['value']);
        $this->assertNotSame('yearly', $period['value']);
        $this->assertNotSame('annual', $period['value']);

        $baseline = Config::get('shm-business-rule-contract.t79_baseline.interest');
        $this->assertSame('monthly', $baseline['period']);
    }

    public function test_t79_guard_method_cannot_revert_to_effective_or_annuity(): void
    {
        $method = Config::get('shm-business-rule-contract.rate.calculation_method');

        $this->assertSame('flat', $method['value']);
        $this->assertNotSame('effective', $method['value']);
        $this->assertNotSame('annuity', $method['value']);

        $baseline = Config::get('shm-business-rule-contract.t79_baseline.interest');
        $this->assertSame('flat', $baseline['method']);
    }

    public function test_t79_guard_legacy_two_percent_is_historical_not_active_rate(): void
    {
        // T82: angka 2% tetap tercatat sebagai historical value (T73/T79)
        // tetapi BUKAN lagi rate aktif — rate = f(product, tenor).
        $this->assertSame(2.0, Config::get('shm-business-rule-contract.rate.legacy_official_rate_percent'));
        $this->assertSame(2.0, Config::get('shm-business-rule-contract.t79_baseline.interest.rate_percent'));
        $this->assertSame(
            'CONFIRMED (T82)',
            Config::get('shm-business-rule-contract.rate.product_driven_rate.status'),
        );
    }

    public function test_t79_guard_interest_is_not_reducing_balance(): void
    {
        $application = Config::get('shm-business-rule-contract.rate.rate_application');

        $this->assertSame('original_principal', $application['value']);
        $this->assertStringContainsString('bukan reducing balance', $application['note']);

        $baseline = Config::get('shm-business-rule-contract.t79_baseline.interest');
        $this->assertFalse($baseline['decreases_with_principal']);
        $this->assertSame('original_principal × 2%', $baseline['monthly_formula']);
    }

    public function test_t79_guard_installment_frequency_cannot_change(): void
    {
        $this->assertSame('monthly', Config::get('shm-business-rule-contract.installment.frequency'));
        $this->assertSame('monthly', Config::get('shm-business-rule-contract.decision_domains.installment_frequency.value'));

        $frequency = Config::get('shm-business-rule-contract.t79_baseline.installment_frequency');
        $this->assertSame('monthly', $frequency['value']);
        $this->assertSame(1, $frequency['installments_per_month']);
        $this->assertSame('tenor_months', $frequency['number_of_installments']);
    }

    public function test_t79_guard_no_speculative_tenor_limits(): void
    {
        $bounds = Config::get('shm-business-rule-contract.decision_domains.tenor.tenor_bounds');

        $this->assertSame('PENDING BUSINESS CONFIRMATION', $bounds['status']);
        $this->assertArrayNotHasKey('minimum', $bounds);
        $this->assertArrayNotHasKey('maximum', $bounds);
        $this->assertArrayNotHasKey('min_months', $bounds);
        $this->assertArrayNotHasKey('max_months', $bounds);

        $baseline = Config::get('shm-business-rule-contract.t79_baseline.tenor');
        $this->assertArrayNotHasKey('min', $baseline);
        $this->assertArrayNotHasKey('max', $baseline);
    }

    public function test_t79_guard_no_speculative_penalty_percentages(): void
    {
        $penalty = Config::get('shm-business-rule-contract.decision_domains.penalty');

        foreach (['percent', 'percent_per_period', 'rate_percent', 'amount_per_day', 'fixed_amount', 'grace_days'] as $key) {
            $this->assertArrayNotHasKey($key, $penalty, "Domain penalty tidak boleh memuat nilai {$key}.");
        }

        $baseline = Config::get('shm-business-rule-contract.t79_baseline.penalty');
        $this->assertSame('PENDING BUSINESS CONFIRMATION', $baseline['rule_status']);
        $this->assertFalse($baseline['calculation_implemented']);
    }

    public function test_t79_guard_no_settlement_tolerance_adopted(): void
    {
        $tolerance = Config::get('shm-business-rule-contract.decision_domains.settlement.tolerance');

        $this->assertSame('PENDING BUSINESS CONFIRMATION', $tolerance['status']);
        $this->assertArrayNotHasKey('value', $tolerance);
        $this->assertStringContainsString('TIDAK mengadopsi settlement_tolerance 0.01', $tolerance['note']);

        $baseline = Config::get('shm-business-rule-contract.t79_baseline.settlement');
        $this->assertStringContainsString('PENDING BUSINESS CONFIRMATION', $baseline['tolerance']);
    }

    public function test_t79_guard_settlement_does_not_charge_future_flat_interest(): void
    {
        $future = Config::get('shm-business-rule-contract.decision_domains.settlement.future_flat_interest_not_auto_charged');

        $this->assertSame('CONFIRMED BASELINE', $future['status']);
        $this->assertTrue($future['value']);

        $baseline = Config::get('shm-business-rule-contract.t79_baseline.settlement');
        $this->assertTrue($baseline['future_flat_interest_not_auto_charged']);
    }

    public function test_t79_guard_no_grace_period_introduced(): void
    {
        $dueDate = Config::get('shm-business-rule-contract.decision_domains.repayment_schedule.due_date_rule');

        $this->assertStringContainsString('TIDAK diperkenalkan', $dueDate['grace_period']);

        $baseline = Config::get('shm-business-rule-contract.t79_baseline.due_date');
        $this->assertStringContainsString('TIDAK diperkenalkan', $baseline['grace_period']);
    }

    public function test_t79_guard_no_rounding_fractional_rupiah_persisted(): void
    {
        $rounding = Config::get('shm-business-rule-contract.decision_domains.rounding');

        $this->assertSame('CONFIRMED BASELINE', $rounding['status']);
        $this->assertSame('rupiah', $rounding['monetary_unit']);
        $this->assertFalse($rounding['persist_fractional_rupiah']);
        $this->assertSame('nearest_rupiah', $rounding['method']);
        $this->assertFalse($rounding['accumulate_error']);
        $this->assertArrayNotHasKey('decimals', $rounding);
    }

    public function test_t79_guard_payment_reversal_never_deletes_history(): void
    {
        $reversal = Config::get('shm-business-rule-contract.decision_domains.payment_reversal');

        $this->assertFalse($reversal['delete_history']);
        $this->assertTrue($reversal['auditable']);
        $this->assertTrue($reversal['restores_obligation']);

        $baseline = Config::get('shm-business-rule-contract.t79_baseline.payment_reversal');
        $this->assertFalse($baseline['delete_history']);
        $this->assertTrue($baseline['auditable']);
    }

    public function test_t79_guard_collection_is_based_on_actual_repayment_status(): void
    {
        $collection = Config::get('shm-business-rule-contract.decision_domains.collection');

        $this->assertTrue($collection['basis']['fabricated_status_forbidden']);
        $this->assertFalse($collection['percentages_or_schedules_invented']);
    }

    // =================================================================
    // T84 FINANCIAL & PAYMENT CONTRACT TESTS (Section S)
    // =================================================================

    public function test_t84_disbursement_costs_and_net_disbursement_contract(): void
    {
        $costs = Config::get('shm-business-rule-contract.disbursement_costs');

        $this->assertSame(2.0, $costs['admin_fee']['percent']);
        $this->assertSame(3.0, $costs['provision_fee']['percent']);
        $this->assertSame(1.0, $costs['insurance_fee']['percent']);
        $this->assertSame('custom', $costs['notary_fee']['type']);
        $this->assertSame(100000, $costs['principal_savings']['amount']);
        $this->assertSame(25000, $costs['mandatory_savings']['monthly_amount']);
        $this->assertSame('initial_disbursement', $costs['deduction_timing']['timing']);

        // Verify calculation logic against a sample principal and tenor
        $principal = 450000000.0;
        $tenor = 36;
        $admin = $principal * 0.02; // 9,000,000
        $provision = $principal * 0.03; // 13,500,000
        $insurance = $principal * 0.01; // 4,500,000
        $notary = 2000000.0;
        $principalSavings = 100000.0;
        $mandatorySavings = 25000 * $tenor; // 900,000

        $totalDeduction = $admin + $provision + $insurance + $notary + $principalSavings + $mandatorySavings; // 30,000,000
        $netDisbursement = $principal - $totalDeduction; // 420,000,000

        $this->assertEquals(30000000.0, $totalDeduction);
        $this->assertEquals(420000000.0, $netDisbursement);

        $netConfig = Config::get('shm-business-rule-contract.net_disbursement');
        $this->assertStringContainsString('Original principal / plafond tetap menjadi basis', $netConfig['principal_preservation']);
    }

    public function test_t84_payment_allocation_partial_overpayment_reversal_contracts(): void
    {
        $allocation = Config::get('shm-business-rule-contract.payment_allocation');
        $this->assertSame(['penalty', 'interest', 'principal'], $allocation['order']);
        $this->assertTrue($allocation['chronological']);

        $partial = Config::get('shm-business-rule-contract.partial_payment');
        $this->assertTrue($partial['allowed']);

        $overpayment = Config::get('shm-business-rule-contract.overpayment');
        $this->assertFalse($overpayment['silently_discarded']);

        $reversal = Config::get('shm-business-rule-contract.payment_reversal');
        $this->assertTrue($reversal['explicit']);
        $this->assertTrue($reversal['auditable']);
        $this->assertFalse($reversal['delete_history']);

        $idempotency = Config::get('shm-business-rule-contract.payment_idempotency');
        $this->assertNotNull($idempotency['rule']);

        $concurrency = Config::get('shm-business-rule-contract.payment_concurrency');
        $this->assertNotNull($concurrency['rule']);

        $settlement = Config::get('shm-business-rule-contract.settlement_dependency');
        $this->assertNotNull($settlement['rule']);

        $collection = Config::get('shm-business-rule-contract.collection_dependency');
        $this->assertNotNull($collection['source_of_truth']);
    }

    public function test_t84_all_assertions_are_true(): void
    {
        $assertions = Config::get('shm-business-rule-contract.assert');

        foreach ($assertions as $key => $value) {
            $this->assertTrue($value, "Assertion {$key} harus bernilai true.");
        }
    }
}
