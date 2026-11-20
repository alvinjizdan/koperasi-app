<?php

namespace Tests\Feature\Shm;

use App\Models\Role;
use App\Services\Shm\ShmAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShmSuperadminPreflightTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Case 1: Canonical Superadmin ID 1 valid dan aktif.
     */
    public function test_preflight_passes_when_superadmin_id_1_canonical_and_active(): void
    {
        Role::query()->where('id', 1)->update([
            'name' => 'superadmin',
            'display_name' => 'Superadmin',
            'entity' => 'shared',
            'is_system' => true,
            'is_active' => true,
        ]);
        Role::query()->where('name', 'superadmin')->where('id', '<>', 1)->delete();

        $authService = app(ShmAuthorizationService::class);

        $this->assertTrue($authService->validateSuperadminPreflight());
    }

    /**
     * Case 2: ID 1 valid tetapi terdapat duplicate Superadmin role pada ID lain.
     */
    public function test_preflight_fails_when_duplicate_superadmin_role_exists(): void
    {
        Role::query()->where('id', 1)->update([
            'name' => 'superadmin',
            'display_name' => 'Superadmin',
            'entity' => 'shared',
            'is_system' => true,
            'is_active' => true,
        ]);

        Role::query()->create([
            'id' => 999,
            'name' => 'superadmin',
            'display_name' => 'Duplicate Superadmin',
            'entity' => 'makro',
            'is_system' => true,
            'is_active' => true,
        ]);

        $authService = app(ShmAuthorizationService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("PREFLIGHT_ABORT: Terdeteksi 1 role duplikat bernama 'superadmin' di luar ID 1.");

        $authService->validateSuperadminPreflight();
    }

    /**
     * Case 3: Canonical Superadmin tidak aktif.
     */
    public function test_preflight_fails_when_superadmin_id_1_is_inactive(): void
    {
        Role::query()->where('id', 1)->update([
            'name' => 'superadmin',
            'display_name' => 'Superadmin',
            'entity' => 'shared',
            'is_system' => true,
            'is_active' => false,
        ]);

        $authService = app(ShmAuthorizationService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("PREFLIGHT_ABORT: Role ID 1 ('superadmin') tidak aktif (is_active=false). Canonical Superadmin wajib aktif.");

        $authService->validateSuperadminPreflight();
    }

    /**
     * Case 4: ID 1 ditempati role lain.
     */
    public function test_preflight_fails_when_role_id_1_occupied_by_another_role(): void
    {
        Role::query()->where('id', 1)->update([
            'name' => 'branch_manager',
            'display_name' => 'Branch Manager',
            'entity' => 'mikro',
            'is_system' => true,
            'is_active' => true,
        ]);

        $authService = app(ShmAuthorizationService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("PREFLIGHT_ABORT: Role ID 1 ('branch_manager') tidak memenuhi canonical identity Superadmin (name=superadmin).");

        $authService->validateSuperadminPreflight();
    }

    /**
     * Case 5: ID 1 memiliki entity yang salah.
     */
    public function test_preflight_fails_when_role_id_1_has_wrong_entity(): void
    {
        Role::query()->where('id', 1)->update([
            'name' => 'superadmin',
            'display_name' => 'Superadmin',
            'entity' => 'makro',
            'is_system' => true,
            'is_active' => true,
        ]);

        $authService = app(ShmAuthorizationService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("PREFLIGHT_ABORT: Role ID 1 ('superadmin') memiliki entity 'makro' (seharusnya 'shared').");

        $authService->validateSuperadminPreflight();
    }

    /**
     * Case 6: ID 1 memiliki is_system yang salah (false).
     */
    public function test_preflight_fails_when_role_id_1_is_not_system_role(): void
    {
        Role::query()->where('id', 1)->update([
            'name' => 'superadmin',
            'display_name' => 'Superadmin',
            'entity' => 'shared',
            'is_system' => false,
            'is_active' => true,
        ]);

        $authService = app(ShmAuthorizationService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("PREFLIGHT_ABORT: Role ID 1 ('superadmin') bukan role sistem (is_system=false).");

        $authService->validateSuperadminPreflight();
    }

    /**
     * Case 7: Superadmin berada pada ID lain dan ID 1 kosong.
     */
    public function test_preflight_fails_when_superadmin_on_other_id_and_id_1_missing(): void
    {
        Role::query()->where('id', 1)->delete();
        Role::query()->where('name', 'superadmin')->delete();

        Role::query()->create([
            'id' => 5,
            'name' => 'superadmin',
            'display_name' => 'Superadmin at ID 5',
            'entity' => 'shared',
            'is_system' => true,
            'is_active' => true,
        ]);

        $authService = app(ShmAuthorizationService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('PREFLIGHT_ABORT: Role ID 1 tidak ditemukan pada database.');

        $authService->validateSuperadminPreflight();
    }

    /**
     * Case 8: Database clean install (tabel roles kosong).
     */
    public function test_preflight_fails_when_database_is_clean_install(): void
    {
        Role::query()->truncate();

        $authService = app(ShmAuthorizationService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('PREFLIGHT_ABORT: Role ID 1 tidak ditemukan pada database.');

        $authService->validateSuperadminPreflight();
    }
}
