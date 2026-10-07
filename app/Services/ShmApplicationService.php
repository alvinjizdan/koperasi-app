<?php

namespace App\Services;

use App\Enums\ShmApplicationStatus;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmProperty;
use App\Support\DbTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Pembuatan dan pemeliharaan data pengajuan SHM.
 *
 * Nomor pengajuan SELALU dibuat di server memakai komponen acak yang
 * berasal dari kriptografi. Pendekatan max-id-plus-one yang dipakai
 * Loan berisiko bentrok ketika dua request berjalan bersamaan, dan
 * angka berurutan juga membocorkan jumlah pengajuan. Constraint UNIQUE
 *
 * Tidak ada kolom nominal, bunga, margin, diskon, atau formula keuangan
 * apa pun yang dikunci di kelas ini. Seluruh aturan finansial SHM masih
 * PENDING BUSINESS DECISION.
 */
class ShmApplicationService
{
    /**
     * Berapa kali mencoba lagi bila nomor acak kebetulan bentrok.
     */
    private const NUMBER_ATTEMPTS = 5;

    public function __construct(
        private readonly ShmCustomerService $customers,
    ) {}

    /**
     * Buat pengajuan baru atas nama Marketing yang sedang login.
     *
     * marketing_id tidak pernah dibaca dari payload.
     *
     * Nasabah diambil dari salah satu sumber:
     * - `shm_customer_id` untuk nasabah yang sudah terdaftar;
     * - `new_customer` untuk nasabah baru yang dibuat langsung dari flow
     *   pengajuan. Nasabah baru dibuat sebagai ShmCustomer, TIDAK pernah
     *   sebagai anggota koperasi.
     *
     * @param  array{shm_customer_id?: int|null, new_customer?: array{name: string, nik?: string|null, phone?: string|null}|null, notes?: string|null, marketing_activity_id?: int|null}  $data
     */
    public function create(array $data): ShmApplication
    {
        return DbTransaction::run(function () use ($data): ShmApplication {
            $actorId = Auth::id();

            $customerId = $this->resolveCustomerId($data);

            $application = ShmApplication::query()->create([
                'application_number' => $this->generateApplicationNumber(),
                // Rujukan nasabah SELALU ke master nasabah SHM, bukan ke anggota koperasi.
                'shm_customer_id' => $customerId,
                // Actor dari user yang login, bukan dari input form.
                'marketing_id' => $actorId,
                'marketing_activity_id' => $data['marketing_activity_id'] ?? null,
                'status' => ShmApplicationStatus::Draft->value,
                'notes' => $data['notes'] ?? null,
            ]);

            AuditLogger::log(
                'shm.application.created',
                ShmApplication::class,
                $application->id,
                $application->application_number,
                'Pengajuan SHM dibuat.',
                [
                    'shm_customer_id' => $application->shm_customer_id,
                    'status' => $application->status->value,
                    'actor_id' => $actorId,
                ],
            );

            return $application;
        });
    }

    /**
     * Perbarui data dasar pengajuan.
     *
     * Hanya boleh saat pengajuan masih draft. Setelah keluar dari draft,
     * perubahan data dasar menjadi PENDING BUSINESS DECISION karena belum
     * ada aturan soal apakah dan siapa yang boleh memperbaiki pengajuan
     * yang sedang berjalan.
     *
     * @param  array{shm_customer_id?: int|null, notes?: string|null}  $data
     */
    public function update(ShmApplication $application, array $data): ShmApplication
    {
        return DbTransaction::run(function () use ($application, $data): ShmApplication {
            $locked = ShmApplication::query()->lockForUpdate()->findOrFail($application->id);

            $previousCustomerId = $locked->shm_customer_id;

            $locked->fill([
                'shm_customer_id' => array_key_exists('shm_customer_id', $data) && $data['shm_customer_id'] !== null
                    ? (int) $data['shm_customer_id']
                    : $locked->shm_customer_id,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
            ]);

            $locked->save();

            AuditLogger::log(
                'shm.application.updated',
                ShmApplication::class,
                $locked->id,
                $locked->application_number,
                'Data pengajuan SHM diperbarui.',
                [
                    // Perubahan relasi nasabah dicatat supaya jejak pemindahan
                    // pengajuan antar nasabah tetap dapat ditelusuri.
                    'shm_customer_id' => $previousCustomerId === $locked->shm_customer_id
                        ? $locked->shm_customer_id
                        : ['old' => $previousCustomerId, 'new' => $locked->shm_customer_id],
                    'actor_id' => Auth::id(),
                ],
            );

            return $locked;
        });
    }

