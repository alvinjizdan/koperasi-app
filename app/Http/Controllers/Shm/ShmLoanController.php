<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Concerns\AuthorizesShmOwnership;
use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shm\LoanSimulationRequest;
use App\Http\Requests\Shm\StoreShmLoanApplicationRequest;
use App\Http\Requests\Shm\StoreShmLoanProductRequest;
use App\Http\Requests\Shm\UpdateShmLoanApplicationRequest;
use App\Http\Requests\Shm\UpdateShmLoanProductRequest;
use App\Models\AuditLog;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanApplicationCollateral;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanProduct;
use App\Services\AuditLogger;
use App\Services\Shm\ShmLoanApplicationPdf;
use App\Services\Shm\ShmLoanApplicationService;
use App\Services\Shm\ShmLoanDisbursementService;
use App\Services\Shm\ShmLoanSimulationPdf;
use App\Services\Shm\ShmLoanSimulationService;
use App\Services\Shm\ShmRepaymentScheduleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Ticket 64 — Modul Pinjaman SHM: master produk, record pinjaman, simulasi,
 * dan ekspor PDF hasil simulasi.
 *
 * Domain finansial SHM TERPISAH dari pinjaman koperasi: query hanya membaca
 * `shm_loan_*` dan `shm_customers`, tidak pernah menyentuh `loans`,
 * `installment_*`, `payments`, maupun `members`.
 *
 * Rate bunga/jasa resmi = f(produk, tenor) dari matriks produk (T82).
 * Halaman simulasi mewajibkan pemilihan metode & periode secara eksplisit
 * (sandbox); baseline resmi untuk produk = flat + bulanan (T79) dan
 * pembangkitan jadwal selalu memakai kontrak eksplisit.
 *
 * Otorisasi halaman memakai permission `shm.view` yang sudah ada. CRUD master
 * produk (T98-FIX) memakai permission terpisah `shm.product.manage` (pola
 * BUG-01: baca ≠ tulis); tidak ada mekanisme ownership tambahan (data
 * finansial melekat pada nasabah SHM yang merupakan master bersama, sama
 * seperti modul Nasabah).
 */
class ShmLoanController extends Controller
{
    use AuthorizesShmOwnership;
    use InteractsWithSorting;

    public function __construct(
        private readonly ShmLoanSimulationService $simulation,
        private readonly ShmLoanSimulationPdf $pdf,
        private readonly ShmLoanApplicationService $applications,
        private readonly ShmLoanApplicationPdf $applicationPdf,
        private readonly ShmLoanDisbursementService $disbursement,
        private readonly ShmRepaymentScheduleService $schedule,
    ) {}

    /**
     * Daftar produk Pinjaman SHM.
     */
    public function products(Request $request): Response
    {
        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status', 'all'),
        ];

        $query = ShmLoanProduct::query()
            ->withCount('loans')
            ->with('rateTiers')
            ->search($filters['search']);

        if ($filters['status'] === 'active') {
            $query->where('is_active', true);
        } elseif ($filters['status'] === 'inactive') {
            $query->where('is_active', false);
        }

        $sortStatus = $this->applySorting($query, $request, [
            'code' => 'code',
            'name' => 'name',
            'is_active' => 'is_active',
            'created_at' => 'created_at',
        ], [['created_at', 'desc']]);

