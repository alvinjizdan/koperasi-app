<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Concerns\AuthorizesShmOwnership;
use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shm\LoanSimulationRequest;
use App\Http\Requests\Shm\StoreShmLoanApplicationRequest;
use App\Http\Requests\Shm\UpdateShmLoanApplicationRequest;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Services\Shm\ShmLoanApplicationPdf;
use App\Services\Shm\ShmLoanApplicationService;
use App\Services\Shm\ShmLoanDisbursementService;
use App\Services\Shm\ShmLoanSimulationPdf;
use App\Services\Shm\ShmLoanSimulationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
 * Rate bunga/jasa resmi = 2% (ShmLoan::OFFICIAL_RATE_PERCENT). Formula dan
 * periodisasi TIDAK ditentukan sistem: halaman simulasi mewajibkan pemilihan
 * metode & periode secara eksplisit karena keduanya masih PENDING BUSINESS
 * CONFIRMATION.
 *
 * Otorisasi memakai permission `shm.view` yang sudah ada; tidak ada permission
 * baru dan tidak ada mekanisme ownership tambahan (data finansial melekat pada
 * nasabah SHM yang merupakan master bersama, sama seperti modul Nasabah).
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
                'description' => $product->description,
                'is_active' => $product->is_active,
                'loans_count' => $product->loans_count ?? 0,
                'created_at' => $product->created_at,
            ]);

        return Inertia::render('Shm/Loans/Products', [
            'products' => $products,
            'sort' => $sortStatus,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'],
            ],
        ]);
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
            ->with(['customer', 'product'])
            ->search($filters['search'])
            ->status($filters['status']);

        $sortStatus = $this->applySorting($query, $request, [
            'loan_number' => 'loan_number',
            'principal' => 'principal',
            'tenor_months' => 'tenor_months',
            'rate_percent' => 'rate_percent',
            'status' => 'status',
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
    public function show(ShmLoan $loan): Response
    {
        $loan->load(['customer', 'product']);

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

        return Inertia::render('Shm/Loans/Show', [
            'loan' => $this->presentLoan($loan),
            'result' => $result,
        ]);
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
     * Halaman ini masih FONDASI: belum ada endpoint penyimpanan pengajuan
     * pinjaman SHM (tulis = PENDING BUSINESS CONFIRMATION), sehingga form
     * hanya menyusun data di sisi klien dan tidak pernah menulis record
     * setengah matang ke database.
     *
     * Data yang dikirim murni domain SHM: daftar nasabah dari
     * `shm_customers` (TIDAK pernah dari `members`), produk dari
     * `shm_loan_products`, rate resmi = 2%, dan opsi metode/periode dari
     * service simulasi existing sebagai satu-satunya source of truth.
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
            'rate' => ShmLoan::OFFICIAL_RATE_PERCENT,
            'products' => ShmLoanProduct::query()->active()->orderBy('name')->get(['id', 'code', 'name']),
            'customers' => ShmCustomer::query()
                ->active()
                ->orderBy('name')
                ->limit(200)
                ->get(['id', 'customer_number', 'name', 'nik', 'phone', 'address', 'is_active', 'created_at'])
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
            'pengajuan' => [
                'principal' => $application->principal,
                'tenor_months' => $application->tenor_months,
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
            'pribadi' => ['customer_id' => (string) $application->shm_customer_id],
            'pengajuan' => [
                'product_id' => $application->shm_loan_product_id !== null ? (string) $application->shm_loan_product_id : '',
                'principal' => $money($application->principal),
                'tenor_months' => (string) $application->tenor_months,
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
                // Kunci lokal UI (tidak pernah dikirim ke backend).
                'foto' => '',
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
