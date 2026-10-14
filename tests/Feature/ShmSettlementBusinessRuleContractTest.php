<?php

namespace Tests\Feature;

use App\Models\SavingProduct;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * T89A — Finalisasi Business Rule Settlement / Pelunasan SHM.
 *
 * TICKET KONTRAK: mengunci keputusan bisnis T89A agar T89B dapat
 * mengimplementasikan settlement completion tanpa menebak aturan.
 *
 * Seluruh test di sini bersifat CONTRACT-LEVEL (config/shm-business-rule-
 * contract.php + source scan statis + registry permission): TIDAK ada
 * tulisan ke database, TIDAK ada perubahan behavior runtime payment,
 * allocation, schedule, loan, maupun settlement (AC10/AC12).
 *
 * Keputusan yang dikunci:
 * - Model B: Settlement ≠ Payment; loan closed HANYA setelah pembayaran
 *   pelunasan terverifikasi (AC1–AC3).
 * - Kalkulasi pelunasan = simulasi pinjaman dengan persisted parameters
 *   (product rate matrix tetap source of truth; universal rate dilarang).
 * - Interest discount: eligible IFF principal > Rp50.000.000; besaran/formula
 *   tetap PENDING — TIDAK ditebak (AC4/AC5).
 * - Penalty: custom fillable, manual input, tanpa formula hard-coded (AC6).
 * - Overpayment → Simpanan Pokok koperasi existing (AC7).
 * - Approval: required, authority PENDING, workflow only (AC8).
 * - Collection: penagihan loan ACTIVE yang overdue, bukan loan closed (AC9).
 */
class ShmSettlementBusinessRuleContractTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function settlement(): array
    {
        return (array) Config::get('shm-business-rule-contract.settlement');
    }

    // ------------------------------------------------------------------
    // 1. Model B — Settlement dan Payment adalah dua proses berbeda
    // ------------------------------------------------------------------

    public function test_settlement_uses_model_b_separate_settlement_and_payment(): void
    {
        $settlement = $this->settlement();

        $this->assertSame('separate_settlement_and_payment', $settlement['model']['type']);

        // Urutan konseptual: payment tercatat & terverifikasi BARU settlement
        // completed, dan loan closed selalu tahap TERAKHIR.
        $flow = $settlement['model']['flow'];

        $this->assertSame('loan_active', $flow[0]);
        $this->assertSame('loan_closed', end($flow));

        $paymentRecorded = array_search('settlement_payment_recorded', $flow, true);
        $paymentVerified = array_search('settlement_payment_verified', $flow, true);
        $completed = array_search('settlement_completed', $flow, true);
        $closed = array_search('loan_closed', $flow, true);

        $this->assertNotFalse($paymentRecorded);
        $this->assertNotFalse($paymentVerified);
        $this->assertNotFalse($completed);
        $this->assertNotFalse($closed);
        // assertLessThan($expected, $actual) mengecek $actual < $expected.
        $this->assertLessThan($paymentVerified, $paymentRecorded);
        $this->assertLessThan($completed, $paymentVerified);
        $this->assertLessThan($closed, $completed);

        // Payment tetap satu-satunya transaksi uang masuk.
        $this->assertTrue($settlement['payment']['separate_from_settlement']);
        $this->assertSame('existing_payment_flow', $settlement['payment']['mechanism']);
    }

    // ------------------------------------------------------------------
    // 2. Settlement TIDAK otomatis menutup pinjaman
    // ------------------------------------------------------------------

    public function test_settlement_never_closes_loan_automatically(): void
    {
        $settlement = $this->settlement();
        $closure = $settlement['closure'];

        // Pinjaman tidak boleh ditutup saat preview, settlement pending,
        // maupun approved tanpa pembayaran.
        $this->assertContains('preview', $closure['not_before']);
        $this->assertContains('settlement_pending', $closure['not_before']);
        $this->assertContains('settlement_approved_without_payment', $closure['not_before']);
        $this->assertContains('confirmation_button_press', $closure['not_before']);

        // T89B mengimplementasikan penutupan (AC11): kontrak yang ditegak
        // kini LOKASI & GUARD-nya — penutupan HANYA ada di dalam complete()
        // dan selalu didahului guard payment_verified + idempotensi completed.
        $service = (string) file_get_contents(
            base_path('app/Services/Shm/ShmLoanSettlementService.php'),
        );
        $controller = (string) file_get_contents(
            base_path('app/Http/Controllers/Shm/ShmLoanSettlementController.php'),
        );

        // Controller TIDAK PERNAH menutup pinjaman (hanya memanggil service).
        $this->assertStringNotContainsString(
            'STATUS_CLOSED',
            $controller,
            'ShmLoanSettlementController tidak boleh menutup pinjaman — penutupan milik service complete().',
        );

        // Service: tepat SATU lokasi penutupan (assignment status), berada
        // SETELAH guard payment_verified, dan idempoten terhadap completed.
        $this->assertSame(
            1,
            substr_count($service, 'status = ShmLoan::STATUS_CLOSED'),
            'ShmLoanSettlementService harus memiliki tepat satu lokasi penutupan pinjaman (AC11).',
        );
        $this->assertStringContainsString(
            'guardStatus($settlement, ShmLoanSettlement::STATUS_PAYMENT_VERIFIED',
            $service,
            'Penutupan pinjaman wajib didahului guard status payment_verified.',
        );
        $this->assertStringContainsString(
            'if ($settlement->status === ShmLoanSettlement::STATUS_COMPLETED)',
            $service,
            'Completion wajib idempoten: panggilan ulang pada settlement completed tidak boleh mengulang efek.',
        );
    }

    // ------------------------------------------------------------------
    // 3. Loan close HANYA setelah settlement payment completed
    // ------------------------------------------------------------------

    public function test_loan_closes_only_after_settlement_payment_completed(): void
    {
        $settlement = $this->settlement();

        $this->assertSame('active_to_closed', $settlement['closure']['transition']);
        $this->assertSame('settlement_payment_completed', $settlement['closure']['condition']);

        // Pembayaran wajib diverifikasi lebih dulu dan memenuhi nominal
        // kewajiban settlement.
        $this->assertTrue($settlement['payment']['must_be_verified_before_completion']);
        $this->assertTrue($settlement['payment']['nominal_must_meet_settlement_obligation']);
        $this->assertTrue($settlement['approval']['required']);
    }

    // ------------------------------------------------------------------
    // 4 & 5. Interest discount threshold (principal/plafon awal)
    // ------------------------------------------------------------------

    public function test_interest_discount_eligibility_uses_initial_principal_threshold(): void
    {
        $discount = $this->settlement()['interest_discount'];
        $threshold = (float) $discount['threshold_principal_gt'];

        $this->assertSame('50000000', $discount['threshold_principal_gt']);
        $this->assertSame('principal/plafon awal loan', $discount['threshold_basis']);

        // Pinjaman > Rp50.000.000 = eligible.
        $this->assertTrue((float) '50000001' > $threshold);
        $this->assertTrue((float) '75000000' > $threshold);
        $this->assertTrue($discount['eligible_above_threshold']);

        // Pinjaman <= Rp50.000.000 = TIDAK eligible (bunga flat tanpa potongan).
        $this->assertFalse((float) '50000000' > $threshold);
        $this->assertFalse((float) '10000000' > $threshold);
        $this->assertFalse($discount['eligible_at_or_below_threshold']);
        $this->assertStringContainsString('flat', $discount['below_threshold_rule']);

        // Decision domain (T79/T89A) konsisten.
        $domain = Config::get('shm-business-rule-contract.decision_domains.settlement.discount_or_rebate');
        $this->assertTrue($domain['eligible_above_threshold']);
        $this->assertFalse($domain['eligible_at_or_below_threshold']);
        $this->assertSame('50000000', $domain['threshold_principal_gt']);
    }

    // ------------------------------------------------------------------
    // 6. Besaran/formula discount: T89B mengonfirmasi formula mekanisme
    //    (base × policy percent); persentase TETAP data-driven tanpa seed
    //    default — tidak ada angka yang dikarang.
    // ------------------------------------------------------------------

    public function test_discount_formula_is_data_driven_with_no_seeded_percentage(): void
    {
        $discount = $this->settlement()['interest_discount'];

        // Formula mekanisme dikonfirmasi T89B: base = sisa bunga; persentase
        // dari master policy (bukan universal rate, bukan angka rekaan).
        $this->assertStringContainsString('remaining_interest', $discount['formula']);
        $this->assertStringContainsString('policy.discount_percent', $discount['formula']);
        $this->assertStringContainsString('remaining/future interest', $discount['base']);
        $this->assertStringContainsString('data-driven', $discount['percent_source']);
        $this->assertStringContainsString('TIDAK ada default percentage', $discount['percent_source']);

        // `cannot_guess` tetap true: persentase TIDAK BISA dikarang sistem.
        $this->assertTrue($discount['cannot_guess']);

        // Tidak ada field persentase angka yang tertanam di kontrak.
        foreach (['value', 'amount', 'discount_value', 'discount_amount', 'discount_percent', 'percentage', 'default_percent'] as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $discount,
                "Contract tidak boleh memuat persentase/besaran discount rekaan ({$forbidden}).",
            );
        }

        // Master policy data-driven & tanpa seed default (AC7).
        $policy = Config::get('shm-business-rule-contract.settlement.discount_policy');
        $this->assertTrue($policy['data_driven']);
        $this->assertFalse($policy['seeded_default_percentages']);
        $this->assertTrue($policy['snapshot_per_settlement_immutable']);
        $this->assertTrue($policy['overlap_active_forbidden']);
        $this->assertStringContainsString('dilarang diam-diam memakai 0%', $policy['no_matching_policy']);

        // Domain T79 tetap PENDING dengan struktur lamanya utuh.
        $domain = Config::get('shm-business-rule-contract.decision_domains.settlement.discount_or_rebate');
        $this->assertSame('PENDING BUSINESS CONFIRMATION', $domain['status']);
        $this->assertTrue($domain['cannot_guess']);
        $this->assertArrayNotHasKey('value', $domain);
    }

    // ------------------------------------------------------------------
    // 7. Penalty = custom fillable, input manual
    // ------------------------------------------------------------------

    public function test_penalty_is_custom_fillable_with_manual_input(): void
    {
        $penalty = $this->settlement()['penalty'];

        $this->assertSame('custom_fillable', $penalty['mode']);
        $this->assertSame('manual', $penalty['input']);
        $this->assertFalse($penalty['auto_calculated']);
    }

    // ------------------------------------------------------------------
    // 8. Tidak ada formula penalty yang di-hard-code
    // ------------------------------------------------------------------

    public function test_no_penalty_formula_is_hard_coded(): void
    {
        $penalty = $this->settlement()['penalty'];

        foreach (['formula', 'grace_period', 'max_penalty'] as $pendingKey) {
            $this->assertSame(
                'PENDING BUSINESS CONFIRMATION',
                $penalty[$pendingKey],
                "Penalty {$pendingKey} harus tetap PENDING (T89A tidak mengarang formula).",
            );
        }

        foreach (['percent', 'percentage', 'per_day', 'daily', 'rate', 'value', 'amount'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $penalty);
        }

        // Guard T79 tetap utuh: penalty domain & baseline tidak berubah.
        $this->assertSame(
            'PENDING BUSINESS CONFIRMATION',
            Config::get('shm-business-rule-contract.decision_domains.penalty.status'),
        );
        $this->assertFalse(
            Config::get('shm-business-rule-contract.t79_baseline.penalty.calculation_implemented'),
        );
    }

    // ------------------------------------------------------------------
    // 9. Overpayment settlement → Simpanan Pokok koperasi (existing)
    // ------------------------------------------------------------------

    public function test_settlement_overpayment_destination_is_koperasi_principal_savings(): void
    {
        $overpayment = $this->settlement()['overpayment'];

        $this->assertSame('koperasi_principal_savings', $overpayment['destination']);
        $this->assertFalse($overpayment['silently_discarded']);
        $this->assertStringContainsString('Simpanan Pokok', $overpayment['destination_detail']);

        // T89B: posting overpayment adalah BLOCKER BUSINESS DATA (hubungan
        // member tidak tersedia untuk nasabah SHM) — ditolak eksplisit,
        // tanpa membuat member / SHM Savings.
        $this->assertStringContainsString('BLOCKER BUSINESS DATA', $overpayment['implementation_dependency']);
        $this->assertStringContainsString('TIDAK membuat member', $overpayment['implementation_dependency']);

        // Struktur Simpanan Pokok yang dipakai MEMANG sudah ada pada koperasi
        // (SavingProduct category `principal`) — bukan entitas baru.
        $this->assertSame('principal', SavingProduct::CATEGORY_PRINCIPAL);
        $this->assertArrayHasKey(
            SavingProduct::CATEGORY_PRINCIPAL,
            SavingProduct::CATEGORIES,
        );
        $this->assertSame('Simpanan Pokok', SavingProduct::CATEGORIES[SavingProduct::CATEGORY_PRINCIPAL]);

        // Akuntansi overpayment umum TETAP PENDING (T89A tidak menggantinya).
        $this->assertSame(
            'PENDING BUSINESS CONFIRMATION',
            Config::get('shm-business-rule-contract.decision_domains.overpayment.accounting_treatment.status'),
        );
        $this->assertFalse(
            Config::get('shm-business-rule-contract.decision_domains.overpayment.silently_discarded'),
        );
    }

    // ------------------------------------------------------------------
    // 10. Approval required; authority TIDAK di-hard-code
    // ------------------------------------------------------------------

    public function test_approval_is_required_but_authority_stays_pending(): void
    {
        $approval = $this->settlement()['approval'];

        $this->assertTrue($approval['required']);
        $this->assertSame('PENDING BUSINESS CONFIRMATION', $approval['authority']);
        $this->assertSame('prepare workflow only', $approval['implementation']);
        $this->assertSame(['pending', 'approved', 'rejected'], $approval['status_lifecycle']);

        // Permission technical capability tetap dipertahankan, TANPA role
        // final yang dikunci: marketing sengaja tidak memilikinya.
        $shmActions = (array) Config::get('permissions.modules.shm.actions');
        $this->assertArrayHasKey('settlement.create', $shmActions);

        $marketingPermissions = (array) Config::get('permissions.default_roles.marketing.permissions');
        $this->assertNotContains('shm.settlement.create', $marketingPermissions);

        $admin = (array) Config::get('permissions.default_roles.admin');
        $this->assertTrue($admin['all_permissions']);
    }

    // ------------------------------------------------------------------
    // 11. Collection = penagihan loan ACTIVE yang overdue (bukan closed)
    // ------------------------------------------------------------------

    public function test_collection_targets_active_overdue_loans_not_closed_loans(): void
    {
        $collection = $this->settlement()['collection'];

        $this->assertSame('active', $collection['trigger']['loan_status']);
        $this->assertSame('overdue', $collection['trigger']['repayment_state']);
        $this->assertSame('collection_of_outstanding_obligation', $collection['purpose']);

        // Kontrak collection T79 tetap utuh — T89A hanya menambah definisi
        // trigger/purpose sebagai dependency T90.
        $t79Collection = Config::get('shm-business-rule-contract.collection');
        $this->assertStringContainsString('CONFIRMED BASELINE', $t79Collection['status']);
        $this->assertStringContainsString('PENDING BUSINESS CONFIRMATION', $t79Collection['status']);
        $this->assertContains('escalation rule', $t79Collection['blocked_by']);
    }

    // ------------------------------------------------------------------
    // 12. Rule T79/T84 yang confirmed TIDAK berubah (anti-regressi kontrak)
    // ------------------------------------------------------------------

    public function test_confirmed_t79_t84_rules_are_preserved(): void
    {
        $settlement = $this->settlement();

        // Status komposisi amount tetap memuat CONFIRMED BASELINE + PENDING.
        $this->assertStringContainsString('CONFIRMED BASELINE', $settlement['status']);
        $this->assertStringContainsString('PENDING BUSINESS CONFIRMATION', $settlement['status']);

        // Komposisi amount, future interest, dan gerbang blocked_by (runtime
        // ShmLoanSettlementService) tidak berubah pada ticket kontrak ini.
        $this->assertSame(
            'outstanding principal + applicable obligations yang sudah jatuh tempo/terakru menurut jadwal',
            $settlement['amount'],
        );
        $this->assertStringContainsString('TIDAK otomatis', $settlement['future_interest']);
        $this->assertSame([
            'discount/potongan rule',
            'settlement tolerance',
            'approval requirement',
            'payment confirmation rule',
            'status transition rule',
            'implementation (ticket Settlement/Pelunasan)',
        ], $settlement['blocked_by']);

        // Tolerance & future flat interest tetap pada posisi lamanya.
        $tolerance = Config::get('shm-business-rule-contract.decision_domains.settlement.tolerance');
        $this->assertSame('PENDING BUSINESS CONFIRMATION', $tolerance['status']);
        $this->assertStringContainsString(
            'TIDAK mengadopsi settlement_tolerance 0.01',
            $tolerance['note'],
        );

        $this->assertTrue(
            Config::get('shm-business-rule-contract.decision_domains.settlement.future_flat_interest_not_auto_charged.value'),
        );
        $this->assertTrue(
            Config::get('shm-business-rule-contract.t79_baseline.settlement.future_flat_interest_not_auto_charged'),
        );
    }

    // ------------------------------------------------------------------
    // 13. Payment / allocation contract TIDAK berubah
    // ------------------------------------------------------------------

    public function test_payment_and_allocation_contract_is_unchanged(): void
    {
        // Urutan alokasi penalty → interest → principal tetap kontrak.
        $this->assertSame(
            ['penalty', 'interest', 'principal'],
            Config::get('shm-business-rule-contract.payment.allocation_order'),
        );

        // Partial payment tetap diizinkan; overpayment tidak pernah dibuang.
        $this->assertStringContainsString(
            'allowed',
            Config::get('shm-business-rule-contract.payment.partial_payment'),
        );
        $this->assertFalse(
            Config::get('shm-business-rule-contract.overpayment.silently_discarded'),
        );

        // Channel/mandatory field payment tetap PENDING (T89A tidak mengisi).
        $this->assertSame(
            'PENDING BUSINESS CONFIRMATION',
            Config::get('shm-business-rule-contract.decision_domains.payment.payment_channel.status'),
        );
        $this->assertSame(
            'PENDING BUSINESS CONFIRMATION',
            Config::get('shm-business-rule-contract.settlement.payment.channel'),
        );
        $this->assertSame(
            'PENDING BUSINESS CONFIRMATION',
            Config::get('shm-business-rule-contract.settlement.payment.mandatory_fields'),
        );
    }
}
