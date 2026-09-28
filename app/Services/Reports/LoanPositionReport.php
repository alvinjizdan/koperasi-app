<?php

namespace App\Services\Reports;

use App\Models\LoanBalanceSnapshot;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Laporan Posisi Pinjaman per Tanggal (Ticket 26).
 *
 * Sumber data = snapshot tersimpan (`loan_balance_snapshots`) — TIDAK ada
 * perhitungan ulang dari konfigurasi bunga/penalti saat ini dan TIDAK ada
 * fallback ke saldo pinjaman hari ini. Laporan hanya menampilkan snapshot
 * yang benar-benar tersedia; ketersediaan histori dilaporkan eksplisit.
 *
 * Read-only murni: tidak ada mutasi finansial, tidak memicu akrual penalti.
 */
class LoanPositionReport
{
    /**
     * Daftar snapshot per tanggal posisi (terbaru per pinjaman), dengan nama
     * anggota untuk tampilan. Eager loading mencegah N+1.
     *
     * @param  array{as_of_date: ?string, search: ?string}  $filters
     * @return LengthAwarePaginator<int, LoanBalanceSnapshot>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $asOfDate = $filters['as_of_date'] ?? ReportPeriod::todayDate();
        $search = trim((string) ($filters['search'] ?? ''));

        return LoanBalanceSnapshot::query()
            ->where('as_of_date', $asOfDate)
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.mb_strtolower($search).'%';

                $q->whereHas('loan', function ($loan) use ($term) {
                    $loan->whereRaw('LOWER(loan_number) LIKE ?', [$term])
                        ->orWhereHas('member', function ($member) use ($term) {
                            $member->whereRaw('LOWER(full_name) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(member_number) LIKE ?', [$term]);
                        });
                });
            })
            ->with(['loan:id,loan_number,member_id', 'loan.member:id,member_number,full_name'])
            ->orderBy('loan_id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Ringkasan agregat HANYA dari snapshot yang tampil pada tanggal posisi
     * yang diminta (bukan campuran tanggal; bukan saldo hari ini).
     *
     * @param  array{as_of_date: ?string, search: ?string}  $filters
     * @return array{as_of_date: string, snapshots_count: int, total_outstanding_principal: float, total_outstanding_interest: float, total_outstanding_penalty: float, total_owed: float, note: string}
     */
    public function summary(array $filters): array
    {
        $asOfDate = $filters['as_of_date'] ?? ReportPeriod::todayDate();
        $search = trim((string) ($filters['search'] ?? ''));

        $base = LoanBalanceSnapshot::query()
            ->where('as_of_date', $asOfDate)
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.mb_strtolower($search).'%';

                $q->whereHas('loan', function ($loan) use ($term) {
                    $loan->whereRaw('LOWER(loan_number) LIKE ?', [$term])
                        ->orWhereHas('member', function ($member) use ($term) {
                            $member->whereRaw('LOWER(full_name) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(member_number) LIKE ?', [$term]);
                        });
                });
            });

        return [
            'as_of_date' => $asOfDate,
            'snapshots_count' => (clone $base)->count(),
            'total_outstanding_principal' => round((float) (clone $base)->sum('outstanding_principal'), 2),
            'total_outstanding_interest' => round((float) (clone $base)->sum('outstanding_interest'), 2),
            'total_outstanding_penalty' => round((float) (clone $base)->sum('outstanding_penalty'), 2),
            'total_owed' => round((float) (clone $base)->sum('total_owed'), 2),
            'note' => 'Ringkasan hanya menjumlahkan snapshot pada tanggal posisi yang sama.',
        ];
    }

    /**
     * Header kolom CSV (Ticket 27). Nilai berasal dari snapshot tersimpan.
     */
    public function csvHeaders(): array
    {
        return [
            'Nomor Pinjaman',
            'Nomor Anggota',
            'Nama Anggota',
            'Tanggal Posisi',
            'Waktu Snapshot Dibuat',
            'Pokok Terutang',
            'Bunga Terutang',
            'Penalti Terutang',
            'Total Kewajiban',
            'Total Pembayaran',
            'Jumlah Jadwal',
            'Jadwal Belum Lunas',
            'Status Histori Terverifikasi',
            'Status Pinjaman',
        ];
    }

    /**
     * Stream baris CSV untuk tanggal posisi (dan pencarian) yang aktif.
     * Urutan deterministik (loan_id); null yang tidak tersedia ditulis '-' —
     * tidak ada nilai nol palsu untuk data yang tidak tersedia.
     *
     * @param  array{as_of_date: ?string, search: ?string}  $filters
     * @return Generator<int, array<int, string|float|int|null>>
     */
    public function stream(array $filters): Generator
    {
        $asOfDate = $filters['as_of_date'] ?? ReportPeriod::todayDate();
        $search = trim((string) ($filters['search'] ?? ''));

        $snapshots = LoanBalanceSnapshot::query()
            ->where('as_of_date', $asOfDate)
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.mb_strtolower($search).'%';

                $q->whereHas('loan', function ($loan) use ($term) {
                    $loan->whereRaw('LOWER(loan_number) LIKE ?', [$term])
                        ->orWhereHas('member', function ($member) use ($term) {
                            $member->whereRaw('LOWER(full_name) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(member_number) LIKE ?', [$term]);
                        });
                });
            })
            ->with(['loan:id,loan_number,member_id', 'loan.member:id,member_number,full_name'])
            ->orderBy('loan_id')
            ->lazy();

        foreach ($snapshots as $snapshot) {
            yield [
                $snapshot->loan?->loan_number ?? '-',
                $snapshot->loan?->member?->member_number ?? '-',
                $snapshot->loan?->member?->full_name ?? '-',
                (string) $snapshot->as_of_date,
                $snapshot->created_at->format('Y-m-d H:i:s'),
                (float) $snapshot->outstanding_principal,
                (float) $snapshot->outstanding_interest,
                (float) $snapshot->outstanding_penalty,
                (float) $snapshot->total_owed,
                $snapshot->total_paid !== null ? (float) $snapshot->total_paid : '-',
                $snapshot->schedule_count,
                $snapshot->unpaid_schedule_count,
                $snapshot->created_at->toDateString() <= (string) $snapshot->as_of_date ? 'Terverifikasi' : 'Tidak Terverifikasi',
                $snapshot->status,
            ];
        }
    }

    /**
     * Metadata ketersediaan histori untuk tanggal posisi yang diminta:
     * berapa snapshot tersedia, dan snapshot terdekat jika tanggal diminta kosong.
     *
     * @return array{as_of_date: string, snapshots_on_date: int, nearest_as_of_date: ?string, first_ever_as_of_date: ?string}
     */
    public function availability(string $asOfDate): array
    {
        $onDate = LoanBalanceSnapshot::query()->where('as_of_date', $asOfDate)->count();

        $nearest = $onDate > 0
            ? null
            : LoanBalanceSnapshot::query()->orderByDesc('as_of_date')->value('as_of_date');

        $firstEver = LoanBalanceSnapshot::query()->orderBy('as_of_date')->value('as_of_date');

        return [
            'as_of_date' => $asOfDate,
            'snapshots_on_date' => $onDate,
            // Snapshot terdekat hanya INFORMASI — tidak pernah ditampilkan sebagai
            // nilai tanggal yang diminta.
            'nearest_as_of_date' => $nearest !== null ? (string) $nearest : null,
            // Batas bawah histori: sebelum tanggal ini tidak ada data sama sekali.
            'first_ever_as_of_date' => $firstEver !== null ? (string) $firstEver : null,
        ];
    }
}
