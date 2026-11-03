<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shm\StoreShmSavingsAccountRequest;
use App\Models\Shm\ShmSavingProduct;
use App\Models\Shm\ShmSavingsAccount;
use App\Models\ShmCustomer;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShmSavingsAccountController extends Controller
{
    use InteractsWithSorting;

    /**
     * Tampilkan daftar rekening simpanan SHM.
     */
    public function index(Request $request): Response
    {
        $search = $request->input('search');
        $status = $request->input('status', 'all');
        $productId = $request->input('product_id');

        $query = ShmSavingsAccount::with(['customer', 'savingProduct'])
            ->when($search, fn ($q) => $q->where('account_number', 'ilike', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$search}%")
                    ->orWhere('customer_number', 'ilike', "%{$search}%")))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($productId, fn ($q) => $q->where('shm_saving_product_id', $productId));

        $sortStatus = $this->applySorting($query, $request, [
            'account_number' => 'shm_savings_accounts.account_number',
            'balance' => 'shm_savings_accounts.balance',
            'status' => 'shm_savings_accounts.status',
            'opened_at' => 'shm_savings_accounts.opened_at',
            'created_at' => 'shm_savings_accounts.created_at',
        ], [['shm_savings_accounts.created_at', 'desc']]);

        $accounts = $query->paginate($this->resolvePerPage($request))->withQueryString();

        return Inertia::render('Shm/Savings/Accounts/Index', [
            'accounts' => $accounts,
            'products' => ShmSavingProduct::active()->orderBy('name')->get(['id', 'code', 'name']),
            'sort' => $sortStatus,
            'filters' => [
                'search' => $search ?? '',
                'status' => $status,
                'product_id' => $productId ?? '',
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Tampilkan form pembukaan rekening simpanan SHM.
     */
    public function create(): Response
    {
        return Inertia::render('Shm/Savings/Accounts/Create', [
            'customers' => ShmCustomer::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'customer_number']),
            'products' => ShmSavingProduct::active()->orderBy('name')->get(['id', 'code', 'name', 'category']),
            'today_date' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * Buka rekening simpanan SHM baru.
     */
    public function store(StoreShmSavingsAccountRequest $request): RedirectResponse
    {
        $product = ShmSavingProduct::findOrFail($request->shm_saving_product_id);
        if (! $product->is_active) {
            return redirect()->back()->withErrors(['shm_saving_product_id' => 'Produk simpanan SHM tidak aktif.'])->withInput();
        }

        $customer = ShmCustomer::findOrFail($request->shm_customer_id);

        $account = ShmSavingsAccount::create([
            'account_number' => ShmSavingsAccount::generateAccountNumber(),
            'shm_customer_id' => $customer->id,
            'shm_saving_product_id' => $product->id,
            'opened_at' => $request->opened_at,
            'status' => 'active',
            'balance' => 0,
        ]);

        AuditLogger::log(
            'shm.savings.account.opened',
            ShmSavingsAccount::class,
            $account->id,
            $account->account_number,
            sprintf('Rekening simpanan SHM dibuka untuk nasabah %s (%s).', $customer->name, $customer->customer_number),
            [
                'account_number' => $account->account_number,
                'shm_customer_id' => $customer->id,
                'customer_number' => $customer->customer_number,
                'product' => $product->name,
                'opened_at' => $account->opened_at->format('Y-m-d'),
            ],
        );

        return redirect()
            ->route('shm.savings.accounts.show', $account->id)
            ->with('success', 'Rekening simpanan SHM '.$account->account_number.' berhasil dibuka.');
    }

    /**
     * Tampilkan detail rekening SHM beserta riwayat mutasi transaksinya.
     */
    public function show(Request $request, ShmSavingsAccount $account): Response
    {
        $account->load(['customer', 'savingProduct']);

        $typeFilter = $request->input('type', 'all');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $search = $request->input('search');

        $transactionsQuery = $account->transactions()
            ->with('processor')
            ->when($typeFilter !== 'all', fn ($q) => $q->where('type', $typeFilter))
            ->when($dateFrom, fn ($q) => $q->where('transaction_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('transaction_date', '<=', $dateTo))
            ->when($search, fn ($q) => $q->where('transaction_number', 'ilike', "%{$search}%"));

        $transactionSortStatus = $this->applySorting($transactionsQuery, $request, [
            'transaction_number' => 'shm_savings_transactions.transaction_number',
            'type' => 'shm_savings_transactions.type',
            'amount' => 'shm_savings_transactions.amount',
            'transaction_date' => 'shm_savings_transactions.transaction_date',
            'created_at' => 'shm_savings_transactions.created_at',
        ], [['shm_savings_transactions.created_at', 'desc']]);

        $transactions = $transactionsQuery->paginate($this->resolvePerPage($request))->withQueryString();

        return Inertia::render('Shm/Savings/Accounts/Show', [
            'account' => $account,
            'transactions' => $transactions,
            'sort' => $transactionSortStatus,
            'filters' => [
                'type' => $typeFilter,
                'date_from' => $dateFrom ?? '',
                'date_to' => $dateTo ?? '',
                'search' => $search ?? '',
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }
}
