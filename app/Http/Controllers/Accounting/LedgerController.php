<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Services\Accounting\JournalService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Buku besar (Ticket 18): hanya jurnal POSTED; filter akun + rentang tanggal;
 * saldo berjalan berdasarkan saldo normal akun.
 */
class LedgerController extends Controller
{
    public function __construct(private JournalService $journals) {}

    public function index(): Response
    {
        $accountId = request()->integer('account_id') ?: null;
        $startDate = request()->string('start_date')->toString() ?: null;
        $endDate = request()->string('end_date')->toString() ?: null;

        $result = $this->journals->ledger($accountId, $startDate, $endDate);

        return Inertia::render('Akuntansi/BukuBesar/Index', [
            'accounts' => $this->allAccounts(),
            'filters' => [
                'account_id' => $accountId,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'rows' => $result['rows'],
            'totals' => $result['totals'],
            'selectedAccount' => $result['account'] !== null ? [
                'id' => $result['account']->id,
                'code' => $result['account']->code,
                'name' => $result['account']->name,
                'normal_balance' => $result['account']->normal_balance,
                'category_label' => $result['account']->categoryLabel(),
            ] : null,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allAccounts(): array
    {
        return ChartOfAccount::query()
            ->koperasi()
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'category', 'normal_balance'])
            ->map(fn ($account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'category' => $account->category,
                'normal_balance' => $account->normal_balance,
            ])
            ->all();
    }
}
