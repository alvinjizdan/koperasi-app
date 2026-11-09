<?php

namespace App\Services\Shm;

use App\Models\ShmAgent;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Service pengelola domain Master Agen SHM / BSM Makro.
 *
 * Mengelola pembuatan, modifikasi, status aktif/nonaktif, serta
 * pembangkitan kode berurutan yang aman dari konkurensi (AGEN-NNN).
 */
class ShmAgentService
{
    private const CODE_PREFIX = 'AGEN-';

    private const CODE_PAD_LENGTH = 3;

    private const MAX_CODE_ATTEMPTS = 5;

    /**
     * Buat Agen baru dalam transaksi atomik.
     *
     * @param  array{name: string, nik: string, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data, ?int $actorId = null): ShmAgent
    {
        $nik = trim($data['nik']);
        $name = trim($data['name']);
        $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;

        if (ShmAgent::query()->where('nik', $nik)->exists()) {
            throw ValidationException::withMessages([
                'nik' => 'NIK sudah terdaftar untuk agen lain.',
            ]);
        }

        $attempts = 0;

        while ($attempts < self::MAX_CODE_ATTEMPTS) {
            $attempts++;

            try {
                return DB::transaction(function () use ($name, $nik, $isActive): ShmAgent {
                    $code = $this->generateAgentCode();

                    $agent = ShmAgent::create([
                        'code' => $code,
                        'name' => $name,
                        'nik' => $nik,
                        'is_active' => $isActive,
                    ]);

                    AuditLogger::log(
                        'shm.agent.created',
                        ShmAgent::class,
                        $agent->id,
                        $agent->code,
                        sprintf('Agen SHM %s (%s) dibuat.', $agent->name, $agent->code),
                        [
                            'code' => $agent->code,
                            'name' => $agent->name,
                            'nik' => $agent->nik,
                            'is_active' => $agent->is_active,
                        ],
                    );

                    return $agent;
                });
            } catch (QueryException $exception) {
                // Unique constraint collision retry
                if ($this->isUniqueViolation($exception) && $attempts < self::MAX_CODE_ATTEMPTS) {
                    continue;
                }

                throw $exception;
            }
        }

        throw new RuntimeException('Gagal membuat kode unik untuk Agen SHM setelah beberapa percobaan.');
    }

    /**
     * Update data agen (Nama Lengkap, NIK, Status).
     * Kode Agen TIDAK PERNAH berubah saat update.
     *
     * @param  array{name: string, nik: string, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function update(ShmAgent $agent, array $data, ?int $actorId = null): ShmAgent
    {
        $nik = trim($data['nik']);
        $name = trim($data['name']);

        if (ShmAgent::query()->where('nik', $nik)->where('id', '!=', $agent->id)->exists()) {
            throw ValidationException::withMessages([
                'nik' => 'NIK sudah digunakan oleh agen lain.',
            ]);
        }

        return DB::transaction(function () use ($agent, $name, $nik, $data): ShmAgent {
            /** @var ShmAgent $locked */
            $locked = ShmAgent::query()->where('id', $agent->id)->lockForUpdate()->firstOrFail();

            $before = [
                'name' => $locked->name,
                'nik' => $locked->nik,
                'is_active' => $locked->is_active,
            ];

            $locked->name = $name;
            $locked->nik = $nik;

            if (array_key_exists('is_active', $data)) {
                $locked->is_active = (bool) $data['is_active'];
            }

            $locked->save();

            AuditLogger::log(
                'shm.agent.updated',
                ShmAgent::class,
                $locked->id,
                $locked->code,
                sprintf('Data agen SHM %s (%s) diperbarui.', $locked->name, $locked->code),
                [
                    'before' => $before,
                    'after' => [
                        'name' => $locked->name,
                        'nik' => $locked->nik,
                        'is_active' => $locked->is_active,
                    ],
                ],
            );

            return $locked;
        });
    }

    /**
     * Aktifkan atau nonaktifkan agen.
     * Kode agen tidak pernah berubah dan record tidak dihapus.
     */
    public function setActive(ShmAgent $agent, bool $isActive, ?int $actorId = null): ShmAgent
    {
        return DB::transaction(function () use ($agent, $isActive): ShmAgent {
            /** @var ShmAgent $locked */
            $locked = ShmAgent::query()->where('id', $agent->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_active === $isActive) {
                return $locked;
            }

            $before = ['is_active' => $locked->is_active];
            $locked->is_active = $isActive;
            $locked->save();

            AuditLogger::log(
                $isActive ? 'shm.agent.activated' : 'shm.agent.deactivated',
                ShmAgent::class,
                $locked->id,
                $locked->code,
                sprintf('Agen SHM %s (%s) %s.', $locked->name, $locked->code, $isActive ? 'diaktifkan' : 'dinonaktifkan'),
                [
                    'before' => $before,
                    'after' => ['is_active' => $locked->is_active],
                ],
            );

            return $locked;
        });
    }

    /**
     * Hapus agen (hanya jika belum pernah digunakan pada pengajuan pinjaman SHM).
     *
     * @throws ValidationException
     */
    public function delete(ShmAgent $agent, ?int $actorId = null): void
    {
        if ($agent->loanApplications()->exists()) {
            throw ValidationException::withMessages([
                'agent' => 'Agen tidak dapat dihapus karena sudah tercatat pada pengajuan pinjaman SHM. Anda dapat menonaktifkannya.',
            ]);
        }

        DB::transaction(function () use ($agent): void {
            $before = [
                'code' => $agent->code,
                'name' => $agent->name,
                'nik' => $agent->nik,
                'is_active' => $agent->is_active,
            ];

            $agentId = $agent->id;
            $agent->delete();

            AuditLogger::log(
                'shm.agent.deleted',
                ShmAgent::class,
                $agentId,
                $before['code'],
                sprintf('Agen SHM %s (%s) dihapus.', $before['name'], $before['code']),
                $before,
            );
        });
    }

    /**
     * Bangkitkan Kode Agen urut berformat AGEN-NNN (mis. AGEN-001, AGEN-002).
     *
     * Menggunakan PostgreSQL sequence `shm_agents_code_seq` pada driver pgsql
     * agar atomic dan non-blocking pada konkurensi.
     * Fallback driver SQLite/test membaca kode AGEN-NNN tertinggi yang ada + 1.
     */
    public function generateAgentCode(): string
    {
        $sequence = null;

        if (DB::getDriverName() === 'pgsql') {
            try {
                $row = DB::selectOne("SELECT nextval('shm_agents_code_seq') AS seq");
                $sequence = $row->seq !== null ? (int) $row->seq : null;
            } catch (\Throwable) {
                $sequence = null;
            }
        }

        if ($sequence === null || $sequence <= 0) {
            $sequence = $this->highestSequenceFromExistingCodes() + 1;
        }

        return self::CODE_PREFIX.str_pad((string) $sequence, self::CODE_PAD_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Mencari urutan numerik tertinggi dari kode AGEN-NNN yang sudah tersimpan.
     */
    private function highestSequenceFromExistingCodes(): int
    {
        $highest = 0;

        foreach (ShmAgent::query()->pluck('code') as $code) {
            if (preg_match('/^AGEN-(\d+)$/', (string) $code, $matches) === 1) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return $highest;
    }

    /**
     * Memeriksa apakah exception disebabkan oleh pelanggaran constraint unik.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();

        // 23505 = Postgres unique_violation, 19 = SQLite constraint violation
        return $sqlState === '23505' || str_contains($exception->getMessage(), 'UNIQUE constraint failed');
    }
}
