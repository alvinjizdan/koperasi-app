<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\ShmAccountingMapping;
use App\Models\ShmLoanProduct;
use App\Services\Accounting\ShmAccountingMappingService;
use App\Support\AccountingContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShmAccountingMappingController extends Controller
{
    public function __construct(
        private ShmAccountingMappingService $mappingService
    ) {}

    public function index(): Response
    {
        $mappings = $this->mappingService->query()->get()->map(fn (ShmAccountingMapping $m) => [
            'id' => $m->id,
            'business_event' => $m->business_event,
            'business_event_label' => ShmAccountingMapping::supportedEvents()[$m->business_event] ?? $m->business_event,
            'product' => $m->product ? [
                'id' => $m->product->id,
                'code' => $m->product->code,
                'name' => $m->product->name,
            ] : null,
            'debit_account_role' => $m->debit_account_role,
            'debit_account_role_label' => ShmAccountingMapping::supportedRoles()[$m->debit_account_role] ?? null,
            'debit_account' => $m->debitAccount ? [
                'code' => $m->debitAccount->code,
                'name' => $m->debitAccount->name,
            ] : null,
            'credit_account_role' => $m->credit_account_role,
            'credit_account_role_label' => ShmAccountingMapping::supportedRoles()[$m->credit_account_role] ?? null,
            'credit_account' => $m->creditAccount ? [
                'code' => $m->creditAccount->code,
                'name' => $m->creditAccount->name,
            ] : null,
            'is_active' => $m->is_active,
            'description' => $m->description,
            'updated_at' => $m->updated_at?->format('Y-m-d H:i'),
        ]);

        return Inertia::render('Shm/Accounting/Mapping/Index', [
            'mappings' => $mappings,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Shm/Accounting/Mapping/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRequest($request);

        $mapping = $this->mappingService->saveMapping($validated, (int) auth()->id());

        return redirect()->route('shm.accounting-mapping.index')
            ->with('success', "Mapping akuntansi [{$mapping->business_event}] berhasil disimpan.");
    }

    public function edit(ShmAccountingMapping $mapping): Response
    {
        if ($mapping->accounting_context !== AccountingContext::SHM) {
            abort(404);
        }

        return Inertia::render('Shm/Accounting/Mapping/Form', array_merge($this->formProps(), [
            'mapping' => [
                'id' => $mapping->id,
                'business_event' => $mapping->business_event,
                'shm_loan_product_id' => $mapping->shm_loan_product_id,
                'debit_account_role' => $mapping->debit_account_role,
                'debit_account_id' => $mapping->debit_account_id,
                'credit_account_role' => $mapping->credit_account_role,
                'credit_account_id' => $mapping->credit_account_id,
                'is_active' => $mapping->is_active,
                'description' => $mapping->description,
            ],
        ]));
    }

    public function update(Request $request, ShmAccountingMapping $mapping): RedirectResponse
    {
        if ($mapping->accounting_context !== AccountingContext::SHM) {
            abort(404);
        }

        $validated = $this->validateRequest($request);

        $this->mappingService->saveMapping($validated, (int) auth()->id(), $mapping->id);

        return redirect()->route('shm.accounting-mapping.index')
            ->with('success', "Mapping akuntansi [{$mapping->business_event}] berhasil diperbarui.");
    }

    public function toggle(ShmAccountingMapping $mapping): RedirectResponse
    {
        $this->mappingService->toggleActive($mapping, (int) auth()->id());

        return redirect()->route('shm.accounting-mapping.index')
            ->with('success', 'Status mapping berhasil diubah.');
    }

    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'business_event' => ['required', 'string'],
            'shm_loan_product_id' => ['nullable', 'integer'],
            'debit_account_role' => ['nullable', 'string'],
            'debit_account_id' => ['nullable', 'integer'],
            'credit_account_role' => ['nullable', 'string'],
            'credit_account_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function formProps(): array
    {
        $products = ShmLoanProduct::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $accounts = ChartOfAccount::query()
            ->shm()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'category']);

        return [
            'products' => $products,
            'accounts' => $accounts,
            'supportedEvents' => ShmAccountingMapping::supportedEvents(),
            'supportedRoles' => ShmAccountingMapping::supportedRoles(),
        ];
    }
}
