<?php

namespace App\Services\Shm;

use App\Models\ShmSettlementDiscountPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * T89B — Master policy diskon bunga pelunasan SHM (DATA-DRIVEN).
 *
 * Source of truth pemilihan discount: rentang sisa bulan + effective dating.
 * Aturan yang ditegakkan di sini (bukan di React):
 * - rentang valid: min_remaining_months <= max_remaining_months;
 * - discount_percent valid: 0–100 (tidak negatif; TIDAK ada default seed);
 * - effective_until >= effective_from bila keduanya diisi;
 * - TIDAK boleh ada dua policy AKTIF dengan rentang bulan beririsan DAN
 *   effective period beririsan — pemilihan policy selalu deterministik;
 * - policy non-aktif boleh beririsan (hanya aktif yang dipakai selection).
 *
 * Selectors tidak pernah mengembangkkan "0% diam-diam": bila tidak ada
 * policy yang cocok, pemanggil wajib menampilkan konfigurasi bisnis yang
 * belum lengkap (lihat ShmLoanSettlementService::preview/request).
 */
class ShmSettlementDiscountPolicyService
{
    /**
     * Buat policy baru.
     *
     * @param  array{
     *     min_remaining_months: int,
     *     max_remaining_months: int,
     *     discount_percent: numeric,
     *     is_active?: bool,
     *     effective_from?: ?string,
     *     effective_until?: ?string,
     *     notes?: ?string
     * }  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): ShmSettlementDiscountPolicy
    {
        $validated = $this->validate($data);
        $this->guardOverlap(null, $validated);

        return ShmSettlementDiscountPolicy::query()->create($validated);
    }

    /**
     * Ubah policy. Snapshot settlement LAMA tidak pernah ikut berubah
     * (kolom snapshot tersimpan pada baris settlement — AC6).
     *
     * @param  array{
     *     min_remaining_months?: int,
     *     max_remaining_months?: int,
     *     discount_percent?: numeric,
     *     is_active?: bool,
     *     effective_from?: ?string,
     *     effective_until?: ?string,
     *     notes?: ?string
     * }  $data
     *
     * @throws ValidationException
     */
    public function update(ShmSettlementDiscountPolicy $policy, array $data): ShmSettlementDiscountPolicy
    {
        $merged = array_merge([
            'min_remaining_months' => $policy->min_remaining_months,
            'max_remaining_months' => $policy->max_remaining_months,
            'discount_percent' => $policy->discount_percent,
            'is_active' => $policy->is_active,
            'effective_from' => $policy->effective_from?->toDateString(),
            'effective_until' => $policy->effective_until?->toDateString(),
            'notes' => $policy->notes,
        ], $data);

        $validated = $this->validate($merged);
        $this->guardOverlap($policy->id, $validated);

        $policy->fill($validated);
        $policy->save();

        return $policy;
    }

    /**
     * Aktif/nonaktifkan policy (toggle). Menonaktifkan selalu boleh;
     * mengaktifkan kembali melewati guard overlap yang sama.
     *
     * @throws ValidationException
     */
    public function toggle(ShmSettlementDiscountPolicy $policy): ShmSettlementDiscountPolicy
    {
        return $this->update($policy, ['is_active' => ! $policy->is_active]);
    }

