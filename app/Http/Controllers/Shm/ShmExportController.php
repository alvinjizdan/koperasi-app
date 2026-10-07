<?php

namespace App\Http\Controllers\Shm;

use App\Enums\ShmApplicationStatus;
use App\Http\Controllers\Concerns\AuthorizesShmOwnership;
use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Controllers\Controller;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Modul Export — pemantauan data operasional (Ticket 55).
 *
 * EMPAT halaman ini HANYA MEMBACA: Data Nasabah Masuk, Approve, Reject, dan
 * Pencairan. Tidak ada CRUD, tidak ada workflow baru, tidak ada ekspor file —
 * yang ada hanyalah daftar pengajuan yang dipetakan ke tahap existing dari
 * ShmApplicationStatus:
 *
 *   masuk     => pengajuan yang sudah disubmit (submitted_at terisi)
 *   approve   => status approved
 *   reject    => status rejected
 *   pencairan => disbursed_at terisi (kolom existing; mencakup disbursed
 *                dan completed karena disbursed_at diisi saat pencairan)
 *
 * Mapping ini dibaca dari kolom/status yang benar-benar ada; tidak ada status
 * baru dan tidak ada aturan bisnis baru. Satu controller dan satu pipeline
 * query dipakai untuk keempat kategori.
 *
 * Otorisasi memakai permission `shm.view` existing. Pembatasan kepemilikan
 * (marketing hanya melihat pengajuannya sendiri tanpa `shm.view_all`)
 * tetap ditegakkan di level query — export bukan pintu bypass visibility.
 */
class ShmExportController extends Controller
{
    use AuthorizesShmOwnership;
    use InteractsWithSorting;

    /**
     * Konfigurasi kategori export.
     *
     * `condition` dipanggil pada query dasar dan hanya boleh memakai
     * kolom/status existing. Kolom tanggal ditampilkan sesuai kategori.
     *
     * @var array<string, array{title: string, description: string, event_column: string, event_label: string}>
     */
    private const CATEGORIES = [
        'masuk' => [
            'title' => 'Data Nasabah Masuk',
            'description' => 'Pengajuan yang sudah masuk ke alur (pernah disubmit).',
            'event_column' => 'submitted_at',
            'event_label' => 'Tanggal Pengajuan',
        ],
        'approve' => [
            'title' => 'Data Nasabah Approve',
            'description' => 'Pengajuan dengan status approved.',
            'event_column' => 'approved_at',
            'event_label' => 'Tanggal Approval',
        ],
        'reject' => [
            'title' => 'Data Nasabah Reject',
            'description' => 'Pengajuan dengan status rejected.',
            'event_column' => 'rejected_at',
            'event_label' => 'Tanggal Penolakan',
        ],
        'pencairan' => [
            'title' => 'Data Nasabah Pencairan',
            'description' => 'Pengajuan yang sudah dicairkan (disbursed_at terisi).',
            'event_column' => 'disbursed_at',
            'event_label' => 'Tanggal Pencairan',
        ],
    ];

    /**
     * Halaman daftar untuk satu kategori export.
     */
    public function index(Request $request, string $category): Response
    {
        $this->authorizeAction();

        abort_unless(isset(self::CATEGORIES[$category]), 404);

        $config = self::CATEGORIES[$category];

        $filters = [
            'search' => $request->input('search'),
            'type' => $request->input('type'),
        ];

        $query = $this->categoryQuery($category)
            // Eager load minimal: hanya relasi yang tampil di tabel (anti N+1),
            // bukan seluruh graph SHM.
            // latestOfMany dimuat tanpa daftar kolom: membatasi kolom pada
            // relasi latestOfMany membuat alias join ambigu di SQLite
            // (pola yang sama dengan ShmApprovalController).
            ->with([
                'customer:id,customer_number,name',
                'marketing:id,name',
                'latestMemorandum',
            ])
            ->when(
                $filters['type'],
                fn (Builder $q) => $q->whereHas(
                    'memorandums',
                    fn (Builder $memo) => $memo->where('type', $filters['type'])
                )
            )
            ->when($filters['search'], function (Builder $q, string $search): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('application_number', 'like', $term)
                        ->orWhereIn(
                            'shm_customer_id',
                            ShmCustomer::query()
                                ->select('id')
                                ->where(function (Builder $customer) use ($term): void {
                                    $customer->where('name', 'like', $term)
                                        ->orWhere('customer_number', 'like', $term);
                                })
                        );
                });
            });

        $sortStatus = $this->applySorting($query, $request, [
            'application_number' => 'application_number',
            'submitted_at' => 'submitted_at',
            'approved_at' => 'approved_at',
            'rejected_at' => 'rejected_at',
            'disbursed_at' => 'disbursed_at',
            'updated_at' => 'updated_at',
        ], [['updated_at', 'desc']]);

        $applications = $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (ShmApplication $application): array => $this->summaryRow($application));

        return Inertia::render('Shm/Exports/ExportList', [
            'category' => $category,
            'categoryConfig' => [
                'title' => $config['title'],
                'description' => $config['description'],
                'event_column' => $config['event_column'],
                'event_label' => $config['event_label'],
            ],
            'applications' => $applications,
            'sort' => $sortStatus,
            'filters' => $filters,
            'types' => ShmMemorandum::typeLabels(),
            'routes' => [
                'masuk' => route('shm.exports.masuk'),
                'approve' => route('shm.exports.approve'),
                'reject' => route('shm.exports.reject'),
                'pencairan' => route('shm.exports.pencairan'),
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Query pengajuan untuk satu kategori, dengan scope kepemilikan existing.
     *
     * Tanpa `shm.view_all`, pengguna hanya melihat pengajuannya sendiri —
     * aturan yang sama dengan modul SHM lain, ditegakkan di server.
     */
    private function categoryQuery(string $category): Builder
    {
        $query = ShmApplication::query();

        if (! $this->canViewAllShm()) {
            $query->where('marketing_id', auth()->id());
        }

        return match ($category) {
            // Kolom dan status yang dipakai di sini semuanya existing;
            // tidak ada status atau kolom baru yang dibuat.
            'masuk' => $query->whereNotNull('submitted_at'),
            'approve' => $query->where('status', ShmApplicationStatus::Approved),
            'reject' => $query->where('status', ShmApplicationStatus::Rejected),
            'pencairan' => $query->whereNotNull('disbursed_at'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Baris ringkas untuk tabel daftar. Tidak ada kolom nominal: tabel
     * pengajuan SHM memang tidak memiliki kolom finansial.
     *
     * @return array<string, mixed>
     */
    private function summaryRow(ShmApplication $application): array
    {
        $memorandum = $application->latestMemorandum;

        return [
            'id' => $application->id,
            'application_number' => $application->application_number,
            'status' => $application->status->value,
            'status_label' => $application->status->label(),
            'customer_label' => $application->customerLabel(),
            'customer_number' => $application->customer?->customer_number,
            'marketing' => $application->marketing?->name,
            'memorandum_type' => $memorandum?->type,
            'memorandum_type_label' => $memorandum?->typeLabel(),
            'submitted_at' => $application->submitted_at,
            'approved_at' => $application->approved_at,
            'rejected_at' => $application->rejected_at,
            'disbursed_at' => $application->disbursed_at,
            'updated_at' => $application->updated_at,
        ];
    }

    /**
     * Pemeriksaan permission read-only yang konsisten dengan modul SHM lain.
     */
    private function authorizeAction(): void
    {
        abort_unless(
            Gate::allows('shm.view'),
            403,
            'Anda tidak memiliki hak akses untuk modul export.'
        );
    }
}
