<?php

namespace Tests\Feature;

use App\Models\ShmApplication;
use App\Models\ShmCreditCheck;
use App\Models\ShmCreditCheckException;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\ShmMemorandumSubmission;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditCheckingMemorandumModelPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_shm_memorandums_has_cc04_persistence_columns_and_casts(): void
    {
        $user = User::factory()->create();
        $customer = ShmCustomer::factory()->create();
        $application = ShmApplication::create([
            'application_number' => 'APP-TEST-'.uniqid(),
            'shm_customer_id' => $customer->id,
            'marketing_id' => $user->id,
            'type' => 'shgb',
            'status' => 'credit_checking',
        ]);

        $creditCheck = ShmCreditCheck::create([
            'shm_application_id' => $application->id,
            'filled_by' => $user->id,
            'status' => 'completed',
            'outcome' => 'pass',
            'result' => 'Hasil pemeriksaan aman',
            'started_at' => now()->subHour(),
            'checked_at' => now(),
        ]);

        $snapshotPayload = [
            'version' => 1,
            'snapshot_captured_at' => now()->toIso8601String(),
            'source_credit_check_id' => $creditCheck->id,
            'credit_check' => [
                'status' => 'completed',
                'outcome' => 'pass',
                'outcome_label' => 'Lolos (PASS)',
                'result_summary' => 'Hasil pemeriksaan aman',
                'checker_id' => $user->id,
                'checker_name' => $user->name,
            ],
            'exception_authorization' => [
                'is_excepted' => false,
            ],
        ];

        $memorandum = ShmMemorandum::create([
            'shm_application_id' => $application->id,
            'created_by' => $user->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'shm_credit_check_id' => $creditCheck->id,
            'credit_check_snapshot' => $snapshotPayload,
        ]);

        $this->assertNull($memorandum->first_submitted_at);
        $this->assertTrue($memorandum->canRefreshSnapshot());
        $this->assertFalse($memorandum->isSnapshotFrozen());
        $this->assertSame($creditCheck->id, $memorandum->shm_credit_check_id);
        $this->assertSame('pass', $memorandum->credit_check_snapshot['credit_check']['outcome']);

        // Uji relasi creditCheck
        $this->assertInstanceOf(ShmCreditCheck::class, $memorandum->creditCheck);
        $this->assertSame($creditCheck->id, $memorandum->creditCheck->id);

        // Uji freeze anchor
        $memorandum->update(['first_submitted_at' => now()]);
        $this->assertTrue($memorandum->fresh()->isSnapshotFrozen());
        $this->assertFalse($memorandum->fresh()->canRefreshSnapshot());
    }

    public function test_shm_memorandum_submissions_stores_revision_artifact_with_canonical_hash(): void
    {
        $user = User::factory()->create();
        $customer = ShmCustomer::factory()->create();
        $application = ShmApplication::create([
            'application_number' => 'APP-TEST-'.uniqid(),
            'shm_customer_id' => $customer->id,
            'marketing_id' => $user->id,
            'type' => 'shgb',
            'status' => 'memorandum',
        ]);

        $memorandum = ShmMemorandum::create([
            'shm_application_id' => $application->id,
            'created_by' => $user->id,
            'status' => ShmMemorandum::STATUS_SUBMITTED,
            'first_submitted_at' => now(),
        ]);

        $submissionData = [
            'shm_memorandum_id' => $memorandum->id,
            'revision_number' => 1,
            'memorandum_type' => ShmMemorandum::TYPE_SHM_SHGB,
            'submitted_by' => $user->id,
            'submitted_by_name' => $user->name,
            'submitted_at' => now(),
            'narrative_notes' => 'Naskah pengajuan lengkap',
            'risk_mitigation_notes' => 'Mitigasi risiko terverifikasi',
            'borrower_snapshot' => ['name' => $customer->name, 'nik' => $customer->nik],
            'collateral_snapshot' => [['shm_number' => '12345', 'area' => 150]],
            'credit_check_snapshot' => ['outcome' => 'pass', 'summary' => 'Lancar'],
            'template_version' => 'v1.0',
        ];

        $contentHash = ShmMemorandumSubmission::computeContentHash($submissionData);
        $submissionData['content_hash'] = $contentHash;

        $submission = ShmMemorandumSubmission::create($submissionData);

        $this->assertDatabaseHas('shm_memorandum_submissions', [
            'id' => $submission->id,
            'revision_number' => 1,
            'content_hash' => $contentHash,
        ]);

        $this->assertInstanceOf(ShmMemorandum::class, $submission->memorandum);
        $this->assertSame($memorandum->id, $submission->memorandum->id);
        $this->assertCount(1, $memorandum->submissions);

        // Uji unique constraint pada (shm_memorandum_id, revision_number)
        $this->expectException(QueryException::class);
        ShmMemorandumSubmission::create($submissionData);
    }

    /**
     * CC-04A: Uji mutasi menyeluruh terhadap seluruh 15 field penyusun kanonikal hash.
     */
    public function test_mutation_based_hash_coverage_detects_every_field_modification(): void
    {
        $basePayload = [
            'revision_number' => 1,
            'memorandum_type' => 'shm_shgb',
            'submitted_by' => 42,
            'submitted_by_name' => 'Bambang Submitter',
            'submitted_at' => '2026-11-18T10:00:00+07:00',
            'narrative_notes' => 'Catatan analisis awal',
            'risk_mitigation_notes' => 'Mitigasi kapasitas',
            'shgb_start_date' => '2026-01-01',
            'shgb_end_date' => '2046-01-01',
            'borrower_snapshot' => ['name' => 'Nasabah Asli', 'nik' => '3578000000000001'],
            'collateral_snapshot' => [['shm' => '001', 'area' => 200]],
            'buyback_documents' => [['doc' => 'pks', 'hash' => 'abc123hash']],
            'credit_check_snapshot' => ['outcome' => 'pass', 'summary' => 'Lancar'],
            'exception_snapshot' => null,
            'template_version' => 'v1.0',
        ];

        $baseHash = ShmMemorandumSubmission::computeContentHash($basePayload);
        $this->assertSame(64, strlen($baseHash), 'Hash harus tepat 64 karakter heksadesimal SHA-256');

        // 1. Identical payload produces identical hash
        $this->assertSame($baseHash, ShmMemorandumSubmission::computeContentHash($basePayload));

        // 2. Changing revision_number changes hash
        $mutated = $basePayload;
        $mutated['revision_number'] = 2;
        $this->assertNotSame($baseHash, ShmMemorandumSubmission::computeContentHash($mutated));

        // 3. Changing memorandum_type changes hash
        $mutated = $basePayload;
        $mutated['memorandum_type'] = 'buyback';
        $this->assertNotSame($baseHash, ShmMemorandumSubmission::computeContentHash($mutated));

        // 4. Changing submitted_by changes hash
        $mutated = $basePayload;
        $mutated['submitted_by'] = 99;
        $this->assertNotSame($baseHash, ShmMemorandumSubmission::computeContentHash($mutated));

        // 5. Changing submitted_by_name changes hash (CC04-F01 fix)
        $mutated = $basePayload;
        $mutated['submitted_by_name'] = 'Bambang Submitter Mutated';
        $this->assertNotSame($baseHash, ShmMemorandumSubmission::computeContentHash($mutated));

        // 6. Changing submitted_at changes hash
        $mutated = $basePayload;
        $mutated['submitted_at'] = '2026-11-18T10:05:00+07:00';
        $this->assertNotSame($baseHash, ShmMemorandumSubmission::computeContentHash($mutated));

        // 7. Changing narrative_notes changes hash
        $mutated = $basePayload;
        $mutated['narrative_notes'] = 'Catatan analisis awal yang telah diubah';
        $this->assertNotSame($baseHash, ShmMemorandumSubmission::computeContentHash($mutated));

        // 8. Changing risk_mitigation_notes changes hash
        $mutated = $basePayload;
        $mutated['risk_mitigation_notes'] = 'Mitigasi baru';
        $this->assertNotSame($baseHash, ShmMemorandumSubmission::computeContentHash($mutated));

        // 9. Changing shgb_start_date changes hash
        $mutated = $basePayload;
        $mutated['shgb_start_date'] = '2026-02-01';
        $this->assertNotSame($baseHash, ShmMemorandumSubmission::computeContentHash($mutated));

        // 10. Changing shgb_end_date changes hash
        $mutated = $basePayload;
        $mutated['shgb_end_date'] = '2046-02-01';
        $this->assertNotSame($baseHash, ShmMemorandumSubmission::computeContentHash($mutated));

        // 11. Changing borrower_snapshot changes hash
        $mutated = $basePayload;
        $mutated['borrower_snapshot']['name'] = 'Nama Berubah';
        $this->assertNotSame($baseHash, ShmMemorandumSubmission::computeContentHash($mutated));

        // 12. Changing collateral_snapshot changes hash
        $mutated = $basePayload;
        $mutated['collateral_snapshot'][0]['area'] = 250;
        $this->assertNotSame($baseHash, ShmMemorandumSubmission::computeContentHash($mutated));

        // 13. Changing buyback_documents changes hash
        $mutated = $basePayload;
        $mutated['buyback_documents'][0]['hash'] = 'def456hash';
        $this->assertNotSame($baseHash, ShmMemorandumSubmission::computeContentHash($mutated));

        // 14. Changing credit_check_snapshot changes hash
        $mutated = $basePayload;
        $mutated['credit_check_snapshot']['outcome'] = 'review';
        $this->assertNotSame($baseHash, ShmMemorandumSubmission::computeContentHash($mutated));

        // 15. Changing exception_snapshot changes hash
        $mutated = $basePayload;
        $mutated['exception_snapshot'] = ['exception_number' => 'EXP-001'];
        $this->assertNotSame($baseHash, ShmMemorandumSubmission::computeContentHash($mutated));

        // 16. Changing template_version changes hash
        $mutated = $basePayload;
        $mutated['template_version'] = 'v1.1';
        $this->assertNotSame($baseHash, ShmMemorandumSubmission::computeContentHash($mutated));

        // 17. Deterministic JSON key order stability in nested associative arrays
        $reorderedPayload = $basePayload;
        $reorderedPayload['borrower_snapshot'] = [
            'nik' => '3578000000000001', // ditaruh di awal
            'name' => 'Nasabah Asli',
        ];
        $this->assertSame(
            $baseHash,
            ShmMemorandumSubmission::computeContentHash($reorderedPayload),
            'Perubahan urutan key pada nested array tidak boleh mengubah hash (harus deterministik)'
        );

        // 18. List arrays preserve order significance
        $listReorderedPayload = $basePayload;
        $listReorderedPayload['collateral_snapshot'] = [
            ['shm' => '002', 'area' => 300],
            ['shm' => '001', 'area' => 200],
        ];
        $this->assertNotSame(
            $baseHash,
            ShmMemorandumSubmission::computeContentHash($listReorderedPayload),
            'Perubahan urutan elemen list array harus menghasilkan hash berbeda'
        );
    }

    public function test_shm_credit_check_exceptions_persists_durable_authorization(): void
    {
        $user = User::factory()->create();
        $customer = ShmCustomer::factory()->create();
        $application = ShmApplication::create([
            'application_number' => 'APP-TEST-'.uniqid(),
            'shm_customer_id' => $customer->id,
            'marketing_id' => $user->id,
            'type' => 'shgb',
            'status' => 'credit_checking',
        ]);

        $exception = ShmCreditCheckException::create([
            'exception_number' => 'EXP-SHM-202611-0001',
            'shm_application_id' => $application->id,
            'exception_type' => ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
            'status' => ShmCreditCheckException::STATUS_AUTHORIZED,
            'authorized_by' => $user->id,
            'authorized_by_name' => $user->name,
            'authorized_at' => now(),
            'blocking_condition' => 'credit_check_outcome_fail',
            'internal_justification' => 'Nasabah memiliki agunan tambahan likuid deposito 100%.',
            'official_disclosure_summary' => 'Pembiayaan disetujui atas dispensasi khusus Direktur Utama No. EXP-SHM-202611-0001.',
        ]);

        $this->assertTrue($exception->isAvailable());
        $this->assertFalse($exception->isConsumed());
        $this->assertInstanceOf(ShmApplication::class, $exception->application);
        $this->assertSame($application->id, $exception->application->id);
        $this->assertCount(1, $application->creditCheckExceptions);

        $memo = ShmMemorandum::create([
            'shm_application_id' => $application->id,
            'created_by' => $user->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
        ]);

        // Tandai dikonsumsi dengan metadata valid
        $exception->update([
            'status' => ShmCreditCheckException::STATUS_CONSUMED,
            'consumed_memorandum_id' => $memo->id,
            'consumed_at' => now(),
        ]);

        $this->assertFalse($exception->fresh()->isAvailable());
        $this->assertTrue($exception->fresh()->isConsumed());
    }
}
