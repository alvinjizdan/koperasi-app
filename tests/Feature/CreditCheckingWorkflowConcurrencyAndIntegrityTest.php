<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCreditCheck;
use App\Models\ShmCreditCheckException;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\ShmMemorandumSubmission;
use App\Models\User;
use App\Services\Shm\ShmCreditCheckExceptionService;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\MakroSystemRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Tests\Concurrency\ConcurrencyHarness;
use Tests\TestCase;

class CreditCheckingWorkflowConcurrencyAndIntegrityTest extends TestCase
{
    private ?ConcurrencyHarness $harness = null;

    private bool $postgresReady = false;

    private User $direkturUtama;

    private User $marketing;

    private User $creditAnalyst;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->postgresAvailable()) {
            $this->markTestSkipped('PostgreSQL server tidak tersedia untuk verifikasi konkurensi.');
        }

        $this->harness = new ConcurrencyHarness($this);
        $this->harness->setUpDatabase();
        $this->postgresReady = true;

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(MakroPhysicalRoleSeeder::class);
        $this->seed(MakroSystemRoleSeeder::class);

        $dirutRole = Role::where('name', 'direktur_utama_makro')->first() ?? Role::where('name', 'direktur_utama')->firstOrFail();
        $marketingRole = Role::where('name', 'marketing_makro')->first() ?? Role::where('name', 'marketing')->firstOrFail();
        $caRole = Role::where('name', 'admin_makro')->first() ?? Role::where('name', 'admin')->firstOrFail();

        $this->direkturUtama = User::factory()->create();
        $this->direkturUtama->roles()->attach($dirutRole->id);

        $this->marketing = User::factory()->create();
        $this->marketing->roles()->attach($marketingRole->id);

        $this->creditAnalyst = User::factory()->create();
        $this->creditAnalyst->roles()->attach($caRole->id);
    }

    protected function tearDown(): void
    {
        if ($this->postgresReady && $this->harness !== null) {
            $this->harness->tearDownDatabase();
        }

        parent::tearDown();
    }

    private function postgresAvailable(): bool
    {
        try {
            $base = config('database.connections.pgsql');
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $base['host'] ?? '127.0.0.1', $base['port'] ?? 5432);
            new \PDO($dsn, $base['username'] ?? 'postgres', $base['password'] ?? 'postgres', [\PDO::ATTR_TIMEOUT => 2]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function makeApplication(string $type = 'shgb'): ShmApplication
    {
        $customer = ShmCustomer::factory()->create();

        return ShmApplication::create([
            'application_number' => 'APP-TEST-'.uniqid(),
            'shm_customer_id' => $customer->id,
            'marketing_id' => $this->marketing->id,
            'type' => $type,
            'status' => 'credit_checking',
        ]);
    }

    public function test_concurrent_memorandum_submissions_cannot_allocate_duplicate_revisions(): void
    {
        $application = $this->makeApplication();
        $check = ShmCreditCheck::create([
            'shm_application_id' => $application->id,
            'filled_by' => $this->creditAnalyst->id,
            'status' => 'completed',
            'outcome' => 'pass',
            'result' => 'Aman tanpa kendala.',
            'started_at' => now()->subDay(),
            'checked_at' => now(),
        ]);

        $memorandum = ShmMemorandum::create([
            'shm_application_id' => $application->id,
            'created_by' => $this->creditAnalyst->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Catatan telaah konkurensi.',
            'shm_credit_check_id' => $check->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);

        // Luncurkan 2 worker paralel yang mencoba submit memorandum yang sama secara bersamaan
        $results = $this->harness->runParallel([
            [
                'task' => 'shm_memorandum_submit',
                'params' => [
                    'memorandum_id' => $memorandum->id,
                    'user_id' => $this->creditAnalyst->id,
                    'notes' => 'Submit dari Worker 1',
                ],
            ],
            [
                'task' => 'shm_memorandum_submit',
                'params' => [
                    'memorandum_id' => $memorandum->id,
                    'user_id' => $this->creditAnalyst->id,
                    'notes' => 'Submit dari Worker 2',
                ],
            ],
        ]);

        $successful = 0;
        $failed = 0;
        foreach ($results->all() as $res) {
            if ($res->ok) {
                $successful++;
            } else {
                $failed++;
            }
        }

        // Tepat 1 request harus berhasil menjadi status submitted, sementara yang lain gagal karena row lock / status bukan draft
        $this->assertSame(1, $successful, 'Tepat satu submission harus berhasil');
        $this->assertSame(1, $failed, 'Submission kedua yang bersamaan harus ditolak');

        // Pastikan hanya terbentuk tepat 1 revision artifact di database PostgreSQL
        $submissionsCount = ShmMemorandumSubmission::where('shm_memorandum_id', $memorandum->id)->count();
        $this->assertSame(1, $submissionsCount);

        $submission = ShmMemorandumSubmission::where('shm_memorandum_id', $memorandum->id)->first();
        $this->assertSame(1, $submission->revision_number);
    }

    public function test_concurrent_exception_consumption_race_condition(): void
    {
        $application = $this->makeApplication();
        $check = ShmCreditCheck::create([
            'shm_application_id' => $application->id,
            'filled_by' => $this->creditAnalyst->id,
            'status' => 'completed',
            'outcome' => 'fail',
            'result' => 'Riwayat kredit macet.',
            'started_at' => now()->subDay(),
            'checked_at' => now(),
        ]);

        $exceptionService = app(ShmCreditCheckExceptionService::class);
        $exception = $exceptionService->authorizeException(
            $application,
            ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
            $this->direkturUtama,
            'Justifikasi mitigasi risiko kuat atas persetujuan Direktur Utama.',
            'Ringkasan resmi disclosure Direktur Utama.',
            'Hasil credit check FAIL'
        );

        $memo1 = ShmMemorandum::create([
            'shm_application_id' => $application->id,
            'created_by' => $this->creditAnalyst->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Memo 1',
            'shm_credit_check_id' => $check->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);

        $memo2 = ShmMemorandum::create([
            'shm_application_id' => $application->id,
            'created_by' => $this->creditAnalyst->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Memo 2',
            'shm_credit_check_id' => $check->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);

        // Dua worker mencoba mengonsumsi dispensasi yang sama secara paralel
        $results = $this->harness->runParallel([
            [
                'task' => 'shm_exception_consume',
                'params' => [
                    'application_id' => $application->id,
                    'memorandum_id' => $memo1->id,
                    'exception_type' => ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
                ],
            ],
            [
                'task' => 'shm_exception_consume',
                'params' => [
                    'application_id' => $application->id,
                    'memorandum_id' => $memo2->id,
                    'exception_type' => ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
                ],
            ],
        ]);

        $successful = 0;
        $failed = 0;
        foreach ($results->all() as $res) {
            if ($res->ok) {
                $successful++;
            } else {
                $failed++;
            }
        }

        $this->assertSame(1, $successful, 'Tepat satu konsumsi yang boleh berhasil');
        $this->assertSame(1, $failed, 'Konsumsi kedua harus ditolak karena status bukan authorized lagi');

        $exception->refresh();
        $this->assertSame(ShmCreditCheckException::STATUS_CONSUMED, $exception->status);
        $this->assertNotNull($exception->consumed_at);
        $this->assertNotNull($exception->consumed_memorandum_id);
    }

    public function test_concurrent_completion_bypass_consumption_race_condition(): void
    {
        $application = $this->makeApplication();
        $check = ShmCreditCheck::create([
            'shm_application_id' => $application->id,
            'filled_by' => $this->creditAnalyst->id,
            'status' => 'drafted',
            'started_at' => now(),
        ]);

        $exceptionService = app(ShmCreditCheckExceptionService::class);
        $exception = $exceptionService->authorizeException(
            $application,
            ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            $this->direkturUtama,
            'Justifikasi mitigasi risiko kuat atas persetujuan Direktur Utama.',
            'Ringkasan resmi disclosure Direktur Utama.',
            'Credit check belum selesai'
        );

        $memo1 = ShmMemorandum::create([
            'shm_application_id' => $application->id,
            'created_by' => $this->creditAnalyst->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Memo 1',
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);

        $memo2 = ShmMemorandum::create([
            'shm_application_id' => $application->id,
            'created_by' => $this->creditAnalyst->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Memo 2',
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);

        // Dua worker mencoba mengonsumsi dispensasi Completion Bypass yang sama secara paralel
        $results = $this->harness->runParallel([
            [
                'task' => 'shm_exception_consume',
                'params' => [
                    'application_id' => $application->id,
                    'memorandum_id' => $memo1->id,
                    'exception_type' => ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
                ],
            ],
            [
                'task' => 'shm_exception_consume',
                'params' => [
                    'application_id' => $application->id,
                    'memorandum_id' => $memo2->id,
                    'exception_type' => ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
                ],
            ],
        ]);

        $successful = 0;
        $failed = 0;
        foreach ($results->all() as $res) {
            if ($res->ok) {
                $successful++;
            } else {
                $failed++;
            }
        }

        $this->assertSame(1, $successful, 'Tepat satu konsumsi bypass yang boleh berhasil');
        $this->assertSame(1, $failed, 'Konsumsi bypass kedua harus ditolak karena status bukan authorized lagi');

        $exception->refresh();
        $this->assertSame(ShmCreditCheckException::STATUS_CONSUMED, $exception->status);
        $this->assertNotNull($exception->consumed_at);
        $this->assertNotNull($exception->consumed_memorandum_id);
    }
}
