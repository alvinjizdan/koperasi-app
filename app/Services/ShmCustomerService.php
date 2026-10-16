<?php

namespace App\Services;

use App\Models\MarketingActivity;
use App\Models\Member;
use App\Models\ShmCustomer;
use App\Models\ShmLoanApplication;
use App\Support\DbTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Pembuatan dan pemeliharaan master data Nasabah SHM.
 *
 * NOMOR NASABAH mengikuti format resmi T102: `NNNNN-ANG` (`00001-ANG`,
 * `00002-ANG`, ...) — sequence PER DOMAIN yang independen dari nomor anggota
 * koperasi. Kode anggota tidak pernah dipakai sebagai kode nasabah dan kode
 * nasabah tidak memengaruhi sequence anggota; keduanya boleh kebetulan sama
 * karena berada pada tabel/domain berbeda.
 *
 * Nilai sequence diambil dari sequence PostgreSQL `shm_customers_code_seq`
 * (pola SavingsAccount::generateAccountNumber) sehingga aman pada dua request
 * bersamaan; index unique `shm_customers.customer_number` tetap menjadi
 * safety net terakhir. Data legacy format `NSB-...` TIDAK diubah dan TIDAK
 * ikut dihitung — sequence format baru dimulai dari 00001.
 *
 * Tidak ada aturan finansial atau kelayakan apa pun di kelas ini.
 *
 * @phpstan-type CustomerPayload array{name: string, nik?: string|null, phone?: string|null, email?: string|null, address?: string|null, latitude?: string|float|null, longitude?: string|float|null}
 */
class ShmCustomerService
{
    /**
     * Berapa kali percobaan bila nomor kebetulan bentrok (unique constraint)
     * — umumnya hanya mungkin pada race yang memakai fallback non-postgresql.
     */
    private const NUMBER_ATTEMPTS = 5;

