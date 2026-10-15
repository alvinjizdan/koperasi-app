<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * T91 — Collection Business Rule & Workflow Contract SHM.
 *
 * TICKET KONTRAK: mengunci keputusan bisnis Collection yang sudah
 * dikonfirmasi pihak koperasi (disesuaikan role KSP Bima Sakti Mandiri)
 * agar ticket implementasi Collection berikutnya tidak menebak aturan.
 *
 * Seluruh test di sini CONTRACT-LEVEL (config/shm-business-rule-contract.php
 * + source scan statis): TIDAK ada tulisan ke database, TIDAK ada perubahan
 * behavior runtime monitoring T90, payment, settlement, maupun loan status.
 *
 * Keputusan yang dikunci:
 * - Internal application: workflow antarpegawai, bukan customer-facing;
 *   tanpa WhatsApp/SMS/email otomatis (Rule 15).
 * - Owner awal = Marketing; role Collector khusus TIDAK dibuat (Rule 1).
 * - Escalation authority = Branch Manager & President Director (Rule 9/12).
 * - Finance Manager menggantikan nomenklatur "Kasir" pada desain baru.
 * - Eligibility = predicate T90 dipertahankan apa adanya (Rule 3).
 * - 5 status lifecycle, 5 aging bucket, activity/result, PTP contract
 *   (Rule 4-8) — persistensi/enum = ticket implementasi.
 * - Payment tetap workflow existing T86-T88; Settlement tetap workflow T89
 *   terpisah (Rule 10/11).
 * - 17 pending business rules TIDAK boleh berubah jadi implementasi angka.
 */
class ShmCollectionBusinessRuleContractTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function workflow(): array
    {
        return (array) Config::get('shm-business-rule-contract.collection_workflow');
    }

    // ------------------------------------------------------------------
    // 1. Eligibility — predicate T90 tidak berubah (Rule 3)
    // ------------------------------------------------------------------

    public function test_collection_eligibility_contract_preserves_t90_predicate(): void
    {
        $eligibility = $this->workflow()['eligibility'];

        $this->assertStringContainsString('T90 predicate', $eligibility['status']);

        $requires = $eligibility['requires'];

        $this->assertStringContainsString('status = active', $requires[0]);
        $this->assertStringContainsString('disbursed_at IS NOT NULL', $requires[1]);
        $this->assertStringContainsString('due_date < today', $requires[2]);
        $this->assertStringContainsString('> 0', $requires[3]);

        $excluded = $eligibility['excluded'];

        $this->assertStringContainsString('closed', $excluded[0]);
        $this->assertStringContainsString('undisbursed', $excluded[1]);
        $this->assertStringContainsString('future_only', $excluded[2]);
        $this->assertStringContainsString('overdue_fully_paid', $excluded[3]);

        $this->assertStringContainsString('none', $eligibility['grace_period']);
        $this->assertTrue($eligibility['change_forbidden_without_explicit_new_rule']);

        // Guard statis: T90 monitoring source tetap memakai predikat yang sama.
        $service = (string) file_get_contents(
            base_path('app/Services/Shm/ShmCollectionQueryService.php'),
        );

        $this->assertStringContainsString('applyOverdueCondition', $service);
        $this->assertStringContainsString('ShmLoan::STATUS_ACTIVE', $service);
        $this->assertStringContainsString("whereNotNull('disbursed_at')", $service);
        $this->assertStringContainsString('toDateString()', $service);
    }

    // ------------------------------------------------------------------
    // 2. Owner — Marketing; tanpa role Collector (Rule 1)
    // ------------------------------------------------------------------

    public function test_marketing_is_initial_collection_owner_without_collector_role(): void
    {
        $owner = $this->workflow()['owner'];

        $this->assertSame('marketing', $owner['initial_owner_role']);
        $this->assertStringContainsString(
            'PENDING BUSINESS CONFIRMATION',
            $owner['dedicated_collector_role'],
        );
        $this->assertContains('record_activity', $owner['marketing_responsibilities']);

        $collector = $this->workflow()['roles']['collector'];

        $this->assertFalse($collector['created']);
    }

    // ------------------------------------------------------------------
    // 3-4. Escalation authority — Branch Manager & President Director
    // ------------------------------------------------------------------

    public function test_escalation_authorities_are_branch_manager_and_president_director(): void
    {
        $escalation = $this->workflow()['escalation'];

        $this->assertContains('branch_manager', $escalation['authorities']);
        $this->assertContains('president_director', $escalation['authorities']);
        $this->assertSame('marketing', $escalation['initial_level']);

        // T99: eskalasi otomatis HANYA jalur 3 PTP MISSED per periode
        // anchored (angka dari ticket, bukan tebakan); timing non-PTP tetap
        // PENDING BUSINESS CONFIRMATION.
        $this->assertSame('CONDITIONAL', $escalation['automatic_escalation']);
        $this->assertSame(3, $escalation['missed_ptp_threshold']);
        $this->assertStringContainsString(
            '3 PTP MISSED',
            $escalation['automatic_escalation_condition'],
        );
        $this->assertStringContainsString('CONFIRMED (T99)', $escalation['threshold']);
        $this->assertStringContainsString('PENDING', $escalation['timing']);

        // Authority assignment ada pada dua role yang sama (Rule 2).
        $this->assertSame(
            ['branch_manager', 'president_director'],
            $escalation['assignment_authority'],
        );
        $this->assertStringContainsString(
            'PENDING',
            $escalation['assignment_algorithm'],
        );

        // Role business tercatat dengan status implementasi teknis.
        $roles = $this->workflow()['roles'];

        $this->assertFalse($roles['branch_manager']['technical_role_exists']);
        $this->assertFalse($roles['president_director']['technical_role_exists']);
    }

    // ------------------------------------------------------------------
    // 5. Nomenklatur — Finance Manager menggantikan Kasir
    // ------------------------------------------------------------------

    public function test_finance_manager_replaces_kasir_nomenclature_in_new_contract(): void
    {
        $financeManager = $this->workflow()['roles']['finance_manager'];

        $this->assertSame('FINANCE MANAGER', $financeManager['business_role']);
        $this->assertSame('Kasir', $financeManager['nomenclature_replaces']);
        $this->assertSame('Finance Manager', $financeManager['new_workflow_design_uses']);
        $this->assertStringContainsString(
            'compatibility/legacy',
            $financeManager['legacy_term_policy'],
        );
        $this->assertFalse($financeManager['collection_initial_owner']);

        // Workflow pengajuan (context) memakai nomenklatur baru — bukan Kasir.
        $sequence = $this->workflow()['context']['loan_application_workflow_context']['sequence'];

        $this->assertContains('finance_manager_pencairan', $sequence);
        $this->assertNotContains('kasir_pencairan', $sequence);

        // Legacy role "Kasir" di registry permission TETAP (compatibility) —
        // T91 tidak menghapus/mengganti role teknis existing.
        $permissions = (string) file_get_contents(base_path('config/permissions.php'));

        $this->assertStringContainsString("'display_name' => 'Kasir'", $permissions);
    }

    // ------------------------------------------------------------------
    // 6. Status lifecycle (Rule 4)
    // ------------------------------------------------------------------

    public function test_collection_status_lifecycle_is_documented(): void
    {
        $statuses = $this->workflow()['statuses'];

        $this->assertSame(
            ['OPEN', 'IN_PROGRESS', 'PROMISE_TO_PAY', 'ESCALATED', 'RESOLVED'],
            $statuses['values'],
        );

        foreach ($statuses['values'] as $value) {
            $this->assertArrayHasKey($value, $statuses['meanings']);
        }

        // Persistensi status = PENDING; tidak ada perubahan loan/installment status.
        $this->assertStringContainsString('PENDING', $statuses['persistence']);
        $this->assertTrue($statuses['loan_status_untouched']);
        $this->assertTrue($statuses['installment_db_status_untouched']);

        // Workflow baseline + escalation branch tercatat tanpa auto-escalation.
        $workflow = $this->workflow()['workflow'];

        $this->assertSame(
            ['loan_active_overdue', 'collection_open', 'marketing_follow_up', 'resolved'],
            $workflow['baseline'],
        );
        $this->assertFalse($workflow['auto_escalation']);
    }

    // ------------------------------------------------------------------
    // 7. Aging buckets (Rule 5)
    // ------------------------------------------------------------------

    public function test_aging_buckets_are_documented_from_t90_days_overdue(): void
    {
        $aging = $this->workflow()['aging'];

        $this->assertStringContainsString('days_overdue', $aging['calculation']);

        $buckets = $aging['buckets'];

        $this->assertCount(5, $buckets);
        $this->assertSame(1, $buckets[0]['min_days']);
        $this->assertSame(7, $buckets[0]['max_days']);
        $this->assertSame(8, $buckets[1]['min_days']);
        $this->assertSame(30, $buckets[1]['max_days']);
        $this->assertSame(31, $buckets[2]['min_days']);
        $this->assertSame(60, $buckets[2]['max_days']);
        $this->assertSame(61, $buckets[3]['min_days']);
        $this->assertSame(90, $buckets[3]['max_days']);
        $this->assertSame(91, $buckets[4]['min_days']);
        $this->assertNull($buckets[4]['max_days']);

        // Bucket TIDAK memicu escalation otomatis (Rule 5).
        $this->assertFalse($aging['auto_escalation_by_bucket']);
    }

    // ------------------------------------------------------------------
    // 8. Activity & Result contract (Rule 6-7)
    // ------------------------------------------------------------------

    public function test_collection_activity_and_result_contract_are_documented(): void
    {
        $workflow = $this->workflow();

        $activities = $workflow['activities'];

        $this->assertSame(
            ['phone_call', 'visit', 'follow_up', 'other'],
            $activities['types'],
        );
        $this->assertSame(
            ['datetime', 'user_marketing', 'activity', 'result', 'notes', 'next_action_optional'],
            $activities['fields'],
        );
        $this->assertStringContainsString('PENDING', $activities['automation']);

        $results = $workflow['results'];

        $this->assertSame(
            ['CONTACTED', 'NOT_REACHED', 'PROMISE_TO_PAY', 'PAID', 'UNABLE_TO_PAY', 'OTHER'],
            $results['values'],
        );
        // Pemetaan enum database = PENDING sampai implementasi (Rule 7).
        $this->assertStringContainsString('PENDING', $results['enum_mapping']);
    }

    // ------------------------------------------------------------------
    // 9. Promise to Pay (Rule 8) — limit T99 CONFIRMED + sisa PENDING
    // ------------------------------------------------------------------

    public function test_promise_to_pay_contract_records_fields_and_t99_limits(): void
    {
        $ptp = $this->workflow()['promise_to_pay'];

        $this->assertTrue($ptp['recordable']);
        $this->assertSame(
            ['promised_date', 'promised_amount', 'notes'],
            $ptp['fields'],
        );
        $this->assertStringContainsString('PROMISE_TO_PAY', $ptp['status_effect']);

        // T99 CONFIRMED: kelayakan overdue ≥ 7 hari (due_date nyata) & kuota
        // 3 per periode berlabuh — angka berasal dari ticket, bukan tebakan.
        $this->assertSame(7, $ptp['eligibility']['overdue_min_days']);
        $this->assertStringContainsString('due_date nyata', $ptp['eligibility']['basis']);
        $this->assertSame(3, $ptp['quota']['max_per_period']);
        $this->assertSame(
            ['OPEN', 'FULFILLED', 'MISSED', 'CANCELLED'],
            $ptp['quota']['counts_toward_quota'],
        );
        $this->assertFalse($ptp['quota']['failed_validation_counts']);
        $this->assertStringContainsString('BERLABUH', $ptp['quota']['period']);
        $this->assertStringContainsString('addMonthsNoOverflow', $ptp['quota']['period']);
        $this->assertStringContainsString('CONFIRMED (T99)', $ptp['max_attempts']);
        $this->assertStringContainsString('collection:ptp-missed', $ptp['broken_ptp_detection']);

        // Batasan yang MEMANG belum diputuskan tetap PENDING — tidak diisi.
        $this->assertSame('PENDING BUSINESS CONFIRMATION', $ptp['grace_period']);
        $this->assertStringContainsString('PENDING BUSINESS CONFIRMATION', $ptp['broken_ptp_penalty']);
        $this->assertStringContainsString('PENDING BUSINESS CONFIRMATION', $ptp['manual_missed_escalation_effect']);
        $this->assertTrue($ptp['cannot_guess']);
    }

    // ------------------------------------------------------------------
    // 10. Payment resolution — workflow payment existing (Rule 10)
    // ------------------------------------------------------------------

    public function test_payment_resolution_uses_existing_payment_workflow(): void
    {
        $payment = $this->workflow()['payment_resolution'];

        $this->assertStringContainsString('existing SHM payment workflow', $payment['payment_workflow']);
        $this->assertFalse($payment['collection_creates_payment']);
        $this->assertTrue($payment['payment_allocation_unchanged']);
        $this->assertTrue($payment['payment_workflow_unchanged_by_t91']);
        $this->assertFalse($payment['loan_closed_by_collection']);

        // Partial payment → outstanding dihitung ulang; RESOLVED saat target lunas.
        $this->assertStringContainsString('outstanding dihitung ulang', $payment['partial_payment_effect']);
        $this->assertStringContainsString('RESOLVED', $payment['resolution_condition']);
    }

    // ------------------------------------------------------------------
    // 11. Settlement tetap workflow terpisah (Rule 11)
    // ------------------------------------------------------------------

    public function test_settlement_remains_a_separate_workflow_from_collection(): void
    {
        $settlement = $this->workflow()['settlement_interaction'];

        $this->assertTrue($settlement['separate_workflows']);
        $this->assertSame(
            ['pending', 'approved', 'payment_recorded', 'payment_verified', 'completed'],
            $settlement['settlement_statuses'],
        );
        $this->assertFalse($settlement['collection_creates_payment_or_settlement']);
        $this->assertTrue($settlement['settlement_unchanged_by_t91']);

        // Aturan per tahap settlement = PENDING (tidak ditebak).
        $this->assertStringContainsString(
            'PENDING BUSINESS CONFIRMATION',
            $settlement['settlement_stage_specific_rules'],
        );
        $this->assertTrue($settlement['cannot_guess']);
    }

    // ------------------------------------------------------------------
    // 12. Pending rules tidak berubah jadi implementasi yang dikarang
    // ------------------------------------------------------------------

    public function test_pending_business_rules_are_listed_and_not_invented(): void
    {
        $pending = $this->workflow()['pending_rules'];

        $this->assertSame('PENDING BUSINESS CONFIRMATION', $pending['status']);
        $this->assertTrue($pending['cannot_guess']);
        $this->assertTrue($pending['invention_forbidden']);

        $items = $pending['items'];

        // T99 melepas dua item yang sudah CONFIRMED (escalation threshold
        // jalur PTP & maksimum PTP per periode) — daftar kini 15 item.
        $this->assertCount(15, $items);
        $this->assertNotContains('exact_escalation_threshold', $items);
        $this->assertNotContains('maximum_ptp_attempts', $items);
        $this->assertContains('automatic_escalation_timing', $items);
        $this->assertContains('exact_ptp_grace_period', $items);
        $this->assertContains('penalty_formula', $items);
        $this->assertContains('penalty_grace_period', $items);
        $this->assertContains('penalty_maximum', $items);
        $this->assertContains('collection_cadence', $items);
        $this->assertContains('automatic_scheduler_or_job', $items);
        $this->assertContains('collector_role', $items);
        $this->assertContains('exact_assignment_algorithm', $items);
        $this->assertContains('settlement_vs_collection_temporary_suspension_rule', $items);
        $this->assertContains('restructuring', $items);
        $this->assertContains('write_off', $items);
        $this->assertContains('collection_closure_tolerance', $items);
        $this->assertContains('detailed_permission_matrix_implementation', $items);
        $this->assertContains('notification_mechanism', $items);
    }

    // ------------------------------------------------------------------
    // Context internal-application (Rule 15) & workflow pengajuan
    // ------------------------------------------------------------------

    public function test_collection_is_internal_only_without_external_automation(): void
    {
        $context = $this->workflow()['context'];

        $this->assertSame('internal_koperasi', $context['application_type']);
        $this->assertFalse($context['customer_facing']);

        foreach ($context['external_automation'] as $channel => $enabled) {
            $this->assertFalse($enabled, "External automation '{$channel}' harus false.");
        }

        $this->assertStringContainsString('TICKET TERPISAH', $context['internal_notification_future']);
    }

    public function test_loan_application_workflow_context_is_recorded_but_not_changed(): void
    {
        $context = $this->workflow()['context']['loan_application_workflow_context'];

        $this->assertSame(
            [
                'marketing_input_pengajuan',
                'branch_manager_analisis',
                'president_director_final_approval',
                'finance_manager_pencairan',
                'loan_active',
            ],
            $context['sequence'],
        );
        $this->assertStringContainsString('CONTEXT ONLY', $context['status']);
        $this->assertStringContainsString('PENDING TICKET TERPISAH', $context['technical_change']);
    }

    // ------------------------------------------------------------------
    // Permission konseptual matrix (Rule 13) + permission teknis (T101)
    // ------------------------------------------------------------------

    public function test_permission_contract_keeps_matrix_conceptual_and_no_collector_role(): void
    {
        $permissions = $this->workflow()['permissions'];

        // Matrix detail (SIAPA menerima authority) tetap konseptual/pending.
        $this->assertStringContainsString('CONCEPTUAL ONLY', $permissions['status']);
        $this->assertFalse($permissions['new_permissions_added_by_t91']);
        $this->assertFalse($permissions['collector_role_created']);
        $this->assertStringContainsString(
            'PENDING BUSINESS CONFIRMATION',
            $permissions['detailed_matrix'],
        );

        $this->assertContains('view_collection', $permissions['marketing']);
        $this->assertContains('monitor_collection', $permissions['branch_manager']);
        $this->assertContains('monitor_collection', $permissions['president_director']);
        $this->assertContains('not_initial_collection_owner', $permissions['finance_manager']);

        // Basis permission tetap yang existing (T90).
        $this->assertStringContainsString('shm.view', $permissions['existing_basis'][0]);

        $this->assertSame(
            [
                'shm.collection.view',
                'shm.collection.transition',
                'shm.collection.activity.create',
                'shm.collection.ptp.create',
                'shm.collection.ptp.transition',
            ],
            $permissions['technical_permissions_added_by_t101'],
        );

        // Registry: role collector TIDAK dibuat; permission teknis
        // Collection (T101) terdaftar sehingga grantable via UI.
        $registry = (string) file_get_contents(base_path('config/permissions.php'));

        $this->assertStringNotContainsString("'collector'", $registry);

        foreach ($permissions['technical_permissions_added_by_t101'] as $name) {
            $this->assertStringContainsString("'".substr($name, 4)."'", $registry);
        }
    }

    // ------------------------------------------------------------------
    // Audit trail (Rule 14) — mekanisme existing
    // ------------------------------------------------------------------

    public function test_audit_requirements_use_the_existing_audit_mechanism(): void
    {
        $audit = $this->workflow()['audit'];

        $this->assertStringContainsString('existing audit mechanism', $audit['mechanism']);

        $traceable = $audit['must_trace'];

        $this->assertCount(8, $traceable);
        $this->assertContains('collection_dibuka_open', $traceable);
        $this->assertContains('perubahan_status', $traceable);
        $this->assertContains('assignment_reassignment', $traceable);
        $this->assertContains('aktivitas_follow_up', $traceable);
        $this->assertContains('hasil_follow_up', $traceable);
        $this->assertContains('promise_to_pay', $traceable);
        $this->assertContains('escalation', $traceable);
        $this->assertContains('resolution', $traceable);

        // GET monitoring T90 tidak menulis audit.
        $this->assertTrue($audit['read_only_get_writes_no_audit']);
    }

    // ------------------------------------------------------------------
    // Integritas kontrak T91: additive, contract-only, versi diperbarui
    // ------------------------------------------------------------------

    public function test_t91_contract_is_additive_and_reference_only(): void
    {
        $meta = (array) Config::get('shm-business-rule-contract.meta');

        $this->assertStringContainsString('T91', $meta['version']);
        $this->assertSame('reference_contract_only', $meta['status']);
        $this->assertStringContainsString('ADDITIVE', $meta['changed_by']);

        $contractOnly = $this->workflow()['contract_only'];

        $this->assertFalse($contractOnly['ui_action_created']);
        $this->assertFalse($contractOnly['db_collection_case_created']);
        $this->assertFalse($contractOnly['permission_created']);
        $this->assertFalse($contractOnly['t90_monitoring_changed']);
        $this->assertFalse($contractOnly['payment_settlement_loan_status_changed']);

        // Seluruh assert guard (termasuk T91) bernilai true.
        foreach ((array) Config::get('shm-business-rule-contract.assert') as $key => $value) {
            $this->assertTrue($value, "Assertion {$key} harus bernilai true.");
        }
    }

    public function test_t90_collection_routes_remain_get_only(): void
    {
        $routes = (string) file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString(
            "Route::get('/collection', [ShmCollectionController::class, 'index'])",
            $routes,
        );
        $this->assertStringContainsString(
            "Route::get('/collection/{loan}', [ShmCollectionController::class, 'show'])",
            $routes,
        );
        $this->assertStringNotContainsString(
            "Route::post('/collection'",
            $routes,
        );
        $this->assertStringNotContainsString(
            "Route::delete('/collection'",
            $routes,
        );
    }
}