    /**
     * Pilih policy yang berlaku untuk sisa bulan tertentu pada tanggal
     * settlement. Deterministik: aktif + rentang cocok + effective pada
     * tanggal. Lebih dari satu kecocokan = konflik konfigurasi bisnis
     * (error eksplisit, bukan pilihan acak).
     *
     * @return ShmSettlementDiscountPolicy|null null bila tidak ada policy
     *                                          yang cocok (dilarang
     *                                          menggantinya dengan 0%)
     *
     * @throws ValidationException bila ada lebih dari satu kecocokan
     */
    public function selectFor(int $remainingMonths, Carbon $on): ?ShmSettlementDiscountPolicy
    {
        $matches = ShmSettlementDiscountPolicy::query()
            ->active()
            ->where('min_remaining_months', '<=', $remainingMonths)
            ->where('max_remaining_months', '>=', $remainingMonths)
            ->where(function ($query) use ($on): void {
                $query->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $on->toDateString());
            })
            ->where(function ($query) use ($on): void {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $on->toDateString());
            })
            ->orderBy('id')
            ->get();

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'policy' => sprintf(
                    'Konfigurasi policy diskon bentrok: %d policy aktif cocok untuk sisa %d bulan pada tanggal %s. Nonaktifkan salah satunya.',
                    $matches->count(),
                    $remainingMonths,
                    $on->toDateString(),
                ),
            ]);
        }

        return $matches->first();
    }

    /**
     * Validasi dasar satu policy.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $data): array
    {
        $errors = [];

        $min = filter_var($data['min_remaining_months'] ?? null, FILTER_VALIDATE_INT);
        $max = filter_var($data['max_remaining_months'] ?? null, FILTER_VALIDATE_INT);
        $percent = $data['discount_percent'] ?? null;

        if ($min === false || $min === null || $min < 0) {
            $errors['min_remaining_months'] = 'Rentang minimum sisa bulan harus bilangan bulat >= 0.';
        }

        if ($max === false || $max === null || $max < 0) {
            $errors['max_remaining_months'] = 'Rentang maksimum sisa bulan harus bilangan bulat >= 0.';
        }

        if ($errors === [] && $min > $max) {
            $errors['max_remaining_months'] = 'Rentang tidak valid: bulan minimum tidak boleh melebihi bulan maksimum.';
        }

        if (! is_numeric($percent) || (float) $percent < 0 || (float) $percent > 100) {
            $errors['discount_percent'] = 'Persentase diskon harus angka antara 0 dan 100.';
        }

        $from = ! empty($data['effective_from']) ? Carbon::parse($data['effective_from']) : null;
        $until = ! empty($data['effective_until']) ? Carbon::parse($data['effective_until']) : null;

        if ($from !== null && $until !== null && $until->lt($from)) {
            $errors['effective_until'] = 'Tanggal berakhir tidak boleh lebih awal dari tanggal mulai.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'min_remaining_months' => (int) $min,
            'max_remaining_months' => (int) $max,
            'discount_percent' => number_format((float) $percent, 2, '.', ''),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'effective_from' => $from?->toDateString(),
            'effective_until' => $until?->toDateString(),
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * Tolak policy aktif yang beririsan (rentang bulan DAN effective
     * period) dengan policy aktif lain — syarat pemilihan deterministik.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function guardOverlap(?int $excludeId, array $validated): void
    {
        if (! $validated['is_active']) {
            return; // Policy non-aktif tidak ikut selection, boleh beririsan.
        }

        $conflict = ShmSettlementDiscountPolicy::query()
            ->when($excludeId !== null, fn ($query) => $query->where('id', '!=', $excludeId))
            ->where('is_active', true)
            ->where('min_remaining_months', '<=', $validated['max_remaining_months'])
            ->where('max_remaining_months', '>=', $validated['min_remaining_months'])
            ->where(function ($query) use ($validated): void {
                $until = $validated['effective_until'];
                if ($until === null) {
                    $query->whereRaw('1 = 1'); // effective tanpa batas atas → selalu irisan.
                } else {
                    $query->whereNull('effective_from')
                        ->orWhere('effective_from', '<=', $until);
                }
            })
            ->where(function ($query) use ($validated): void {
                $from = $validated['effective_from'];
                if ($from === null) {
                    $query->whereRaw('1 = 1'); // effective tanpa batas bawah → selalu irisan.
                } else {
                    $query->whereNull('effective_until')
                        ->orWhere('effective_until', '>=', $from);
                }
            })
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'min_remaining_months' => 'Ada policy AKTIF lain dengan rentang sisa bulan dan periode efektif yang beririsan. Nonaktifkan atau ubah salah satunya terlebih dahulu.',
            ]);
        }
    }
}
