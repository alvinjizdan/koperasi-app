<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Concerns\AuthorizesShmOwnership;
use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Controllers\Controller;
use App\Models\ShmLoanApplicationCollateral;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShmLoanCollateralController extends Controller
{
    use AuthorizesShmOwnership;
    use InteractsWithSorting;

    /**
     * Tampilkan daftar jaminan pinjaman BSM Makro.
     * Merepresentasikan relasi: Nasabah -> Pinjaman -> Jaminan.
     */
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->input('search'),
            'type' => $request->input('type', 'all'),
        ];

        $query = ShmLoanApplicationCollateral::query()
            ->with([
                'application.customer',
                'application.loan.product',
            ])
            ->whereHas('application.loan');

        // Scope kepemilikan marketing jika user tidak memiliki permission shm.view_all
        if (! $this->canViewAllShm()) {
            $query->whereHas('application', function (Builder $appQuery) use ($request) {
                $appQuery->where('marketing_id', (int) $request->user()->id);
            });
        }

        // Filter search: Nomor nasabah, Nama nasabah, Nomor pinjaman, Nama jaminan
        if ($filters['search']) {
            $term = '%'.trim($filters['search']).'%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('collateral_name', 'like', $term)
                    ->orWhere('collateral_type', 'like', $term)
                    ->orWhere('notes', 'like', $term)
                    ->orWhereHas('application.customer', function (Builder $customerQuery) use ($term) {
                        $customerQuery->where('name', 'like', $term)
                            ->orWhere('customer_number', 'like', $term)
                            ->orWhere('nik', 'like', $term);
                    })
                    ->orWhereHas('application.loan', function (Builder $loanQuery) use ($term) {
                        $loanQuery->where('loan_number', 'like', $term);
                    });
            });
        }

        if ($filters['type'] && $filters['type'] !== 'all') {
            $query->where('collateral_type', $filters['type']);
        }

        // Ambil list jenis jaminan yang unik untuk opsi filter
        $availableTypes = ShmLoanApplicationCollateral::query()
            ->whereHas('application.loan')
            ->distinct()
            ->pluck('collateral_type')
            ->filter()
            ->values()
            ->all();

        $collaterals = $query->latest('id')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(function (ShmLoanApplicationCollateral $collateral): array {
                $app = $collateral->application;
                $loan = $app?->loan;
                $customer = $app?->customer;

                return [
                    'id' => $collateral->id,
                    'collateral_type' => $collateral->collateral_type,
                    'collateral_name' => $collateral->collateral_name,
                    'notes' => $collateral->notes,
                    'estimated_value' => (float) ($collateral->estimated_value ?? 0),
                    'binding_status' => $collateral->binding_status,
                    'binding_date' => $collateral->binding_date?->format('Y-m-d'),
                    'rights_value' => (float) ($collateral->rights_value ?? 0),
                    'photo_url' => $collateral->photo_path !== null && $app !== null
                        ? route('shm.loans.pengajuan.jaminan.foto', [$app->id, $collateral->id])
                        : null,
                    'document_url' => $collateral->document_path !== null && $app !== null
                        ? route('shm.loans.pengajuan.jaminan.dokumen', [$app->id, $collateral->id])
                        : null,
                    'document_name' => $collateral->document_name,
                    'loan' => $loan ? [
                        'id' => $loan->id,
                        'loan_number' => $loan->loan_number,
                        'status' => $loan->status,
                        'principal' => (float) $loan->principal,
                        'product_name' => $loan->product?->name,
                    ] : null,
                    'customer' => $customer ? [
                        'id' => $customer->id,
                        'customer_number' => $customer->customer_number,
                        'name' => $customer->name,
                        'nik' => $customer->nik,
                        'phone' => $customer->phone,
                    ] : null,
                ];
            });

        return Inertia::render('Shm/Loans/Collaterals', [
            'collaterals' => $collaterals,
            'availableTypes' => $availableTypes,
            'filters' => $filters,
        ]);
    }
}
