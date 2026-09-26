<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSavingsAccountRequest;
use App\Models\Member;
use App\Models\SavingProduct;
use App\Models\SavingsAccount;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SavingsAccountController extends Controller
{
    /**
     * Tampilkan daftar rekening simpanan.
     */
    public function index(Request $request): Response
    {
        $search = $request->input('search');
        $status = $request->input('status', 'all');

        $accounts = SavingsAccount::with(['member', 'savingProduct'])
            ->when($search, fn ($q) => $q->where('account_number', 'ilike', "%{$search}%")
                ->orWhereHas('member', fn ($m) => $m->where('full_name', 'ilike', "%{$search}%")))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('created_at', 'desc')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Simpanan/Rekening/Index', [
            'accounts' => $accounts,
            'filters' => [
                'search' => $search ?? '',
                'status' => $status,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Tampilkan form pembukaan rekening simpanan.
     */
    public function create(): Response
    {
        return Inertia::render('Simpanan/Rekening/Create', [
            'members' => Member::where('status', 'active')->orderBy('full_name')->get(['id', 'full_name', 'member_number']),
            'products' => SavingProduct::active()->orderBy('name')->get(['id', 'code', 'name']),
            'today_date' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * Buka rekening simpanan baru.
     */
    public function store(StoreSavingsAccountRequest $request): RedirectResponse
    {
        // Validasi: produk harus aktif
        $product = SavingProduct::findOrFail($request->saving_product_id);
        if (! $product->is_active) {
            return redirect()->back()->withErrors(['saving_product_id' => 'Produk simpanan tidak aktif.'])->withInput();
        }

        $account = SavingsAccount::create([
            'account_number' => SavingsAccount::generateAccountNumber(),
            'member_id' => $request->member_id,
            'saving_product_id' => $request->saving_product_id,
            'opened_at' => $request->opened_at,
            'status' => 'active',
            'balance' => 0,
        ]);

        AuditLogger::log(
            'savings.account.opened',
            SavingsAccount::class,
            $account->id,
            $account->account_number,
            sprintf('Rekening simpanan dibuka untuk anggota %s.', $account->member->full_name),
            [
                'account_number' => $account->account_number,
                'member_id' => $account->member_id,
                'member_number' => $account->member->member_number,
                'product' => $account->savingProduct->name,
                'opened_at' => $account->opened_at->format('Y-m-d'),
            ],
        );

        return redirect()
            ->route('simpanan-rekening.show', $account->id)
            ->with('success', 'Rekening simpanan '.$account->account_number.' berhasil dibuka.');
    }

    /**
     * Tampilkan detail rekening beserta riwayat transaksi.
     */
    public function show(Request $request, SavingsAccount $savingsAccount): Response
    {
        $savingsAccount->load(['member', 'savingProduct']);

        $typeFilter = $request->input('type', 'all');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $search = $request->input('search');

        $transactions = $savingsAccount->transactions()
            ->with('operator')
            ->when($typeFilter !== 'all', fn ($q) => $q->where('type', $typeFilter))
            ->when($dateFrom, fn ($q) => $q->where('transaction_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('transaction_date', '<=', $dateTo))
            ->when($search, fn ($q) => $q->where('transaction_number', 'ilike', "%{$search}%"))
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Simpanan/Rekening/Show', [
            'account' => $savingsAccount,
            'transactions' => $transactions,
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
