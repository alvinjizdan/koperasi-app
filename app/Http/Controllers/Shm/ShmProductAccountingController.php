<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Controller;
use App\Models\ShmLoanProduct;
use App\Models\ShmProductAccounting;
use App\Services\Accounting\ShmProductAccountingService;
use App\Support\AccountingContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShmProductAccountingController extends Controller
{
    public function __construct(
        private ShmProductAccountingService $productAccountingService
    ) {}

    public function index(): Response
    {
        $configurations = $this->productAccountingService->query()->get();

        // Get all products to identify which ones are configured and which ones are not
        $products = ShmLoanProduct::query()
            ->with(['accounting.cashAccount', 'accounting.receivableAccount', 'accounting.interestRevenueAccount', 'accounting.penaltyRevenueAccount', 'accounting.settlementDiscountAccount'])
            ->orderBy('code')
            ->get()
            ->map(fn (ShmLoanProduct $product) => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'category' => $product->category,
                'is_active' => $product->is_active,
                'accounting' => $product->accounting ? [
                    'id' => $product->accounting->id,
                    'is_active' => $product->accounting->is_active,
                    'cash_account' => $product->accounting->cashAccount ? [
                        'code' => $product->accounting->cashAccount->code,
                        'name' => $product->accounting->cashAccount->name,
                    ] : null,
                    'receivable_account' => $product->accounting->receivableAccount ? [
                        'code' => $product->accounting->receivableAccount->code,
                        'name' => $product->accounting->receivableAccount->name,
                    ] : null,
                    'interest_revenue_account' => $product->accounting->interestRevenueAccount ? [
                        'code' => $product->accounting->interestRevenueAccount->code,
                        'name' => $product->accounting->interestRevenueAccount->name,
                    ] : null,
                    'penalty_revenue_account' => $product->accounting->penaltyRevenueAccount ? [
                        'code' => $product->accounting->penaltyRevenueAccount->code,
                        'name' => $product->accounting->penaltyRevenueAccount->name,
                    ] : null,
                    'settlement_discount_account' => $product->accounting->settlementDiscountAccount ? [
                        'code' => $product->accounting->settlementDiscountAccount->code,
                        'name' => $product->accounting->settlementDiscountAccount->name,
                    ] : null,
                    'notes' => $product->accounting->notes,
                    'updated_at' => $product->accounting->updated_at?->format('Y-m-d H:i'),
                ] : null,
            ]);

        return Inertia::render('Shm/Accounting/ProductAccounting/Index', [
            'products' => $products,
        ]);
    }

    public function edit(ShmLoanProduct $product): Response
    {
        $product->load(['accounting.cashAccount', 'accounting.receivableAccount', 'accounting.interestRevenueAccount', 'accounting.penaltyRevenueAccount', 'accounting.settlementDiscountAccount']);
        $selectableAccounts = $this->productAccountingService->getSelectableAccounts();

        return Inertia::render('Shm/Accounting/ProductAccounting/Form', [
            'product' => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'category' => $product->category,
            ],
            'configuration' => $product->accounting ? [
                'id' => $product->accounting->id,
                'shm_loan_product_id' => $product->id,
                'cash_account_id' => $product->accounting->cash_account_id,
                'receivable_account_id' => $product->accounting->receivable_account_id,
                'interest_revenue_account_id' => $product->accounting->interest_revenue_account_id,
                'penalty_revenue_account_id' => $product->accounting->penalty_revenue_account_id,
                'settlement_discount_account_id' => $product->accounting->settlement_discount_account_id,
                'is_active' => $product->accounting->is_active,
                'notes' => $product->accounting->notes,
            ] : null,
            'selectableAccounts' => $selectableAccounts,
        ]);
    }

    public function update(Request $request, ShmLoanProduct $product): RedirectResponse
    {
        $validated = $request->validate([
            'cash_account_id' => ['nullable', 'integer'],
            'receivable_account_id' => ['nullable', 'integer'],
            'interest_revenue_account_id' => ['nullable', 'integer'],
            'penalty_revenue_account_id' => ['nullable', 'integer'],
            'settlement_discount_account_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['shm_loan_product_id'] = $product->id;

        $this->productAccountingService->saveConfiguration($validated, (int) auth()->id());

        return redirect()->route('shm.product-accounting.index')
            ->with('success', "Konfigurasi akuntansi produk [{$product->code}] berhasil disimpan.");
    }

    public function toggle(ShmProductAccounting $config): RedirectResponse
    {
        if ($config->accounting_context !== AccountingContext::SHM) {
            abort(404, 'Konfigurasi tidak ditemukan dalam konteks Akuntansi SHM.');
        }

        $this->productAccountingService->toggleActive($config, (int) auth()->id());

        return redirect()->route('shm.product-accounting.index')
            ->with('success', 'Status konfigurasi berhasil diubah.');
    }
}
