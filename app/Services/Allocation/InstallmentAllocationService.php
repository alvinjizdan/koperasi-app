<?php

namespace App\Services\Allocation;

use App\Models\InstallmentAllocation;
use App\Models\InstallmentPayment;
use App\Models\InstallmentSchedule;
use Illuminate\Support\Collection;

/**
 * Alokasi pembayaran angsuran ke komponen kewajiban (Ticket 15 bagian E).
 *
 * - Urutan komponen dikonfigurasi di config/business-rules.php ('allocation.order'),
 *   default provisional: penalty → interest → principal. Bukan kebijakan final koperasi.
 * - Setiap alokasi dicatat per (payment, schedule, component) agar dapat
 *   ditelusuri dan direkonsiliasi (E.5).
 */
class InstallmentAllocationService
{
    /**
     * Alokasikan nominal ke angsuran yang belum lunas.
     *
     * @param  Collection<int, InstallmentSchedule>  $schedules  Sudah terurut installment_number
     * @return array{remaining: float, total_allocated: float, by_component: array<string, float>}
     */
    public function allocate(float $amount, Collection $schedules, int $paymentId, int $decimals = 2): array
    {
        $order = (array) config('business-rules.allocation.order', ['penalty', 'interest', 'principal']);
        $left = round($amount, $decimals);
        $byComponent = array_fill_keys($order, 0.0);

        foreach ($schedules as $schedule) {
            if ($left <= 0.0) {
                break;
            }

            foreach ($order as $component) {
                if ($left <= 0.0) {
                    break;
                }

                $owed = $this->owedForComponent($schedule, $component);
                if ($owed <= 0.0) {
                    continue;
                }

                $applied = min($left, $owed);
                $applied = round($applied, $decimals);
                if ($applied <= 0.0) {
                    continue;
                }

                $this->applyToSchedule($schedule, $component, $applied);

                InstallmentAllocation::create([
                    'installment_payment_id' => $paymentId,
                    'installment_schedule_id' => $schedule->id,
                    'component' => $component,
                    'amount' => $applied,
                ]);

                $byComponent[$component] = round(($byComponent[$component] ?? 0.0) + $applied, $decimals);
                $left = round($left - $applied, $decimals);
            }

            // Jadwal lunas bila seluruh komponennya nol.
            if ($this->totalOwed($schedule) <= 0.0) {
                $schedule->status = 'PAID';
            }
        }

        return [
            'remaining' => $left,
            'total_allocated' => round($amount - $left, $decimals),
            'by_component' => $byComponent,
        ];
    }

    /**
     * Batalkan seluruh efek alokasi dari satu pembayaran (Ticket 21, E).
     *
     * Terbayar per komponen jadwal dikembalikan sebesar alokasi yang tercatat;
     * status jadwal dihitung ulang. Pembayaran dan baris alokasi asal TIDAK
     * dihapus/diubah — riwayat finansial tetap utuh. Aman dipanggil ulang:
     * tanpa alokasi, tidak ada perubahan.
     */
    public function reverseAllocations(InstallmentPayment $payment): void
    {
        $allocations = InstallmentAllocation::query()
            ->where('installment_payment_id', $payment->id)
            ->with('schedule')
            ->get();

        foreach ($allocations as $allocation) {
            $schedule = $allocation->schedule;
            if ($schedule === null) {
                continue;
            }

            match ($allocation->component) {
                'penalty' => $schedule->penalty_paid = (string) round(max(0.0, (float) $schedule->penalty_paid - (float) $allocation->amount), 2),
                'interest' => $schedule->interest_paid = (string) round(max(0.0, (float) ($schedule->interest_paid ?? 0.0) - (float) $allocation->amount), 2),
                'principal' => $schedule->paid_amount = (string) round(max(0.0, (float) $schedule->paid_amount - (float) $allocation->amount), 2),
            };

            $schedule->remaining_amount = (string) max(
                0.0,
                round((float) $schedule->total_amount - (float) $schedule->paid_amount - (float) ($schedule->interest_paid ?? 0.0), 2)
            );

            $this->refreshScheduleStatus($schedule);
            $schedule->save();
        }
    }

    /** Hitung ulang status jadwal dari sisa kewajiban (dipakai normal & reversal). */
    private function refreshScheduleStatus(InstallmentSchedule $schedule): void
    {
        if ($this->totalOwed($schedule) <= 0.0) {
            $schedule->status = 'PAID';

            return;
        }

        if ($schedule->status === 'PAID') {
            $schedule->status = 'PARTIALLY_PAID';
        }
        // Status DUE/OVERDUE dipertahankan; updateScheduleStatuses() akan
        // memperbarui berdasarkan tanggal pada pembacaan berikutnya.
    }

    /** Kewajiban yang masih belum dibayar untuk satu komponen. */
    public function owedForComponent(InstallmentSchedule $schedule, string $component): float
    {
        return match ($component) {
            'penalty' => round(max(0.0, (float) $schedule->penalty_accrued - (float) $schedule->penalty_paid), 2),
            'interest' => round(max(0.0, (float) $schedule->interest_amount - $this->paidInterest($schedule)), 2),
            'principal' => round(max(0.0, (float) $schedule->principal_amount - $this->paidPrincipal($schedule)), 2),
            default => 0.0,
        };
    }

    /** Total kewajiban belum dibayar untuk seluruh komponen. */
    public function totalOwed(InstallmentSchedule $schedule): float
    {
        return round(
            $this->owedForComponent($schedule, 'penalty')
            + $this->owedForComponent($schedule, 'interest')
            + $this->owedForComponent($schedule, 'principal'),
            2
        );
    }

    private function applyToSchedule(InstallmentSchedule $schedule, string $component, float $applied): void
    {
        match ($component) {
            'penalty' => $schedule->penalty_paid = (string) round((float) $schedule->penalty_paid + $applied, 2),
            'interest' => $schedule->interest_paid = (string) round($this->paidInterest($schedule) + $applied, 2),
            'principal' => $schedule->paid_amount = (string) round($this->paidPrincipal($schedule) + $applied, 2),
        };

        // remaining_amount tetap berarti sisa pokok+bunga komponen utama
        // agar kompatibel dengan pembacaan lama (paid/remaining pokok-bunga).
        $schedule->remaining_amount = (string) max(
            0.0,
            round((float) $schedule->total_amount - $this->paidPrincipal($schedule) - $this->paidInterest($schedule), 2)
        );

        if ($this->totalOwed($schedule) <= 0.0) {
            $schedule->status = 'PAID';
        } elseif (in_array($schedule->status, ['PAID'], true)) {
            $schedule->status = 'PARTIALLY_PAID';
        } elseif (! in_array($schedule->status, ['PARTIALLY_PAID', 'OVERDUE', 'DUE'], true)) {
            $schedule->status = 'PARTIALLY_PAID';
        }
    }

    private function paidPrincipal(InstallmentSchedule $schedule): float
    {
        return (float) $schedule->paid_amount;
    }

    private function paidInterest(InstallmentSchedule $schedule): float
    {
        return (float) ($schedule->interest_paid ?? 0.0);
    }
}
