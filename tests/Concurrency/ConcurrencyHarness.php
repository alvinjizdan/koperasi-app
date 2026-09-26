<?php

namespace Tests\Concurrency;

use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * Harness untuk menjalankan worker pengujian konkurensi sebagai proses PHP
 * paralel (proc_open via Illuminate Process pool) terhadap PostgreSQL.
 */
class ConcurrencyHarness
{
    private const RESULT_MARKER = '###RESULT###';

    private string $database;

    public function __construct($test)
    {
        // Nama database pengujian khusus konkurensi agar tidak menabrak
        // database pengujian utama berbasis sqlite :memory:.
        $this->database = 'ksp_bima_sakti_test_concurrency_'.Str::lower(Str::random(6));
    }

    /**
     * Siapkan database PostgreSQL pengujian khusus konkurensi: database baru
     * per test (isolasi penuh), lalu migrasi skema. Seeder dan data test
     * dijalankan oleh test class pada koneksi ini sehingga worker paralel
     * dapat membacanya (data harus sudah commit, bukan di dalam transaksi).
     */
    public function setUpDatabase(): void
    {
        $base = config('database.connections.pgsql');

        $adminDsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $base['host'], $base['port'] ?? 5432);
        $adminPdo = new \PDO($adminDsn, $base['username'], $base['password']);

        $exists = $adminPdo->query(
            "SELECT 1 FROM pg_database WHERE datname = '{$this->database}'"
        )->fetch();

        if ($exists === false) {
            $adminPdo->exec('CREATE DATABASE "'.$this->database.'"');
        }

        config(['database.default' => 'pgsql']);
        config(['database.connections.pgsql.database' => $this->database]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');

        Artisan::call('migrate', ['--force' => true]);
    }

    /**
     * Buang database pengujian konkurensi setelah test selesai.
     */
    public function tearDownDatabase(): void
    {
        try {
            DB::connection('pgsql')->disconnect();

            $base = config('database.connections.pgsql');
            $adminDsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $base['host'], $base['port'] ?? 5432);
            $adminPdo = new \PDO($adminDsn, $base['username'], $base['password']);
            $adminPdo->exec('DROP DATABASE IF EXISTS "'.$this->database.'" WITH (FORCE)');
        } catch (\Throwable) {
            // Cleanup best-effort: jangan mengganggu hasil test.
        }
    }

    /**
     * Jalankan sejumlah task worker secara paralel (proses PHP terpisah).
     *
     * @param  array<int, array{task: string, params: array<string, mixed>}>  $tasks
     */
    public function runParallel(array $tasks): WorkerResults
    {
        $commands = [];

        foreach ($tasks as $task) {
            $payload = base64_encode((string) json_encode([
                'task' => $task['task'],
                'params' => $task['params'],
                'database' => $this->database,
            ]));

            $commands[] = [
                'php' => PHP_BINARY,
                'script' => __DIR__.'/worker.php',
                'payload' => $payload,
            ];
        }

        $results = [];

        $pool = Process::pool(function (Pool $pool) use ($commands) {
            foreach ($commands as $command) {
                $pool->path(base_path())->command(
                    '"'.$command['php'].'" "'.$command['script'].'" "'.$command['payload'].'"'
                )->timeout(120);
            }
        })->start()->wait();

        foreach ($pool as $output) {
            $results[] = $this->parseResult((string) $output->output());
        }

        return new WorkerResults($results);
    }

    /**
     * Terjemahkan output worker menjadi hasil terstruktur.
     */
    private function parseResult(string $output): WorkerResult
    {
        $markerPos = strpos($output, self::RESULT_MARKER);

        if ($markerPos === false) {
            return new WorkerResult(false, 'error', [], mb_substr($output, 0, 400));
        }

        $decoded = json_decode(substr($output, $markerPos + strlen(self::RESULT_MARKER)), true);

        if (! is_array($decoded)) {
            return new WorkerResult(false, 'error', [], 'Output worker tidak dapat di-decode.');
        }

        return new WorkerResult(
            (bool) ($decoded['ok'] ?? false),
            (string) ($decoded['kind'] ?? 'unknown'),
            $decoded['errors'] ?? [],
            (string) ($decoded['message'] ?? ''),
        );
    }
}

/**
 * Hasil satu worker.
 */
class WorkerResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $kind,
        public readonly array $errors,
        public readonly string $message,
    ) {}
}

/**
 * Kumpulan hasil worker dengan assertion helper.
 */
class WorkerResults
{
    /**
     * @param  array<int, WorkerResult>  $results
     */
    public function __construct(private readonly array $results) {}

    public function count(): int
    {
        return count($this->results);
    }

    public function first(): WorkerResult
    {
        return $this->results[0];
    }

    public function whereOk(): self
    {
        return new self(array_values(array_filter($this->results, fn (WorkerResult $r) => $r->ok)));
    }

    public function assertAllOk(): void
    {
        foreach ($this->results as $index => $result) {
            Assert::assertTrue(
                $result->ok,
                "Worker #{$index} gagal [{$result->kind}]: {$result->message} ".json_encode($result->errors),
            );
        }
    }

    public function assertNoPartialEffects(): void
    {
        foreach ($this->results as $index => $result) {
            Assert::assertTrue(
                $result->ok || $result->kind === 'validation',
                "Worker #{$index} gagal non-validasi [{$result->kind}]: {$result->message}",
            );
        }
    }
}
