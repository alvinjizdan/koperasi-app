<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Controller;
use App\Models\ShmSettlementDiscountPolicy;
use App\Services\Shm\ShmSettlementDiscountPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * T89B — Master Policy Diskon Bunga Pelunasan SHM (data-driven).
 *
 * Halaman operasional untuk mengatur persentase diskon per rentang sisa
 * bulan TANPA mengubah source code. Tanpa seed persentase default (AC7) —
 * seluruh nilai adalah keputusan admin.
 *
 * Otorisasi: baca = `shm.view`; tulis (create/update/toggle) =
 * `shm.settlement.policy.manage` (permission technical mengikuti pola
 * existing; TIDAK diberikan ke marketing — role authority bisnis PENDING).
 *
 * Seluruh validasi bisnis (rentang valid, persentase 0–100, tanpa overlap
 * policy aktif) ada di ShmSettlementDiscountPolicyService — bukan di React.
 */
class ShmSettlementDiscountPolicyController extends Controller
{
    public function __construct(private readonly ShmSettlementDiscountPolicyService $service) {}

    /**
     * Daftar policy diskon.
     */
    public function index(): Response
    {
        return Inertia::render('Shm/Loans/SettlementPolicies', [
            'policies' => ShmSettlementDiscountPolicy::query()
                ->orderBy('min_remaining_months')
                ->orderBy('id')
                ->get()
                ->map(fn (ShmSettlementDiscountPolicy $policy): array => [
                    'id' => $policy->id,
                    'min_remaining_months' => $policy->min_remaining_months,
                    'max_remaining_months' => $policy->max_remaining_months,
                    'discount_percent' => $policy->discount_percent,
                    'is_active' => $policy->is_active,
                    'effective_from' => $policy->effective_from?->toDateString(),
                    'effective_until' => $policy->effective_until?->toDateString(),
                    'notes' => $policy->notes,
                    'used_by_settlements' => $policy->settlements()->count(),
                ])->all(),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Buat policy baru (POST).
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePayload($request);

        try {
            $this->service->create($data);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('shm.loans.pelunasan.policies.index')
                ->with('error', $this->firstError($exception));
        }

        return redirect()
            ->route('shm.loans.pelunasan.policies.index')
            ->with('success', 'Policy diskon berhasil dibuat.');
    }

    /**
     * Ubah policy (PUT).
     */
    public function update(Request $request, ShmSettlementDiscountPolicy $policy): RedirectResponse
    {
        $data = $this->validatePayload($request);

        try {
            $this->service->update($policy, $data);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('shm.loans.pelunasan.policies.index')
                ->with('error', $this->firstError($exception));
        }

        return redirect()
            ->route('shm.loans.pelunasan.policies.index')
            ->with('success', 'Policy diskon diperbarui. Settlement lama memakai snapshot-nya sendiri.');
    }

    /**
     * Aktif/nonaktifkan policy (POST).
     */
    public function toggle(ShmSettlementDiscountPolicy $policy): RedirectResponse
    {
        try {
            $this->service->toggle($policy);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('shm.loans.pelunasan.policies.index')
                ->with('error', $this->firstError($exception));
        }

        return redirect()
            ->route('shm.loans.pelunasan.policies.index')
            ->with('success', 'Status policy diperbarui.');
    }

    /**
     * Validasi tipe dasar — aturan bisnis tetap di service.
     *
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'min_remaining_months' => ['required', 'integer', 'min:0'],
            'max_remaining_months' => ['required', 'integer', 'min:0'],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * Pesan error pertama (lintas key) untuk flash.
     */
    private function firstError(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            foreach ($messages as $message) {
                return (string) $message;
            }
        }

        return 'Policy diskon tidak dapat disimpan.';
    }
}
