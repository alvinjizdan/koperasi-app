<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportFilterRequest;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\ReportRegistry;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor CSV laporan dasar.
 *
 * Ekspor mengikuti filter yang sedang aktif dan hanya menyertakan kolom yang
 * diperlukan. Data pribadi anggota (nomor identitas dan nomor telepon) tidak
 * disertakan karena lapisan izin berbasis role belum tersedia.
 */
class ReportExportController extends Controller
{
    public function __construct(private readonly ReportExporter $exporter) {}

    /**
     * Unduh laporan sebagai CSV.
     */
    public function export(ReportFilterRequest $request, string $report): StreamedResponse
    {
        abort_unless(ReportRegistry::exists($report), 404);

        $filters = $request->filters();
        $period = ReportPeriod::fromFilters($filters);
        $service = ReportRegistry::make($report, $period);

        return $this->exporter->download(
            ReportRegistry::filename($report, $period),
            $service->csvHeaders(),
            $service->stream($filters),
        );
    }
}
