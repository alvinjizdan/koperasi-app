<?php

namespace App\Services\Shm;

use App\Models\ShmLoan;
use App\Models\ShmLoanInstallment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Boundary pembangkitan repayment schedule Pinjaman SHM.
 *
 * T75 — struktur boundary (dipertahankan):
 * 1. MENOLAK pinjaman yang belum layak (belum cair / bukan status active)
 *    dengan business error eksplisit — jadwal tidak pernah dibuat untuk
 *    pinjaman pending/rejected/undisbursed.
 * 2. IDEMPOTEN: bila pinjaman sudah memiliki angsuran, jadwal eksisting
 *    DIKEMBALIKKAN apa adanya — tidak ada baris duplikat, tidak ada
 *    penghapusan, tidak ada regenerasi diam-diam.
 * 3. ATOMIK: seluruh pemeriksaan, perhitungan, dan penulisan berada dalam
 *    SATU transaksi database dengan lockForUpdate pada baris pinjaman.
 *    Kegagalan penulisan → rollback penuh, tanpa jadwal parsial.
 * 4. GAGAL DETERMINISTIK: kontrak perhitungan pinjaman yang tidak sesuai
 *    (rate produk tidak terisi/≤ 0, periode ≠ monthly, metode ≠ flat)
 *    menolak pembangkitan lewat ScheduleConfigurationIncomplete —
 *    tanpa default yang ditebak, tanpa satu baris pun tertulis.
 *
 * T80 — implementasi pembangkitan mengikuti CONFIRMED BASELINE T79
 * (config/shm-business-rule-contract.php — sumber kebenaran aturan):
 * - Bunga flat atas POKOK AWAL dengan rate PRODUK (T82 — rate =
 *   f(product, tenor); universal 2% tidak lagi berlaku): bulanan =
 *   pokok × rate%, KONSTAN untuk seluruh angsuran (tidak menyusut).
 * - Pokok dialokasikan merata: pokok / tenor, dibulatkan rupiah terdekat
 *   tanpa akumulasi kesalahan; angsuran TERAKHIR menjadi angsuran
 *   penyeimbang sehingga Σ pokok = pokok pinjaman secara tepat.
 * - Nilai moneter yang disimpan SELALU rupiah utuh (tanpa pecahan).
 * - Jatuh tempo bulanan (T83): angsuran #1 = first_due_date (Marketing-
 *   pilih, kolom date pada pinjaman); angsuran berikutnya = first_due_date
 *   + i bulan dari ANCHOR ASLI dengan aturan akhir bulan
 *   (addMonthsNoOverflow) sehingga tidak terjadi drift; tanpa grace period;
 *   TIDAK memakai disbursed_at sebagai anchor dan TIDAK menghitung
 *   otomatis dari tanggal pencairan.
 * - Angsuran baru berstatus 'scheduled' dengan paid_amount 0 — bukan
 *   pembayaran; TIDAK ada record pembayaran, denda, settlement, atau
 *   collection yang dibuat di sini (payment = ticket terpisah).
 *
 * Service ini TIDAK menangani pembayaran, alokasi pembayaran, denda,
 * settlement, maupun collection.
 */
