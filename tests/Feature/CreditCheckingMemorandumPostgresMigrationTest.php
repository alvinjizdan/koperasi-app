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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concurrency\ConcurrencyHarness;
use Tests\TestCase;

class CreditCheckingMemorandumPostgresMigrationTest extends TestCase
{
    private ?ConcurrencyHarness $harness = null;

    private bool $postgresReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->postgresAvailable()) {
            $this->markTestSkipped('PostgreSQL server tidak tersedia untuk verifikasi skema.');
        }

        $this->harness = new ConcurrencyHarness($this);
        $this->harness->setUpDatabase();
        $this->postgresReady = true;
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

    public function test_postgres_schema_has_new_tables_and_columns_with_correct_types(): void
    {
        // 1. Verifikasi kolom baru pada shm_memorandums
        $this->assertTrue(Schema::connection('pgsql')->hasColumn('shm_memorandums', 'first_submitted_at'));
        $this->assertTrue(Schema::connection('pgsql')->hasColumn('shm_memorandums', 'shm_credit_check_id'));
        $this->assertTrue(Schema::connection('pgsql')->hasColumn('shm_memorandums', 'credit_check_snapshot'));

        // 2. Verifikasi tabel shm_memorandum_submissions
        $this->assertTrue(Schema::connection('pgsql')->hasTable('shm_memorandum_submissions'));
        $this->assertTrue(Schema::connection('pgsql')->hasColumn('shm_memorandum_submissions', 'revision_number'));
        $this->assertTrue(Schema::connection('pgsql')->hasColumn('shm_memorandum_submissions', 'content_hash'));
        $this->assertTrue(Schema::connection('pgsql')->hasColumn('shm_memorandum_submissions', 'credit_check_snapshot'));
        $this->assertTrue(Schema::connection('pgsql')->hasColumn('shm_memorandum_submissions', 'borrower_snapshot'));

        // 3. Verifikasi tabel shm_credit_check_exceptions
        $this->assertTrue(Schema::connection('pgsql')->hasTable('shm_credit_check_exceptions'));
        $this->assertTrue(Schema::connection('pgsql')->hasColumn('shm_credit_check_exceptions', 'exception_number'));
        $this->assertTrue(Schema::connection('pgsql')->hasColumn('shm_credit_check_exceptions', 'internal_justification'));
        $this->assertTrue(Schema::connection('pgsql')->hasColumn('shm_credit_check_exceptions', 'official_disclosure_summary'));

        // 4. Verifikasi eksekusi CRUD & Relasi pada engine PostgreSQL
        $user = User::create([
            'name' => 'Tester PG',
            'email' => 'tester-pg-'.uniqid().'@test.com',
            'password' => bcrypt('password'),
        ]);

        $customer = ShmCustomer::create([
            'customer_number' => 'CUST-PG-'.uniqid(),
            'name' => 'Nasabah PG',
            'nik' => '3578000000000001',
            'phone' => '081234567890',
            'status' => 'active',
        ]);

        $app = ShmApplication::create([
            'application_number' => 'APP-PG-'.uniqid(),
            'shm_customer_id' => $customer->id,
            'marketing_id' => $user->id,
            'type' => 'shgb',
            'status' => 'credit_checking',
        ]);

        $cc = ShmCreditCheck::create([
            'shm_application_id' => $app->id,
            'filled_by' => $user->id,
            'status' => 'completed',
            'outcome' => 'pass',
            'result' => 'Lolos pemeriksaan',
            'started_at' => now()->subHour(),
            'checked_at' => now(),
        ]);

        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'created_by' => $user->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'shm_credit_check_id' => $cc->id,
            'credit_check_snapshot' => ['outcome' => 'pass', 'summary' => 'PG Test'],
        ]);

        $this->assertNull($memo->first_submitted_at);
        $this->assertTrue($memo->canRefreshSnapshot());

        // Submission revision
        $sub = ShmMemorandumSubmission::create([
            'shm_memorandum_id' => $memo->id,
            'revision_number' => 1,
            'memorandum_type' => 'shm_shgb',
            'submitted_by' => $user->id,
            'submitted_by_name' => $user->name,
            'submitted_at' => now(),
            'borrower_snapshot' => ['name' => $customer->name],
            'collateral_snapshot' => [['type' => 'shm']],
            'credit_check_snapshot' => ['outcome' => 'pass'],
            'content_hash' => hash('sha256', 'test-hash-payload'),
        ]);

        $this->assertSame(1, $sub->revision_number);
        $this->assertCount(1, $memo->submissions);

        // Exception
        $exp = ShmCreditCheckException::create([
            'exception_number' => 'EXP-PG-001',
            'shm_application_id' => $app->id,
            'exception_type' => ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
            'status' => ShmCreditCheckException::STATUS_AUTHORIZED,
            'authorized_by' => $user->id,
            'authorized_by_name' => $user->name,
            'authorized_at' => now(),
            'blocking_condition' => 'outcome_fail',
            'internal_justification' => 'Alasan rahasia pengurus KSP',
            'official_disclosure_summary' => 'Dispensasi resmi direksi',
        ]);

        $this->assertSame('EXP-PG-001', $exp->exception_number);
        $this->assertTrue($exp->isAvailable());

        // 5. Uji CHECK Constraint pada PostgreSQL (CC-04A Hardening)
        // A. Cek penolakan tipe exception invalid
        $invalidTypeRejected = false;
        try {
            DB::connection('pgsql')->table('shm_credit_check_exceptions')->insert([
                'exception_number' => 'EXP-INVALID-TYPE',
                'shm_application_id' => $app->id,
                'exception_type' => 'invalid_bogus_type', // Harus ditolak
                'status' => 'authorized',
                'authorized_by' => $user->id,
                'authorized_by_name' => $user->name,
                'authorized_at' => now(),
                'blocking_condition' => 'test',
                'internal_justification' => 'test',
                'official_disclosure_summary' => 'test',
            ]);
        } catch (QueryException $e) {
            $invalidTypeRejected = true;
            $this->assertStringContainsString('chk_shm_cc_exp_type', $e->getMessage());
        }
        $this->assertTrue($invalidTypeRejected, 'PostgreSQL CHECK constraint harus menolak tipe exception ilegal');

        // B. Cek penolakan status consumed tanpa consumed_at / consumed_memorandum_id
        $invalidConsumedRejected = false;
        try {
            DB::connection('pgsql')->table('shm_credit_check_exceptions')->insert([
                'exception_number' => 'EXP-INVALID-CONSUMED',
                'shm_application_id' => $app->id,
                'exception_type' => 'fail_override',
                'status' => 'consumed', // Consumed tapi metadata kosong
                'authorized_by' => $user->id,
                'authorized_by_name' => $user->name,
                'authorized_at' => now(),
                'blocking_condition' => 'test',
                'internal_justification' => 'test',
                'official_disclosure_summary' => 'test',
                'consumed_at' => null,
                'consumed_memorandum_id' => null,
            ]);
        } catch (QueryException $e) {
            $invalidConsumedRejected = true;
            $this->assertStringContainsString('chk_shm_cc_exp_consumed_state', $e->getMessage());
        }
        $this->assertTrue($invalidConsumedRejected, 'PostgreSQL CHECK constraint harus menolak status consumed tanpa metadata konsumsi');

        // 6. Uji Rollback migrasi pada database PostgreSQL terisolasi
        Artisan::call('migrate:rollback', ['--step' => 3]);

        $this->assertFalse(Schema::connection('pgsql')->hasTable('shm_credit_check_exceptions'));
        $this->assertFalse(Schema::connection('pgsql')->hasTable('shm_memorandum_submissions'));
        $this->assertFalse(Schema::connection('pgsql')->hasColumn('shm_memorandums', 'credit_check_snapshot'));
        $this->assertFalse(Schema::connection('pgsql')->hasColumn('shm_memorandums', 'first_submitted_at'));
    }
}
