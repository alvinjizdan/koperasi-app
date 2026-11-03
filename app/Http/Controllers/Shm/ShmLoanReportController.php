<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Concerns\AuthorizesShmOwnership;
use App\Http\Controllers\Controller;
use App\Models\ShmLoan;
use App\Models\ShmLoanInstallment;
use App\Services\Shm\ShmLoanReportPdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ShmLoanReportController extends Controller
{
    use AuthorizesShmOwnership;

    public function __construct(
        private readonly ShmLoanReportPdf $pdfService,
    ) {}

    /**
     * Tampilkan halaman laporan per pinjaman BSM Makro.
     */
    public function index(Request $request): Response
    {
        $selectedLoanId = $request->input('loan_id');

        // Query daftar pinjaman untuk selector
        $loansQuery = ShmLoan::query()
            ->with([
                'customer:id,customer_number,name,nik,phone',
                'product:id,code,name',
            ]);

        if (! $this->canViewAllShm()) {
            $loansQuery->ownedBy((int) $request->user()->id);
        }

        $loansList = $loansQuery->latest('id')
            ->get()
            ->map(fn (ShmLoan $l): array => [
                'id' => $l->id,
                'loan_number' => $l->loan_number,
                'customer_name' => $l->customer?->name,
                'customer_number' => $l->customer?->customer_number,
                'product_name' => $l->product?->name,
                'principal' => (float) $l->principal,
                'status' => $l->status,
                'disbursed_at' => $l->disbursed_at?->format('d/m/Y'),
            ]);

        $selectedLoan = null;
        $reportData = null;

        if ($selectedLoanId) {
            $loan = ShmLoan::query()
                ->with([
                    'customer',
                    'product',
                    'application.collaterals',
                    'installments',
                ])
                ->find($selectedLoanId);

            if ($loan) {
                $this->authorizeLoanRecordOwnership($request, $loan);
                $selectedLoan = $loan;
                $reportData = $this->buildReportData($loan);
            }
        }

        return Inertia::render('Shm/Loans/Report', [
            'loansList' => $loansList,
            'selectedLoanId' => $selectedLoan ? $selectedLoan->id : null,
            'reportData' => $reportData,
        ]);
    }

    /**
     * Export laporan satu pinjaman ke format PDF.
     */
    public function exportPdf(Request $request, ShmLoan $loan): SymfonyResponse
    {
        $this->authorizeLoanRecordOwnership($request, $loan);

        $loan->load([
            'customer',
            'product',
            'application.collaterals',
            'installments',
        ]);

        $reportData = $this->buildReportData($loan);

        return $this->pdfService->download($loan, $reportData);
    }

    /**
     * Susun snapshot data laporan secara komprehensif dan read-only.
     *
     * @return array<string, mixed>
     */
    private function buildReportData(ShmLoan $loan): array
    {
        $app = $loan->application;
        $customer = $loan->customer;

        // Hitung ringkasan angsuran & kewajiban
        $totalPrincipal = (float) $loan->principal;
        $paidPrincipal = 0.0;
        $paidInterest = 0.0;
        $totalInterestScheduled = 0.0;
        $paidInstallmentsCount = 0;
        $installmentsData = [];

        foreach ($loan->installments as $inst) {
            $paidPrincipal += (float) $inst->paid_principal_amount;
            $paidInterest += (float) $inst->paid_interest_amount;
            $totalInterestScheduled += (float) $inst->interest_amount;

            if ($inst->status === ShmLoanInstallment::STATUS_PAID) {
                $paidInstallmentsCount++;
            }

            $installmentsData[] = [
                'id' => $inst->id,
                'nomor' => $inst->installment_number,
                'jatuh_tempo' => $inst->due_date?->format('d/m/Y') ?? '-',
                'pokok' => (float) $inst->principal_amount,
                'bunga' => (float) $inst->interest_amount,
                'total' => (float) $inst->total_amount,
                'terbayar' => (float) $inst->paid_amount,
                'status' => $inst->status,
                'paid_at' => $inst->paid_at?->format('d/m/Y H:i'),
            ];
        }

        $sisaPokok = max(0.0, $totalPrincipal - $paidPrincipal);
        $sisaBunga = max(0.0, $totalInterestScheduled - $paidInterest);
        $totalSisaKewajiban = $sisaPokok + $sisaBunga;

        // Data jaminan dari pengajuan asal
        $collateralsData = [];
        if ($app && $app->collaterals) {
            foreach ($app->collaterals as $c) {
                $collateralsData[] = [
                    'id' => $c->id,
                    'tanggal' => $c->collateral_date?->format('d/m/Y') ?? '-',
                    'jenis' => $c->collateral_type,
                    'nama' => $c->collateral_name,
                    'keterangan' => $c->notes,
                    'nilai_perkiraan' => (float) ($c->estimated_value ?? 0),
                    'status_pengikatan' => $c->binding_status,
                    'tanggal_pengikatan' => $c->binding_date?->format('d/m/Y') ?? '-',
                    'nilai_hak_tanggungan' => (float) ($c->rights_value ?? 0),
                    'photo_url' => $c->photo_path !== null
                        ? route('shm.loans.pengajuan.jaminan.foto', [$app->id, $c->id])
                        : null,
                    'document_url' => $c->document_path !== null
                        ? route('shm.loans.pengajuan.jaminan.dokumen', [$app->id, $c->id])
                        : null,
                    'document_name' => $c->document_name,
                ];
            }
        }

        // Format TTL
        $ttl = null;
        if ($app && ($app->tempat_lahir || $app->tanggal_lahir)) {
            $ttl = trim(($app->tempat_lahir ?: '').', '.($app->tanggal_lahir?->format('d/m/Y') ?: ''), ', ');
        }

        return [
            'identitas' => [
                'id' => $loan->id,
                'nomor_pinjaman' => $loan->loan_number,
                'status' => $loan->status,
                'tanggal_pencairan' => $loan->disbursed_at?->format('d/m/Y') ?? '-',
                'jatuh_tempo_pertama' => $loan->first_due_date?->format('d/m/Y') ?? '-',
            ],
            'nasabah' => [
                'id' => $customer?->id,
                'nomor_nasabah' => $customer?->customer_number ?? '-',
                'nama' => $customer?->name ?? '-',
                'nik' => $customer?->nik ?? '-',
                'telepon' => $customer?->phone ?? '-',
                'alamat' => $customer?->address ?? '-',
                'status_aktif' => (bool) ($customer?->is_active ?? false),
                'tanggal_gabung' => $customer?->created_at?->format('d/m/Y') ?? '-',
                'unit_kerja' => $app?->unit_kerja,
                'nip' => $app?->nip,
                'telepon_rumah' => $app?->telepon_rumah,
                'ttl' => $ttl,
                'jumlah_tanggungan' => $app?->jumlah_tanggungan,
            ],
            'pinjaman' => [
                'produk' => $loan->product ? "{$loan->product->name} ({$loan->product->code})" : '-',
                'plafon' => (float) $loan->principal,
                'tenor_bulan' => $loan->tenor_months,
                'rate_percent' => (float) $loan->rate_percent,
                'rate_period' => $loan->rate_period ?? 'bulan',
                'calculation_method' => $loan->calculation_method ?? 'flat',
                'tujuan' => $loan->purpose ?: '-',
                'nomor_pengajuan' => $app?->application_number,
                'tanggal_pengajuan' => $app?->application_date?->format('d/m/Y'),
            ],
            'jaminan' => $collateralsData,
            'ringkasan' => [
                'total_pinjaman' => $totalPrincipal,
                'pokok_dibayar' => $paidPrincipal,
                'bunga_dibayar' => $paidInterest,
                'sisa_pokok' => $sisaPokok,
                'sisa_bunga' => $sisaBunga,
                'total_sisa_kewajiban' => $totalSisaKewajiban,
                'total_angsuran' => $loan->installments->count(),
                'angsuran_terbayar' => $paidInstallmentsCount,
                'angsuran_tersisa' => max(0, $loan->installments->count() - $paidInstallmentsCount),
            ],
            'angsuran' => $installmentsData,
        ];
    }
}
