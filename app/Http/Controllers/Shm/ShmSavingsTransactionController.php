<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shm\StoreShmSavingsTransactionRequest;
use App\Models\Shm\ShmSavingsAccount;
use App\Models\Shm\ShmSavingsTransaction;
use App\Services\AuditLogger;
use App\Services\Shm\ShmSavingsTransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ShmSavingsTransactionController extends Controller
{
    public function __construct(private readonly ShmSavingsTransactionService $service) {}

    /**
     * Tampilkan form setoran simpanan SHM.
     */
    public function createDeposit(ShmSavingsAccount $account): Response
    {
        $account->load(['customer', 'savingProduct']);

        return Inertia::render('Shm/Savings/Transactions/Deposit', [
            'account' => $account,
            'today_date' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * Simpan transaksi setoran simpanan SHM.
     */
    public function storeDeposit(StoreShmSavingsTransactionRequest $request, ShmSavingsAccount $account): RedirectResponse
    {
        if ($account->status !== 'active') {
            return redirect()->back()->with('error', 'Rekening simpanan SHM tidak aktif. Transaksi tidak dapat diproses.');
        }

        try {
            $transaction = $this->service->deposit($account, [
                ...$request->validated(),
                'processed_by' => auth()->id(),
            ]);

            AuditLogger::log(
                'shm.savings.transaction.deposited',
                ShmSavingsTransaction::class,
                $transaction->id,
                $transaction->transaction_number,
                sprintf('Setoran manual simpanan SHM sebesar Rp%s pada rekening %s.', number_format((float) $transaction->amount, 0, ',', '.'), $account->account_number),
                [
                    'account_number' => $account->account_number,
                    'amount' => (float) $transaction->amount,
                    'transaction_number' => $transaction->transaction_number,
                ]
            );
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('shm.savings.accounts.show', $account->id)
            ->with('success', 'Setoran simpanan SHM berhasil dicatat. Nomor transaksi: '.$transaction->transaction_number);
    }

    /**
     * Tampilkan form penarikan simpanan SHM.
     */
    public function createWithdraw(ShmSavingsAccount $account): Response
    {
        $account->load(['customer', 'savingProduct']);

        return Inertia::render('Shm/Savings/Transactions/Withdraw', [
            'account' => $account,
            'today_date' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * Simpan transaksi penarikan simpanan SHM.
     */
    public function storeWithdraw(StoreShmSavingsTransactionRequest $request, ShmSavingsAccount $account): RedirectResponse
    {
        if ($account->status !== 'active') {
            return redirect()->back()->with('error', 'Rekening simpanan SHM tidak aktif. Transaksi tidak dapat diproses.');
        }

        try {
            $transaction = $this->service->withdraw($account, [
                ...$request->validated(),
                'processed_by' => auth()->id(),
            ]);

            AuditLogger::log(
                'shm.savings.transaction.withdrawn',
                ShmSavingsTransaction::class,
                $transaction->id,
                $transaction->transaction_number,
                sprintf('Penarikan manual simpanan SHM sebesar Rp%s pada rekening %s.', number_format((float) $transaction->amount, 0, ',', '.'), $account->account_number),
                [
                    'account_number' => $account->account_number,
                    'amount' => (float) $transaction->amount,
                    'transaction_number' => $transaction->transaction_number,
                ]
            );
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('shm.savings.accounts.show', $account->id)
            ->with('success', 'Penarikan simpanan SHM berhasil dicatat. Nomor transaksi: '.$transaction->transaction_number);
    }

    /**
     * Tampilkan detail transaksi simpanan SHM.
     */
    public function show(ShmSavingsTransaction $transaction): Response
    {
        $transaction->load(['savingsAccount.customer', 'savingsAccount.savingProduct', 'processor', 'reversals']);

        return Inertia::render('Shm/Savings/Transactions/Show', [
            'transaction' => $transaction,
        ]);
    }
}
