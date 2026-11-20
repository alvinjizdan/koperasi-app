<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Concerns\AuthorizesShmOwnership;
use App\Http\Controllers\Controller;
use App\Models\ShmLoanSettlement;
use App\Models\ShmSettlementRefund;
use App\Services\Shm\ShmSettlementRefundService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TICKET 42 — Controller Operasional Refund Pelunasan SHM.
 */
class ShmSettlementRefundController extends Controller
{
    use AuthorizesShmOwnership;

    public function __construct(
        private readonly ShmSettlementRefundService $refundService,
    ) {}

    /**
     * Tampilkan daftar permohonan refund.
     */
    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $search = $request->query('search');

        $query = ShmSettlementRefund::query()
            ->with([
                'settlement:id,settlement_reference,overpayment_amount',
                'loan:id,loan_number',
                'customer:id,name,customer_number',
                'requester:id,name',
                'approver:id,name',
                'payer:id,name',
            ])
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->when($search, function (Builder $q, $s) {
                $q->where(function (Builder $sub) use ($s) {
                    $sub->where('refund_reference', 'like', "%{$s}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))
                        ->orWhereHas('loan', fn ($l) => $l->where('loan_number', 'like', "%{$s}%"))
                        ->orWhereHas('settlement', fn ($st) => $st->where('settlement_reference', 'like', "%{$s}%"));
                });
            })
            ->orderByDesc('id');

        $refunds = $query->paginate(15)->withQueryString();

        // Mask bank account number pada daftar umum untuk proteksi data nasabah
        $refunds->getCollection()->transform(function (ShmSettlementRefund $refund) {
            $details = $refund->payout_details ?? [];
            if (! empty($details['account_number'])) {
                $acc = (string) $details['account_number'];
                $len = strlen($acc);
                $details['account_number'] = $len > 4 ? str_repeat('*', max(0, $len - 4)).substr($acc, -4) : $acc;
            }
            $refund->masked_payout_details = $details;

            return $refund;
        });

        return Inertia::render('Shm/Loans/Refunds/Index', [
            'refunds' => $refunds,
            'filters' => [
                'status' => $status,
                'search' => $search,
            ],
        ]);
    }

    /**
     * Tampilkan detail transaksi refund.
     */
    public function show(Request $request, ShmSettlementRefund $refund): Response
    {
        $refund->load([
            'settlement:id,settlement_reference,overpayment_amount,settlement_amount,settlement_date,status',
            'loan:id,loan_number,principal',
            'customer:id,name,customer_number,phone',
            'requester:id,name',
            'approver:id,name',
            'rejecter:id,name',
            'canceller:id,name',
            'payer:id,name',
            'reverser:id,name',
            'recoveryRequester:id,name',
            'recoverer:id,name',
            'recoveries' => fn ($q) => $q->with(['requester:id,name', 'confirmer:id,name'])->orderByDesc('id'),
            'accountingLinks' => fn ($q) => $q->with(['journalEntry:id,journal_number,status,transaction_date', 'linker:id,name', 'cashTransaction:id,transaction_number'])->orderByDesc('id'),
        ]);

        $summary = $this->refundService->calculateRefundableSummary($refund->settlement);

        return Inertia::render('Shm/Loans/Refunds/Show', [
            'refund' => $refund,
            'summary' => $summary,
        ]);
    }

    /**
     * Ajukan permohonan refund baru untuk sebuah settlement.
     */
    public function store(Request $request, ShmLoanSettlement $settlement): RedirectResponse
    {
        $this->authorizeLoanRecordOwnership($request, $settlement->loan);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'payout_method' => ['required', 'string', 'in:cash,bank_transfer'],
            'payout_details' => ['nullable', 'array'],
            'payout_details.bank_name' => ['nullable', 'string', 'max:100'],
            'payout_details.account_number' => ['nullable', 'string', 'max:50'],
            'payout_details.account_holder' => ['nullable', 'string', 'max:150'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $refund = $this->refundService->request($settlement, $data, (int) $request->user()->id);

        return redirect()
            ->back()
            ->with('success', sprintf('Permohonan refund %s sebesar Rp %s berhasil dibuat dan menunggu approval.', $refund->refund_reference, number_format((float) $refund->amount, 0, ',', '.')));
    }

    /**
     * Setujui permohonan refund.
     */
    public function approve(Request $request, ShmSettlementRefund $refund): RedirectResponse
    {
        $this->refundService->approve($refund, (int) $request->user()->id);

        return redirect()
            ->back()
            ->with('success', sprintf('Permohonan refund %s disetujui. Siap dieksekusi pembayarannya.', $refund->refund_reference));
    }

    /**
     * Tolak permohonan refund.
     */
    public function reject(Request $request, ShmSettlementRefund $refund): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->refundService->reject($refund, $data['reason'], (int) $request->user()->id);

        return redirect()
            ->back()
            ->with('success', sprintf('Permohonan refund %s telah ditolak.', $refund->refund_reference));
    }

