<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Services\Accounting\ShmChartOfAccountService;
use App\Support\AccountingContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ShmAccountController extends Controller
{
    public function __construct(
        private ShmChartOfAccountService $coaService
    ) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();
        $category = $request->string('category')->trim()->toString();
        $status = $request->string('status')->trim()->toString();

        $accounts = ChartOfAccount::query()
            ->shm()
            ->with(['parent:id,code,name', 'children:id,parent_id'])
            ->withCount('journalLines as journal_usage_count')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('code', 'ilike', "%{$search}%")
                        ->orWhere('name', 'ilike', "%{$search}%");
                });
            })
            ->when($category !== '', fn ($q) => $q->where('category', $category))
            ->when($status !== '', function ($q) use ($status) {
                if ($status === 'active') {
                    $q->where('is_active', true);
                } elseif ($status === 'inactive') {
                    $q->where('is_active', false);
                }
            })
            ->orderBy('code')
            ->get()
            ->map(fn (ChartOfAccount $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'category' => $account->category,
                'category_label' => $account->categoryLabel(),
                'normal_balance' => $account->normal_balance,
                'parent_id' => $account->parent_id,
                'parent_code' => $account->parent?->code,
                'parent_name' => $account->parent?->name,
                'is_active' => $account->is_active,
                'description' => $account->description,
                'journal_usage_count' => $account->journal_usage_count,
                'can_be_deleted' => $account->journal_usage_count === 0 && ! $account->children()->exists(),
            ]);

        $categories = collect(ChartOfAccount::CATEGORIES)
            ->map(fn (array $definition, string $key) => [
                'value' => $key,
                'label' => $definition['label'],
                'normal_balance' => $definition['normal_balance'],
            ])
            ->values();

        return Inertia::render('Shm/Coa/Index', [
            'accounts' => $accounts,
            'categories' => $categories,
            'filters' => [
                'search' => $search,
                'category' => $category,
                'status' => $status,
            ],
            'importResult' => session('importResult'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Shm/Coa/Form', [
            'categories' => $this->categories(),
            'parents' => $this->parents(),
            'account' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string'],
            'parent_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $account = $this->coaService->createAccount($validated, (int) auth()->id());

        return redirect()->route('shm.coa.index')
            ->with('success', 'Akun '.$account->code.' - '.$account->name.' berhasil ditambahkan ke SHM CoA.');
    }

    public function edit(ChartOfAccount $account): Response
    {
        $this->assertShmAccount($account);

        return Inertia::render('Shm/Coa/Form', [
            'categories' => $this->categories(),
            'parents' => $this->parents($account->id),
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'category' => $account->category,
                'category_label' => $account->categoryLabel(),
                'normal_balance' => $account->normal_balance,
                'parent_id' => $account->parent_id,
                'is_active' => $account->is_active,
                'description' => $account->description,
            ],
        ]);
    }

    public function update(Request $request, ChartOfAccount $account): RedirectResponse
    {
        $this->assertShmAccount($account);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string'],
            'parent_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->coaService->updateAccount($account, $validated, (int) auth()->id());

        return redirect()->route('shm.coa.index')
            ->with('success', 'Akun '.$account->code.' berhasil diperbarui.');
    }

    public function deactivate(ChartOfAccount $account): RedirectResponse
    {
        $this->assertShmAccount($account);

        $result = $this->coaService->deactivateOrDelete($account, (int) auth()->id());

        return redirect()->route('shm.coa.index')
            ->with('success', $result['message']);
    }

    public function export(): BinaryFileResponse
    {
        $filePath = $this->coaService->exportToXlsx();
        $fileName = 'SHM_Chart_of_Accounts_'.date('Ymd_His').'.xlsx';

        return response()->download($filePath, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function template(): BinaryFileResponse
    {
        $filePath = $this->coaService->generateTemplateXlsx();
        $fileName = 'Template_Impor_SHM_COA.xlsx';

        return response()->download($filePath, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'], // 10MB max
        ], [
            'file.mimes' => 'Format berkas tidak didukung. Hanya format .xlsx yang diperbolehkan (format .xls dan .csv ditolak).',
        ]);

        $file = $request->file('file');
        $result = $this->coaService->importFromXlsx($file, (int) auth()->id());

        if (! empty($result['errors'])) {
            return redirect()->route('shm.coa.index')
                ->with('importResult', $result)
                ->withErrors(['file' => 'Terdapat kesalahan pada data impor XLSX. Silakan periksa rincian kesalahan di bawah.']);
        }

        return redirect()->route('shm.coa.index')
            ->with('importResult', $result)
            ->with('success', "Berhasil mengimpor {$result['successful_rows']} akun ke SHM CoA.");
    }

    private function assertShmAccount(ChartOfAccount $account): void
    {
        if ($account->accounting_context !== AccountingContext::SHM) {
            abort(404, 'Akun tidak ditemukan dalam konteks Akuntansi SHM.');
        }
    }

    private function categories(): array
    {
        return collect(ChartOfAccount::CATEGORIES)
            ->map(fn (array $definition, string $key) => [
                'value' => $key,
                'label' => $definition['label'],
                'normal_balance' => $definition['normal_balance'],
            ])
            ->values()
            ->all();
    }

    private function parents(?int $ignoreId = null): array
    {
        return ChartOfAccount::query()
            ->shm()
            ->where('is_active', true)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'category'])
            ->map(fn (ChartOfAccount $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'category' => $account->category,
            ])
            ->all();
    }
}
