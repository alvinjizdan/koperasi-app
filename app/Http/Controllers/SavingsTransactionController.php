<?php

namespace App\Http\Controllers;

use App\Http\Requests\DepositRequest;
use App\Http\Requests\ReverseSavingsTransactionRequest;
use App\Http\Requests\WithdrawRequest;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\SavingsTransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SavingsTransactionController extends Controller
{
    public function __construct(private SavingsTransactionService $service) {}

    /**
     * Tampilkan form setoran untuk rekening tertentu.
     */
    public function createDeposit(SavingsAccount $savingsAccount): Response
    {
        $savingsAccount->load(['member', 'savingProduct']);

        return Inertia::render('Simpanan/Transaksi/Deposit', [
            'account' => $savingsAccount,
            'today_date' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * Proses setoran.
     */
    public function storeDeposit(DepositRequest $request, SavingsAccount $savingsAccount): RedirectResponse
    {
        if ($savingsAccount->status !== 'active') {
            return redirect()->back()->with('error', 'Rekening tidak aktif. Transaksi tidak dapat diproses.');
        }

        try {
            $transaction = $this->service->deposit($savingsAccount, [
                ...$request->validated(),
                'processed_by' => auth()->id(),
            ]);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('simpanan-rekening.show', $savingsAccount->id)
            ->with('success', 'Setoran berhasil dicatat. Nomor transaksi: '.$transaction->transaction_number);
    }

    /**
     * Tampilkan form penarikan untuk rekening tertentu.
     */
    public function createWithdraw(SavingsAccount $savingsAccount): Response
    {
        $savingsAccount->load(['member', 'savingProduct']);

        return Inertia::render('Simpanan/Transaksi/Withdraw', [
            'account' => $savingsAccount,
            'today_date' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * Proses penarikan.
     */
    public function storeWithdraw(WithdrawRequest $request, SavingsAccount $savingsAccount): RedirectResponse
    {
        if ($savingsAccount->status !== 'active') {
            return redirect()->back()->with('error', 'Rekening tidak aktif. Transaksi tidak dapat diproses.');
        }

        try {
            $transaction = $this->service->withdraw($savingsAccount, [
                ...$request->validated(),
                'processed_by' => auth()->id(),
            ]);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('simpanan-rekening.show', $savingsAccount->id)
            ->with('success', 'Penarikan berhasil dicatat. Nomor transaksi: '.$transaction->transaction_number);
    }

    /**
     * Tampilkan detail transaksi.
     */
    public function show(SavingsTransaction $savingsTransaction): Response
    {
        $savingsTransaction->load(['savingsAccount.member', 'savingsAccount.savingProduct', 'operator']);

        return Inertia::render('Simpanan/Transaksi/Show', [
            'transaction' => $savingsTransaction,
        ]);
    }

    /**
     * Koreksi transaksi melalui reversal teraudit (Ticket 15, A.7/A.8).
     */
    public function reverse(ReverseSavingsTransactionRequest $request, SavingsTransaction $savingsTransaction): RedirectResponse
    {
        try {
            $reversal = $this->service->reverse(
                $savingsTransaction,
                (int) auth()->id(),
                (string) $request->validated('reason'),
            );
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        }

        return redirect()
            ->route('simpanan-rekening.show', $reversal->savings_account_id)
            ->with('success', 'Reversal berhasil dicatat. Nomor transaksi: '.$reversal->transaction_number);
    }
}