    /**
     * Batalkan permohonan refund.
     */
    public function cancel(Request $request, ShmSettlementRefund $refund): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->refundService->cancel($refund, $data['reason'], (int) $request->user()->id);

        return redirect()
            ->back()
            ->with('success', sprintf('Refund %s telah dibatalkan.', $refund->refund_reference));
    }

    /**
     * Eksekusi pembayaran refund.
     */
    public function executePayout(Request $request, ShmSettlementRefund $refund): RedirectResponse
    {
        $rules = [
            'payout_notes' => ['nullable', 'string', 'max:500'],
            'evidence' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf'],
        ];

        if ($refund->payout_method === ShmSettlementRefund::METHOD_CASH) {
            $rules['recipient_name'] = ['required', 'string', 'max:150'];
            $rules['payout_reference'] = ['nullable', 'string', 'max:100'];
        } else {
            $rules['payout_reference'] = ['required', 'string', 'max:100'];
            $rules['destination_bank'] = ['nullable', 'string', 'max:100'];
            $rules['destination_account_number'] = ['nullable', 'string', 'max:50'];
            $rules['destination_account_holder'] = ['nullable', 'string', 'max:150'];
        }

        $data = $request->validate($rules);

        $evidencePath = $request->file('evidence')->store('shm/refund_evidence', 'public');

        $this->refundService->executePayout($refund, [
            'payout_reference' => $data['payout_reference'] ?? null,
            'payout_notes' => $data['payout_notes'] ?? null,
            'payout_evidence_path' => $evidencePath,
            'recipient_name' => $data['recipient_name'] ?? null,
            'destination_bank' => $data['destination_bank'] ?? null,
            'destination_account_number' => $data['destination_account_number'] ?? null,
            'destination_account_holder' => $data['destination_account_holder'] ?? null,
        ], (int) $request->user()->id);

        return redirect()
            ->back()
            ->with('success', sprintf('Pembayaran refund %s sebesar Rp %s berhasil dicatat.', $refund->refund_reference, number_format((float) $refund->amount, 0, ',', '.')));
    }

    /**
     * Ajukan pembalikan (reversal) atas pembayaran refund (paid -> recovery_pending).
     */
    public function reverse(Request $request, ShmSettlementRefund $refund): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->refundService->requestReversal($refund, $data['reason'], (int) $request->user()->id);

        return redirect()
            ->back()
            ->with('success', sprintf('Permohonan pembalikan refund %s berhasil dicatat (status: recovery_pending). Saldo refundable belum bertambah hingga pengembalian dana riil dikonfirmasi.', $refund->refund_reference));
    }

    /**
     * Konfirmasi penerimaan pengembalian dana riil (recovery) atas refund yang dibalikkan.
     */
    public function confirmRecovery(Request $request, ShmSettlementRefund $refund): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'recovery_reference' => ['required', 'string', 'max:100'],
            'recovery_method' => ['required', 'string', 'in:cash,bank_transfer'],
            'recovery_notes' => ['nullable', 'string', 'max:500'],
            'evidence' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf'],
        ]);

        $evidencePath = $request->file('evidence')->store('shm/recovery_evidence', 'public');

        $this->refundService->confirmRecovery($refund, [
            'amount' => $data['amount'],
            'recovery_reference' => $data['recovery_reference'],
            'recovery_method' => $data['recovery_method'],
            'recovery_notes' => $data['recovery_notes'] ?? null,
            'recovery_evidence_path' => $evidencePath,
        ], (int) $request->user()->id);

        return redirect()
            ->back()
            ->with('success', sprintf('Pengembalian dana riil atas refund %s sebesar Rp %s berhasil dikonfirmasi.', $refund->refund_reference, number_format((float) $data['amount'], 0, ',', '.')));
    }
}
