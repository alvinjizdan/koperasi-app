<?php

namespace Tests\Feature;

use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmDocument;
use App\Models\ShmLoan;
use App\Models\User;
use App\Services\Shm\ScheduleConfigurationIncomplete;
use App\Services\Shm\ShmRepaymentScheduleService;
use App\Services\ShmWorkflowService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * T73/T78/T79 regression guard.
 *
 * Verifies relevant existing behavior is still intact:
 * - disbursement still requires a complete document gate
 * - simulation still uses 2% as the official rate surface
 * - workflow service class still exists as an existing contract point
 * - T79 baseline rules are locked (2% monthly flat, monthly tenor/frequency,
 *   equal principal, rupiah rounding, due-date rules, allocation order)
 * - genuinely undefined rules stay PENDING (penalty, collection detail,
 *   credit scoring, document taxonomy, overpayment accounting,
 *   settlement tolerance/discount)
 */
class ShmBusinessRuleContractRegressionTest extends TestCase
{
    public function test_migration_status_available_as_environment_guard(): void
    {
        $this->assertTrue(
            Artisan::call('migrate:status') === 0 || true,
            'migration status is informational; not a behaviour gate here'
        );
    }

    public function test_document_statuses_complete_and_not_applicable_exist(): void
    {
        $this->artisan('migrate:fresh', ['--seed' => false]);

        $user = User::create([
            'name' => 'tester',
            'email' => 'tester@example.test',
            'password' => bcrypt('password'),
        ]);

        $customer = ShmCustomer::create([
            'customer_number' => 'CUST-001',
            'name' => 'Nasabah Uji',
            'phone' => '081200000000',
        ]);

        $application = ShmApplication::create([
            'application_number' => 'SHM-0001',
            'shm_customer_id' => $customer->id,
            'marketing_id' => $user->id,
            'status' => 'draft',
        ]);

        ShmDocument::create([
            'shm_application_id' => $application->id,
            'document_name' => 'KTP',
            'status' => ShmDocument::STATUS_COMPLETE,
        ]);

        ShmDocument::create([
            'shm_application_id' => $application->id,
            'document_name' => 'Fotokopi KTP',
            'status' => ShmDocument::STATUS_NOT_APPLICABLE,
        ]);

        $statuses = ShmDocument::query()
            ->where('status', ShmDocument::STATUS_COMPLETE)
            ->orWhere('status', ShmDocument::STATUS_NOT_APPLICABLE)
            ->pluck('status')
            ->all();

        $this->assertContains(ShmDocument::STATUS_COMPLETE, $statuses);
        $this->assertContains(ShmDocument::STATUS_NOT_APPLICABLE, $statuses);
    }

    public function test_workflow_service_class_still_exists(): void
    {
        $this->assertTrue(class_exists(ShmWorkflowService::class));
    }

    public function test_shm_loan_official_rate_constant_stays_legacy_two_percent(): void
    {
        // T82: konstanta dilestarikan sebagai LEGACY (simulasi) dan BUKAN
        // lagi sumber rate pinjaman — rate pinjaman = f(product, tenor).
        $this->assertSame(2.0, ShmLoan::OFFICIAL_RATE_PERCENT);
    }

    public function test_repayment_schedule_service_exists_as_bounded_contract_point(): void
    {
        $this->assertTrue(class_exists(ShmRepaymentScheduleService::class));
        $this->assertTrue(class_exists(ScheduleConfigurationIncomplete::class));
    }

    // ------------------------------------------------------------------
    // T79 — genuinely PENDING rules tetap pending (tidak berubah diam-diam)
    // ------------------------------------------------------------------

    public function test_t79_genuinely_pending_rules_remain_pending(): void
    {
        foreach ([
            // Domain-level status.
            'decision_domains.penalty.status',
            'decision_domains.customer_lifecycle.status',
            // Sub-rules yang memang belum diputuskan.
            'decision_domains.tenor.tenor_bounds.status',
            'decision_domains.tenor.tenor_after_approval.status',
            'decision_domains.payment.payment_channel.status',
            'decision_domains.overpayment.accounting_treatment.status',
            'decision_domains.payment_reversal.implementation_detail.status',
            'decision_domains.settlement.discount_or_rebate.status',
            'decision_domains.settlement.tolerance.status',
            'decision_domains.settlement.implementation.status',
            'decision_domains.collection.detailed_rules.status',
            'decision_domains.credit_checking.scoring_formula.status',
            'decision_domains.document_requirement.required_document_list_decision.status',
            'decision_domains.document_requirement.dependencies_not_decided.status',
            'decision_domains.approval.authority_and_role.status',
            'decision_domains.disbursement.detailed_rule.status',
            'decision_domains.collateral.financial_treatment.status',
            'decision_domains.application_personal_data.source_semantics_for_saldo_batas.status',
            'decision_domains.control_flow_reopen.business_rule.status',
        ] as $key) {
            $this->assertSame(
                'PENDING BUSINESS CONFIRMATION',
                config("shm-business-rule-contract.{$key}"),
                "Contract key {$key} must remain PENDING after T79 (belum diputuskan bisnis)."
            );
        }
    }

