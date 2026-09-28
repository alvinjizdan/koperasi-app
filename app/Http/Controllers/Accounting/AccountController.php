<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengelolaan Chart of Accounts (Ticket 18).
 *
 * Aturan: kode unik; parent tidak boleh diri sendiri; kategori & saldo normal
 * konsisten; akun yang sudah terpakai di jurnal tidak dapat dihapus langsung
 * (hanya dinonaktifkan); perubahan kode/nama mempertahankan referensi historis
 * (baris jurnal merujuk ID, bukan kode).
 */
class AccountController extends Controller
{
    public function index(): Response
    {
        $accounts = ChartOfAccount::query()
            ->with(['parent:id,code,name', 'children:id,parent_id'])
            ->withCount('journalLines as journal_usage_count')
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
                'is_template' => $account->is_template,
                'description' => $account->description,
                'journal_usage_count' => $account->journal_usage_count,
                'can_be_deleted' => $account->journal_usage_count === 0,
            ]);

        $categories = collect(ChartOfAccount::CATEGORIES)
            ->map(fn (array $definition, string $key) => [
                'value' => $key,
                'label' => $definition['label'],
                'normal_balance' => $definition['normal_balance'],
            ])
            ->values();

        return Inertia::render('Akuntansi/Coa/Index', [
            'accounts' => $accounts,
            'categories' => $categories,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Akuntansi/Coa/Form', [
            'categories' => $this->categories(),
            'parents' => $this->parents(),
            'account' => null,
        ]);
    }

    public function edit(ChartOfAccount $account): Response
    {
        return Inertia::render('Akuntansi/Coa/Form', [
            'categories' => $this->categories(),
            'parents' => $this->parents(),
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'category' => $account->category,
                'category_label' => $account->categoryLabel(),
                'normal_balance' => $account->normal_balance,
                'parent_id' => $account->parent_id,
                'is_active' => $account->is_active,
                'is_template' => $account->is_template,
                'description' => $account->description,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $account = ChartOfAccount::create($data);

        AuditLogger::log(
            'accounting.account.created',
            ChartOfAccount::class,
            $account->id,
            $account->code.' - '.$account->name,
            'Akun COA baru dibuat.',
            ['category' => $account->category, 'normal_balance' => $account->normal_balance]
        );

        return redirect()->route('akuntansi.coa.index')
            ->with('success', 'Akun '.$account->code.' - '.$account->name.' berhasil dibuat.');
    }

    public function update(Request $request, ChartOfAccount $account): RedirectResponse
    {
        $data = $this->validated($request, $account->id);

        // Referensi historis aman: jurnal merujuk ID; kode/nama boleh berubah
        // tetapi kategori/saldo normal yang memengaruhi makna saldo diberi catatan audit.
        $account->update($data);

        AuditLogger::logChanges(
            $account,
            'accounting.account.updated',
            ['code', 'name', 'category', 'normal_balance', 'is_active', 'parent_id', 'description'],
            'Akun COA diperbarui.'
        );

        return redirect()->route('akuntansi.coa.index')
            ->with('success', 'Akun '.$account->code.' berhasil diperbarui.');
    }

    public function deactivate(ChartOfAccount $account): RedirectResponse
    {
        if ($account->isUsedInJournals()) {
            // Akun terpakai: tidak dihapus, hanya dinonaktifkan agar histori tetap.
            $account->update(['is_active' => false]);

            AuditLogger::log(
                'accounting.account.deactivated',
                ChartOfAccount::class,
                $account->id,
                $account->code.' - '.$account->name,
                'Akun yang sudah digunakan di jurnal dinonaktifkan (tidak dihapus) agar referensi historis tetap utuh.'
            );

            return redirect()->route('akuntansi.coa.index')
                ->with('success', 'Akun '.$account->code.' dinonaktifkan. Akun yang sudah terpakai di jurnal tidak dapat dihapus.');
        }

        $account->delete();

        AuditLogger::log(
            'accounting.account.deleted',
            ChartOfAccount::class,
            $account->id,
            $account->code.' - '.$account->name,
            'Akun COA yang belum pernah digunakan di jurnal dihapus.'
        );

        return redirect()->route('akuntansi.coa.index')
            ->with('success', 'Akun '.$account->code.' dihapus.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parents(): array
    {
        return ChartOfAccount::query()
            ->where('is_active', true)
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

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('chart_of_accounts', 'code')->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(array_keys(ChartOfAccount::CATEGORIES))],
            'parent_id' => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $categoryDefinition = ChartOfAccount::CATEGORIES[$data['category']];
        $data['normal_balance'] = $categoryDefinition['normal_balance'];

        if (isset($data['parent_id'])) {
            $parentId = (int) $data['parent_id'];

            // Ditolak sebelum model disentuh agar tidak ada perubahan parsial.
            if ($parentId === (int) $ignoreId) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Akun induk tidak boleh merujuk akun itu sendiri.'],
                ]);
            }

            $parent = ChartOfAccount::find($parentId);

            if ($parent !== null && $parent->category !== $data['category']) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Akun induk harus dalam kategori yang sama ('.$parent->categoryLabel().').'],
                ]);
            }
        }

        return $data;
    }
}
