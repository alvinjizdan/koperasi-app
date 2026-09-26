<?php

namespace App\Services\Reports;

use App\Models\Member;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Laporan anggota.
 *
 * Data pribadi (nomor identitas dan nomor telepon) ditampilkan dalam bentuk
 * tersamarkan dan tidak disertakan pada ekspor CSV karena lapisan izin berbasis
 * role belum tersedia di aplikasi ini.
 */
class MemberReport
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->query($filters)
            ->orderBy('member_number')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Member $member) => $this->toRow($member));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $query = $this->query($filters);

        return [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('status', 'active')->count(),
            'inactive' => (clone $query)->where('status', 'inactive')->count(),
            'with_savings_account' => (clone $query)->has('savingsAccounts')->count(),
            'with_active_loan' => (clone $query)->whereHas('loans', fn ($q) => $q->whereIn('status', ['ACTIVE', 'DISBURSED']))->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Generator<int, array<int, string|int>>
     */
    public function stream(array $filters): Generator
    {
        $query = $this->query($filters);

        foreach ($query->orderBy('member_number')->lazy() as $member) {
            yield [
                $member->member_number,
                $member->full_name,
                $member->joined_at?->toDateString() ?? '-',
                $member->status === 'active' ? 'Aktif' : 'Nonaktif',
                (int) $member->savings_accounts_count,
                (int) $member->active_loans_count,
            ];
        }
    }

    /**
     * @return array<int, string>
     */
    public function csvHeaders(): array
    {
        return ['Nomor Anggota', 'Nama Anggota', 'Tanggal Bergabung', 'Status', 'Jumlah Rekening Simpanan', 'Jumlah Pinjaman Aktif'];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return Member::query()
            ->withCount(['savingsAccounts as savings_accounts_count'])
            ->withCount(['loans as active_loans_count' => fn ($q) => $q->whereIn('status', ['ACTIVE', 'DISBURSED'])])
            ->when(
                ($filters['status'] ?? 'all') !== 'all',
                fn ($q) => $q->where('status', $filters['status'])
            )
            ->when(
                ! empty($filters['date_from']),
                fn ($q) => $q->whereDate('joined_at', '>=', $filters['date_from'])
            )
            ->when(
                ! empty($filters['date_to']),
                fn ($q) => $q->whereDate('joined_at', '<=', $filters['date_to'])
            )
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.mb_strtolower($search).'%';

                $q->where(function ($inner) use ($term) {
                    $inner->whereRaw('LOWER(member_number) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(full_name) LIKE ?', [$term]);
                });
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Member $member): array
    {
        return [
            'id' => $member->id,
            'member_number' => $member->member_number,
            'full_name' => $member->full_name,
            'identity_number_masked' => $this->mask($member->identity_number),
            'phone_masked' => $this->mask($member->phone, 4),
            'joined_at' => $member->joined_at?->toDateString(),
            'status' => $member->status,
            'savings_accounts_count' => (int) $member->savings_accounts_count,
            'active_loans_count' => (int) $member->active_loans_count,
            'url' => route('anggota.show', $member->id),
        ];
    }

    /**
     * Tampilkan hanya beberapa karakter terakhir dari data pribadi.
     */
    private function mask(?string $value, int $visible = 4): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $length = strlen($value);

        if ($length <= $visible) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', $length - $visible).substr($value, -$visible);
    }
}