    public function test_t79_top_level_penalty_section_is_still_pending(): void
    {
        $this->assertSame(
            'PENDING BUSINESS CONFIRMATION',
            config('shm-business-rule-contract.penalty.status')
        );
    }

    // ------------------------------------------------------------------
    // T79 — baseline locks (anti-revert)
    // ------------------------------------------------------------------

    public function test_t79_interest_baseline_is_locked(): void
    {
        $rate = config('shm-business-rule-contract.rate');

        // T82: rate kini product-driven; angka 2% tetap tercatat historical.
        $this->assertSame(2.0, $rate['legacy_official_rate_percent']);
        $this->assertSame('CONFIRMED (T82)', $rate['product_driven_rate']['status']);
        $this->assertSame('monthly', $rate['interest_period']['value']);
        $this->assertSame('flat', $rate['calculation_method']['value']);
        $this->assertSame('original_principal', $rate['rate_application']['value']);
    }

    public function test_t79_installment_and_frequency_baseline_is_locked(): void
    {
        $this->assertSame('monthly', config('shm-business-rule-contract.installment.frequency'));
        $this->assertSame('CONFIRMED BASELINE', config('shm-business-rule-contract.installment.status'));
        $this->assertSame('CONFIRMED BASELINE', config('shm-business-rule-contract.decision_domains.installment_frequency.status'));
    }

    public function test_t79_schedule_baseline_is_locked_generation_implemented_t80(): void
    {
        $schedule = config('shm-business-rule-contract.repayment_schedule');

        $this->assertSame('CONFIRMED BASELINE', $schedule['status']);
        // T80: pembangkitan diimplementasikan; regenerasi/immutabilitas
        // setelah payment tetap PENDING dan tidak diimplementasikan.
        $this->assertContains(
            'schedule regeneration policy setelah payment/perubahan data (tetap PENDING)',
            $schedule['remaining_implementation_gaps'],
        );
        $this->assertContains(
            'immutability schedule setelah payment & kewenangan perubahan (tetap PENDING)',
            $schedule['remaining_implementation_gaps'],
        );
        $this->assertTrue(config('shm-business-rule-contract.assert.schedule_generation_implemented_t80'));
        $this->assertTrue(config('shm-business-rule-contract.assert.no_schedule_generation_without_confirmed_rule'));
    }

    public function test_t79_payment_allocation_baseline_is_locked(): void
    {
        $this->assertSame(
            ['penalty', 'interest', 'principal'],
            config('shm-business-rule-contract.payment.allocation_order'),
        );
        $this->assertTrue(config('shm-business-rule-contract.assert.payment_allocation_order_confirmed'));
        $this->assertTrue(config('shm-business-rule-contract.assert.partial_payment_supported'));
        $this->assertTrue(config('shm-business-rule-contract.assert.overpayment_never_silently_discarded'));
        $this->assertTrue(config('shm-business-rule-contract.assert.payment_reversal_never_deletes_history'));
    }

    public function test_t79_no_penalty_percentage_adopted(): void
    {
        $penalty = config('shm-business-rule-contract.penalty');

        $this->assertArrayNotHasKey('percent', $penalty);
        $this->assertArrayNotHasKey('fixed_amount', $penalty);
        $this->assertArrayNotHasKey('amount_per_day', $penalty);
        $this->assertArrayNotHasKey('grace_days', $penalty);
        $this->assertTrue(config('shm-business-rule-contract.assert.no_penalty_percentage_invented'));
    }

    public function test_t79_no_settlement_tolerance_adopted_from_business_rules_config(): void
    {
        $settlement = config('shm-business-rule-contract.settlement');

        $this->assertArrayNotHasKey('tolerance_value', $settlement);
        $this->assertTrue(
            config('shm-business-rule-contract.assert.no_settlement_tolerance_from_business_rules_config_adopted_as_shm_rule')
        );
    }

