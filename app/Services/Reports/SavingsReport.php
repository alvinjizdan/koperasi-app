<?php

namespace App\Services\Reports;

use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Laporan simpanan.
 *
 * Saldo saat ini selalu diambil dari kolom saldo resmi rekening
 * (savings_accounts.balance), sedangkan mutasi periode dihitung hanya dari
 * transaksi simpanan berstatus completed. Keduanya tidak pernah dijumlahkan
 * menjadi satu angka. Jasa/bunga simpanan tidak dihitung karena rumusnya belum
 * dikonfirmasi.
 */
class SavingsReport
{
    public function __construct(private readonly ReportPeriod $period) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->query($filters)
            ->withSum(['transactions as period_deposits' => $this->periodConstraint('deposit')], 'amount')
            ->withSum(['transactions as period_withdrawals' => $this->periodConstraint('withdrawal')], 'amount')
            ->orderBy('account_number')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (SavingsAccount $account) => $this->toRow($account));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $accountIds = $this->query($filters)->select('id');

        $deposits = (float) SavingsTransaction::query()
            ->whereIn('savings_account_id', $accountIds)
            ->where('status', 'completed')
            ->where('type', 'deposit')
            ->whereBetween('transaction_date', $this->period->dateRange())
            ->sum('amount');

        $withdrawals = (float) SavingsTransaction::query()
            ->whereIn('savings_account_id', $accountIds)
            ->where('status', 'completed')
            ->where('type', 'withdrawal')
            ->whereBetween('transaction_date', $this->period->dateRange())
            ->sum('amount');

        return [
            'accounts' => (clone $accountIds)->count(),
            'active_accounts' => (clone $accountIds)->where('status', 'active')->count(),
            'total_balance' => round((float) ($this->query($filters)->sum('balance')), 2),
            'period_deposits' => round($deposits, 2),
            'period_withdrawals' => round($withdrawals, 2),
            'period_net' => round($deposits - $withdrawals, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Generator<int, array<int, string|float>>
     */
    public function stream(array $filters): Generator
    {
        // Ringkasan saldo dihitung dari rekening, mutasi periode dari ledger transaksi.
        $query = $this->query($filters)
            ->withSum(['transactions as period_deposits' => $this->periodConstraint('deposit')], 'amount')
            ->withSum(['transactions as period_withdrawals' => $this->periodConstraint('withdrawal')], 'amount');

        foreach ($query->orderBy('account_number')->lazy() as $account) {
            $row = $this->toRow($account);

            yield [
                $row['account_number'],
                $row['member_number'],
                $row['member_name'],
                $row['product_name'],
                $row['opened_at'] ?? '-',
                $row['status_label'],
                $row['balance'],
                $row['period_deposits'],
                $row['period_withdrawals'],
            ];
        }
    }

    /**
     * @return array<int, string>
     */
    public function csvHeaders(): array
    {
        return [
            'Nomor Rekening',
            'Nomor Anggota',
            'Nama Anggota',
            'Produk Simpanan',
            'Tanggal Buka',
            'Status Rekening',
            'Saldo Saat Ini',
            'Setoran Periode',
            'Penarikan Periode',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return SavingsAccount::query()
            ->with([
                'member:id,member_number,full_name',
                'savingProduct:id,code,name',
            ])
            ->when(
                ! empty($filters['product_id']),
                fn (Builder $q) => $q->where('saving_product_id', $filters['product_id'])
            )
            ->when(
                ($filters['account_status'] ?? 'all') !== 'all',
                fn (Builder $q) => $q->where('status', $filters['account_status'])
            )
            ->when($search !== '', function (Builder $q) use ($search) {
                $term = '%'.mb_strtolower($search).'%';

                $q->where(function (Builder $inner) use ($term) {
                    $inner->whereRaw('LOWER(account_number) LIKE ?', [$term])
                        ->orWhereHas('member', function (Builder $member) use ($term) {
                            $member->whereRaw('LOWER(full_name) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(member_number) LIKE ?', [$term]);
                        });
                });
            });
    }

    /**
     * Batasan transaksi sah pada periode laporan untuk jenis transaksi tertentu.
     */
    private function periodConstraint(string $type): \Closure
    {
        return fn ($query) => $query
            ->where('type', $type)
            ->where('status', 'completed')
            ->whereBetween('transaction_date', $this->period->dateRange());
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(SavingsAccount $account): array
    {
        $deposits = round((float) ($account->period_deposits ?? 0), 2);
        $withdrawals = round((float) ($account->period_withdrawals ?? 0), 2);

        return [
            'id' => $account->id,
            'account_number' => $account->account_number,
            'member_number' => $account->member?->member_number,
            'member_name' => $account->member?->full_name,
            'product_name' => $account->savingProduct?->name,
            'opened_at' => $account->opened_at?->toDateString(),
            'status' => $account->status,
            'status_label' => $account->status === 'active' ? 'Aktif' : 'Ditutup',
            'balance' => round((float) $account->balance, 2),
            'period_deposits' => $deposits,
            'period_withdrawals' => $withdrawals,
            'url' => route('simpanan-rekening.show', $account->id),
        ];
    }
}