class ShmRepaymentScheduleService
{
    /**
     * Bangun (atau kembalikan) jadwal angsuran satu pinjaman SHM.
     *
     * @return Collection<int, ShmLoanInstallment>
     *
     * @throws ValidationException bila pinjaman tidak layak (belum cair /
     *                             bukan active / principal-tenor tidak valid)
     * @throws ScheduleConfigurationIncomplete bila kontrak perhitungan tidak
     *                                         sesuai baseline T79 / belum terisi
     */
    public function generate(ShmLoan $loan): Collection
    {
        return DB::transaction(function () use ($loan): Collection {
            // Lock baris pinjaman: state dibaca di dalam transaksi, bukan dari
            // state UI yang mungkin sudah basi. Request paralel menunggu lock
            // ini lalu MEMBACA ULANG jadwal eksisting (lihat di bawah) sehingga
            // tidak pernah membuat angsuran ganda (pola T75 dipertahankan).
            $locked = ShmLoan::query()->lockForUpdate()->findOrFail($loan->id);

            // IDEMPOTENCY: jadwal eksisting dikembalikan apa adanya. Tidak ada
            // penghapusan/regenerasi diam-diam — jadwal parsial/korup yang sudah
            // ada TIDAK diperbaiki diam-diam di T80 (di luar boundary ticket).
            $existing = $locked->installments()->orderBy('installment_number')->get();

            if ($existing->isNotEmpty()) {
                return $existing;
            }

            $this->assertEligible($locked);
            $this->assertBaselineContract($locked);

            // Hitung SELURUH baris lebih dulu (tanpa menulis apa pun), lalu
            // tulis — kegagalan kapan pun menghasilkan rollback penuh sehingga
            // tidak pernah ada jadwal parsial (mis. 3 dari 12).
            $rows = $this->buildScheduleRows($locked);

            foreach ($rows as $row) {
                ShmLoanInstallment::query()->create($row);
            }

            return $locked->installments()->get();
        });
    }