    /**
     * Buat atau perbarui objek SHM/SHGB milik pengajuan.
     *
     * certificate_type hanya membedakan SHM dan SHGB. Tidak ada aturan legal
     * turunan dan koordinat tidak pernah dipakai sebagai radius validasi.
     *
     * @param  array{certificate_type: string, certificate_number?: string|null, owner_name?: string|null, address: string, latitude?: string|null, longitude?: string|null, notes?: string|null}  $data
     */
    public function saveProperty(ShmApplication $application, array $data, ?int $propertyId = null): ShmProperty
    {
        return DbTransaction::run(function () use ($application, $data, $propertyId): ShmProperty {
            $actorId = Auth::id();

            $property = $propertyId !== null
                ? ShmProperty::query()->where('shm_application_id', $application->id)->findOrFail($propertyId)
                : new ShmProperty(['shm_application_id' => $application->id]);

            $property->fill($data);
            $property->save();

            AuditLogger::log(
                'shm.property.updated',
                ShmProperty::class,
                $property->id,
                $application->application_number,
                'Objek SHM/SHGB diperbarui.',
                [
                    'certificate_type' => $property->certificate_type,
                    // Koordinat dicatat sebagai jejak lokasi objek, bukan bukti
                    // kepemilikan dan bukan dasar validasi apa pun.
                    'latitude' => $property->latitude === null ? null : (float) $property->latitude,
                    'longitude' => $property->longitude === null ? null : (float) $property->longitude,
                    'actor_id' => $actorId,
                ],
            );

            return $property;
        });
    }

    /**
     * Nomor pengajuan baru, dibuat di server.
     *
     * Komponen acak berasal dari random_bytes sehingga tidak dapat ditebak
     * dan tidak bentrok antar request paralel.
     *
     * @throws RuntimeException bila setelah beberapa percobaan tetap bentrok
     */
    public function generateApplicationNumber(): string
    {
        for ($attempt = 0; $attempt < self::NUMBER_ATTEMPTS; $attempt++) {
            $candidate = sprintf(
                'SHM-%s-%s',
                now()->format('Ymd'),
                strtoupper(bin2hex(random_bytes(4))),
            );

            $exists = ShmApplication::query()
                ->where('application_number', $candidate)
                ->exists();

            if (! $exists) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Nomor pengajuan tidak dapat dibuat setelah beberapa percobaan.'
        );
    }

    /**
     * Nasabah SHM aktif untuk dipilih pada form pengajuan.
     *
     * Master nasabah bersifat bersama: seluruh Marketing memilih dari daftar
     * nasabah yang sama. Tidak ada pembacaan tabel members di sini.
     *
     * @return Collection<int, ShmCustomer>
     */
    public function selectableCustomers()
    {
        return ShmCustomer::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'customer_number', 'name']);
    }

    /**
     * Tentukan id nasabah untuk pengajuan baru.
     *
     * Nasabah baru dibuat melalui ShmCustomerService sebagai master nasabah
     * SHM, bukan sebagai anggota koperasi.
     *
     * @param  array{shm_customer_id?: int|null, new_customer?: array{name: string, nik?: string|null, phone?: string|null}|null}  $data
     */
    private function resolveCustomerId(array $data): int
    {
        $newCustomer = $data['new_customer'] ?? null;

        if (is_array($newCustomer) && trim((string) ($newCustomer['name'] ?? '')) !== '') {
            return $this->customers->create([
                'name' => (string) $newCustomer['name'],
                'nik' => $newCustomer['nik'] ?? null,
                'phone' => $newCustomer['phone'] ?? null,
            ])->id;
        }

        return (int) $data['shm_customer_id'];
    }
}
