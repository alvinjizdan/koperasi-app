<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class ProvisionSuperadminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'superadmin:provision';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provision akun Superadministrator dan selaraskan Role superadmin ke entity shared (hanya untuk environment local & testing)';

    /**
     * Explicit environment allowlist for provisioning execution.
     *
     * @var array<int, string>
     */
    protected const PERMITTED_ENVIRONMENTS = [
        'local',
        'testing',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Memulai pra-pemeriksaan provisioning Superadmin...');

        // 1. Explicit Environment Allowlist Guard: Must execute before any user or role queries
        $currentEnvironment = app()->environment();
        if (! in_array($currentEnvironment, self::PERMITTED_ENVIRONMENTS, true)) {
            $this->error(sprintf(
                'Akses ditolak: Perintah provisioning superadmin tidak diizinkan pada environment "%s". Hanya diizinkan pada: %s.',
                $currentEnvironment,
                implode(', ', self::PERMITTED_ENVIRONMENTS)
            ));

            return self::FAILURE;
        }

        $email = 'superadmin@ksp.local';
        $existingUser = User::query()->where('email', $email)->first();

        // 2. Existing User Guard: Fail closed to prevent accidental privilege escalation
        if ($existingUser !== null) {
            $this->error("User dengan email {$email} sudah ada (ID: {$existingUser->id}). Provisioning dibatalkan.");

            return self::FAILURE;
        }

        // 3. Role Lookup: Canonical constant lookup instead of hardcoded ID
        $superadminRole = Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->first();
        if ($superadminRole === null) {
            $this->error(sprintf('Role canonical "%s" tidak ditemukan pada database.', Role::SYSTEM_SUPERADMIN));

            return self::FAILURE;
        }

        // 4. Secure Password Handling: Interactive hidden prompt only
        $password = $this->secret('Masukkan password untuk Superadministrator:');

        if (empty($password)) {
            $this->error('Password tidak boleh kosong.');

            return self::FAILURE;
        }

        DB::beginTransaction();

        try {
            // 1. Update Role superadmin entity to shared if not already set
            if ($superadminRole->entity !== 'shared') {
                $superadminRole->entity = 'shared';
                $superadminRole->save();
            }

            // 2. Create Superadmin User (new instance only)
            $user = new User;
            $user->name = 'Superadministrator';
            $user->email = $email;
            $user->password = Hash::make($password);
            $user->email_verified_at = now();
            $user->is_active = true;
            $user->save();

            // 3. Attach User to Superadmin Role
            $user->roles()->syncWithoutDetaching([$superadminRole->id]);

            DB::commit();

            $this->info(sprintf(
                'Berhasil mem-provisioning akun %s (User ID: %d) dan mengaitkan ke Role %s (entity: shared).',
                $email,
                $user->id,
                $superadminRole->name
            ));

            return self::SUCCESS;
        } catch (QueryException $e) {
            DB::rollBack();

            // Handle duplicate-key concurrency race condition safely without credential exposure
            if ($this->isUniqueConstraintViolation($e)) {
                $this->error("Konflik integritas data: Akun dengan email {$email} terdeteksi dibuat bersamaan pada proses lain. Provisioning dibatalkan secara aman.");

                return self::FAILURE;
            }

            $this->error('Gagal melakukan provisioning database: Terjadi kesalahan integritas query.');

            return self::FAILURE;
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Gagal melakukan provisioning: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Determine if the given query exception is specifically a unique constraint violation.
     * Prevents misclassifying foreign key or other general integrity violations (SQLSTATE 23000).
     */
    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        $previous = $e->getPrevious();
        $code = (string) $e->getCode();
        $prevCode = $previous !== null ? (string) $previous->getCode() : '';
        $sqlState = (string) ($e->errorInfo[0] ?? $code);

        // 1. PostgreSQL Driver: SQLSTATE 23505 is specifically unique_violation
        if ($sqlState === '23505' || $code === '23505' || $prevCode === '23505') {
            return true;
        }

        // 2. MySQL / MariaDB Driver: Error code 1062 is specifically ER_DUP_ENTRY
        // Often wrapped in PDOException with getCode() 23000 and errorInfo[1] = 1062
        if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) {
            return true;
        }
        if ($previous instanceof \PDOException && isset($previous->errorInfo[1]) && (int) $previous->errorInfo[1] === 1062) {
            return true;
        }

        // 3. SQLite Driver: Check specific SQLite unique constraint violation signatures
        // SQLite extended error code 2067 is SQLITE_CONSTRAINT_UNIQUE; 19 is SQLITE_CONSTRAINT
        $msg = strtolower($e->getMessage());
        if (
            str_contains($msg, 'unique constraint failed')
            || str_contains($msg, 'columns email are not unique')
            || (str_contains($msg, 'duplicate entry') && ! str_contains($msg, 'foreign key'))
        ) {
            return true;
        }

        return false;
    }
}
