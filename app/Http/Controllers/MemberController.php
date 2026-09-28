<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\InstallmentSchedule;
use App\Models\Member;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    /**
     * Tampilkan daftar anggota dengan pencarian dan pagination.
     */
    public function index(Request $request): Response
    {
        $search = $request->input('search');
        $status = $request->input('status', 'all');

        $members = Member::query()
            ->search($search)
            ->status($status)
            ->orderBy('created_at', 'desc')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Anggota/Index', [
            'members' => $members,
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
     * Tampilkan form untuk menambah anggota baru.
     */
    public function create(): Response
    {
        // Generasi nomor anggota usulan berdasarkan sequence ID terakhir untuk membantu pengguna
        $lastId = Member::max('id') ?? 0;
        $nextId = $lastId + 1;
        $suggestedNumber = 'ANG-'.str_pad((string) $nextId, 4, '0', STR_PAD_LEFT);

        return Inertia::render('Anggota/Create', [
            'suggested_member_number' => $suggestedNumber,
            'today_date' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * Simpan data anggota baru ke dalam database.
     */
    public function store(StoreMemberRequest $request): RedirectResponse
    {
        $member = Member::create($request->validated());

        AuditLogger::log(
            'member.created',
            Member::class,
            $member->id,
            $member->full_name,
            sprintf('Anggota baru terdaftar dengan nomor %s.', $member->member_number),
            [
                'member_number' => $member->member_number,
                'full_name' => $member->full_name,
                'joined_at' => $member->joined_at?->format('Y-m-d'),
                'status' => $member->status,
            ],
        );

        return redirect()
            ->route('anggota.show', $member->id)
            ->with('success', 'Anggota baru dengan nomor '.$member->member_number.' berhasil terdaftar.');
    }

    /**
     * Tampilkan rincian detail data anggota.
     */
    public function show(Member $member): Response
    {
        // Ticket 17 BUG-02: ringkasan finansial dihitung dari sumber kebenaran
        // modul masing-masing (bukan angka statis) dan mengikuti definisi yang
        // sama dengan laporan: saldo simpanan dari savings_accounts.balance,
        // sisa kewajiban pinjaman aktif dari total_owed (pokok+bunga+denda).
        $member->load(['savingsAccounts:id,member_id,balance']);

        $activeLoanIds = $member->loans()
            ->whereIn('status', ['ACTIVE', 'DISBURSED'])
            ->pluck('id');

        $activeLoansOutstanding = round((float) InstallmentSchedule::query()
            ->whereIn('loan_id', $activeLoanIds)
            ->selectRaw('COALESCE(SUM(
                principal_amount - paid_amount
                + interest_amount - interest_paid
                + penalty_accrued - penalty_paid
            ), 0) as owed')->value('owed'), 2);

        return Inertia::render('Anggota/Show', [
            'member' => $member,
            'financial_summary' => [
                'total_savings_balance' => round((float) $member->savingsAccounts->sum('balance'), 2),
                'savings_account_count' => $member->savingsAccounts->count(),
                'active_loans_outstanding' => $activeLoansOutstanding,
                'active_loan_count' => $activeLoanIds->count(),
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Tampilkan form untuk mengedit data anggota.
     */
    public function edit(Member $member): Response
    {
        return Inertia::render('Anggota/Edit', [
            'member' => $member,
        ]);
    }

    /**
     * Perbarui data anggota di database.
     */
    public function update(UpdateMemberRequest $request, Member $member): RedirectResponse
    {
        $member->update($request->validated());

        AuditLogger::logChanges(
            $member,
            'member.updated',
            ['full_name', 'identity_number', 'phone', 'address', 'joined_at', 'status'],
            'Data anggota diperbarui.'
        );

        return redirect()
            ->route('anggota.show', $member->id)
            ->with('success', 'Data anggota '.$member->full_name.' berhasil diperbarui.');
    }

    /**
     * Nonaktifkan status keanggotaan (mengubah status menjadi inactive).
     */
    public function deactivate(Member $member): RedirectResponse
    {
        if ($member->status === 'inactive') {
            return redirect()
                ->back()
                ->with('error', 'Anggota sudah dalam status Nonaktif.');
        }

        $member->update(['status' => 'inactive']);

        AuditLogger::log(
            'member.deactivated',
            Member::class,
            $member->id,
            $member->full_name,
            sprintf('Status anggota %s diubah menjadi Nonaktif.', $member->member_number),
            [
                'member_number' => $member->member_number,
                'from_status' => 'active',
                'to_status' => 'inactive',
            ],
        );

        return redirect()
            ->back()
            ->with('success', 'Status anggota '.$member->full_name.' berhasil diubah menjadi Nonaktif.');
    }
}
