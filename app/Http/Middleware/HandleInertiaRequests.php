<?php

namespace App\Http\Middleware;

use App\Models\ShmApproval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                // Daftar nama permission efektif untuk penyesuaian tampilan (sidebar).
                // Otorisasi tetap diverifikasi ulang di backend pada setiap request.
                'permissions' => $user !== null
                    ? $user->effectivePermissions()->pluck('name')->all()
                    : [],
                // T105: penanda khusus tampilan agar kontrol yang pasti ditolak
                // backend (edit role sistem, dsb.) dapat disembunyikan. Bukan
                // security boundary — backend tetap melakukan enforcement.
                'is_superadmin' => $user !== null && $user->isSuperadmin(),
            ],
            // Ticket 57: jumlah approval pending untuk badge sidebar, dihitung
            // scope-safe HANYA untuk pengguna yang memegang permission approval
            // existing (shm.approval.decide) atau superadmin — pengguna lain
            // tidak menerima angkanya sama sekali, sehingga jumlah pending
            // tidak bisa dipakai untuk memindai data orang lain. Satu query
            // COUNT per request, tidak memuat baris, tanpa cache/counter.
            'shm' => $user !== null
                && ($user->isSuperadmin() || Gate::allows('shm.approval.decide'))
                ? [
                    'pending_approvals' => [
                        'approvals' => ShmApproval::pendingCountForUser(
                            $user->isSuperadmin() || Gate::allows('shm.view_all'),
                            (int) $user->id,
                        ),
                    ],
                ]
                : null,
        ];
    }
}
