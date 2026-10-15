<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanApplicationAsset;
use App\Models\ShmLoanApplicationCollateral;
use App\Models\ShmLoanApplicationRequirement;
use App\Models\ShmLoanProduct;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Ticket 67 — backend domain & persistence Pengajuan Pinjaman SHM + export PDF.
 *
 * Yang diuji:
 * - CREATE atomik (induk + jaminan + agunan + persyaratan) + audit tepat 1x;
 * - customer wajib dari `shm_customers` (id anggota koperasi ditolak);
 * - validasi server-side tanpa aturan bisnis yang diarang;
 * - SHOW membuka kembali pengajuan tersimpan (11 section) dengan authorization;
 * - PDF berisi 11 section, identifier, nasabah, nominal, tenor, rate dari
 *   matriks produk (T82), tanpa 16% screenshot dan tanpa data anggota koperasi;
 * - rollback penuh bila child persistence gagal;
 * - rate resmi = f(produk, tenor); tidak ada kolom rate tersendiri di
 *   pengajuan.
 *
 * Yang TIDAK diuji: formula bunga (PENDING), keputusan approval (workflow
 * existing, ticket berikutnya). Upload foto jaminan diuji khusus di
 * ShmLoanCollateralPhotoTest (Ticket 77).
 */
class ShmLoanApplicationTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $admin;

    private User $marketing;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = $this->userWithRole('superadmin');
        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->kasir = $this->userWithRole('kasir');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /**
     * Payload 11 section yang valid — bentuk sama dengan state form T66.
     *
     * @return array<string, mixed>
     */
    private function validPayload(int $customerId): array
    {
        return [
            'pribadi' => ['customer_id' => $customerId],
            'pengajuan' => [
                'product_id' => ShmLoanProduct::factory()->create()->id,
                'principal' => '460000000',
                'tenor_months' => 48,
                // T82 — jatuh tempo pertama dipilih Marketing (bukan auto).
                'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
                'purpose' => 'UNTUK MODAL USAHA',
                'loan_sequence' => 1,
                'disbursement_method' => 'Tunai',
            ],
            'income' => [
                'tetap' => '5000000',
                'pasangan' => '1500000',
                'usaha' => '500000',
            ],
            'expense' => [
                'potongan' => '1000000',
                'rumahTangga' => '2000000',
                'pendidikan' => '',
                'sewa' => '',
                'listrik' => '',
                'transportasi' => '',
                'lain' => '',
            ],
            'tambahan' => [
                'koperasi_loan_balance' => '0',
                'other_facility' => 'Tidak ada',
                'institution_name' => '',
            ],
            'agunan' => [
                ['jenis' => 'Kendaraan', 'nomor' => 'F1234', 'tahun' => '2020', 'nilai' => '15000000'],
            ],
            'jaminan' => [
                [
                    'tanggal' => now()->format('Y-m-d'),
                    'jenis' => 'BPKB',
                    'nama' => 'Motor Honda Beat',
                    'keterangan' => 'Milik nasabah, belum diikat',
                    'nilai_perkiraan' => '15000000',
                    'status_pengikatan' => 'Belum diikat',
                    'tanggal_pengikatan' => '',
                    'nilai_hak_tanggungan' => '',
                ],
            ],
            'catatan' => [
                'date' => now()->format('Y-m-d'),
                'name' => 'Kasir Uji T67',
                'notes' => "Catatan kasir untuk uji T67.\nBaris kedua.",
            ],
            'analisa' => [
                'analyst_code' => 'AN-01',
                'analyst_office' => 'Kantor Pusat',
                'officer_code' => 'AO-01',
                'officer_office' => 'Kantor Pusat',
                'date' => now()->format('Y-m-d'),
                'value' => '460000000',
                'notes' => 'Analisa uji Ticket 67.',
            ],
            'kolektor' => ['name' => 'Kolektor Uji T67'],
            'persetujuan' => [
                'approved_amount' => '',
                'provision_fee' => '',
                'administration_fee' => '',
                'method' => 'flat',
                'notes' => 'Menunggu keputusan.',
            ],
            'persyaratan' => [
                ['text' => 'Slip gaji terakhir', 'done' => false],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeAs(User $user, array $payload): TestResponse
    {
        return $this->actingAs($user)->post(route('shm.loans.pengajuan.store'), $payload);
    }

    /** Buat pengajuan valid langsung lewat endpoint (mengikuti jalur asli). */
    private function makeApplication(): ShmLoanApplication
    {
        $customer = ShmCustomer::factory()->create();
        $this->storeAs($this->admin, $this->validPayload($customer->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return ShmLoanApplication::query()->latest('id')->firstOrFail();
    }

    // -----------------------------------------------------------------
    // A. CREATE
    // -----------------------------------------------------------------

    public function test_guest_is_redirected_when_storing(): void
    {
        $customer = ShmCustomer::factory()->create();

        $this->post(route('shm.loans.pengajuan.store'), $this->validPayload($customer->id))
            ->assertRedirect(route('shm.login'));

        $this->assertSame(0, ShmLoanApplication::query()->count());
    }

    public function test_user_without_shm_permission_is_forbidden_when_storing(): void
    {
        $customer = ShmCustomer::factory()->create();

        $this->actingAs($this->kasir)
            ->post(route('shm.loans.pengajuan.store'), $this->validPayload($customer->id))
            ->assertForbidden();

        $this->assertSame(0, ShmLoanApplication::query()->count());
    }

    public function test_authorized_user_can_store_application_with_all_sections(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        $this->storeAs($this->admin, $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $application = ShmLoanApplication::query()->sole();

        // Identifier dibuat server-side, status awal = vocabulary existing.
        $this->assertMatchesRegularExpression('/^PENGAJ-PIN-\d{8}-[0-9A-F]{8}$/', $application->application_number);
        $this->assertSame('pending', $application->status);
        $this->assertSame($customer->id, $application->shm_customer_id);
        $this->assertSame(now()->format('Y-m-d'), $application->application_date->format('Y-m-d'));

        // Data finansial tersimpan (decimal, bukan float).
        $this->assertSame(460000000.0, (float) $application->principal);
        $this->assertSame(48, $application->tenor_months);
        // T82 — product_id & first_due_date tersimpan pada pengajuan.
        $this->assertNotNull($application->shm_loan_product_id);
        $this->assertSame(now()->addMonth()->startOfMonth()->toDateString(), $application->first_due_date->toDateString());
        $this->assertSame(5000000.0, (float) $application->income_fixed);
        $this->assertSame(2000000.0, (float) $application->expense_household);
        $this->assertSame('flat', $application->calculation_method);
        $this->assertSame('Kolektor Uji T67', $application->collector_name);

        // Derived totals benar (tanpa kolom total ganda).
        $this->assertSame(7000000.0, $application->totalIncome());
        $this->assertSame(3000000.0, $application->totalExpense());
        $this->assertSame(4000000.0, $application->remainingIncome());

        // Relasi customer & produk dari domain SHM.
        $this->assertInstanceOf(ShmCustomer::class, $application->customer);
        $this->assertNotNull($application->product);

        // Child sections ikut tersimpan.
        $this->assertSame(1, $application->collaterals()->count());
        $this->assertSame(1, $application->assets()->count());
        $this->assertSame(1, $application->requirements()->count());
        $this->assertSame('Motor Honda Beat', $application->collaterals()->first()->collateral_name);
        $this->assertSame('Kendaraan', $application->assets()->first()->asset_type);
        $this->assertSame('Slip gaji terakhir', $application->requirements()->first()->requirement);
    }

    public function test_koperasi_member_id_cannot_be_used_as_shm_customer(): void
    {
        $member = Member::factory()->create();

        $payload = $this->validPayload(1);
        $payload['pribadi']['customer_id'] = $member->id;

        $this->storeAs($this->admin, $payload)
            ->assertSessionHasErrors('pribadi.customer_id');

        $this->assertSame(0, ShmLoanApplication::query()->count());
        $this->assertSame(
            0,
            AuditLog::query()->where('action', 'shm.loan_application.created')->count(),
        );
    }

    public function test_store_rolls_back_all_records_when_child_persistence_fails(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        $this->withoutExceptionHandling();

        // Paksa kegagalan pada child terakhir sehingga induk + anak lain
        // sudah terlanjur dibuat di dalam transaksi.
        ShmLoanApplicationRequirement::creating(function (): void {
            throw new RuntimeException('Child persistence gagal (uji T67).');
        });

        try {
            $this->storeAs($this->admin, $payload);
            $this->fail('Exception child persistence seharusnya muncul.');
        } catch (RuntimeException) {
            // Diharapkan — transaksi harus rollback seluruhnya.
        }

        $this->assertSame(0, ShmLoanApplication::query()->count());
        $this->assertSame(0, ShmLoanApplicationCollateral::query()->count());
        $this->assertSame(0, ShmLoanApplicationAsset::query()->count());
        $this->assertSame(0, ShmLoanApplicationRequirement::query()->count());
        $this->assertSame(
            0,
            AuditLog::query()->where('action', 'shm.loan_application.created')->count(),
            'Audit tidak boleh tertulis bila create gagal.',
        );
    }

    public function test_create_writes_exactly_one_audit_log(): void
    {
        $application = $this->makeApplication();

        $logs = AuditLog::query()
            ->where('action', 'shm.loan_application.created')
            ->get();

        $this->assertCount(1, $logs);
        $this->assertSame($this->admin->id, $logs->first()->actor_id);
        $this->assertSame(ShmLoanApplication::class, $logs->first()->object_type);
        $this->assertSame($application->id, $logs->first()->object_id);
        $this->assertSame($application->application_number, $logs->first()->object_label);
    }

    // -----------------------------------------------------------------
    // B. VALIDATION
    // -----------------------------------------------------------------

    public function test_invalid_input_is_rejected_server_side(): void
    {
        $customer = ShmCustomer::factory()->create();

        // Nasabah tidak ada.
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['customer_id'] = 999999;
        $this->storeAs($this->admin, $payload)->assertSessionHasErrors('pribadi.customer_id');

        // Nominal negatif.
        $payload = $this->validPayload($customer->id);
        $payload['pengajuan']['principal'] = '-5000';
        $this->storeAs($this->admin, $payload)->assertSessionHasErrors('pengajuan.principal');

        // Tenor bukan bilangan.
        $payload = $this->validPayload($customer->id);
        $payload['pengajuan']['tenor_months'] = 'belasan';
        $this->storeAs($this->admin, $payload)->assertSessionHasErrors('pengajuan.tenor_months');

        // Metode di luar supportedMethods().
        $payload = $this->validPayload($customer->id);
        $payload['persetujuan']['method'] = 'tanpa-bunga-khusus';
        $this->storeAs($this->admin, $payload)->assertSessionHasErrors('persetujuan.method');

        // Nested malformed: jaminan tanpa jenis.
        $payload = $this->validPayload($customer->id);
        unset($payload['jaminan'][0]['jenis']);
        $this->storeAs($this->admin, $payload)->assertSessionHasErrors('jaminan.0.jenis');

        // Pengeluaran negatif (finansial non-negatif, tanpa eligibility rule).
        $payload = $this->validPayload($customer->id);
        $payload['expense']['listrik'] = '-100';
        $this->storeAs($this->admin, $payload)->assertSessionHasErrors('expense.listrik');

        $this->assertSame(0, ShmLoanApplication::query()->count());
    }

    // -----------------------------------------------------------------
    // C. SHOW
    // -----------------------------------------------------------------

    public function test_saved_application_can_be_reopened_with_all_sections(): void
    {
        $application = $this->makeApplication();

        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.show', $application))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($application): bool {
                $page->component('Shm/Loans/PengajuanShow')
                    ->where('application.application_number', $application->application_number)
                    ->where('application.status', 'pending')
                    ->where('application.rate', fn (mixed $value): bool => (float) $value === 2.0)
                    ->where('application.pengajuan.principal', '460000000.00')
                    ->where('application.income.total', fn (mixed $value): bool => (float) $value === 7000000.0)
                    ->where('application.sisa', fn (mixed $value): bool => (float) $value === 4000000.0)
                    ->where('application.jaminan.0.nama', 'Motor Honda Beat')
                    ->where('application.agunan.0.jenis', 'Kendaraan')
                    ->where('application.persyaratan.0.text', 'Slip gaji terakhir')
                    ->where('application.catatan.name', 'Kasir Uji T67')
                    ->where('application.analisa.analyst_code', 'AN-01')
                    ->where('application.kolektor.name', 'Kolektor Uji T67')
                    ->where('application.persetujuan.method', 'flat')
                    ->etc();

                return true;
            });

        // Refresh (user yang sama) tetap menampilkan data yang sama
        // (persistensi nyata). Cross-owner kini 403 — diuji T68.
        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.show', $application))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/PengajuanShow')
                ->where('application.application_number', $application->application_number)
                ->etc());
    }

    public function test_show_requires_shm_permission(): void
    {
        $application = $this->makeApplication();

        // Buat sesi kembali tamu untuk menguji guard guest.
        $this->app['auth']->forgetGuards();

        $this->get(route('shm.loans.pengajuan.show', $application))
            ->assertRedirect(route('shm.login'));

        $this->actingAs($this->kasir)
            ->get(route('shm.loans.pengajuan.show', $application))
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // D. PDF
    // -----------------------------------------------------------------

    public function test_pdf_export_contains_saved_application_with_eleven_sections(): void
    {
        // Data koperasi yang TIDAK boleh muncul di PDF SHM.
        Member::factory()->create(['full_name' => 'ANGGOTA KOPERASI JANGAN MUNCUL']);

        $application = $this->makeApplication();

        $response = $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.pdf', $application));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');

        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertMatchesRegularExpression(
            '/attachment; filename="pengajuan-pinjaman-PENGAJ-PIN-\d{8}-[0-9A-F]{8}\.pdf"/',
            $disposition,
        );

        $content = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertStringContainsString('%%EOF', $content);

        // Teks dalam PDF ditulis dompdf sebagai UTF-16BE.
        $needle = fn (string $text): string => mb_convert_encoding($text, 'UTF-16BE');

        // Header & identifier.
        $this->assertStringContainsString($needle('PENGAJUAN PINJAMAN SHM'), $content);
        $this->assertStringContainsString($needle($application->application_number), $content);

        // Data nasabah SHM (bukan anggota koperasi).
        $this->assertStringContainsString($needle($application->customer->name), $content);
        $this->assertStringNotContainsString(
            $needle('ANGGOTA KOPERASI JANGAN MUNCUL'),
            $content,
        );

        // Nominal, tenor, rate = tier produk (T82).
        $this->assertStringContainsString($needle('460.000.000'), $content);
        $this->assertStringContainsString($needle('48 kali angsuran'), $content);
        // Badge PDF dapat ter-wrap di spasi (dompdf); assert token tanpa
        // spasi agar tidak bergantung pada layout sel.
        $this->assertStringContainsString($needle('tier'), $content);
        $this->assertStringContainsString($needle('T82'), $content);
        $this->assertStringNotContainsString($needle('16%'), $content);

        // Seluruh 11 section.
        foreach ([
            '1. Data Pribadi',
            '2. Pengajuan Pinjaman',
            '3. Data Penghasilan dan Pengeluaran Tiap Bulan',
            '4. Data Tambahan',
            '5. Jaminan Pinjaman',
            '6. Keterangan / Catatan oleh Kasir / SC',
            '7. Analisa Pinjaman',
            '8. Data Tambahan Analisa Pinjaman',
            '9. Kolektor Pinjaman',
            '10. Persetujuan Pinjaman',
            '11. Persyaratan Tambahan Jika Pinjaman Disetujui',
        ] as $section) {
            // Judul section dirender melalui CSS `text-transform: uppercase`,
            // sehingga needle disamakan dengan bentuk huruf besar.
            $this->assertStringContainsString(
                $needle(mb_strtoupper($section)),
                $content,
                "PDF kehilangan section \"{$section}\".",
            );
        }
    }

    public function test_pdf_requires_shm_permission(): void
    {
        $application = $this->makeApplication();

        // Buat sesi kembali tamu untuk menguji guard guest.
        $this->app['auth']->forgetGuards();

        $this->get(route('shm.loans.pengajuan.pdf', $application))
            ->assertRedirect(route('shm.login'));

        $this->actingAs($this->kasir)
            ->get(route('shm.loans.pengajuan.pdf', $application))
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // E. DOMAIN GUARDRAILS
    // -----------------------------------------------------------------

    public function test_no_stored_rate_column_and_no_universal_rate_fallback(): void
    {
        // T82 — konstanta legacy masih ada tetapi BUKAN lagi rate pinjaman;
        // rate = f(product, tenor) tanpa kolom rate pada pengajuan.
        $this->assertSame(2.0, ShmLoan::OFFICIAL_RATE_PERCENT);

        // Rate tidak pernah disimpan di pengajuan — sumber nilai tunggal.
        $this->assertFalse(Schema::hasColumn('shm_loan_applications', 'rate_percent'));

        // Tidak ada dependency ke tabel koperasi.
        $this->assertFalse(Schema::hasColumn('shm_loan_applications', 'member_id'));
        $details = Schema::getColumns('shm_loan_applications');
        $customerColumns = collect($details)
            ->pluck('name')
            ->filter(fn (string $name): bool => str_contains($name, 'customer'))
            ->values()
            ->all();
        $this->assertSame(['shm_customer_id'], $customerColumns);
    }

    public function test_application_routes_coexist_with_existing_loan_routes(): void
    {
        // T77 menambah satu route GET foto jaminan (jaminan/{collateral}/foto).
        // T86 menambah tiga route pembayaran (GET index, GET show, POST store).
        // T87 menambah satu route alokasi pembayaran (POST allocate).
        // T89 menambah tiga route pelunasan (GET index, GET show, POST store).
        // T89B menambah workflow pelunasan + master policy diskon.
        $expected = [
            'shm.loans.index',
            // T86 — transaksi pembayaran pinjaman SHM.
            // T87 — alokasi pembayaran ke angsuran (POST).
            'shm.loans.payments.allocate',
            'shm.loans.payments.index',
            'shm.loans.payments.show',
            'shm.loans.payments.store',
            // T89/T89B — pelunasan / settlement.
            'shm.loans.pelunasan.approve',
            'shm.loans.pelunasan.complete',
            'shm.loans.pelunasan.index',
            'shm.loans.pelunasan.payment',
            'shm.loans.pelunasan.payment.verify',
            'shm.loans.pelunasan.policies.index',
            'shm.loans.pelunasan.policies.store',
            'shm.loans.pelunasan.policies.toggle',
            'shm.loans.pelunasan.policies.update',
            'shm.loans.pelunasan.reject',
            'shm.loans.pelunasan.show',
            'shm.loans.pelunasan.store',
            'shm.loans.pengajuan',
            'shm.loans.pengajuan.daftar',
            'shm.loans.pengajuan.disburse',
            'shm.loans.pengajuan.edit',
            'shm.loans.pengajuan.jaminan.foto',
            'shm.loans.pengajuan.pdf',
            'shm.loans.pengajuan.show',
            'shm.loans.pengajuan.store',
            'shm.loans.pengajuan.submit-approval',
            'shm.loans.pengajuan.update',
            // T98-FIX — CRUD master produk (baca ≠ tulis: shm.product.manage).
            'shm.loans.products.create',
            'shm.loans.products.edit',
            'shm.loans.products.index',
            'shm.loans.products.store',
            'shm.loans.products.update',
            // T83 — action eksplisit pembangkitan jadwal angsuran.
            'shm.loans.schedule.generate',
            'shm.loans.show',
            'shm.loans.simulation',
            'shm.loans.simulation.pdf',
        ];

        $actual = collect(Route::getRoutes())
            ->map->getName()
            ->filter(fn (?string $name): bool => str_starts_with((string) $name, 'shm.loans.'))
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expected, $actual, 'Permukaan route shm.loans.* berubah.');

        // Wildcard loan detail tetap hanya cocok dengan id numerik; route
        // literal pengajuan tidak tertelan (berbeda jumlah segmen).
        $this->actingAs($this->admin)
            ->get('/shm/pinjaman/pengajuan')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Shm/Loans/Pengajuan'));
    }

    // -----------------------------------------------------------------
    // F. T82 — KONTRAK PRODUK: rate = f(product, tenor) & first due date
    // -----------------------------------------------------------------

    /**
     * Produk bergaya BSM Prioritas (tier eksak) — tier default factory
     * dihapus agar resolusi benar-benar diuji.
     */
    private function prioritasLikeProduct(): ShmLoanProduct
    {
        $product = ShmLoanProduct::factory()->create();
        $product->rateTiers()->delete();

        foreach ([[12, 18, 1.30], [24, 30, 1.40]] as [$min, $max, $rate]) {
            $product->rateTiers()->create([
                'min_tenor_months' => $min,
                'max_tenor_months' => $max,
                'rate_percent' => $rate,
                'rate_period' => 'monthly',
                'calculation_method' => 'flat',
            ]);
        }

        return $product;
    }

    public function test_product_and_valid_tenor_resolve_correct_rate(): void
    {
        $product = $this->prioritasLikeProduct();
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pengajuan'] = array_merge($payload['pengajuan'], [
            'product_id' => $product->id,
            'tenor_months' => 18,
        ]);

        $this->storeAs($this->admin, $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $application = ShmLoanApplication::query()->sole();

        $this->assertSame(18, $application->tenor_months);
        $this->assertSame($product->id, $application->shm_loan_product_id);
        // Rate EXAKT dari tier 12–18 → 1.30% (bukan universal 2%).
        $this->assertSame(1.3, $application->officialRatePercent());
    }

    public function test_invalid_tenor_is_rejected_by_backend(): void
    {
        $product = $this->prioritasLikeProduct();
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pengajuan'] = array_merge($payload['pengajuan'], [
            'product_id' => $product->id,
            'tenor_months' => 22, // gap 19–23
        ]);

        $this->storeAs($this->admin, $payload)
            ->assertSessionHasErrors('pengajuan.tenor_months');

        $this->assertSame(0, ShmLoanApplication::query()->count(), 'Pengajuan dengan tenor gap tidak boleh tersimpan.');
    }

    public function test_client_cannot_override_authoritative_rate(): void
    {
        $product = $this->prioritasLikeProduct();
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pengajuan'] = array_merge($payload['pengajuan'], [
            'product_id' => $product->id,
            'tenor_months' => 18,
            // Upaya payload klien mengirim rate — diabaikan (tidak ada kolom,
            // tidak ada pemetaan); rate tetap hasil resolusi backend.
            'rate' => 9.99,
            'rate_percent' => 9.99,
        ]);
        $payload['rate'] = 9.99;

        $this->storeAs($this->admin, $payload)
            ->assertSessionHasNoErrors();

        $application = ShmLoanApplication::query()->sole();

        $this->assertFalse(Schema::hasColumn('shm_loan_applications', 'rate_percent'));
        $this->assertSame(1.3, $application->officialRatePercent(), 'Rate klien tidak pernah menjadi authoritative.');
    }

    public function test_first_due_date_is_stored_as_date_type(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pengajuan']['first_due_date'] = '2027-03-15';

        $this->storeAs($this->admin, $payload)->assertSessionHasNoErrors();

        $application = ShmLoanApplication::query()->sole();

        $this->assertSame('2027-03-15', $application->first_due_date->toDateString());
        $this->assertSame('date', Schema::getColumnType('shm_loan_applications', 'first_due_date'));
    }

    public function test_first_due_date_is_not_auto_calculated(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        // Marketing belum mengisi → TIDAK boleh diisi otomatis/di-default
        // (bukan disbursement + 1 bulan, bukan hari ini).
        $payload['pengajuan']['first_due_date'] = '';

        $this->storeAs($this->admin, $payload)->assertSessionHasNoErrors();

        $application = ShmLoanApplication::query()->sole();

        $this->assertNull($application->first_due_date, 'Tanpa input Marketing, first_due_date harus tetap null.');
    }
}