        $products = $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (ShmLoanProduct $product): array => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                // T82 — kategori & syarat produk dari master (read-only).
                'category' => $product->category,
                'description' => $product->description,
                'eligibility_summary' => $product->eligibility_summary,
                'is_active' => $product->is_active,
                // Tier rate EXAKT per rentang tenor (monthly, flat).
                'rate_tiers' => $product->rateTiers->map(fn ($tier): array => [
                    'min_tenor_months' => $tier->min_tenor_months,
                    'max_tenor_months' => $tier->max_tenor_months,
                    'rate_percent' => (float) $tier->rate_percent,
                    'rate_period' => $tier->rate_period,
                    'calculation_method' => $tier->calculation_method,
                ])->all(),
                'loans_count' => $product->loans_count ?? 0,
                'created_at' => $product->created_at,
            ]);

        return Inertia::render('Shm/Loans/Products', [
            'products' => $products,
            'sort' => $sortStatus,
            // Tombol Tambah/Edit hanya tampil bagi pemegang shm.product.manage;
            // backend tetap menegakkan permission pada route tulis.
            'canManage' => Gate::allows('shm.product.manage'),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'],
            ],
        ]);
    }

    /**
     * Form pembuatan master produk Pinjaman SHM (T98-FIX).
     */
    public function createProduct(): Response
    {
        return Inertia::render('Shm/Loans/ProductForm', [
            'product' => null,
            'categories' => $this->productCategories(),
        ]);
    }

    /**
     * Simpan master produk Pinjaman SHM baru (T98-FIX).
     *
     * Hanya menulis baris `shm_loan_products` (skema existing, tanpa
     * migrasi). Produk baru TANPA tier rate: ShmProductRateService tetap
     * menolak pengajuan sampai tier diisi — tanpa fallback 2% dan tanpa
     * pembuatan tier otomatis.
     */
    public function storeProduct(StoreShmLoanProductRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $product = ShmLoanProduct::query()->create($data);

        return redirect()
            ->route('shm.loans.products.index')
            ->with('success', "Produk pinjaman SHM {$product->code} berhasil ditambahkan.");
    }

    /**
     * Form edit master produk Pinjaman SHM (T98-FIX).
     */
    public function editProduct(ShmLoanProduct $product): Response
    {
        return Inertia::render('Shm/Loans/ProductForm', [
            'product' => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'category' => $product->category,
                'description' => $product->description,
                'eligibility_summary' => $product->eligibility_summary,
                'is_active' => $product->is_active,
                'loans_count' => $product->loans()->count(),
            ],
            'categories' => $this->productCategories(),
        ]);
    }

    /**
     * Perbarui master produk Pinjaman SHM (T98-FIX).
     *
     * Hanya menyentuh baris master. Histori pengajuan/pinjaman TIDAK
     * berubah: rate pinjaman lama tetap snapshot di `shm_loans.rate_percent`
     * dan produk nonaktif hanya menolak pengajuan/penggunaan BARU.
     */
    public function updateProduct(ShmLoanProduct $product, UpdateShmLoanProductRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $product->update($data);

        return redirect()
            ->route('shm.loans.products.index')
            ->with('success', "Produk pinjaman SHM {$product->code} berhasil diperbarui.");
    }

    /**
     * Tiga kategori resmi T82 untuk dropdown form produk.
     *
     * @return array<int, array{id: string, label: string}>
     */
    private function productCategories(): array
    {
        return [
            ['id' => ShmLoanProduct::CATEGORY_PERUMAHAN, 'label' => 'Perumahan'],
            ['id' => ShmLoanProduct::CATEGORY_PERKAMPUNGAN, 'label' => 'Perkampungan'],
            ['id' => ShmLoanProduct::CATEGORY_RO, 'label' => 'RO'],
        ];
    }

    /**
     * Daftar record Pinjaman nasabah SHM.
     */
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status', 'all'),
        ];

        $query = ShmLoan::query()
            ->with([
                'customer:id,customer_number,name',
                'product:id,code,name',
            ])
            ->search($filters['search'])
            ->status($filters['status']);

        // Ticket 70 — scope kepemilikan di QUERY (server-side), bukan hanya
        // dengan menyembunyikan baris di UI. Kepemilikan mengikuti pengajuan
        // asal pencairan; tanpa `shm.view_all` pengguna hanya melihat pinjaman
        // hasil pengajuan miliknya sendiri.
        if (! $this->canViewAllShm()) {
            $query->ownedBy((int) $request->user()->id);
        }

        $sortStatus = $this->applySorting($query, $request, [
            'loan_number' => 'loan_number',
            'principal' => 'principal',
            'tenor_months' => 'tenor_months',
            'rate_percent' => 'rate_percent',
            'status' => 'status',
            'disbursed_at' => 'disbursed_at',
            'created_at' => 'created_at',
        ], [['created_at', 'desc']]);

        $loans = $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (ShmLoan $loan): array => $this->presentLoan($loan));

        return Inertia::render('Shm/Loans/Index', [
            'loans' => $loans,
            'sort' => $sortStatus,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'],
            ],
        ]);
    }

    /**
     * Detail pinjaman SHM: plafon, tenor, rate, saldo pokok, dan tabel
     * angsuran — hanya bila record menyimpan pilihan eksplisit metode &
     * periode rate. Tanpa keduanya, formula SHM masih PENDING dan tidak ada
     * jadwal yang boleh ditampilkan.
     */
    public function show(Request $request, ShmLoan $loan): Response
    {
        // Ticket 70 — otorisasi SEBELUM detail dikembalikan. Ownership
        // mengikuti pengajuan asal pencairan (AuthorizesShmOwnership).
        $this->authorizeLoanRecordOwnership($request, $loan);

        // Relasi di-eager-load sekali di sini sehingga seluruh section detail
        // (nasabah, pengajuan, jaminan, barang agunan) tidak menghasilkan
        // query per baris (tanpa N+1).
        $loan->load([
            'customer',
            'product',
            'application.customer',
            'application.collaterals',
            'application.assets',
        ]);

        $result = null;

        if ($loan->hasExplicitCalculation()) {
            $result = $this->simulation->simulate([
                'principal' => (float) $loan->principal,
                'tenor_months' => $loan->tenor_months,
                'rate_percent' => (float) $loan->rate_percent,
                'method' => $loan->calculation_method,
                'rate_period' => $loan->rate_period,
            ]);
        }

        // T83 — jadwal angsuran TERSIMPAN (shm_loan_installments) disajikan
        // dari database; halaman ini TIDAK pernah membangkitkan jadwal.
        $installments = $loan->installments;

        return Inertia::render('Shm/Loans/Show', [
            'loan' => $this->presentLoanDetail($loan),
            'result' => $result,
            'schedule' => [
                'generated' => $installments->isNotEmpty(),
                'installments' => $installments->map(fn (ShmLoanInstallment $installment): array => [
                    'installment_number' => $installment->installment_number,
                    'due_date' => $installment->due_date?->format('Y-m-d'),
                    'principal_amount' => $installment->principal_amount,
                    'interest_amount' => $installment->interest_amount,
                    'total_amount' => $installment->total_amount,
                    'paid_amount' => $installment->paid_amount,
                    'remaining_amount' => $installment->remaining_amount,
                    'status' => $installment->status,
                ])->values()->all(),
            ],
            // Tombol hanya ditawarkan saat jadwal belum ada dan pinjaman
            // memenuhi prasyarat dasar; guard sesungguhnya tetap di service.
            'can_generate_schedule' => $installments->isEmpty()
                && $loan->status === ShmLoan::STATUS_ACTIVE
                && $loan->disbursed_at !== null,
        ]);
    }

    /**
     * T83 — ACTION EKSPLISIT pembangkitan jadwal angsuran.
     *
     * Hanya dipanggil saat officer menekan tombol pada Detail Pinjaman —
     * TIDAK pernah otomatis saat pencairan/approval/membuka halaman.
     * Controller hanya orchestration: otorisasi (scope kepemilikan yang sama
     * dengan halaman detail) + pemanggilan service + flash + audit. Seluruh
     * logika finansial tetap di ShmRepaymentScheduleService (T80/T83).
     *
     * Idempoten: bila jadwal sudah ada, service mengembalikan jadwal
     * eksisting tanpa duplikat/overwrite — flash "sudah tersedia" dan TIDAK
     * ada audit baru (no-op bukan perubahan state).
     */
    public function generateSchedule(Request $request, ShmLoan $loan): RedirectResponse
    {
        // Scope otorisasi IDENTIK dengan halaman detail (shm.view +
        // kepemilikan) — endpoint POST tidak menjadi bypass visibility.
        $this->authorizeLoanRecordOwnership($request, $loan);

        $hadSchedule = $loan->installments()->exists();

        try {
            $installments = $this->schedule->generate($loan);
        } catch (ValidationException $exception) {
            // Alasan eksplisit (mis. first_due_date belum ditentukan) tanpa
            // menulis satu baris angsuran pun.
            return redirect()
                ->route('shm.loans.show', $loan)
                ->withErrors($exception->errors())
                ->with('error', (string) $exception->getMessage());
        }

        if ($hadSchedule) {
            return redirect()
                ->route('shm.loans.show', $loan)
                ->with('success', 'Jadwal angsuran sudah tersedia.');
        }

        // Tepat SATU audit untuk pembangkitan NYATA (perubahan state).
        AuditLogger::log(
            'shm.loan.schedule.generated',
            ShmLoan::class,
            $loan->id,
            $loan->loan_number,
            sprintf(
                'Jadwal angsuran pinjaman %s dibuat: %d angsuran, jatuh tempo pertama %s.',
                $loan->loan_number,
                $installments->count(),
                $loan->first_due_date?->toDateString() ?? '-',
            ),
            [
                'shm_loan_id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'installments_count' => $installments->count(),
                'first_due_date' => $loan->first_due_date?->toDateString(),
                'rate_percent' => (string) $loan->rate_percent,
                'rate_period' => $loan->rate_period,
                'calculation_method' => $loan->calculation_method,
                'tenor_months' => $loan->tenor_months,
            ],
        );

        return redirect()
            ->route('shm.loans.show', $loan)
            ->with('success', 'Jadwal angsuran berhasil dibuat.');
    }

    /**
     * Halaman Simulasi Pinjaman SHM.
     *
     * Render awal (tanpa parameter) menampilkan formulir kosong dengan rate
     * default 2%. Submit menyertakan `run=1` sehingga validasi berjalan penuh
     * sebelum hasil dihitung.
     */
    public function simulation(LoanSimulationRequest $request): Response
    {
        $submitted = $request->isSubmitted();
        $payload = $submitted ? $request->payload() : $this->emptyForm();
        $result = $submitted ? $this->simulation->simulate($payload) : null;

        return Inertia::render('Shm/Loans/Simulation', [
            'form' => $payload,
            'result' => $result,
            'rate' => ShmLoan::OFFICIAL_RATE_PERCENT,
            'products' => ShmLoanProduct::query()->active()->orderBy('name')->get(['id', 'code', 'name']),
            'options' => [
                'methods' => ShmLoanSimulationService::supportedMethods(),
                'periods' => ShmLoanSimulationService::supportedPeriods(),
            ],
        ]);
    }

    /**
     * Export hasil simulasi yang sedang ditampilkan menjadi PDF.
     *
     * Nama file deterministic: identitas = hash dari seluruh input simulasi,
     * sehingga input yang sama selalu menghasilkan nama yang sama dan tidak
     * ada data sensitif yang ditulis ke URL/filename selain hash-nya.
     */
    public function simulationPdf(LoanSimulationRequest $request): SymfonyResponse|RedirectResponse
    {
        if (! $request->isSubmitted()) {
            return to_route('shm.loans.simulation');
        }

        $payload = $request->payload();
        $result = $this->simulation->simulate($payload);

        $product = $payload['product_id']
            ? ShmLoanProduct::query()->find($payload['product_id'])
            : null;

        return $this->pdf->download($payload, $result, $product);
    }

    /**
     * Halaman Pengajuan Pinjaman SHM (Ticket 66).
     *
     * SATU halaman yang dibagi menjadi 11 section accordion di sisi klien;
     * toggle accordion TIDAK pernah melakukan request backend dan bukan
     * navigasi route.
     *
     * Penyimpanan pengajuan dilakukan melalui endpoint store eksplisit (T68):
     * form hanya menyusun data di sisi klien dan tidak pernah menulis record
     * setengah matang ke database.
     *
     * Data yang dikirim murni domain SHM: daftar nasabah dari
     * `shm_customers` (TIDAK pernah dari `members`), produk + tier rate
     * dari `shm_loan_products`/`shm_loan_product_rate_tiers` (T82 — rate
     * diturunkan frontend dari product+tenor untuk TAMPILAN saja; backend
     * tetap resolve ulang), dan opsi metode/periode dari service simulasi
     * existing.
     */
    public function pengajuan(): Response
    {
        return Inertia::render('Shm/Loans/Pengajuan', $this->formPageProps());
    }

    /**
     * Ticket 68 — mode edit form 11 section.
     *
     * Komponen React yang SAMA dengan create (Pengajuan.jsx) — tidak ada
     * halaman edit terpisah. Data existing diisikan sebagai initial state;
     * setelah disimpan redirect ke detail. Pengajuan yang sudah diajukan
     * approval ditolak di sini (redirect ke detail dengan pesan) — lock
     * sesungguhnya tetap di dalam transaksi service (server-side).
     */
    public function edit(Request $request, ShmLoanApplication $application): Response|RedirectResponse
    {
        $this->authorizeLoanOwnership($request, $application);

        if ($application->isLocked()) {
            return redirect()
                ->route('shm.loans.pengajuan.show', $application)
                ->with('error', 'Pengajuan yang sudah diajukan approval tidak dapat diubah.');
        }

        $application->load(['collaterals', 'assets', 'requirements']);

        return Inertia::render('Shm/Loans/Pengajuan', array_merge($this->formPageProps(), [
            'application' => [
                'id' => $application->id,
                'application_number' => $application->application_number,
            ],
            'formData' => $this->formDataFor($application),
        ]));
    }

    /**
     * Ticket 68 — Daftar Pengajuan Pinjaman (server-side list).
     *
     * Search nomor pengajuan & nama/nomor nasabah, filter status, sorting
     * whitelist, pagination dengan withQueryString() — tanpa filtering di
     * React. Scope kepemilikan ditegakkan di query: tanpa `shm.view_all`,
     * hanya pengajuan milik sendiri.
     */
    public function daftar(Request $request): Response
    {
        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status', 'all'),
        ];

        $query = ShmLoanApplication::query()
            ->with([
                'customer:id,customer_number,name',
                'product:id,code,name',
                'shmApplication:id,application_number,status',
                // latestOfMany TANPA daftar kolom (alias join ambigu di
                // SQLite — pola ShmApprovalController).
                'shmApplication.latestApproval',
            ]);

        if (! $this->canViewAllShm()) {
            $query->where('marketing_id', $request->user()->id);
        }

        $query
            ->when(
                filled($filters['status']) && $filters['status'] !== 'all',
                fn (Builder $q) => $q->where('status', $filters['status'])
            )
            ->when($filters['search'], function (Builder $q, string $search): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';

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
            'application_date' => 'application_date',
            'principal' => 'principal',
            'tenor_months' => 'tenor_months',
            'status' => 'status',
            'updated_at' => 'updated_at',
        ], [['updated_at', 'desc']]);

        $applications = $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (ShmLoanApplication $application): array => $this->listRow($application));

        return Inertia::render('Shm/Loans/PengajuanDaftar', [
            'applications' => $applications,
            'sort' => $sortStatus,
            'filters' => $filters,
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Ticket 68 — simpan perubahan pengajuan yang BELUM diajukan approval.
     *
     * Validation ada di FormRequest; lock approval & rekonsiliasi child
     * atomik di service (ValidationException kembali sebagai error form).
     */
    public function update(UpdateShmLoanApplicationRequest $request, ShmLoanApplication $application): RedirectResponse
    {
        $this->authorizeLoanOwnership($request, $application);

        $application = $this->applications->update($application, $request->validated());

        return redirect()
            ->route('shm.loans.pengajuan.show', $application)
            ->with('success', sprintf(
                'Pengajuan %s berhasil diperbarui.',
                $application->application_number,
            ));
    }

    /**
     * Ticket 68 — "Ajukan Approval": REUSE ShmWorkflowService::startApproval
     * & ShmApproval existing (mirror `shm_applications`) — tanpa approval
     * table/service baru. Idempoten: pengajuan yang sudah diajukan tidak
     * menghasilkan approval kedua.
     */
    public function submitApproval(Request $request, ShmLoanApplication $application): RedirectResponse
    {
        $this->authorizeLoanOwnership($request, $application);

        $this->applications->startApproval($application, (int) $request->user()->id);

        return redirect()
            ->route('shm.loans.pengajuan.show', $application)
            ->with('success', 'Pengajuan diajukan ke modul Approval SHM. Menunggu keputusan.');
    }

    /**
     * Ticket 69 — Pencairan pengajuan yang approval-nya DISETUJUI menjadi
     * tepat SATU ShmLoan aktif (disbursed_at terisi).
     *
     * Guard approval/idempotency/transaction/audit semuanya ada di
     * ShmLoanDisbursementService (source of truth); controller hanya
     * ownership (pola T68) dan hasil flash. Percobaan kedua kembali sebagai
     * error form (business error) tanpa membuat loan/audit kedua.
     */
    public function disburse(Request $request, ShmLoanApplication $application): RedirectResponse
    {
        $this->authorizeLoanOwnership($request, $application);

        $loan = $this->disbursement->disburse($application, (int) $request->user()->id);

        return redirect()
            ->route('shm.loans.pengajuan.show', $application)
            ->with('success', sprintf(
                'Pengajuan %s dicairkan — pinjaman %s aktif.',
                $application->application_number,
                $loan->loan_number,
            ));
    }

    /**
     * Props bersama halaman form (create & edit).
     *
     * @return array<string, mixed>
     */
    private function formPageProps(): array
    {
        return [
            'today' => now()->format('Y-m-d'),
            // T82 — rate TIDAK dikirim sebagai angka universal: frontend
            // menurunkan rate dari tier produk + tenor (tampilan saja;
            // backend tetap resolve ulang).
            'products' => ShmLoanProduct::query()->active()
                ->with('rateTiers')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'category'])
                ->map(fn (ShmLoanProduct $product): array => [
                    'id' => $product->id,
                    'code' => $product->code,
                    'name' => $product->name,
                    'category' => $product->category,
                    'rate_tiers' => $product->rateTiers->map(fn ($tier): array => [
                        'min_tenor_months' => $tier->min_tenor_months,
                        'max_tenor_months' => $tier->max_tenor_months,
                        'rate_percent' => (float) $tier->rate_percent,
                    ])->all(),
                ]),
            'customers' => ShmCustomer::query()
                ->active()
                ->orderBy('name')
                ->limit(200)
                ->get([
                    'id', 'customer_number', 'name', 'nik', 'phone', 'address', 'is_active', 'created_at',
                ])
                ->map(fn (ShmCustomer $customer): array => [
                    'id' => $customer->id,
                    'customer_number' => $customer->customer_number,
                    'name' => $customer->name,
                    'nik' => $customer->nik,
                    'phone' => $customer->phone,
                    'address' => $customer->address,
                    'is_active' => $customer->is_active,
                    'registered_at' => $customer->created_at?->format('Y-m-d'),
                ])->all(),
            'options' => [
                'methods' => ShmLoanSimulationService::supportedMethods(),
                'periods' => ShmLoanSimulationService::supportedPeriods(),
            ],
        ];
    }

    /**
     * Simpan Pengajuan Pinjaman SHM (Ticket 67).
     *
     * Seluruh persistensi (record induk + jaminan + barang agunan +
     * persyaratan + audit) berjalan atomik di service; bila satu bagian
     * gagal, tidak ada partial application. Setelah berhasil, redirect ke
     * halaman detail pengajuan (bukan kembali mengisi form).
     */
    public function store(StoreShmLoanApplicationRequest $request): RedirectResponse
    {
        $application = $this->applications->store($request->validated());

        return redirect()
            ->route('shm.loans.pengajuan.show', $application)
            ->with('success', sprintf(
                'Pengajuan %s berhasil disimpan.',
                $application->application_number,
            ));
    }

    /**
     * Detail pengajuan pinjaman SHM yang sudah tersimpan — menampilkan
     * kembali seluruh 11 section tanpa perlu mengisi ulang form.
     *
     * Relasi di-eager-load sekali di sini sehingga render halaman dan
     * export PDF tidak menghasilkan query per baris (tanpa N+1).
     */
    public function pengajuanShow(Request $request, ShmLoanApplication $application): Response
    {
        $this->authorizeLoanOwnership($request, $application);

        $application->load([
            'customer',
            'product',
            'collaterals',
            'assets',
            'requirements',
            'shmApplication.latestApproval.decider:id,name',
            // Ticket 69 — hasil pencairan (null = belum dicairkan).
            'loan',
        ]);

        return Inertia::render('Shm/Loans/PengajuanShow', [
            'application' => $this->presentApplication($application),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Export satu pengajuan lengkap ke PDF (Ticket 67).
     *
     * Authorization mengikuti group `permission:shm.view` yang sama — tidak
     * ada URL publik dan tidak ada parameter ID yang dapat mem-bypass scope.
     */
    public function applicationPdf(Request $request, ShmLoanApplication $application): SymfonyResponse
    {
        $this->authorizeLoanOwnership($request, $application);

        $application->load(['customer', 'product', 'collaterals', 'assets', 'requirements']);

        return $this->applicationPdf->download($application);
    }

    /**
     * Ticket 77 — penyajian satu foto jaminan pengajuan pinjaman SHM.
     *
     * Berkas disimpan pada disk PRIVATE Laravel (storage/app/private), sehingga
     * tidak dapat diakses langsung dari web. Route ini satu-satunya jalan
     * membacanya dan menerapkan aturan yang SAMA dengan halaman pengajuan:
     * - permission `shm.view` (middleware route), dan
     * - ownership pengajuan (tanpa `shm.view_all` hanya pemilik yang boleh).
     * Kolateral yang tidak termasuk dalam pengajuan yang dirujuk tidak akan
     * pernah ditemukan (404) — tidak ada jalur baca silang antar pengajuan.
     */
    public function collateralPhoto(
        Request $request,
        ShmLoanApplication $application,
        ShmLoanApplicationCollateral $collateral,
    ): SymfonyResponse {
        $this->authorizeLoanOwnership($request, $application);

        abort_unless(
            $collateral->shm_loan_application_id === $application->id,
            404,
        );
        abort_if($collateral->photo_path === null, 404);

        $disk = Storage::disk('local');

        abort_unless($disk->exists($collateral->photo_path), 404);

        $contents = $disk->get($collateral->photo_path);

        // Validasi FormRequest membatasi foto ≤ 2 MB, sehingga buffering
        // penuh ke memori aman — dan mengikuti pola PDF export yang SUDAH
        // terbukti (response string-body). StreamedResponse dari
        // Storage::response() memberi body kosong pada PHP built-in dev
        // server; pola string-body bekerja di semua SAPI.
        if (! is_string($contents) || $contents === '') {
            abort(404);
        }

        $mime = match (strtolower(pathinfo($collateral->photo_path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="foto-jaminan-'.$collateral->id.'"',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Bentuk data pengajuan (11 section) untuk props Inertia.
     *
     * @return array<string, mixed>
     */
    private function presentApplication(ShmLoanApplication $application): array
    {
        // Materikan relasi HasOne sekali (pola ShmApprovalController):
        // latestOfMany tidak di-cache sehingga pemanggilan berulang dapat
        // menghasilkan baris berbeda.
        $approval = $application->shmApplication?->latestApproval;

        return [
            'id' => $application->id,
            'application_number' => $application->application_number,
            'status' => $application->status,
            'application_date' => $application->application_date?->format('Y-m-d'),
            'created_at' => $application->created_at,
            // T82 — rate produk (f(product, tenor)); null bila produk/tenor
            // belum menghasilkan tier (tanpa fallback 2%).
            'rate' => $application->officialRatePercent(),
            // Ticket 68 — keputusan dari ShmApproval existing (null = belum
            // diajukan); status keputusan TIDAK disimpan di kolom `status`.
            'approval' => $approval === null ? null : [
                'status' => $approval->status,
                'decider' => $approval->decider?->name,
                'decided_at' => $approval->decided_at,
                'notes' => $approval->notes,
            ],
            // Approval lock: setelah diajukan (pending/decided) form terkunci
            // sampai ada kontrak resubmit business (PENDING).
            'can_edit' => ! $application->isLocked(),
            'can_submit_approval' => ! $application->isLocked(),
            // Ticket 69 — state pencairan: tombol hanya bila approval sudah
            // 'approved' DAN belum ada pinjaman hasil pencairan; guard
            // sesungguhnya tetap di backend service, bukan hanya props ini.
            'can_disburse' => $approval?->status === 'approved' && $application->loan === null,
            'loan' => $application->loan === null ? null : [
                'id' => $application->loan->id,
                'loan_number' => $application->loan->loan_number,
                'status' => $application->loan->status,
                'disbursed_at' => $application->loan->disbursed_at?->format('Y-m-d'),
                'principal' => $application->loan->principal,
            ],
            'customer' => [
                'id' => $application->customer?->id,
                'customer_number' => $application->customer?->customer_number,
                'name' => $application->customer?->name,
                'nik' => $application->customer?->nik,
                'phone' => $application->customer?->phone,
                'address' => $application->customer?->address,
                'is_active' => $application->customer?->is_active,
                'registered_at' => $application->customer?->created_at?->format('Y-m-d'),
            ],
            'product' => $application->product ? [
                'id' => $application->product->id,
                'code' => $application->product->code,
                'name' => $application->product->name,
            ] : null,
            // Ticket 76-FIX — Data Pribadi adalah DATA PENGAJUAN (snapshot per
            // aplikasi di `shm_loan_applications`), bukan data master nasabah.
            'pribadi' => [
                'unit_kerja' => $application->unit_kerja,
                'nip' => $application->nip,
                'telepon_rumah' => $application->telepon_rumah,
                'tempat_lahir' => $application->tempat_lahir,
                'tanggal_lahir' => $application->tanggal_lahir?->format('Y-m-d'),
                'jumlah_tanggungan' => $application->jumlah_tanggungan,
                'saldo_modal' => $application->saldo_modal,
                'saldo_non_modal' => $application->saldo_non_modal,
                'batas_hutang' => $application->batas_hutang,
            ],
            'pengajuan' => [
                'principal' => $application->principal,
                'tenor_months' => $application->tenor_months,
                // T82 — jatuh tempo pertama dipilih Marketing (date-only).
                'first_due_date' => $application->first_due_date?->format('Y-m-d'),
                'purpose' => $application->purpose,
                'loan_sequence' => $application->loan_sequence,
                'disbursement_method' => $application->disbursement_method,
            ],
            'income' => [
                'tetap' => $application->income_fixed,
                'pasangan' => $application->income_spouse,
                'usaha' => $application->income_business,
                'total' => $application->totalIncome(),
            ],
            'expense' => [
                'potongan' => $application->expense_deduction,
                'rumahTangga' => $application->expense_household,
                'pendidikan' => $application->expense_education,
                'sewa' => $application->expense_rent,
                'listrik' => $application->expense_utilities,
                'transportasi' => $application->expense_transport,
                'lain' => $application->expense_other,
                'total' => $application->totalExpense(),
            ],
            'sisa' => $application->remainingIncome(),
            'tambahan' => [
                'koperasi_loan_balance' => $application->koperasi_loan_balance,
                'other_facility' => $application->other_facility,
                'institution_name' => $application->institution_name,
            ],
            'agunan' => $application->assets->map(fn ($asset): array => [
                'jenis' => $asset->asset_type,
                'nomor' => $asset->asset_number,
                'tahun' => $asset->asset_year,
                'nilai' => $asset->estimated_value,
            ])->all(),
            'jaminan' => $application->collaterals->map(fn ($collateral): array => [
                'tanggal' => $collateral->collateral_date?->format('Y-m-d'),
                'jenis' => $collateral->collateral_type,
                'nama' => $collateral->collateral_name,
                'keterangan' => $collateral->notes,
                'nilai_perkiraan' => $collateral->estimated_value,
                'status_pengikatan' => $collateral->binding_status,
                'tanggal_pengikatan' => $collateral->binding_date?->format('Y-m-d'),
                'nilai_hak_tanggungan' => $collateral->rights_value,
                // Ticket 77 — nomor nasabah jaminan SELALU mengikuti nasabah
                // pengajuan (bukan kolom duplikat pada baris jaminan).
                'customer_number' => $application->customer?->customer_number,
                // Foto: hanya URL route terotorisasi — path filesystem tidak
                // pernah diekspos; null = jaminan tanpa foto (backward compat).
                'foto_url' => $collateral->photo_path !== null
                    ? route('shm.loans.pengajuan.jaminan.foto', [$application->id, $collateral->id])
                    : null,
            ])->all(),
            'catatan' => [
                'date' => $application->kasir_note_date?->format('Y-m-d'),
                'name' => $application->kasir_note_name,
                'notes' => $application->kasir_note_text,
            ],
            'analisa' => [
                'analyst_code' => $application->analyst_code,
                'analyst_office' => $application->analyst_office,
                'officer_code' => $application->officer_code,
                'officer_office' => $application->officer_office,
                'date' => $application->analysis_date?->format('Y-m-d'),
                'value' => $application->analysis_value,
                'notes' => $application->analysis_notes,
            ],
            'kolektor' => ['name' => $application->collector_name],
            'persetujuan' => [
                'approved_amount' => $application->approved_amount,
                'provision_fee' => $application->provision_fee,
                'administration_fee' => $application->administration_fee,
                // Ticket 85 — biaya notaris custom.
                'notary_fee' => $application->notary_fee,
                'method' => $application->calculation_method,
                'notes' => $application->approval_notes,
            ],
            'persyaratan' => $application->requirements->map(fn ($requirement): array => [
                'id' => $requirement->id,
                'text' => $requirement->requirement,
                'done' => (bool) $requirement->is_done,
            ])->all(),
        ];
    }

    /**
     * Ticket 68 — data pengajuan dalam bentuk STATE FORM (payload T66)
     * untuk mode edit.
     *
     * Nilai uang dirupiahkan bilangan bulat (tanpa desimal) karena form
     * memakai digit-mentah; child menyertakan `id` agar service dapat
     * merekonsiliasi (update/create/hapus) tanpa delete-all-reinsert.
     * Bentuk payload create & update IDENTIK sehingga validasi sama.
     *
     * @return array<string, mixed>
     */
    private function formDataFor(ShmLoanApplication $application): array
    {
        // "460000000.00" -> "460000000"; null -> '' (nullable via middleware).
        $money = static fn (?string $value): string => $value === null || $value === '' ? '' : (string) (int) round((float) $value);
        $text = static fn (?string $value): string => (string) ($value ?? '');
        $date = static fn ($value): string => $value?->format('Y-m-d') ?? '';

        return [
            'pribadi' => [
                'customer_id' => (string) $application->shm_customer_id,
                // Ticket 76-FIX — nilai Data Pribadi milik pengajuan ini.
                'unit_kerja' => $text($application->unit_kerja),
                'nip' => $text($application->nip),
                'telepon_rumah' => $text($application->telepon_rumah),
                'tempat_lahir' => $text($application->tempat_lahir),
                'tanggal_lahir' => $date($application->tanggal_lahir),
                'jumlah_tanggungan' => $application->jumlah_tanggungan !== null ? (string) $application->jumlah_tanggungan : '',
                'saldo_modal' => $money($application->saldo_modal),
                'saldo_non_modal' => $money($application->saldo_non_modal),
                'batas_hutang' => $money($application->batas_hutang),
            ],
            'pengajuan' => [
                'product_id' => $application->shm_loan_product_id !== null ? (string) $application->shm_loan_product_id : '',
                'principal' => $money($application->principal),
                'tenor_months' => (string) $application->tenor_months,
                // T82 — jatuh tempo pertama dipilih Marketing (date-only).
                'first_due_date' => $date($application->first_due_date),
                'purpose' => $text($application->purpose),
                'loan_sequence' => $application->loan_sequence !== null ? (string) $application->loan_sequence : '',
                'disbursement_method' => $text($application->disbursement_method),
            ],
            'income' => [
                'tetap' => $money($application->income_fixed),
                'pasangan' => $money($application->income_spouse),
                'usaha' => $money($application->income_business),
            ],
            'expense' => [
                'potongan' => $money($application->expense_deduction),
                'rumahTangga' => $money($application->expense_household),
                'pendidikan' => $money($application->expense_education),
                'sewa' => $money($application->expense_rent),
                'listrik' => $money($application->expense_utilities),
                'transportasi' => $money($application->expense_transport),
                'lain' => $money($application->expense_other),
            ],
            'tambahan' => [
                'koperasi_loan_balance' => $money($application->koperasi_loan_balance),
                'other_facility' => $text($application->other_facility),
                'institution_name' => $text($application->institution_name),
            ],
            'agunan' => $application->assets->map(fn ($asset): array => [
                'id' => $asset->id,
                'jenis' => $asset->asset_type,
                'nomor' => $text($asset->asset_number),
                'tahun' => $text($asset->asset_year),
                'nilai' => $money($asset->estimated_value),
            ])->values()->all(),
            'jaminan' => $application->collaterals->map(fn ($collateral): array => [
                'id' => $collateral->id,
                'tanggal' => $date($collateral->collateral_date),
                'jenis' => $collateral->collateral_type,
                'nama' => $collateral->collateral_name,
                'keterangan' => $text($collateral->notes),
                'nilai_perkiraan' => $money($collateral->estimated_value),
                'status_pengikatan' => $text($collateral->binding_status),
                'tanggal_pengikatan' => $date($collateral->binding_date),
                'nilai_hak_tanggungan' => $money($collateral->rights_value),
                // Ticket 77 — `foto` = berkas BARU yang dipilih pengguna saat
                // ini (null bila tidak ada penggantian); `foto_url` = foto
                // tersimpan yang sedang ditampilkan. Keduanya tidak pernah
                // diserialisasi sebagai path filesystem.
                'foto' => null,
                'foto_url' => $collateral->photo_path !== null
                    ? route('shm.loans.pengajuan.jaminan.foto', [$application->id, $collateral->id])
                    : null,
                'picked' => false,
            ])->values()->all(),
            'catatan' => [
                'date' => $date($application->kasir_note_date),
                'name' => $text($application->kasir_note_name),
                'notes' => $text($application->kasir_note_text),
            ],
            'analisa' => [
                'analyst_code' => $text($application->analyst_code),
                'analyst_office' => $text($application->analyst_office),
                'officer_code' => $text($application->officer_code),
                'officer_office' => $text($application->officer_office),
                'date' => $date($application->analysis_date),
                'value' => $money($application->analysis_value),
                'notes' => $text($application->analysis_notes),
            ],
            'kolektor' => ['name' => $text($application->collector_name)],
            'persetujuan' => [
                'approved_amount' => $money($application->approved_amount),
                'provision_fee' => $money($application->provision_fee),
                'administration_fee' => $money($application->administration_fee),
                // Ticket 85 — biaya notaris custom.
                'notary_fee' => $money($application->notary_fee),
                'method' => $text($application->calculation_method),
                'notes' => $text($application->approval_notes),
            ],
            'persyaratan' => $application->requirements->map(fn ($requirement): array => [
                'id' => $requirement->id,
                'text' => $requirement->requirement,
                'done' => (bool) $requirement->is_done,
            ])->values()->all(),
        ];
    }

    /**
     * Ticket 68 — ringkasan satu baris untuk halaman Daftar Pengajuan.
     *
     * @return array<string, mixed>
     */
    private function listRow(ShmLoanApplication $application): array
    {
        $approval = $application->shmApplication?->latestApproval;

        return [
            'id' => $application->id,
            'application_number' => $application->application_number,
            'application_date' => $application->application_date?->format('Y-m-d'),
            'status' => $application->status,
            'principal' => $application->principal,
            'tenor_months' => $application->tenor_months,
            'customer' => [
                'id' => $application->customer?->id,
                'customer_number' => $application->customer?->customer_number,
                'name' => $application->customer?->name,
            ],
            'product' => $application->product === null ? null : [
                'id' => $application->product->id,
                'code' => $application->product->code,
                'name' => $application->product->name,
            ],
            'approval_status' => $approval?->status,
            'editable' => ! $application->isLocked(),
            'updated_at' => $application->updated_at,
        ];
    }

    /**
     * Bentuk data pinjaman untuk props Inertia.
     *
     * @return array<string, mixed>
     */
    private function presentLoan(ShmLoan $loan): array
    {
        return [
            'id' => $loan->id,
            'loan_number' => $loan->loan_number,
            'principal' => $loan->principal,
            'tenor_months' => $loan->tenor_months,
            'rate_percent' => $loan->rate_percent,
            'rate_period' => $loan->rate_period,
            'calculation_method' => $loan->calculation_method,
            // T83 — jatuh tempo pertama (Marketing-pilih) tampil di detail.
            'first_due_date' => $loan->first_due_date?->format('Y-m-d'),
            'outstanding' => $loan->outstanding(),
            'location' => $loan->location,
            'purpose' => $loan->purpose,
            'status' => $loan->status,
            'disbursed_at' => $loan->disbursed_at,
            'note' => $loan->note,
            'created_at' => $loan->created_at,
            'customer' => [
                'id' => $loan->customer?->id,
                'customer_number' => $loan->customer?->customer_number,
                'name' => $loan->customer?->name,
            ],
            'product' => [
                'id' => $loan->product?->id,
                'code' => $loan->product?->code,
                'name' => $loan->product?->name,
            ],
        ];
    }

    /**
     * Ticket 70 — bentuk data detail pinjaman untuk halaman Show.
     *
     * Membangun di atas presentLoan() lalu menambahkan section monitoring
     * yang tidak dibutuhkan oleh halaman daftar: data nasabah SHM lengkap,
     * pengajuan asal pencairan, ringkasan pencairan, serta jaminan dan
     * barang agunan yang tersimpan pada pengajuan tersebut.
     *
     * SELURUH nilai berasal dari record yang SUDAH tersimpan — tidak ada
     * perhitungan baru. Rate tampil apa adanya (rate pinjaman = tier produk
     * untuk tenor pengajuan — T82); periode rate dan metode angsuran hanya
     * ditampilkan bila record memang menyimpannya (kolom nullable, hanya terisi
     * bila dipilih eksplisit — baseline T79: flat per bulan).
     *
     * Nasabah selalu diambil dari `shm_customers` — tidak pernah `members` —
     * sehingga data koperasi dan data SHM tidak pernah tercampur.
     *
     * @return array<string, mixed>
     */
    private function presentLoanDetail(ShmLoan $loan): array
    {
        $application = $loan->application;
        $customer = $loan->customer;

        return array_merge($this->presentLoan($loan), [
            'customer' => [
                'id' => $customer?->id,
                'customer_number' => $customer?->customer_number,
                'name' => $customer?->name,
                'nik' => $customer?->nik,
                'phone' => $customer?->phone,
                'address' => $customer?->address,
                'is_active' => $customer?->is_active,
                'registered_at' => $customer?->created_at?->format('Y-m-d'),
            ],
            // Pengajuan asal pencairan (Ticket 69). NULL bila pinjaman dibuat
            // di luar alur pencairan pengajuan.
            'application' => $application === null ? null : [
                'id' => $application->id,
                'application_number' => $application->application_number,
                'application_date' => $application->application_date?->format('Y-m-d'),
                'purpose' => $application->purpose,
                'principal' => $application->principal,
                'tenor_months' => $application->tenor_months,
                'status' => $application->status,
            ],
            // Informasi pencairan — seluruhnya dari record pinjaman ini.
            'disbursement' => [
                'loan_number' => $loan->loan_number,
                'disbursed_at' => $loan->disbursed_at?->format('Y-m-d'),
                'principal' => $loan->principal,
                'status' => $loan->status,
                // T85 — snapshot biaya pencairan (immutable historical).
                // Seluruh nilai dibaca dari record yang sudah tersimpan;
                // TIDAK dihitung ulang di backend maupun frontend.
                'costs' => [
                    'admin_fee' => $loan->admin_fee,
                    'provision_fee' => $loan->provision_fee,
                    'insurance_fee' => $loan->insurance_fee,
                    'notary_fee' => $loan->notary_fee,
                    'principal_savings' => $loan->principal_savings,
                    'mandatory_savings' => $loan->mandatory_savings,
                    'total_deduction' => $loan->total_deduction,
                    'net_disbursement' => $loan->net_disbursement,
                ],
            ],
            // Jaminan & barang agunan mengikuti pengajuan asal; daftar bebas
            // yang sudah tersimpan. TIDAK ada master collateral baru (T70).
            'jaminan' => $application?->collaterals?->map(fn ($collateral): array => [
                'tanggal' => $collateral->collateral_date?->format('Y-m-d'),
                'jenis' => $collateral->collateral_type,
                'nama' => $collateral->collateral_name,
                'keterangan' => $collateral->notes,
                'nilai_perkiraan' => $collateral->estimated_value,
                'status_pengikatan' => $collateral->binding_status,
                'tanggal_pengikatan' => $collateral->binding_date?->format('Y-m-d'),
                'nilai_hak_tanggungan' => $collateral->rights_value,
            ])->all() ?? [],
            'agunan' => $application?->assets?->map(fn ($asset): array => [
                'jenis' => $asset->asset_type,
                'nomor' => $asset->asset_number,
                'tahun' => $asset->asset_year,
                'nilai' => $asset->estimated_value,
            ])->all() ?? [],
            // Garis waktu: MEMBACA audit trail existing, bukan membuat event.
            'timeline' => $this->loanTimeline($application),
        ]);
    }

    /**
     * Ticket 70 — garis waktu aktivitas pinjaman dari audit trail EXISTING.
     *
     * Hanya membaca baris `audit_logs` yang memang sudah ditulis oleh modul
     * pengajuan dan pencairan; tidak ada event audit baru yang dibuat dan
     * tidak ada schema baru. Karena satu pengajuan hanya pernah menghasilkan
     * satu pinjaman, jejak audit pengajuan asal adalah jejak yang sah untuk
     * pinjaman ini.
     *
     * Pinjaman tanpa pengajuan asal (`shm_loan_application_id` NULL) tidak
     * memiliki jejak yang dapat dirujuk dengan aman, sehingga daftarnya
     * kosong — bukan ditempeli riwayat karangan.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loanTimeline(?ShmLoanApplication $application): array
    {
        if ($application === null) {
            return [];
        }

        return AuditLog::query()
            ->where('object_type', ShmLoanApplication::class)
            ->where('object_id', $application->id)
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'action' => $log->action,
                'description' => $log->description,
                'actor' => $log->actor?->name,
                'created_at' => $log->created_at,
            ])
            ->all();
    }

    /**
     * Isian awal formulir simulasi (belum ada hasil).
     *
     * @return array<string, mixed>
     */
    private function emptyForm(): array
    {
        return [
            'name' => null,
            'product_id' => null,
            'principal' => null,
            'tenor_months' => null,
            'rate_percent' => ShmLoan::OFFICIAL_RATE_PERCENT,
            'method' => '',
            'rate_period' => '',
            'location' => null,
            'purpose' => null,
            'fees' => [
                'administration' => 0.0,
                'provision' => 0.0,
                'insurance' => 0.0,
                'other' => 0.0,
                'block_funds' => 0.0,
            ],
        ];
    }
}
