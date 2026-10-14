<?php

namespace App\Services\Shm;

use App\Models\ShmLoan;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Boundary kueri read-only Collection SHM (Ticket 90).
 *
 * Collection = monitoring/work queue atas pinjaman yang BENAR-BENAR memiliki
 * tunggakan aktual. SATU predikat tunggakan dipakai bersama oleh kelayakan
 * daftar (whereHas), agregat terpilih (selectSub), guard detail, dan ringkasan
 * — sehingga angka daftar dan detail tidak pernah berbeda dan tidak ada
 * sumber perhitungan finansial kedua.
 *
 * Seluruh penyaringan dan agregasi berjalan di DATABASE (server-side);
 * tidak pernah memuat seluruh pinjaman lalu menyaring di PHP/JS.
 *
 * Batas scope T90 — sengaja TIDAK ada di sini karena business rule-nya masih
 * PENDING BUSINESS CONFIRMATION (bukan kelalaian):
 * - tanpa penalty, grace period, collection stage/score/priority;
 * - tanpa collector, cadence, escalation, kunjungan, notification;
 * - tanpa pembayaran/settlement baru dan tanpa perubahan status pinjaman.
 */
class ShmCollectionQueryService
{
    /**
     * Terapkan predikat tunggakan pada kueri angsuran.
     *
     * Dua syarat, identik di seluruh pemakaian (SQL daftar, agregat terpilih,
     * guard detail, ringkasan PHP):
     * 1. due_date < hari ini (timezone aplikasi) — TANPA grace period;
     * 2. outstanding aktual > 0: sisa pokok + sisa bunga menurut kolom split
     *    paid hasil alokasi (principal_amount − paid_principal_amount) +
     *    (interest_amount − paid_interest_amount). Formula IDENTIK dengan
     *    helper model outstandingPrincipal()/outstandingInterest(); angsuran
     *    yang sudah lunas tidak pernah dihitung tunggakan.
     *
     * @param  Builder|QueryBuilder  $query  kueri angsuran (tanpa alias tabel)
     * @return Builder|QueryBuilder query yang sama (fluent) agar dipakai langsung sebagai closure whereHas
     */
    public function applyOverdueCondition($query, ?Carbon $today = null)
    {
        $today ??= today();

        // due_date < hari ini — kolom date, dibandingkan sebagai tanggal ISO.
        $query->whereRaw('due_date < ?', [$today->toDateString()]);

        // Outstanding aktual dari split paid hasil payment allocation.
        $query->whereRaw(
            '(principal_amount - paid_principal_amount)'
            .' + (interest_amount - paid_interest_amount) > 0'
        );

        return $query;
    }

    /**
     * Query kandidat Collection: active + sudah cair + punya tunggakan.
     *
     * Predikat kelayakan (status, disbursed_at, EXISTS tunggakan) dan agregat
     * per pinjaman ditanam di database; filter produk/search/rentang hari
     * dikirim controller. Ownership TIDAK disentuh di sini — diterapkan
     * controller mengikuti pola SHM existing (shm.view_all).
     *
     * @param  array{search?: string|null, product_id?: int|null, min_days_overdue?: int|null, max_days_overdue?: int|null}  $filters
     * @return Builder<ShmLoan>
     */
    public function candidates(array $filters): Builder
    {
        $today = today();

        $query = ShmLoan::query()
            ->with([
                'customer:id,customer_number,name,nik',
                'product:id,code,name',
            ])
            // Kelayakan: pinjaman berjalan, sudah dicairkan.
            ->where('status', ShmLoan::STATUS_ACTIVE)
            ->whereNotNull('disbursed_at')
            // Pencarian server-side memakai scope existing (no pinjaman,
            // nasabah, produk, tujuan) — tidak ada filtering client-side.
            ->search($filters['search'] ?? null);

        if (! empty($filters['product_id'])) {
            $query->where('shm_loan_product_id', (int) $filters['product_id']);
        }

        // Kelayakan: minimal satu angsuran overdue dengan outstanding > 0.
        // Pinjaman dengan seluruh tunggakan LUNAS, closed, maupun belum cair
        // tidak pernah lolos di sini.
        $query->whereHas(
            'installments',
            fn (Builder $installments): Builder => $this->applyOverdueCondition($installments, $today),
        );

        // Rentang days overdue = terjemahan mekanis dari metrik yang tampil
        // (hari ini − jatuh tempo overdue TERAWAL); bukan aturan bisnis baru:
        // - min ≥ N  ⟺ ada angsuran tunggakan berumur ≥ N hari;
        // - max ≤ M  ⟺ TIDAK ADA angsuran tunggakan berumur > M hari.
        if (isset($filters['min_days_overdue']) && $filters['min_days_overdue'] !== null) {
            $cutoff = $today->copy()->subDays($filters['min_days_overdue'])->toDateString();

            $query->whereHas('installments', function (Builder $installments) use ($cutoff, $today): void {
                $this->applyOverdueCondition($installments, $today);
                $installments->where('due_date', '<=', $cutoff);
            });
        }

        if (isset($filters['max_days_overdue']) && $filters['max_days_overdue'] !== null) {
            $cutoff = $today->copy()->subDays($filters['max_days_overdue'])->toDateString();

            $query->whereDoesntHave('installments', function (Builder $installments) use ($cutoff, $today): void {
                $this->applyOverdueCondition($installments, $today);
                $installments->where('due_date', '<', $cutoff);
            });
        }

        // Agregat tunggakan per pinjaman — subquery terkorelasi dengan
        // predikat SAMA (applyOverdueCondition), bukan agregasi PHP.
        $query
            ->select('shm_loans.*')
            ->selectSub($this->overdueAggregate('min(due_date)', $today), 'earliest_overdue_due_date')
            ->selectSub($this->overdueAggregate('count(*)', $today), 'overdue_installments_count')
            ->selectSub($this->overdueAggregate('sum(principal_amount - paid_principal_amount)', $today), 'outstanding_principal_sum')
            ->selectSub($this->overdueAggregate('sum(interest_amount - paid_interest_amount)', $today), 'outstanding_interest_sum')
            ->selectSub($this->lastPaymentAggregate(), 'last_payment_date');

        return $query;
    }

