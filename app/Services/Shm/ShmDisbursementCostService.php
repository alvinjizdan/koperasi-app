<?php

namespace App\Services\Shm;

use InvalidArgumentException;

/**
 * Ticket 85 — Layanan kalkulasi biaya pencairan pinjaman SHM.
 *
 * Source of truth formula kalkulasi biaya pencairan sesuai kontrak T84:
 * 1. admin_fee         = 2% × principal_amount
 * 2. provision_fee     = 3% × principal_amount
 * 3. insurance_fee     = 1% × principal_amount
 * 4. notary_fee        = custom fillable non-negatif
 * 5. principal_savings = Rp100.000 fixed
 * 6. mandatory_savings = Rp25.000 × tenor_months
 * 7. total_deduction   = admin + provision + insurance + notary + principal_savings + mandatory_savings
 * 8. net_disbursement  = principal_amount - total_deduction
 *
 * Seluruh deduction dipotong pada saat pencairan awal (initial disbursement).
 * Original principal / plafond tetap utuh dan TIDAK diubah.
 * Net disbursement BUKAN principal baru.
 */
class ShmDisbursementCostService
{
    public const ADMIN_FEE_PERCENT = 2.0;

    public const PROVISION_FEE_PERCENT = 3.0;

    public const INSURANCE_FEE_PERCENT = 1.0;

    public const PRINCIPAL_SAVINGS_AMOUNT = 100000.0;

    public const MANDATORY_SAVINGS_MONTHLY = 25000.0;

    /**
     * Hitung rincian biaya pencairan dan dana bersih diterima.
     *
     * @return array{
     *     admin_fee: float,
     *     provision_fee: float,
     *     insurance_fee: float,
     *     notary_fee: float,
     *     principal_savings: float,
     *     mandatory_savings: float,
     *     total_deduction: float,
     *     net_disbursement: float
     * }
     */
    public function calculate(float $principal, int $tenorMonths, float $notaryFee = 0.0): array
    {
        if ($principal <= 0) {
            throw new InvalidArgumentException('Principal pinjaman harus lebih dari 0.');
        }

        if ($tenorMonths <= 0) {
            throw new InvalidArgumentException('Tenor pinjaman harus lebih dari 0 bulan.');
        }

        if ($notaryFee < 0) {
            throw new InvalidArgumentException('Biaya notaris tidak boleh negatif.');
        }

        $adminFee = round($principal * (self::ADMIN_FEE_PERCENT / 100.0), 2);
        $provisionFee = round($principal * (self::PROVISION_FEE_PERCENT / 100.0), 2);
        $insuranceFee = round($principal * (self::INSURANCE_FEE_PERCENT / 100.0), 2);
        $notaryFee = round($notaryFee, 2);
        $principalSavings = self::PRINCIPAL_SAVINGS_AMOUNT;
        $mandatorySavings = round(self::MANDATORY_SAVINGS_MONTHLY * $tenorMonths, 2);

        $totalDeduction = round(
            $adminFee + $provisionFee + $insuranceFee + $notaryFee + $principalSavings + $mandatorySavings,
            2
        );

        $netDisbursement = round($principal - $totalDeduction, 2);

        return [
            'admin_fee' => $adminFee,
            'provision_fee' => $provisionFee,
            'insurance_fee' => $insuranceFee,
            'notary_fee' => $notaryFee,
            'principal_savings' => $principalSavings,
            'mandatory_savings' => $mandatorySavings,
            'total_deduction' => $totalDeduction,
            'net_disbursement' => $netDisbursement,
        ];
    }
}
