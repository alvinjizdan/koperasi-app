<?php

namespace App\Services;

use App\Models\ShmCustomer;
use App\Support\DbTransaction;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Pembuatan dan pemeliharaan master data Nasabah SHM.
 *
 * Nomor nasabah SELALU dibuat di server memakai komponen acak dari
 * kriptografi. Pendekatan urut berbasis max-id berisiko bentrok ketika dua
 * request berjalan bersamaan dan membocorkan jumlah nasabah, sehingga
 * dipakai pola yang sama dengan nomor pengajuan SHM.
 *
 * FORMAT NOMOR BERSIFAT PROVISIONAL. KSP belum menetapkan format resmi nomor
 * nasabah SHM, jadi `NSB-YYYYMMDD-XXXXXXXX` hanya penanda internal yang jelas
 * dan dapat diganti ketika keputusan resmi keluar.
 *
 * Tidak ada aturan finansial atau kelayakan apa pun di kelas ini.
 *
 * @phpstan-type CustomerPayload array{name: string, nik?: string|null, phone?: string|null, email?: string|null, address?: string|null, latitude?: string|float|null, longitude?: string|float|null}
 */
class ShmCustomerService
{
    /**
     * Berapa kali mencoba lagi bila nomor acak kebetulan bentrok.
     */
    private const NUMBER_ATTEMPTS = 5;

    /**
     * Buat nasabah SHM baru.
     *
     * `customer_number` tidak pernah dibaca dari payload; nomor dibuat server.
     *
     * @param  CustomerPayload  $data
     */
    public function create(array $data): ShmCustomer
    {
        return DbTransaction::run(function () use ($data): ShmCustomer {
            $actorId = Auth::id();

            $customer = ShmCustomer::query()->create([
                'customer_number' => $this->generateCustomerNumber(),
                'name' => $data['name'],
                'nik' => $data['nik'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'is_active' => true,
            ]);

            AuditLogger::log(
                'shm.customer.created',
                ShmCustomer::class,
                $customer->id,
                $customer->name,
                sprintf('Nasabah SHM %s dibuat.', $customer->customer_number),
                [
                    'customer_number' => $customer->customer_number,
                    'name' => $customer->name,
                    'is_active' => $customer->is_active,
                    'actor_id' => $actorId,
                ],
            );

            return $customer;
        });
    }

    /**
     * Perbarui data nasabah SHM.
     *
     * Nomor nasabah tidak ikut berubah. Satu pemanggilan menghasilkan tepat
     * satu event audit.
     *
     * @param  CustomerPayload  $data
     */
    public function update(ShmCustomer $customer, array $data): ShmCustomer
    {
        return DbTransaction::run(function () use ($customer, $data): ShmCustomer {
            $locked = ShmCustomer::query()->lockForUpdate()->findOrFail($customer->id);
            $tracked = ['name', 'nik', 'phone', 'email', 'address', 'latitude', 'longitude'];
            $changes = [];

            foreach ($tracked as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $old = $locked->getAttribute($field);
                $new = $data[$field];

                if ((string) $old !== (string) $new) {
                    $changes[$field] = ['old' => $old, 'new' => $new];
                }

                $locked->setAttribute($field, $new);
            }

            $locked->save();

            AuditLogger::log(
                'shm.customer.updated',
                ShmCustomer::class,
                $locked->id,
                $locked->name,
                sprintf('Data nasabah SHM %s diperbarui.', $locked->customer_number),
                [
                    'customer_number' => $locked->customer_number,
                    'changes' => $changes,
                    'actor_id' => Auth::id(),
                ],
            );

            return $locked;
        });
    }

    /**
     * Aktifkan atau nonaktifkan nasabah SHM.
     *
     * Nasabah nonaktif tidak dihapus: pengajuan lama tetap merujuk ke barisnya.
     * Satu aksi = satu event audit.
     */
    public function setActive(ShmCustomer $customer, bool $isActive): ShmCustomer
    {
        return DbTransaction::run(function () use ($customer, $isActive): ShmCustomer {
            $locked = ShmCustomer::query()->lockForUpdate()->findOrFail($customer->id);

            $previous = $locked->is_active;
            $locked->is_active = $isActive;
            $locked->save();

            AuditLogger::log(
                'shm.customer.status_changed',
                ShmCustomer::class,
                $locked->id,
                $locked->name,
                sprintf(
                    'Nasabah SHM %s %s.',
                    $locked->customer_number,
                    $isActive ? 'diaktifkan' : 'dinonaktifkan',
                ),
                [
                    'customer_number' => $locked->customer_number,
                    'is_active' => ['old' => $previous, 'new' => $isActive],
                    'actor_id' => Auth::id(),
                ],
            );

            return $locked;
        });
    }

    /**
     * Nomor nasabah baru, dibuat di server.
     *
     * Format provisional `NSB-YYYYMMDD-XXXXXXXX`; komponen acak berasal dari
     * random_bytes sehingga tidak dapat ditebak dan tidak bentrok antar
     * request paralel.
     *
     * @throws RuntimeException bila setelah beberapa percobaan tetap bentrok
     */
    public function generateCustomerNumber(): string
    {
        for ($attempt = 0; $attempt < self::NUMBER_ATTEMPTS; $attempt++) {
            $candidate = sprintf(
                'NSB-%s-%s',
                now()->format('Ymd'),
                strtoupper(bin2hex(random_bytes(4))),
            );

            if (! ShmCustomer::query()->where('customer_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Nomor nasabah SHM tidak dapat dibuat setelah beberapa percobaan.'
        );
    }
}
