<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shm\StoreShmCustomerRequest;
use App\Http\Requests\Shm\UpdateShmCustomerRequest;
use App\Models\Member;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Services\ShmCustomerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master data Nasabah SHM.
 *
 * Nasabah SHM adalah business domain tersendiri, TERPISAH dari anggota
 * koperasi: halaman ini TIDAK PERNAH MENULIS tabel members dan tidak
 * mengonversi anggota koperasi menjadi nasabah SHM. Sejak T89C halaman ini
 * MEMBACA members hanya untuk selector anggota sumber (status aktif) —
 * pembuatan nasabah selalu merujuk anggota yang sudah ada, tanpa auto-create.
 *
 * Kepemilikan nasabah: master nasabah bersifat BERSAMA karena Marketing harus
 * dapat memilih nasabah yang sudah ada saat membuat pengajuan. Karena itu
 * daftar nasabah tidak dibatasi per Marketing. Yang tetap dibatasi kepemilikan
 * adalah DATA PENGAJUAN: pada halaman detail nasabah, Marketing tanpa
 * shm.view_all hanya melihat pengajuan miliknya sendiri.
 *
 * Penghapusan permanen (T102) hanya untuk nasabah tanpa dependensi rekaman
 * SHM; nasabah terpakai dinonaktifkan, bukan dihapus (lihat ShmCustomerService).
 */
class ShmCustomerController extends Controller
{
    use InteractsWithSorting;

    public function __construct(
        private readonly ShmCustomerService $customers,
    ) {}