    /**
     * Buat nasabah SHM baru.
     *
     * `customer_number` tidak pernah dibaca dari payload; nomor dibuat server
     * dari sequence domain Nasabah SHM. Bila nomor kebetulan bentrok pada
     * saat yang sama (unique constraint), seluruh transaksi diulang dengan
     * kandidat baru — tidak pernah menghasilkan duplikat.
     *
     * @param  CustomerPayload  $data
     */
    public function create(array $data): ShmCustomer
    {
        for ($attempt = 1; $attempt <= self::NUMBER_ATTEMPTS; $attempt++) {
            try {
                return DbTransaction::run(function () use ($data): ShmCustomer {
                    $actorId = Auth::id();

                    $memberId = $data['member_id'] ?? null;
                    if (! $memberId) {
                        throw ValidationException::withMessages([
                            'member_id' => ['Anggota koperasi sumber wajib dipilih untuk membuat nasabah SHM.'],
                        ]);
                    }

                    $member = Member::query()->lockForUpdate()->find($memberId);
                    if (! $member) {
                        throw ValidationException::withMessages([
                            'member_id' => ['Anggota koperasi tidak ditemukan.'],
                        ]);
                    }

                    // Member eligibility: hanya anggota dengan status 'active' yang boleh menjadi nasabah SHM
                    if ($member->status !== 'active') {
                        throw ValidationException::withMessages([
                            'member_id' => [sprintf('Anggota %s berstatus "%s" dan tidak memenuhi syarat menjadi Nasabah SHM. Hanya anggota berstatus aktif yang diperbolehkan.', $member->member_number, $member->status)],
                        ]);
                    }

                    // Duplicate prevention (1:1 relationship untuk nasabah aktif)
                    $existingActive = ShmCustomer::query()
                        ->where('member_id', $member->id)
                        ->where('is_active', true)
                        ->first();

                    if ($existingActive) {
                        throw ValidationException::withMessages([
                            'member_id' => [sprintf('Anggota %s sudah terdaftar sebagai Nasabah SHM aktif (%s).', $member->member_number, $existingActive->customer_number)],
                        ]);
                    }

                    $name = ! empty($data['name']) ? $data['name'] : $member->full_name;
                    $nik = array_key_exists('nik', $data) && $data['nik'] !== null ? $data['nik'] : $member->identity_number;
                    $phone = array_key_exists('phone', $data) && $data['phone'] !== null ? $data['phone'] : $member->phone;
                    $address = array_key_exists('address', $data) && $data['address'] !== null ? $data['address'] : $member->address;

                    $customer = ShmCustomer::query()->create([
                        'member_id' => $member->id,
                        'customer_number' => $this->generateCustomerNumber(),
                        'name' => $name,
                        'nik' => $nik,
                        'phone' => $phone,
                        'email' => $data['email'] ?? null,
                        'address' => $address,
                        'latitude' => $data['latitude'] ?? null,
                        'longitude' => $data['longitude'] ?? null,
                        'is_active' => true,
                    ]);

                    AuditLogger::log(
                        'shm.customer.created',
                        ShmCustomer::class,
                        $customer->id,
                        $customer->name,
                        sprintf('Nasabah SHM %s dibuat dari Anggota Koperasi %s.', $customer->customer_number, $member->member_number),
                        [
                            'customer_number' => $customer->customer_number,
                            'member_id' => $member->id,
                            'member_number' => $member->member_number,
                            'name' => $customer->name,
                            'is_active' => $customer->is_active,
                            'actor_id' => $actorId,
                        ],
                    );

                    return $customer;
                });
            } catch (QueryException $exception) {
                // Bentrok nomor (unique constraint customer_number) ketika dua
                // pembuatan berjalan bersamaan: transaksi gagal utuh (tanpa
                // baris parsial), lalu percobaan berikutnya membentuk kandidat
                // baru dari nomor yang sudah ter-commit. Kegagalan query lain
                // tetap dilempar apa adanya.
                $isNumberCollision = str_contains($exception->getMessage(), 'customer_number');

                if (! $isNumberCollision || $attempt === self::NUMBER_ATTEMPTS) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException(
            'Nomor nasabah SHM tidak dapat dibuat setelah beberapa percobaan.'
        );
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
            $tracked = [
                'name', 'nik', 'phone', 'email', 'address', 'latitude', 'longitude',
            ];
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
     * Hapus permanen (hard delete) nasabah SHM bila aman secara dependensi.
     *
     * Aturan T102 — nasabah yang SUDAH dipakai oleh rekaman SHM lain TIDAK
     * boleh dihapus: pengajuan SHM (beserta dokumen, credit checking,
     * memorandum, approval, objek), pinjaman SHM (beserta angsuran, pembayaran,
     * settlement, collection), aktivitas Marketing, dan pengajuan pinjaman SHM.
     * Guard dependency ini menolak dengan pesan yang jelas — tanpa cascade,
     * tanpa menghapus anggota, tanpa men-null-kan relasi diam-diam. Untuk
     * nasabah terpakai, jalur yang didukung arsitektur tetap deaktivasi
     * (`setActive false`), bukan penghapusan.
     *
     * Nasabah tanpa dependensi dihapus permanen; anggota koperasi sumber TIDAK
     * pernah ikut terhapus. Satu aksi = satu event audit.
     */
    public function delete(ShmCustomer $customer): void
    {
        DbTransaction::run(function () use ($customer): void {
            $locked = ShmCustomer::query()->lockForUpdate()->findOrFail($customer->id);

            $blockers = $this->deleteBlockers($locked);

            if ($blockers !== []) {
                throw ValidationException::withMessages([
                    'customer' => sprintf(
                        'Nasabah SHM %s tidak dapat dihapus karena masih memiliki %s. Nonaktifkan nasabah bila tidak dipakai lagi.',
                        $locked->customer_number,
                        implode(', ', $blockers),
                    ),
                ]);
            }

            $memberNumber = $locked->member?->member_number;
            $auditId = $locked->id;
            $auditName = $locked->name;
            $auditNumber = $locked->customer_number;

            $locked->delete();

            AuditLogger::log(
                'shm.customer.deleted',
                ShmCustomer::class,
                $auditId,
                $auditName,
                sprintf('Nasabah SHM %s dihapus permanen.', $auditNumber),
                [
                    'customer_number' => $auditNumber,
                    'member_id' => $locked->member_id,
                    'member_number' => $memberNumber,
                    'actor_id' => Auth::id(),
                ],
            );
        });
    }

    /**
     * Daftar dependensi yang memblokir hard delete (kosong = aman dihapus).
     *
     * Menghitung rekaman dengan foreign key langsung ke `shm_customers`;
     * turunan lain (dokumen, credit checking, memorandum, approval, angsuran,
     * pembayaran, settlement, collection) selalu menempel pada pengajuan/
     * pinjaman sehingga ikut tercakup.
     *
     * @return array<int, string>
     */
    private function deleteBlockers(ShmCustomer $customer): array
    {
        $blockers = [];

        $applications = (int) $customer->applications()->count();
        if ($applications > 0) {
            $blockers[] = "{$applications} pengajuan SHM";
        }

        $loans = (int) $customer->loans()->count();
        if ($loans > 0) {
            $blockers[] = "{$loans} pinjaman SHM";
        }

        $activities = MarketingActivity::query()->where('shm_customer_id', $customer->id)->count();
        if ($activities > 0) {
            $blockers[] = "{$activities} aktivitas Marketing";
        }

        $loanApplications = ShmLoanApplication::query()->where('shm_customer_id', $customer->id)->count();
        if ($loanApplications > 0) {
            $blockers[] = "{$loanApplications} pengajuan pinjaman SHM";
        }

        return $blockers;
    }

    /**
     * Nomor nasabah baru — format resmi `NNNNN-ANG`, dibuat di server.
     *
     * - Sequence domain Nasabah SHM INDEPENDEN dari nomor anggota koperasi
     *   (dan dari sequence legacy `NSB-...` yang tidak dihitung/diubah);
     *   nasabah pertama format baru selalu `00001-ANG`.
     * - Nilai diambil dari sequence PostgreSQL `shm_customers_code_seq`
     *   (pola SavingsAccount::generateAccountNumber): aman pada pembuatan
     *   paralel tanpa membaca MAX(id); index unique customer_number tetap
     *   menjadi safety net terakhir.
     * - Driver non-PostgreSQL (sqlite saat test) memakai fallback pembacaan
     *   kode `NNNNN-ANG` tertinggi + 1 — proteksi tetap berlapis lewat
     *   unique constraint dan retry pada create().
     *
     * @throws RuntimeException bila setelah beberapa percobaan tetap bentrok
     */
    public function generateCustomerNumber(): string
    {
        $sequence = null;

        if (DB::getDriverName() === 'pgsql') {
            try {
                $row = DB::selectOne("SELECT nextval('shm_customers_code_seq') AS seq");
                $sequence = $row->seq !== null ? (int) $row->seq : null;
            } catch (\Throwable) {
                $sequence = null;
            }
        }

        if ($sequence === null || $sequence <= 0) {
            $sequence = $this->highestSequenceFromExistingCodes() + 1;
        }

        return str_pad((string) $sequence, 5, '0', STR_PAD_LEFT).'-ANG';
    }

    /**
     * Kode `NNNNN-ANG` tertinggi yang ada di tabel (0 bila belum ada).
     *
     * Kode legacy format lain (mis. `NSB-...`) sengaja tidak dihitung agar
     * sequence format baru dimulai dari 00001 sesuai aturan T102.
     */
    private function highestSequenceFromExistingCodes(): int
    {
        $highest = 0;

        foreach (ShmCustomer::query()->pluck('customer_number') as $code) {
            if (preg_match('/^(\d{5})-ANG$/', (string) $code, $matches) === 1) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return $highest;
    }
}
