<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Models\ShmLoan;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanSettlement;
use App\Support\AccountingContext;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service Laporan Jurnal Harian BSM Makro (AccountingContext::SHM).
 *
 * Sifat: READ-ONLY.
 * Tidak pernah mengubah (create, update, delete) journal, journal lines, maupun ledger.
 * Mengikuti struktur kolom referensi: contoh5.xlsx (sheet Jurnal_Harian).
 */
class ShmDailyJournalService
{
    /**
     * Build base query untuk Jurnal Harian SHM (Hanya STATUS_POSTED dan AccountingContext::SHM).
     *
     * @param array{
     *     start_date?: ?string,
     *     end_date?: ?string,
     *     account_id?: ?int,
     *     search?: ?string,
     * } $filters
     */
    public function buildQuery(array $filters): Builder
    {
        $startDate = $filters['start_date'] ?? null;
        $endDate = $filters['end_date'] ?? null;
        $accountId = ! empty($filters['account_id']) ? (int) $filters['account_id'] : null;
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;

        $query = JournalEntry::query()
            ->shm()
            ->where('status', JournalEntry::STATUS_POSTED)
            ->with(['lines.account']);

        if ($startDate) {
            $query->whereDate('transaction_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('transaction_date', '<=', $endDate);
        }

        if ($accountId) {
            $query->whereHas('lines', function (Builder $q) use ($accountId): void {
                $q->where('account_id', $accountId);
            });
        }

        if ($search !== null && $search !== '') {
            $term = '%'.strtolower($search).'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->whereRaw('LOWER(journal_number) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$term])
                    ->orWhereHas('lines', function (Builder $lq) use ($term): void {
                        $lq->whereRaw('LOWER(description) LIKE ?', [$term])
                            ->orWhereHas('account', function (Builder $aq) use ($term): void {
                                $aq->whereRaw('LOWER(code) LIKE ?', [$term])
                                    ->orWhereRaw('LOWER(name) LIKE ?', [$term]);
                            });
                    });
            });
        }

        return $query->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');
    }

    /**
     * Hitung total debit dan kredit atas seluruh hasil query yang terfilter.
     *
     * @param array{
     *     start_date?: ?string,
     *     end_date?: ?string,
     *     account_id?: ?int,
     *     search?: ?string,
     * } $filters
     * @return array{total_debit: float, total_credit: float, is_balanced: bool}
     */
    public function calculateTotals(array $filters): array
    {
        $query = $this->buildQuery($filters);

        // Agregasi lewat journal_lines yang berelasi dengan journal_entries terfilter
        $totals = DB::table('journal_lines')
            ->whereIn('journal_entry_id', $query->clone()->select('id'))
            ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->first();

        $totalDebit = round((float) ($totals->total_debit ?? 0), 2);
        $totalCredit = round((float) ($totals->total_credit ?? 0), 2);

        return [
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => $totalDebit === $totalCredit,
        ];
    }

    /**
     * Ambil data jurnal berpaginasi dengan customer resolved.
     *
     * @param array{
     *     start_date?: ?string,
     *     end_date?: ?string,
     *     account_id?: ?int,
     *     search?: ?string,
     * } $filters
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $paginator = $this->buildQuery($filters)->paginate($perPage);

        // Eager load source relationships untuk customer resolution tanpa N+1
        $this->resolveSourcesForEntries($paginator->getCollection());

        // Transformasi entries ke struktur tampilan/group
        $transformedItems = $paginator->getCollection()->map(function (JournalEntry $entry) {
            return $this->transformEntry($entry);
        });

        $paginator->setCollection($transformedItems);

        return $paginator;
    }

    /**
     * Ambil seluruh entries terfilter untuk keperluan Export XLSX.
     *
     * @param array{
     *     start_date?: ?string,
     *     end_date?: ?string,
     *     account_id?: ?int,
     *     search?: ?string,
     * } $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function getForExport(array $filters): Collection
    {
        $entries = $this->buildQuery($filters)->get();

        $this->resolveSourcesForEntries($entries);

        return $entries->map(function (JournalEntry $entry) {
            return $this->transformEntry($entry);
        });
    }

    /**
     * Resolusi Customer, No. Anggota, dan No Rekening / Pinjaman dari source_type & source_id.
     *
     * @param  Collection<int, JournalEntry>  $entries
     */
    public function resolveSourcesForEntries(Collection $entries): void
    {
        $loanIds = [];
        $paymentIds = [];
        $settlementIds = [];

        foreach ($entries as $entry) {
            if ($entry->source_type === ShmLoan::class && $entry->source_id) {
                $loanIds[] = $entry->source_id;
            } elseif ($entry->source_type === ShmLoanPayment::class && $entry->source_id) {
                $paymentIds[] = $entry->source_id;
            } elseif ($entry->source_type === ShmLoanSettlement::class && $entry->source_id) {
                $settlementIds[] = $entry->source_id;
            }
        }

        $loans = ! empty($loanIds)
            ? ShmLoan::with(['customer.member'])->whereIn('id', array_unique($loanIds))->get()->keyBy('id')
            : collect();

        $payments = ! empty($paymentIds)
            ? ShmLoanPayment::with(['loan.customer.member'])->whereIn('id', array_unique($paymentIds))->get()->keyBy('id')
            : collect();

        $settlements = ! empty($settlementIds)
            ? ShmLoanSettlement::with(['loan.customer.member'])->whereIn('id', array_unique($settlementIds))->get()->keyBy('id')
            : collect();

        foreach ($entries as $entry) {
            $customerName = '-';
            $memberNumber = '-';
            $accountNumber = '-';
            $slipNumber = '-';

            if ($entry->source_type === ShmLoan::class && isset($loans[$entry->source_id])) {
                /** @var ShmLoan $loan */
                $loan = $loans[$entry->source_id];
                $customer = $loan->customer;
                $customerName = $customer?->name ?? '-';
                $memberNumber = $customer?->member?->member_number ?? $customer?->customer_number ?? '-';
                $accountNumber = $loan->loan_number;
                $slipNumber = $loan->loan_number;
            } elseif ($entry->source_type === ShmLoanPayment::class && isset($payments[$entry->source_id])) {
                /** @var ShmLoanPayment $payment */
                $payment = $payments[$entry->source_id];
                $loan = $payment->loan;
                $customer = $loan?->customer;
                $customerName = $customer?->name ?? '-';
                $memberNumber = $customer?->member?->member_number ?? $customer?->customer_number ?? '-';
                $accountNumber = $loan?->loan_number ?? '-';
                $slipNumber = $payment->payment_reference ?? '-';
            } elseif ($entry->source_type === ShmLoanSettlement::class && isset($settlements[$entry->source_id])) {
                /** @var ShmLoanSettlement $settlement */
                $settlement = $settlements[$entry->source_id];
                $loan = $settlement->loan;
                $customer = $loan?->customer;
                $customerName = $customer?->name ?? '-';
                $memberNumber = $customer?->member?->member_number ?? $customer?->customer_number ?? '-';
                $accountNumber = $loan?->loan_number ?? '-';
                $slipNumber = $settlement->settlement_reference ?? '-';
            }

            $entry->resolved_customer = [
                'name' => $customerName,
                'member_number' => $memberNumber,
                'account_number' => $accountNumber,
                'slip_number' => $slipNumber,
            ];
        }
    }

    /**
     * Transform satu JournalEntry beserta lines-nya.
     *
     * @return array<string, mixed>
     */
    public function transformEntry(JournalEntry $entry): array
    {
        $cust = $entry->resolved_customer ?? [
            'name' => '-',
            'member_number' => '-',
            'account_number' => '-',
            'slip_number' => '-',
        ];

        $lines = $entry->lines->map(function ($line) use ($entry) {
            $debit = (float) $line->debit;
            $credit = (float) $line->credit;

            return [
                'id' => $line->id,
                'account_code' => $line->account?->code ?? '-',
                'account_name' => $line->account?->name ?? '-',
                'description' => $line->description ?: $entry->description,
                'debit' => $debit > 0 ? $debit : null,
                'credit' => $credit > 0 ? $credit : null,
            ];
        })->values()->all();

        return [
            'id' => $entry->id,
            'voucher' => $entry->journal_number,
            'transaction_date' => $entry->transaction_date ? Carbon::parse($entry->transaction_date)->format('Y-m-d') : null,
            'formatted_date' => $entry->transaction_date ? Carbon::parse($entry->transaction_date)->format('d/m/Y') : null,
            'description' => $entry->description,
            'slip_number' => $cust['slip_number'],
            'customer_name' => $cust['name'],
            'member_number' => $cust['member_number'],
            'account_number' => $cust['account_number'],
            'lines' => $lines,
            'total_debit' => round((float) $entry->lines->sum('debit'), 2),
            'total_credit' => round((float) $entry->lines->sum('credit'), 2),
        ];
    }
}
