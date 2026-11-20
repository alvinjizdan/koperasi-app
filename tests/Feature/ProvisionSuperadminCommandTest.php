<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\TestCase;

class ProvisionSuperadminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_environment_is_rejected_fails_closed(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('superadmin:provision')
            ->expectsOutputToContain('Akses ditolak: Perintah provisioning superadmin tidak diizinkan pada environment "production".')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'superadmin@ksp.local']);
    }

    public function test_staging_environment_is_rejected_fails_closed(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');

        $this->artisan('superadmin:provision')
            ->expectsOutputToContain('Akses ditolak: Perintah provisioning superadmin tidak diizinkan pada environment "staging".')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'superadmin@ksp.local']);
    }

    public function test_unknown_or_unsupported_environments_are_rejected_fails_closed(): void
    {
        $unsupportedEnvironments = ['development', 'qa', 'sandbox', 'uat', 'custom_env'];

        foreach ($unsupportedEnvironments as $env) {
            $this->app->detectEnvironment(fn () => $env);

            $this->artisan('superadmin:provision')
                ->expectsOutputToContain(sprintf('Akses ditolak: Perintah provisioning superadmin tidak diizinkan pada environment "%s".', $env))
                ->assertFailed();

            $this->assertDatabaseMissing('users', ['email' => 'superadmin@ksp.local']);
        }
    }

    public function test_rejected_environments_execute_zero_database_queries(): void
    {
        $rejectedEnvs = ['production', 'staging', 'qa'];

        foreach ($rejectedEnvs as $env) {
            $this->app->detectEnvironment(fn () => $env);

            $queries = [];
            DB::listen(function ($query) use (&$queries) {
                $queries[] = $query->sql;
            });

            $this->artisan('superadmin:provision')
                ->assertFailed();

            $this->assertEmpty($queries, "Environment '{$env}' must abort before executing any database queries.");
        }
    }

    public function test_local_and_testing_environments_are_permitted(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        Role::query()->firstOrCreate(
            ['name' => Role::SYSTEM_SUPERADMIN],
            [
                'display_name' => 'Superadmin',
                'entity' => 'shared',
                'is_system' => true,
                'is_active' => true,
            ]
        );

        $this->artisan('superadmin:provision')
            ->expectsQuestion('Masukkan password untuk Superadministrator:', 'StrongPassLocal123!')
            ->expectsOutputToContain('Berhasil mem-provisioning akun superadmin@ksp.local')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'superadmin@ksp.local']);
    }

    public function test_missing_superadmin_role_fails_safely(): void
    {
        // Explicitly remove canonical superadmin role to test missing role guard
        Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->delete();

        $this->assertDatabaseMissing('roles', ['name' => Role::SYSTEM_SUPERADMIN]);

        $this->artisan('superadmin:provision')
            ->expectsOutputToContain('Role canonical "superadmin" tidak ditemukan pada database.')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'superadmin@ksp.local']);
    }

    public function test_existing_target_email_is_rejected_and_user_remains_unmodified(): void
    {
        $existing = User::factory()->create([
            'email' => 'superadmin@ksp.local',
            'name' => 'Existing Normal User',
            'password' => Hash::make('original-password'),
        ]);

        $memberRole = Role::create([
            'name' => 'marketing_makro',
            'display_name' => 'Marketing',
            'entity' => 'makro',
            'is_system' => true,
            'is_active' => true,
        ]);
        $existing->roles()->attach($memberRole);

        Role::query()->firstOrCreate(
            ['name' => Role::SYSTEM_SUPERADMIN],
            [
                'display_name' => 'Superadmin',
                'entity' => 'shared',
                'is_system' => true,
                'is_active' => true,
            ]
        );

        $this->artisan('superadmin:provision')
            ->expectsOutputToContain("User dengan email superadmin@ksp.local sudah ada (ID: {$existing->id}). Provisioning dibatalkan.")
            ->assertFailed();

        $existing->refresh();
        $this->assertSame('Existing Normal User', $existing->name);
        $this->assertTrue(Hash::check('original-password', $existing->password));
        $this->assertFalse($existing->isSuperadmin());
        $this->assertCount(1, $existing->roles);
        $this->assertSame('marketing_makro', $existing->roles->first()->name);
    }

    public function test_database_unique_constraint_on_user_email_prevents_duplicate(): void
    {
        $this->assertTrue(
            Schema::hasColumn('users', 'email'),
            'Table users must have email column.'
        );

        User::factory()->create(['email' => 'superadmin@ksp.local']);

        $this->expectException(QueryException::class);
        DB::table('users')->insert([
            'name' => 'Duplicate User',
            'email' => 'superadmin@ksp.local',
            'password' => 'some-hash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_sqlite_unique_constraint_violation_is_handled_safely(): void
    {
        $superadminRole = Role::create([
            'name' => Role::SYSTEM_SUPERADMIN,
            'display_name' => 'Superadmin',
            'entity' => 'makro',
            'is_system' => true,
            'is_active' => true,
        ]);

        Event::listen('eloquent.saving: App\Models\User', function ($user) {
            if ($user->email === 'superadmin@ksp.local') {
                throw new QueryException(
                    'sqlite',
                    'insert into "users" ("email") values (?)',
                    ['superadmin@ksp.local'],
                    new \Exception('UNIQUE constraint failed: users.email', 23000)
                );
            }
        });

        $this->artisan('superadmin:provision')
            ->expectsQuestion('Masukkan password untuk Superadministrator:', 'PassRace123!')
            ->expectsOutputToContain('Konflik integritas data: Akun dengan email superadmin@ksp.local terdeteksi dibuat bersamaan pada proses lain.')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'superadmin@ksp.local']);
        $superadminRole->refresh();
        $this->assertSame('makro', $superadminRole->entity);
    }

    public function test_postgres_unique_violation_is_recognized(): void
    {
        $superadminRole = Role::create([
            'name' => Role::SYSTEM_SUPERADMIN,
            'display_name' => 'Superadmin',
            'entity' => 'makro',
            'is_system' => true,
            'is_active' => true,
        ]);

        Event::listen('eloquent.saving: App\Models\User', function ($user) {
            if ($user->email === 'superadmin@ksp.local') {
                // PostgreSQL driver sets code to 23505
                $pdoEx = new \PDOException('ERROR: duplicate key value violates unique constraint "users_email_unique"');
                $pdoEx->errorInfo = ['23505', 7, 'ERROR: duplicate key value violates unique constraint'];
                throw new QueryException(
                    'pgsql',
                    'insert into "users" ("email") values (?)',
                    ['superadmin@ksp.local'],
                    $pdoEx
                );
            }
        });

        $this->artisan('superadmin:provision')
            ->expectsQuestion('Masukkan password untuk Superadministrator:', 'PassRace123!')
            ->expectsOutputToContain('Konflik integritas data: Akun dengan email superadmin@ksp.local terdeteksi dibuat bersamaan pada proses lain.')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'superadmin@ksp.local']);
        $superadminRole->refresh();
        $this->assertSame('makro', $superadminRole->entity);
    }

    public function test_mysql_duplicate_entry_error_is_recognized(): void
    {
        $superadminRole = Role::create([
            'name' => Role::SYSTEM_SUPERADMIN,
            'display_name' => 'Superadmin',
            'entity' => 'makro',
            'is_system' => true,
            'is_active' => true,
        ]);

        Event::listen('eloquent.saving: App\Models\User', function ($user) {
            if ($user->email === 'superadmin@ksp.local') {
                // MySQL driver errorInfo[1] = 1062
                $pdoEx = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'superadmin@ksp.local' for key 'users_email_unique'", 23000);
                $pdoEx->errorInfo = ['23000', 1062, "Duplicate entry 'superadmin@ksp.local' for key 'users_email_unique'"];
                throw new QueryException(
                    'mysql',
                    'insert into `users` (`email`) values (?)',
                    ['superadmin@ksp.local'],
                    $pdoEx
                );
            }
        });

        $this->artisan('superadmin:provision')
            ->expectsQuestion('Masukkan password untuk Superadministrator:', 'PassRace123!')
            ->expectsOutputToContain('Konflik integritas data: Akun dengan email superadmin@ksp.local terdeteksi dibuat bersamaan pada proses lain.')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'superadmin@ksp.local']);
        $superadminRole->refresh();
        $this->assertSame('makro', $superadminRole->entity);
    }

    public function test_generic_sqlstate_23000_or_foreign_key_violation_is_not_classified_as_duplicate(): void
    {
        Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->delete();
        $superadminRole = Role::create([
            'name' => Role::SYSTEM_SUPERADMIN,
            'display_name' => 'Superadmin',
            'entity' => 'makro',
            'is_system' => true,
            'is_active' => true,
        ]);

        Event::listen('eloquent.saving: App\Models\User', function ($user) {
            if ($user->email === 'superadmin@ksp.local') {
                // Foreign key constraint violation (SQLSTATE 23000, error code 1452 in MySQL or foreign key failure in SQLite)
                $pdoEx = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails', 23000);
                $pdoEx->errorInfo = ['23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails'];
                throw new QueryException(
                    'mysql',
                    'insert into `users` (`email`) values (?)',
                    ['superadmin@ksp.local'],
                    $pdoEx
                );
            }
        });

        $this->artisan('superadmin:provision')
            ->expectsQuestion('Masukkan password untuk Superadministrator:', 'PassRace123!')
            ->expectsOutputToContain('Gagal melakukan provisioning database: Terjadi kesalahan integritas query.')
            ->doesntExpectOutputToContain('Konflik integritas data: Akun dengan email superadmin@ksp.local terdeteksi dibuat bersamaan')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'superadmin@ksp.local']);
        $superadminRole->refresh();
        $this->assertSame('makro', $superadminRole->entity);
    }

    public function test_unexpected_database_exception_produces_safe_error_without_leaking_sql(): void
    {
        Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->delete();
        $superadminRole = Role::create([
            'name' => Role::SYSTEM_SUPERADMIN,
            'display_name' => 'Superadmin',
            'entity' => 'makro',
            'is_system' => true,
            'is_active' => true,
        ]);

        Event::listen('eloquent.saving: App\Models\User', function ($user) {
            if ($user->email === 'superadmin@ksp.local') {
                throw new QueryException(
                    'pgsql',
                    'select sensitive_credentials from pg_shadow;',
                    [],
                    new \Exception('Connection terminated unexpectedly', 500)
                );
            }
        });

        $this->artisan('superadmin:provision')
            ->expectsQuestion('Masukkan password untuk Superadministrator:', 'PassRace123!')
            ->expectsOutputToContain('Gagal melakukan provisioning database: Terjadi kesalahan integritas query.')
            ->doesntExpectOutputToContain('pg_shadow')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'superadmin@ksp.local']);
    }

    public function test_empty_password_fails_closed(): void
    {
        Role::query()->firstOrCreate(
            ['name' => Role::SYSTEM_SUPERADMIN],
            [
                'display_name' => 'Superadmin',
                'entity' => 'shared',
                'is_system' => true,
                'is_active' => true,
            ]
        );

        $this->artisan('superadmin:provision')
            ->expectsQuestion('Masukkan password untuk Superadministrator:', '')
            ->expectsOutputToContain('Password tidak boleh kosong.')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'superadmin@ksp.local']);
    }

    public function test_superadmin_role_resolved_by_canonical_name_regardless_of_id(): void
    {
        // First delete any seeded superadmin to ensure custom creation has entity 'makro'
        Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->delete();

        for ($i = 1; $i <= 5; $i++) {
            Role::create([
                'name' => "role_{$i}",
                'display_name' => "Role {$i}",
                'entity' => 'shared',
                'is_system' => false,
                'is_active' => true,
            ]);
        }

        $superadminRole = Role::create([
            'name' => Role::SYSTEM_SUPERADMIN,
            'display_name' => 'Superadmin Canonical',
            'entity' => 'makro',
            'is_system' => true,
            'is_active' => true,
        ]);

        $this->assertNotEquals(14, $superadminRole->id);

        $this->artisan('superadmin:provision')
            ->expectsQuestion('Masukkan password untuk Superadministrator:', 'SecretStrongPass123!')
            ->expectsOutputToContain('Berhasil mem-provisioning akun superadmin@ksp.local')
            ->assertSuccessful();

        $superadminUser = User::where('email', 'superadmin@ksp.local')->first();
        $this->assertNotNull($superadminUser);
        $this->assertSame('Superadministrator', $superadminUser->name);
        $this->assertTrue($superadminUser->is_active);
        $this->assertTrue(Hash::check('SecretStrongPass123!', $superadminUser->password));
        $this->assertNotEquals('SecretStrongPass123!', $superadminUser->password);

        $superadminRole->refresh();
        $this->assertSame('shared', $superadminRole->entity);

        $this->assertCount(1, $superadminUser->roles);
        $this->assertSame(Role::SYSTEM_SUPERADMIN, $superadminUser->roles->first()->name);
        $this->assertSame($superadminRole->id, $superadminUser->roles->first()->id);
        $this->assertTrue($superadminUser->hasRole(Role::SYSTEM_SUPERADMIN));
    }

    public function test_cli_option_password_is_rejected_as_unsupported(): void
    {
        $this->expectException(InvalidOptionException::class);

        $this->artisan('superadmin:provision', ['--password' => 'insecure-cli-pass']);
    }

    public function test_transaction_rollback_when_user_save_fails(): void
    {
        Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->delete();
        $superadminRole = Role::create([
            'name' => Role::SYSTEM_SUPERADMIN,
            'display_name' => 'Superadmin',
            'entity' => 'makro',
            'is_system' => true,
            'is_active' => true,
        ]);

        Event::listen('eloquent.saving: App\Models\User', function () {
            throw new \RuntimeException('Simulated persistence failure during user save.');
        });

        $this->artisan('superadmin:provision')
            ->expectsQuestion('Masukkan password untuk Superadministrator:', 'Pass12345!')
            ->expectsOutputToContain('Gagal melakukan provisioning: Simulated persistence failure during user save.')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'superadmin@ksp.local']);
        $superadminRole->refresh();
        $this->assertSame('makro', $superadminRole->entity, 'Role entity change must be rolled back on user creation failure.');
    }

    public function test_transaction_rollback_when_role_attachment_fails(): void
    {
        Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->delete();
        $superadminRole = Role::create([
            'name' => Role::SYSTEM_SUPERADMIN,
            'display_name' => 'Superadmin',
            'entity' => 'makro',
            'is_system' => true,
            'is_active' => true,
        ]);

        Event::listen('eloquent.saved: App\Models\User', function () {
            throw new \RuntimeException('Simulated failure during role pivot attachment.');
        });

        $this->artisan('superadmin:provision')
            ->expectsQuestion('Masukkan password untuk Superadministrator:', 'Pass12345!')
            ->expectsOutputToContain('Gagal melakukan provisioning: Simulated failure during role pivot attachment.')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'superadmin@ksp.local']);
        $superadminRole->refresh();
        $this->assertSame('makro', $superadminRole->entity, 'Role entity change must be rolled back on attachment failure.');
    }
}