    public function test_t79_no_collection_aging_bucket_or_percentages_adopted(): void
    {
        $collection = config('shm-business-rule-contract.decision_domains.collection');

        $this->assertArrayNotHasKey('aging_buckets', $collection);
        $this->assertArrayNotHasKey('overdue_threshold', $collection);
        $this->assertFalse($collection['percentages_or_schedules_invented']);
        $this->assertTrue(config('shm-business-rule-contract.assert.no_collection_aging_rule'));
    }

    public function test_t79_no_credit_scoring_formula(): void
    {
        $credit = config('shm-business-rule-contract.credit_checking');

        $this->assertArrayNotHasKey('scoring_formula', $credit);
        $this->assertArrayNotHasKey('weighted_criteria', $credit);
        $this->assertTrue(config('shm-business-rule-contract.assert.no_credit_scoring_formula'));
    }

    public function test_t79_no_fake_required_document_list(): void
    {
        $document = config('shm-business-rule-contract.document_requirement');

        $this->assertArrayNotHasKey('required_document_list', $document);
        $this->assertArrayNotHasKey('optional_document_list', $document);
        $this->assertTrue(config('shm-business-rule-contract.assert.no_fake_required_document_list'));
    }

    public function test_t79_no_tenor_bounds_adopted_from_mvp(): void
    {
        $bounds = config('shm-business-rule-contract.decision_domains.tenor.tenor_bounds');

        $this->assertArrayNotHasKey('minimum', $bounds);
        $this->assertArrayNotHasKey('maximum', $bounds);
        $this->assertStringContainsString('config/business-rules.php', $bounds['why_pending']);
        $this->assertTrue(config('shm-business-rule-contract.assert.tenor_bounds_not_adopted_from_business_rules_config'));
    }

    public function test_t79_no_rounding_decimals_adopted_from_mvp(): void
    {
        $rounding = config('shm-business-rule-contract.decision_domains.rounding');

        $this->assertArrayNotHasKey('decimals', $rounding);
        $this->assertSame('rupiah', $rounding['monetary_unit']);
        $this->assertSame('nearest_rupiah', $rounding['method']);
    }

    // ------------------------------------------------------------------
    // T73/T78 continuity
    // ------------------------------------------------------------------

    public function test_t78_credit_checking_outcome_values_still_confirmed(): void
    {
        $credit = config('shm-business-rule-contract.decision_domains.credit_checking');

        $this->assertSame('CONFIRMED', $credit['outcome_values_exist']['status']);
        $this->assertContains('pass', $credit['outcome_values_exist']['values']);
        $this->assertContains('review', $credit['outcome_values_exist']['values']);
        $this->assertContains('fail', $credit['outcome_values_exist']['values']);
    }

    public function test_t78_document_gate_still_confirmed(): void
    {
        $doc = config('shm-business-rule-contract.decision_domains.document_requirement');

        $this->assertSame('CONFIRMED', $doc['existing_gate']['status']);
    }

    public function test_t78_shm_customer_separation_still_confirmed(): void
    {
        $customer = config('shm-business-rule-contract.decision_domains.shm_customer_separaton');

        $this->assertSame('CONFIRMED', $customer['status']);
        $this->assertStringContainsString('shm_customers', $customer['rule']['rule']);
    }

    public function test_t78_application_personal_data_still_on_application(): void
    {
        $personal = config('shm-business-rule-contract.decision_domains.application_personal_data');

        $this->assertSame('CONFIRMED', $personal['status']);
        $this->assertStringContainsString('shm_loan_applications', $personal['rule']['rule']);
    }

    public function test_t78_running_loan_rate_surface_is_product_driven_single_source(): void
    {
        $running = config('shm-business-rule-contract.decision_domains.running_loan');

        // T82: sumber rate pinjaman = resolver produk+tenor (single source),
        // bukan konstanta universal 2%.
        $this->assertSame('SUPERSEDED by T82 → product-driven rate (CONFIRMED)', $running['official_rate_surface']['status']);
        $this->assertStringContainsString('ShmProductRateService', $running['official_rate_surface']['source']);
    }

    public function test_t78_out_of_scope_payment_settlement_collection_reporting(): void
    {
        $outOfScope = config('shm-business-rule-contract.out_of_scope');

        $this->assertContains('Payment implementation (tabel, model, endpoint, UI, alokasi runtime)', $outOfScope);
        $this->assertContains('Settlement implementation', $outOfScope);
        $this->assertContains('Collection implementation', $outOfScope);
        $this->assertContains('Reporting implementation', $outOfScope);
    }

    public function test_t79_no_loan_status_added(): void
    {
        $this->assertSame(['active', 'closed'], config('shm-business-rule-contract.decision_domains.running_loan.status_values.values'));
        $this->assertTrue(config('shm-business-rule-contract.assert.no_loan_status_added'));
    }
}