    /**
     * Daftar nasabah SHM dengan pencarian, filter status, dan pagination.
     */
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status', 'all'),
        ];

        $query = ShmCustomer::query()
            ->with('member:id,member_number,full_name')
            ->search($filters['search'])
            ->status($filters['status']);

        // Jumlah pengajuan mengikuti cakupan akses pengguna supaya angka pada
        // daftar tidak membocorkan pengajuan milik Marketing lain.
        $this->scopeApplicationsCount($query, $request);

        $sortStatus = $this->applySorting($query, $request, [
            'customer_number' => 'customer_number',
            'name' => 'name',
            'nik' => 'nik',
            'is_active' => 'is_active',
            'created_at' => 'created_at',
        ], [['created_at', 'desc']]);

        $customers = $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (ShmCustomer $customer): array => [
                'id' => $customer->id,
                'customer_number' => $customer->customer_number,
                'name' => $customer->name,
                'nik' => $customer->nik,
                'phone' => $customer->phone,
                'address' => $customer->address,
                'is_active' => $customer->is_active,
                // Kode anggota sumber ditampilkan TERPISAH dari kode nasabah
                // (domain berbeda, sequence berbeda — T102).
                'member' => $customer->member ? [
                    'id' => $customer->member->id,
                    'member_number' => $customer->member->member_number,
                    'full_name' => $customer->member->full_name,
                ] : null,
                'applications_count' => $customer->applications_count,
                'created_at' => $customer->created_at,
            ]);

        return Inertia::render('Shm/Customers/Index', [
            'customers' => $customers,
            'sort' => $sortStatus,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'],
            ],
            'permissions' => [
                'can_create' => $request->user()->can('shm.customer.create'),
                'can_update' => $request->user()->can('shm.customer.update'),
                'can_delete' => $request->user()->can('shm.customer.delete'),
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Form pembuatan nasabah SHM.
     */
    public function create(Request $request): Response
    {
        $search = $request->input('search');

        // Daftar anggota koperasi eligible untuk dipilih sebagai sumber Nasabah SHM
        $members = Member::query()
            ->where('status', 'active')
            ->search($search)
            ->with(['savingsAccounts.savingProduct'])
            ->orderBy('full_name')
            ->limit(20)
            ->get()
            ->map(fn (Member $member): array => [
                'id' => $member->id,
                'member_number' => $member->member_number,
                'full_name' => $member->full_name,
                'identity_number' => $member->identity_number,
                'phone' => $member->phone,
                'address' => $member->address,
                'status' => $member->status,
                'has_active_shm_customer' => $member->shmCustomers()->where('is_active', true)->exists(),
            ]);

        return Inertia::render('Shm/Customers/Create', [
            'members' => $members,
            'filters' => [
                'search' => $search ?? '',
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Simpan nasabah SHM baru.
     */
    public function store(StoreShmCustomerRequest $request): RedirectResponse
    {
        $customer = $this->customers->create($request->payload());

        return to_route('shm.customers.show', $customer->id)
            ->with('success', 'Nasabah SHM berhasil ditambahkan.');
    }

    /**
     * Detail nasabah beserta riwayat pengajuannya.
     */
    public function show(Request $request, ShmCustomer $customer): Response
    {
        $applications = $this->scopedApplications($request)
            ->where('shm_customer_id', $customer->id)
            ->with('marketing:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (ShmApplication $application): array => [
                'id' => $application->id,
                'application_number' => $application->application_number,
                'status' => $application->status->value,
                'status_label' => $application->status->label(),
                'marketing' => $application->marketing?->name,
                'submitted_at' => $application->submitted_at,
                'created_at' => $application->created_at,
            ]);

        $customer->load('member');

        return Inertia::render('Shm/Customers/Show', [
            'customer' => [
                'id' => $customer->id,
                'customer_number' => $customer->customer_number,
                'name' => $customer->name,
                'nik' => $customer->nik,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'address' => $customer->address,
                'latitude' => $customer->latitude,
                'longitude' => $customer->longitude,
                'has_location' => $customer->hasLocation(),
                'is_active' => $customer->is_active,
                'created_at' => $customer->created_at,
                'member' => $customer->member ? [
                    'id' => $customer->member->id,
                    'member_number' => $customer->member->member_number,
                    'full_name' => $customer->member->full_name,
                    'identity_number' => $customer->member->identity_number,
                    'status' => $customer->member->status,
                ] : null,
            ],
            'applications' => $applications,
            'permissions' => [
                'can_update' => $request->user()->can('shm.customer.update'),
                'can_delete' => $request->user()->can('shm.customer.delete'),
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Form edit nasabah SHM.
     */
    public function edit(ShmCustomer $customer): Response
    {
        $customer->load('member:id,member_number,full_name');

        return Inertia::render('Shm/Customers/Edit', [
            'customer' => [
                'id' => $customer->id,
                'customer_number' => $customer->customer_number,
                'name' => $customer->name,
                'nik' => $customer->nik,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'address' => $customer->address,
                'latitude' => $customer->latitude,
                'longitude' => $customer->longitude,
                'is_active' => $customer->is_active,
                // Relasi anggota sumber hanya ditampilkan (read-only):
                // asosiasi member TIDAK dapat diubah dari form (T102).
                'member' => $customer->member ? [
                    'id' => $customer->member->id,
                    'member_number' => $customer->member->member_number,
                    'full_name' => $customer->member->full_name,
                ] : null,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Simpan perubahan data nasabah SHM.
     */
    public function update(UpdateShmCustomerRequest $request, ShmCustomer $customer): RedirectResponse
    {
        $this->customers->update($customer, $request->payload());

        return to_route('shm.customers.show', $customer->id)
            ->with('success', 'Data nasabah SHM berhasil diperbarui.');
    }

    /**
     * Aktifkan atau nonaktifkan nasabah.
     *
     * Nasabah nonaktif tidak dihapus; pengajuan lama tetap merujuk ke barisnya.
     * Ini sengaja tidak memakai hard delete.
     */
    public function toggleActive(Request $request, ShmCustomer $customer): RedirectResponse
    {
        $isActive = $request->boolean('is_active');

        $this->customers->setActive($customer, $isActive);

        return back()->with(
            'success',
            $isActive
                ? 'Nasabah SHM berhasil diaktifkan.'
                : 'Nasabah SHM berhasil dinonaktifkan.',
        );
    }

    /**
     * Hapus permanen nasabah SHM (T102).
     *
     * Diproteksi dua lapis: route middleware `permission:shm.customer.delete`
     * (otorisasi teknis via Role & Permission) dan guard dependency di
     * service (nasabah yang masih dipakai rekaman SHM ditolak dengan pesan
     * jelas — tanpa cascade, tanpa menghapus anggota sumber).
     */
    public function destroy(ShmCustomer $customer): RedirectResponse
    {
        try {
            $this->customers->delete($customer);
        } catch (ValidationException $exception) {
            // Alasan penolakan (dependensi masih ada) ditampilkan sebagai
            // flash error pada halaman sebelumnya; tidak ada baris terhapus.
            return redirect()
                ->back()
                ->withErrors($exception->errors())
                ->with('error', (string) $exception->getMessage());
        }

        return to_route('shm.customers.index')
            ->with('success', 'Nasabah SHM berhasil dihapus.');
    }

    /**
     * Query pengajuan yang boleh dilihat pengguna saat ini.
     *
     * Tanpa shm.view_all, pengguna hanya melihat pengajuannya sendiri. Ini
     * menjaga kepemilikan pengajuan Ticket 44/45 tetap berlaku meskipun
     * master nasabah bersifat bersama.
     *
     * @return Builder<ShmApplication>
     */
    private function scopedApplications(Request $request): Builder
    {
        $query = ShmApplication::query();

        if (! $request->user()->can('shm.view_all')) {
            $query->where('marketing_id', $request->user()->id);
        }

        return $query;
    }

    /**
     * Tambahkan hitungan pengajuan yang sudah dibatasi kepemilikannya.
     *
     * @param  Builder<ShmCustomer>  $query
     */
    private function scopeApplicationsCount(Builder $query, Request $request): void
    {
        $query->withCount(['applications' => function ($q) use ($request): void {
            if (! $request->user()->can('shm.view_all')) {
                $q->where('marketing_id', $request->user()->id);
            }
        }]);
    }
}