    /**
     * Eligibility guard (kontrak T75 dipertahankan): hanya pinjaman AKTIF
     * yang SUDAH CAIR yang boleh memiliki jadwal. Tidak ada status loan baru.
     *
     * @throws ValidationException
     */
    private function assertEligible(ShmLoan $loan): void
    {
        if ($loan->disbursed_at === null) {
            throw ValidationException::withMessages([
                'loan' => sprintf(
                    'Pinjaman %s belum dicairkan sehingga jadwal angsuran tidak dapat dibuat.',
                    $loan->loan_number,
                ),
            ]);
        }

        // T83 — anchor jadwal = first_due_date (Marketing-pilih). Tanpa
        // anchor, pembangkitan DITOLAK eksplisit — TIDAK ada fallback ke
        // disbursed_at, disbursed_at + 1 bulan, maupun tanggal hari ini.
        if ($loan->first_due_date === null) {
            throw ValidationException::withMessages([
                'loan' => sprintf(
                    'Jadwal angsuran %s belum dapat dibuat karena tanggal jatuh tempo pertama belum ditentukan.',
                    $loan->loan_number,
                ),
            ]);
        }

        if ($loan->status !== ShmLoan::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'loan' => sprintf(
                    'Hanya pinjaman aktif yang dapat memiliki jadwal angsuran. Status pinjaman %s: %s.',
                    $loan->loan_number,
                    $loan->status,
                ),
            ]);
        }

        // Validitas data finansial dasar. Nilai tidak valid ditolak
        // eksplisit — bukan ditebak, bukan dipbulatkan diam-diam.
        $principal = $this->principalRupiah($loan);

        if ($principal <= 0) {
            throw ValidationException::withMessages([
                'loan' => sprintf(
                    'Pinjaman %s tidak memiliki principal yang valid sehingga jadwal angsuran tidak dapat dibuat.',
                    $loan->loan_number,
                ),
            ]);
        }

        if ($loan->tenor_months < 1) {
            throw ValidationException::withMessages([
                'loan' => sprintf(
                    'Pinjaman %s tidak memiliki tenor yang valid sehingga jadwal angsuran tidak dapat dibuat.',
                    $loan->loan_number,
                ),
            ]);
        }

        // Prasyarat matematis: angsuran penyeimbang tidak boleh negatif.
        // Terjadi hanya pada data tidak realistis (pokok jauh lebih kecil
        // dari kuadrat tenor) — ditolak, bukan menyimpan nilai negatif.
        $regular = $this->regularPrincipalRupiah($principal, $loan->tenor_months);

        if ($principal - $regular * ($loan->tenor_months - 1) < 0) {
            throw ValidationException::withMessages([
                'loan' => sprintf(
                    'Pinjaman %s terlalu kecil untuk tenor %d bulan sehingga alokasi pokok tidak dapat dibentuk. Sesuaikan principal/tenor (pembulatan rupiah terdekat, angsuran terakhir penyeimbang).',
                    $loan->loan_number,
                    $loan->tenor_months,
                ),
            ]);
        }
    }

    /**
     * Kontrak pada record pinjaman: rate PRODUK (T82 — hasil resolusi EXAKT
     * product+tenor; BUKAN universal 2%) + periode monthly + metode flat.
     * Rate yang tidak terisi/tidak positif MENOLAK pembangkitan (tanpa
     * default yang ditebak). Guard status kontrak T75 dipertahankan: bila
     * section installment/repayment_schedule dikembalikan ke PENDING di
     * config, pembangkitan kembali diblokir.
     *
     * @throws ScheduleConfigurationIncomplete
     */
    private function assertBaselineContract(ShmLoan $loan): void
    {
        $issues = [];

        // T82 — rate harian pinjaman = rate tier produk untuk tenor pinjaman
        // (distempel pencairan). Universal 2% TIDAK dipakai: cukup rate
        // terisi & positif; nilainya mengikuti produk masing-masing pinjaman.
        if ((float) $loan->rate_percent <= 0) {
            $issues[] = 'rate produk pinjaman (rate_percent) belum terisi atau tidak positif — rate wajib berasal dari tier rate produk (T82)';
        }

        if ($loan->rate_period === null) {
            $issues[] = 'periode rate pinjaman (rate_period) belum diisi — baseline T79 mensyaratkan "monthly"';
        } elseif ($loan->rate_period !== 'monthly') {
            $issues[] = sprintf(
                'periode rate pinjaman (rate_period) "%s" tidak sesuai baseline T79 "monthly"',
                $loan->rate_period,
            );
        }

        if ($loan->calculation_method === null) {
            $issues[] = 'metode angsuran pinjaman (calculation_method) belum diisi — baseline T79 mensyaratkan "flat"';
        } elseif ($loan->calculation_method !== 'flat') {
            $issues[] = sprintf(
                'metode angsuran pinjaman (calculation_method) "%s" tidak sesuai baseline T79 "flat"',
                $loan->calculation_method,
            );
        }

        // Guard kontrak T75: section contract masih PENDING = pembangkitan
        // tetap diblokir (perlindungan terhadap regresi status kontrak).
        foreach (['installment', 'repayment_schedule'] as $section) {
            $status = (string) config("shm-business-rule-contract.{$section}.status");

            if (strtoupper($status) === 'PENDING BUSINESS CONFIRMATION') {
                foreach ((array) config("shm-business-rule-contract.{$section}.blocked_by", []) as $item) {
                    $issues[] = (string) $item;
                }
            }
        }

        if ($issues !== []) {
            throw ScheduleConfigurationIncomplete::forLoan($loan, $issues);
        }
    }

    /**
     * Susun SELURUH baris jadwal (tanpa menulis apa pun).
     *
     * Rumus (baseline T79 + rate produk T82 + anchor T83):
     * - bunga bulanan = round(pokok_awal × rate_produk%) — rupiah terdekat,
     *   KONSTAN (flat atas pokok awal).
     * - pokok per angsuran = round(pokok_awal / tenor) — rupiah terdekat;
     *   angsuran terakhir = pokok_awal − pokok_regular × (tenor − 1).
     * - total angsuran = pokok + bunga.
     * - JATUH TEMPO (T83): angsuran #1 = first_due_date (Marketing-pilih);
     *   angsuran berikutnya = first_due_date + i bulan, dihitung dari
     *   ANCHOR ASLI (bukan tanggal sebelumnya) sehingga tidak drift; fallback
     *   hari terakhir bulan bila tanggal tidak valid. disbursed_at TIDAK
     *   dipakai sebagai anchor lagi.
     *
     * @return array<int, array<string, int|string|null>>
     */
    private function buildScheduleRows(ShmLoan $loan): array
    {
        $principal = $this->principalRupiah($loan);
        $tenor = $loan->tenor_months;

        // Bunga flat bulanan atas pokok awal — dihitung SEKALI dan konstan
        // untuk seluruh periode (bukan atas saldo, bukan menyusut). Rate
        // = rate produk pinjaman (T82), dihitung dalam ARITMATIKA INTEGER
        // rupiah (pembulatan half-up) — identik hasilnya dengan rumus T80
        // untuk rate 2% dan tetap eksak untuk rate seperti 1.30/1.70.
        $rateTenThousandths = (int) round((float) $loan->rate_percent * 10000);
        $monthlyInterest = intdiv($principal * $rateTenThousandths + 500000, 1000000);

        $regular = $this->regularPrincipalRupiah($principal, $tenor);
        $finalPrincipal = $principal - $regular * ($tenor - 1);

        // T83 — ANCHOR = first_due_date (Marketing-pilih), bukan tanggal
        // pencairan. Ansuran #1 jatuh tepat pada first_due_date.
        $anchor = $loan->first_due_date->copy()->startOfDay();
        $rows = [];

        for ($i = 1; $i <= $tenor; $i++) {
            // Angsuran terakhir menyerap selisih pembulatan sehingga
            // Σ pokok = pokok pinjaman secara tepat (invarian T79).
            $principalComponent = $i === $tenor ? $finalPrincipal : $regular;
            $total = $principalComponent + $monthlyInterest;

            $rows[] = [
                'shm_loan_id' => $loan->id,
                'installment_number' => $i,
                // Anchor first_due_date ASLI (bukan tanggal jatuh tempo
                // sebelumnya) sehingga hari-bulan yang dituju konsisten dan
                // tidak drift; fallback hari terakhir bulan bawaan Carbon.
                'due_date' => $anchor->copy()->addMonthsNoOverflow($i - 1)->toDateString(),
                'principal_amount' => $principalComponent,
                'interest_amount' => $monthlyInterest,
                'total_amount' => $total,
                'paid_amount' => 0,
                // Semantik kolom T74 (lihat ShmLoanInstallmentTest AC04):
                // remaining = total − paid; pada jadwal baru paid = 0 sehingga
                // sisa tagihan = total. BUKAN perilaku pembayaran.
                'remaining_amount' => $total,
                'status' => ShmLoanInstallment::STATUS_SCHEDULED,
                'paid_at' => null,
            ];
        }

        return $rows;
    }

    /**
     * Pokok pinjaman dalam rupiah utuh (integer).
     *
     * Baseline T79: nilai moneter yang disimpan/ditagihkan tidak boleh
     * berisi pecahan rupiah, dan Σ pokok angsuran harus sama TEPAT dengan
     * pokok pinjaman. Keduanya mustahil terpenuhi bila pokok pinjaman
     * sendiri berisi pecahan — pinjaman demikian DITOLAK eksplisit, bukan
     * dibulatkan diam-diam.
     *
     * @throws ValidationException
     */
    private function principalRupiah(ShmLoan $loan): int
    {
        $principal = (float) $loan->principal;

        if ($principal !== floor($principal)) {
            throw ValidationException::withMessages([
                'loan' => sprintf(
                    'Pinjaman %s memiliki principal berpecahan rupiah (%s). Baseline T79 mensyaratkan nilai moneter rupiah utuh sehingga jadwal angsuran tidak dapat dibentuk.',
                    $loan->loan_number,
                    $loan->principal,
                ),
            ]);
        }

        return (int) $principal;
    }

    /**
     * Pokok per angsuran reguler: pembulatan rupiah TERDEKAT dari
     * pokok_awal / tenor (integer arithmetic — tanpa error float).
     */
    private function regularPrincipalRupiah(int $principal, int $tenor): int
    {
        $quotient = intdiv($principal, $tenor);
        $remainder = $principal % $tenor;

        // Round-half-up: tambahkan setengah penyebut lalu integer-divide.
        return $quotient + (($remainder * 2 >= $tenor) ? 1 : 0);
    }
}