    /**
     * Apakah satu pinjaman memenuhi kelayakan Collection (guard detail).
     *
     * Kondisinya identik dengan kelayakan daftar: detail hanya terbuka untuk
     * kandidat overdue, sehingga closed/undisbursed/lunas tidak punya
     * halaman monitoring Collection.
     */
    public function isCandidate(ShmLoan $loan): bool
    {
        return ShmLoan::query()
            ->whereKey($loan->getKey())
            ->where('status', ShmLoan::STATUS_ACTIVE)
            ->whereNotNull('disbursed_at')
            ->whereHas(
                'installments',
                fn (Builder $installments): Builder => $this->applyOverdueCondition($installments),
            )
            ->exists();
    }

    /**
     * Apakah satu angsuran merupakan tunggakan (predikat PHP, identik SQL).
     *
     * Dipakai ringkasan dan presentation state detail — tanpa method ini
     * controller akan menulis ulang formula yang sama.
     */
    public function isOverdueInstallment(ShmLoanInstallment $installment, ?Carbon $today = null): bool
    {
        $today ??= today();

        if ($installment->due_date === null) {
            return false;
        }

        if ($installment->due_date->toDateString() >= $today->toDateString()) {
            return false;
        }

        return ((float) $installment->principal_amount - (float) $installment->paid_principal_amount)
            + ((float) $installment->interest_amount - (float) $installment->paid_interest_amount) > 0;
    }

    /**
     * Ringkasan tunggakan satu pinjaman dari angsuran aktual.
     *
     * Predikat dan formula IDENTIK dengan agregat daftar (helper model
     * outstandingPrincipal()/outstandingInterest()), sehingga detail tidak
     * pernah menampilkan angka berbeda dari daftar. TANPA penalty/grace
     * period — keduanya masih PENDING.
     *
     * @param  Collection<int, ShmLoanInstallment>  $installments  seluruh angsuran pinjaman (sudah dimuat, tanpa N+1)
     * @return array{earliest_overdue_due_date: string|null, days_overdue: int, installments_count: int, outstanding_principal: float, outstanding_interest: float, total_outstanding: float}
     */
    public function overdueSummary(Collection $installments): array
    {
        $today = today();

        $overdue = $installments
            ->filter(fn (ShmLoanInstallment $installment): bool => $this->isOverdueInstallment($installment, $today))
            ->sortBy(fn (ShmLoanInstallment $installment): string => $installment->due_date->toDateString())
            ->values();

        if ($overdue->isEmpty()) {
            return [
                'earliest_overdue_due_date' => null,
                'days_overdue' => 0,
                'installments_count' => 0,
                'outstanding_principal' => 0.0,
                'outstanding_interest' => 0.0,
                'total_outstanding' => 0.0,
            ];
        }

        $earliest = $overdue->first()->due_date->toDateString();
        $principal = (float) $overdue->sum(fn (ShmLoanInstallment $installment): string => $installment->outstandingPrincipal());
        $interest = (float) $overdue->sum(fn (ShmLoanInstallment $installment): string => $installment->outstandingInterest());

        return [
            'earliest_overdue_due_date' => $earliest,
            // Days overdue = hari ini − jatuh tempo overdue terawal (tanpa
            // penalty); absolute karena predikat menjamin earliest < today.
            'days_overdue' => (int) Carbon::parse($earliest)->startOfDay()
                ->diffInDays($today->copy()->startOfDay(), true),
            'installments_count' => $overdue->count(),
            'outstanding_principal' => round($principal, 2),
            'outstanding_interest' => round($interest, 2),
            'total_outstanding' => round($principal + $interest, 2),
        ];
    }

    /**
     * Subquery agregat tunggakan terkorelasi per pinjaman.
     *
     * Predikat di dalam subquery berasal dari applyOverdueCondition —
     * kelayakan dan angka tidak dapat menyimpang satu sama lain.
     */
    private function overdueAggregate(string $expression, Carbon $today): QueryBuilder
    {
        $aggregate = DB::table('shm_loan_installments')
            ->selectRaw($expression)
            ->whereColumn('shm_loan_id', 'shm_loans.id');

        $this->applyOverdueCondition($aggregate, $today);

        return $aggregate;
    }

    /**
     * Subquery tanggal pembayaran terakhir — hanya payment berstatus posted
     * (pembayaran yang dibatalkan bukan riwayat pembayaran valid).
     */
    private function lastPaymentAggregate(): QueryBuilder
    {
        return DB::table('shm_loan_payments')
            ->selectRaw('max(payment_date)')
            ->whereColumn('shm_loan_id', 'shm_loans.id')
            ->where('status', ShmLoanPayment::STATUS_POSTED);
    }
}
