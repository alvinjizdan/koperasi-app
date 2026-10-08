<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\StoreMarketingActivityRequest;
use App\Models\MarketingActivity;
use App\Models\Member;
use App\Models\User;
use App\Services\MarketingActivityService;
use App\Support\Distance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aktivitas/kunjungan Marketing.
 *
 * Pembatasan data ditegakkan di QUERY, bukan hanya lewat middleware:
 * pengguna tanpa `marketing.monitoring.view` selalu dibatasi pada aktivitasnya
 * sendiri, sehingga Marketing tidak dapat melihat kunjungan Marketing lain
 * meskipun ia memiliki endpoint yang sama.
 *
 * Tidak ada endpoint pelacakan lokasi: data hanya dibuat saat check-in.
 */
class MarketingActivityController extends Controller
{
    use InteractsWithSorting;

    public function __construct(private MarketingActivityService $activityService) {}

    /**
     * Aktivitas milik pengguna sendiri.
     *
     * Ticket 63: halaman dirender di bawah resources/js/Pages/Shm/ sehingga
     * tampil dalam layout SHM; query, filter, pagination, dan ownership tidak
     * berubah.
     */
    public function index(Request $request): Response
    {
        $query = $this->scopedQuery($request);

        $sortStatus = $this->applySorting($query, $request, [
            'checked_in_at' => 'checked_in_at',
            'member' => 'member_id',
            'result' => 'result',
            'created_at' => 'created_at',
        ], [['checked_in_at', 'desc']]);

        $activities = $this->paginate($query, $request, $sortStatus);

        return Inertia::render('Shm/Marketing/Activities/Index', [
            'activities' => $activities,
            'sort' => $sortStatus,
            'members' => $this->memberOptions(),
            'filters' => $this->filters($request),
            'can_monitor' => Gate::allows('marketing.monitoring.view'),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Monitoring seluruh aktivitas Marketing (hanya pemegang permission).
     */
    public function monitoring(Request $request): Response
    {
        $query = $this->scopedQuery($request);

        $sortStatus = $this->applySorting($query, $request, [
            'checked_in_at' => 'checked_in_at',
            'member' => 'member_id',
            'marketing' => 'marketing_id',
            'result' => 'result',
            'created_at' => 'created_at',
        ], [['checked_in_at', 'desc']]);

        $activities = $this->paginate($query, $request, $sortStatus);

        return Inertia::render('Shm/Marketing/Monitoring', [
            'activities' => $activities,
            'sort' => $sortStatus,
            'members' => $this->memberOptions(),
            'marketers' => $this->marketerOptions($request),
            'filters' => $this->filters($request),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Check-in kunjungan. Marketing dan waktu selalu berasal dari server.
     */
    public function store(StoreMarketingActivityRequest $request): RedirectResponse
    {
        $activity = $this->activityService->checkIn(
            $request->validated(),
            // marketing_id dari pengguna yang login, bukan dari input.
            (int) $request->user()->id,
        );

        return redirect()
            ->route('shm.marketing.activities.show', $activity->id)
            ->with('success', 'Check-in kunjungan berhasil dicatat pada '.$activity->checked_in_at->format('d/m/Y H:i').'.');
    }

    /**
     * Detail aktivitas. Marketing hanya dapat membuka aktivitasnya sendiri.
     */
    public function show(Request $request, MarketingActivity $activity): Response
    {
        $this->authorizeView($request, $activity);

        $activity->load(['member:id,full_name,member_number,address,latitude,longitude']);

        return Inertia::render('Shm/Marketing/Activities/Show', [
            'activity' => [
                'id' => $activity->id,
                'marketing_id' => $activity->marketing_id,
                'checked_in_at' => $activity->checked_in_at->format('Y-m-d H:i:s'),
                'latitude' => $activity->latitude,
                'longitude' => $activity->longitude,
                'result' => $activity->result,
                'notes' => $activity->notes,
                'member' => [
                    'id' => $activity->member->id,
                    'full_name' => $activity->member->full_name,
                    'member_number' => $activity->member->member_number,
                    'address' => $activity->member->address,
                ],
                // Jarak hanya informasi, bukan syarat check-in.
                'distance_meters' => Gate::allows('marketing.member_location.view')
                ? Distance::betweenInMeters(
                    $activity->member->latitude === null ? null : (float) $activity->member->latitude,
                    $activity->member->longitude === null ? null : (float) $activity->member->longitude,
                    $activity->latitude === null ? null : (float) $activity->latitude,
                    $activity->longitude === null ? null : (float) $activity->longitude,
                )
                : null,
            ],
            'can_update_result' => Gate::allows('marketing.result.update'),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Perbarui hasil dan catatan. Marketing hanya dapat mengubah aktivitasnya.
     */
    public function updateResult(
        Request $request,
        MarketingActivity $activity,
    ): RedirectResponse {
        $this->authorizeView($request, $activity);

        $validated = $request->validate([
            'result' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->activityService->updateResult($activity, $validated, (int) $request->user()->id);

        return redirect()
            ->back()
            ->with('success', 'Hasil kunjungan berhasil diperbarui.');
    }

    /**
     * Query aktivitas dengan pembatasan cakupan + filter.
     */
    private function scopedQuery(Request $request): Builder
    {
        $query = MarketingActivity::query()->with([
            'member:id,full_name,member_number',
            'marketing:id,name',
        ]);

        // Pembatasan inti: tanpa permission monitoring, hanya data sendiri.
        if (! Gate::allows('marketing.monitoring.view')) {
            $query->where('marketing_id', $request->user()->id);
        }

        $memberId = $request->input('member_id');
        $marketingId = $request->input('marketing_id');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        return $query
            ->when($memberId, fn ($q) => $q->where('member_id', (int) $memberId))
            ->when($marketingId, fn ($q) => $q->where('marketing_id', (int) $marketingId))
            ->when($dateFrom, fn ($q) => $q->where('checked_in_at', '>=', Carbon::parse($dateFrom)->startOfDay()))
            ->when($dateTo, fn ($q) => $q->where('checked_in_at', '<=', Carbon::parse($dateTo)->endOfDay()));
    }

    /**
     * Pagination server-side memakai whitelist per_page yang sudah ada.
     */
    private function paginate($query, Request $request, array $sortStatus): mixed
    {
        return $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (MarketingActivity $activity) => [
                'id' => $activity->id,
                'checked_in_at' => $activity->checked_in_at->format('Y-m-d H:i:s'),
                'result' => $activity->result,
                'notes' => $activity->notes,
                // Lokasi hanya dikirim ke halaman yang memang membutuhkannya.
                'latitude' => $activity->latitude,
                'longitude' => $activity->longitude,
                'member' => [
                    'id' => $activity->member?->id,
                    'full_name' => $activity->member?->full_name,
                    'member_number' => $activity->member?->member_number,
                ],
                'marketing' => [
                    'id' => $activity->marketing?->id,
                    'name' => $activity->marketing?->name,
                ],
            ]);
    }

    /**
     * Filter yang sudah dinormalisasi untuk props frontend.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'member_id' => $request->input('member_id', ''),
            'marketing_id' => $request->input('marketing_id', ''),
            'date_from' => $request->input('date_from', ''),
            'date_to' => $request->input('date_to', ''),
        ];
    }

    /**
     * Pilihan anggota untuk form check-in dan filter.
     *
     * Koordinat anggota TIDAK ikut dikirim ke halaman aktivitas; halaman detail
     * anggota yang terpisah dengan permission sendiri.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function memberOptions(): mixed
    {
        return Member::query()
            ->orderBy('full_name')
            ->limit(500)
            ->get(['id', 'full_name', 'member_number'])
            ->map(fn (Member $member) => [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'member_number' => $member->member_number,
            ]);
    }

    /**
     * Pilihan Marketing untuk filter monitoring (hanya bila punya permission).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function marketerOptions(Request $request): mixed
    {
        if (! Gate::allows('marketing.monitoring.view')) {
            return collect();
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['marketing', 'admin', 'superadmin']))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
            ]);
    }

    /**
     * Marketing hanya dapat membuka aktivitas miliknya sendiri.
     */
    private function authorizeView(Request $request, MarketingActivity $activity): void
    {
        abort_if(
            $activity->marketing_id !== (int) $request->user()->id
            && ! Gate::allows('marketing.monitoring.view'),
            403,
            'Anda tidak memiliki izin untuk membuka aktivitas ini.'
        );
    }
}
