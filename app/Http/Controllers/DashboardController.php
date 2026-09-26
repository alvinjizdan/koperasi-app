<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportFilterRequest;
use App\Services\DashboardService;
use App\Services\Reports\ReportPeriod;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboardService) {}

    /**
     * Dashboard operasional koperasi.
     *
     * Metrik posisi (saldo saat ini) tidak terpengaruh filter periode, sedangkan
     * metrik transaksi mengikuti periode terpilih. Angka kas ditampilkan untuk
     * tanggal operasional hari ini agar konsisten dengan modul Kasir.
     */
    public function index(ReportFilterRequest $request): Response
    {
        $filters = $request->filters();
        $period = ReportPeriod::fromFilters($filters);

        return Inertia::render('Dashboard', [
            'today_formatted' => ReportPeriod::today()->locale('id')->translatedFormat('l, d F Y'),
            'period' => $period->toArray(),
            'operational_timezone' => ReportPeriod::timezone(),
            'metrics' => $this->dashboardService->overview($period),
            'filters' => [
                'period' => $period->preset,
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
            ],
            'system_status' => [
                'name' => 'KSP Bima Sakti Mandiri',
                'environment' => config('app.env'),
                'status' => 'Aktif',
            ],
        ]);
    }
}
