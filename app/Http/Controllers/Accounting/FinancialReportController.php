<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\FinancialReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman laporan keuangan (Ticket 19): neraca saldo, neraca, laba rugi,
 * dan status arus kas. Seluruh angka dihitung di backend oleh
 * FinancialReportService dari jurnal POSTED.
 */
class FinancialReportController extends Controller
{
    public function __construct(private FinancialReportService $reports) {}

    public function trialBalance(Request $request)
    {
        $period = $this->validatedPeriod($request);

        if ($period === null) {
            return back()->withErrors(['start_date' => 'Tanggal awal tidak boleh melebihi tanggal akhir.'])->withInput();
        }

        [$startDate, $endDate] = $period;

        $result = $this->reports->trialBalance($startDate, $endDate);

        return Inertia::render('Akuntansi/Laporan/NeracaSaldo', [
            'report' => $result,
            'filters' => ['start_date' => $startDate, 'end_date' => $endDate],
        ]);
    }

    public function balanceSheet(Request $request): Response
    {
        $asOfDate = $this->validatedAsOf($request);

        $result = $this->reports->balanceSheet($asOfDate);

        return Inertia::render('Akuntansi/Laporan/Neraca', [
            'report' => $result,
            'filters' => ['as_of_date' => $asOfDate],
        ]);
    }

    public function incomeStatement(Request $request)
    {
        $period = $this->validatedPeriod($request);

        if ($period === null) {
            return back()->withErrors(['start_date' => 'Tanggal awal tidak boleh melebihi tanggal akhir.'])->withInput();
        }

        [$startDate, $endDate] = $period;

        $result = $this->reports->incomeStatement($startDate, $endDate);

        return Inertia::render('Akuntansi/Laporan/LabaRugi', [
            'report' => $result,
            'filters' => ['start_date' => $startDate, 'end_date' => $endDate],
        ]);
    }

    public function cashFlow(Request $request)
    {
        $period = $this->validatedPeriod($request);

        if ($period === null) {
            return back()->withErrors(['start_date' => 'Tanggal awal tidak boleh melebihi tanggal akhir.'])->withInput();
        }

        [$startDate, $endDate] = $period;

        $result = $this->reports->cashFlowReadiness($startDate, $endDate);

        return Inertia::render('Akuntansi/Laporan/ArusKas', [
            'report' => $result,
            'filters' => ['start_date' => $startDate, 'end_date' => $endDate],
        ]);
    }

    /**
     * Validasi periode: tanggal awal ≤ tanggal akhir.
     * Mengembalikan null bila tidak valid (controller me-redirect back).
     *
     * @return array{0: ?string, 1: ?string}|null
     */
    private function validatedPeriod(Request $request): ?array
    {
        $data = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $startDate = $data['start_date'] ?? null;
        $endDate = $data['end_date'] ?? null;

        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            return null;
        }

        return [$startDate, $endDate];
    }

    private function validatedAsOf(Request $request): string
    {
        $data = $request->validate([
            'as_of_date' => ['nullable', 'date'],
        ]);

        return $data['as_of_date'] ?? now()->format('Y-m-d');
    }
}
