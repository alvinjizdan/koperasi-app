<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanProduct;
use App\Models\User;
use App\Services\Shm\ShmRepaymentScheduleService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Ticket 69 — Pencairan Pengajuan Pinjaman SHM menjadi running loan.
 *
 * Yang diuji:
 * - A. AUTHORIZATION: guest 302, tanpa permission shm.disburse 403,
 *   cross-owner 403 (ownership server-side);
 * - B. APPROVAL GUARD: belum diajukan/pending/rejected ditolak; hanya
 *   ShmApproval 'approved' yang dapat dicairkan;
 * - C. CREATION: tepat satu ShmLoan, status active, disbursed_at terisi,
 *   tautan pengajuan→loan benar, detail page menampilkan state pencairan;
 * - D. IDEMPOTENCY: percobaan kedua ditolak tanpa loan kedua & tanpa audit
 *   kedua;
 * - E. AUDIT: tepat satu audit pencairan dengan actor pelaku;
 * - F. TRANSACTION: kegagalan pembuatan loan ATAU audit → rollback penuh;
 * - G. REGRESSION PENDUKUNG: loan muncul di Daftar Pinjaman existing;
 * - H. KONTRAK FINANSIAL T81/T82: pencairan menstempel rate hasil resolusi
 *   EXAKT produk+tenor (bukan universal 2%) + rate_period monthly +
 *   calculation_method flat + first_due_date Marketing secara atomik; tenor
 *   di luar range produk MENOLAK pencairan; tidak menghasilkan jadwal
 *   angsuran; pinjaman lolos kontrak T80; pinjaman historis tidak tersentuh.
 *
 * Yang TIDAK diuji: formula bunga/cicilan/jadwal (milik T80), resubmit
 * setelah reject (PENDING BUSINESS CONFIRMATION), collection/pembayaran.
 */
class ShmLoanDisbursementTest extends TestCase
{
    use RefreshDatabase;

    private const AUDIT_ACTION = 'shm.loan_application.disbursed';

    private User $admin;

    private User $marketing;

    private User $marketing2;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->marketing2 = $this->userWithRole('marketing');
        $this->kasir = $this->userWithRole('kasir');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /**
     * Pengguna dengan permission eksplisit, tanpa mengubah role bawaan
     * (pola ShmApprovalTest) — untuk kasus cross-owner dengan shm.disburse
     * tetapi TANPA shm.view_all.
     *
     * @param  array<int, string>  $permissionNames
     */
    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $role = Role::query()->create([
            'name' => 'disburser-'.Str::uuid(),
            'display_name' => 'Disburser Test',
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id')
        );

        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * Produk dengan tier rate gaya BSM Prioritas (T82) — tenor 48 jatuh di
     * tier 48–52 dengan rate 1.60% monthly flat. Tier default factory
     * dihapus agar tidak tumpang tindih (rate harus bukti resolusi produk,
     * bukan universal 2%).
     */
    private function productWithOfficialTiers(): ShmLoanProduct
    {
        $product = ShmLoanProduct::factory()->create();
        $product->rateTiers()->delete();

        foreach ([[12, 18, 1.30], [24, 30, 1.40], [36, 42, 1.50], [48, 52, 1.60]] as [$min, $max, $rate]) {
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

    /**
     * Payload 11 section yang valid — bentuk sama dengan state form T66/T68.
     *
     * @return array<string, mixed>
     */
    private function validPayload(int $customerId): array
    {
        return [
            'pribadi' => ['customer_id' => $customerId],
            'pengajuan' => [
                'product_id' => $this->productWithOfficialTiers()->id,
                'principal' => '460000000',
                'tenor_months' => 48,
                // T82 — jatuh tempo pertama dipilih Marketing (bukan auto).
                'first_due_date' => now()->addMonths(2)->startOfMonth()->toDateString(),
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
                    'keterangan' => 'Milik nasabah',
                    'nilai_perkiraan' => '15000000',
                    'status_pengikatan' => 'Belum diikat',
                    'tanggal_pengikatan' => '',
                    'nilai_hak_tanggungan' => '',
                ],
            ],
            'catatan' => [
                'date' => now()->format('Y-m-d'),
                'name' => 'Kasir Uji T69',
                'notes' => 'Catatan kasir uji T69.',
            ],
            'analisa' => [
                'analyst_code' => 'AN-01',
                'analyst_office' => 'Kantor Pusat',
                'officer_code' => 'AO-01',
                'officer_office' => 'Kantor Pusat',
                'date' => now()->format('Y-m-d'),
                'value' => '460000000',
                'notes' => 'Analisa uji Ticket 69.',
            ],
            'kolektor' => ['name' => 'Kolektor Uji T69'],
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

    /** Pengajuan milik $this->marketing (belum diajukan approval). */
    private function makeApplication(?array $payloadOverride = null): ShmLoanApplication
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);

        if ($payloadOverride !== null) {
            $payload = array_merge($payload, $payloadOverride);
        }

        $this->storeAs($this->marketing, $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return ShmLoanApplication::query()->latest('id')->firstOrFail();
    }

    /** Ajukan approval sebagai pemilik (marketing) — mirror ShmApproval dibuat. */
    private function submit(ShmLoanApplication $application): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.submit-approval', $application))
            ->assertSessionHasNoErrors();
    }

    /** Keputusan lewat modul Approval SHM existing (T68). */
    private function decide(ShmLoanApplication $application, string $decision): void
    {
        $mirrorId = $application->refresh()->shm_application_id;

        $this->actingAs($this->admin)
            ->post(route('shm.applications.'.$decision, $mirrorId), ['notes' => 'Keputusan uji T69'])
            ->assertSessionHasNoErrors();
    }

    private function approvedApplication(): ShmLoanApplication
    {
        $application = $this->makeApplication();
        $this->submit($application);
        $this->decide($application, 'approve');

        return $application->refresh();
    }

    private function disburse(ShmLoanApplication $application, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)
            ->post(route('shm.loans.pengajuan.disburse', $application));
    }

    private function disburseAuditCount(): int
    {
        return AuditLog::query()->where('action', self::AUDIT_ACTION)->count();
    }

    // -----------------------------------------------------------------
    // A. AUTHORIZATION
    // -----------------------------------------------------------------

    public function test_guest_is_redirected_and_no_loan_is_created(): void
    {
        $application = $this->approvedApplication();

        // Lupakan actingAs dari pembangun fixture — request berikutnya
        // benar-benar tanpa sesi autentikasi.
        $this->app['auth']->forgetGuards();

        $this->post(route('shm.loans.pengajuan.disburse', $application))
            ->assertRedirect(route('shm.login'));

        $this->assertSame(0, ShmLoan::query()->count());
        $this->assertSame(0, $this->disburseAuditCount());
    }

    public function test_user_without_disburse_permission_is_forbidden(): void
    {
        $application = $this->approvedApplication();

        // Pemilik sendiri TAPI tanpa shm.disburse (marketing sengaja tidak
        // memilikinya) → 403, bukan lolos karena menjadi owner.
        $this->disburse($application, $this->marketing)
            ->assertForbidden();

        // Kasir juga tanpa shm.disburse.
        $this->disburse($application, $this->kasir)
            ->assertForbidden();

        $this->assertSame(0, ShmLoan::query()->count());
        $this->assertSame(0, $this->disburseAuditCount());
    }

    public function test_cross_owner_with_disburse_permission_is_forbidden(): void
    {
        $application = $this->approvedApplication();

        // shm.disburse ada, shm.view_all TIDAK → tetap ditolak milik orang.
        $intruder = $this->userWithPermissions(['shm.view', 'shm.disburse']);
        $this->disburse($application, $intruder)
            ->assertForbidden();

        $this->assertSame(0, ShmLoan::query()->count());
        $this->assertSame(0, $this->disburseAuditCount());
    }

    // -----------------------------------------------------------------
    // B. APPROVAL GUARD
    // -----------------------------------------------------------------

    public function test_not_submitted_application_cannot_be_disbursed(): void
    {
        $application = $this->makeApplication();

        $this->disburse($application)
            ->assertSessionHasErrors('application');

        $this->assertSame(0, ShmLoan::query()->count());
        $this->assertSame(0, $this->disburseAuditCount());
    }

    public function test_pending_approval_cannot_be_disbursed(): void
    {
        $application = $this->makeApplication();
        $this->submit($application);

        $this->disburse($application)
            ->assertSessionHasErrors('application');

        $this->assertSame(0, ShmLoan::query()->count());
        $this->assertSame(0, $this->disburseAuditCount());
    }

    public function test_rejected_approval_cannot_be_disbursed(): void
    {
        $application = $this->makeApplication();
        $this->submit($application);
        $this->decide($application, 'reject');

        $this->disburse($application)
            ->assertSessionHasErrors('application');

        $this->assertSame(0, ShmLoan::query()->count());
        $this->assertSame(0, $this->disburseAuditCount());
    }

    public function test_approved_application_can_be_disbursed(): void
    {
        $application = $this->approvedApplication();

        // Detail page menawarkan pencairan (state approved + belum ada loan).
        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.show', $application))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/PengajuanShow')
                ->where('application.approval.status', 'approved')
                ->where('application.can_disburse', true)
                ->where('application.loan', null),
            );

        $this->disburse($application)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success')
            ->assertRedirect(route('shm.loans.pengajuan.show', $application));

        $this->assertSame(1, ShmLoan::query()->count());
    }

    // -----------------------------------------------------------------
    // C. CREATION
    // -----------------------------------------------------------------

    public function test_disbursement_creates_exactly_one_active_loan_with_correct_mapping(): void
    {
        $application = $this->approvedApplication();

        $this->disburse($application)->assertSessionHasNoErrors();

        $loan = ShmLoan::query()->sole();

        // Identitas & tautan pengajuan → loan.
        $this->assertSame($application->id, $loan->shm_loan_application_id);
        $this->assertSame($application->shm_customer_id, $loan->shm_customer_id);
        $this->assertSame($application->shm_loan_product_id, $loan->shm_loan_product_id);
        $this->assertStringStartsWith('SHM-PIN-', $loan->loan_number);

        // Pemetaan field dari pengajuan (tanpa formula baru).
        $this->assertSame('460000000.00', $loan->principal);
        $this->assertSame(48, $loan->tenor_months);
        $this->assertSame('UNTUK MODAL USAHA', $loan->purpose);

        // Lifecycle: active + disbursed_at terisi; kontrak finansial
        // (T81/T82) distempel bersama pembuatan loan — rate = hasil
        // resolusi EXAKT produk+tenor (48 bulan → tier 48–52 → 1.60%).
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->status);
        $this->assertNotNull($loan->disbursed_at);
        $this->assertSame(now()->toDateString(), $loan->disbursed_at->toDateString());
        $this->assertSame(1.6, (float) $loan->rate_percent);
        $this->assertSame('monthly', $loan->rate_period);
        $this->assertSame('flat', $loan->calculation_method);
        // T82 — first due date dibawa dari pengajuan (Marketing-pilih).
        $this->assertSame(
            $application->first_due_date->toDateString(),
            $loan->first_due_date->toDateString(),
        );

        // Pengajuan TIDAK berubah (status tetap pending per §6, approval utuh).
        $application->refresh();
        $this->assertSame('pending', $application->status);
        $this->assertSame('approved', $application->approval()?->status);
        $this->assertSame($loan->id, $application->loan->id);
    }

    public function test_detail_page_shows_disbursement_state_and_hides_disburse_button(): void
    {
        $application = $this->approvedApplication();
        $this->disburse($application)->assertSessionHasNoErrors();

        $loan = ShmLoan::query()->sole();

        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.show', $application))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/PengajuanShow')
                ->where('application.can_disburse', false, 'Tombol Pencairan harus hilang setelah cair.')
                ->where('application.loan.loan_number', $loan->loan_number)
                ->where('application.loan.status', ShmLoan::STATUS_ACTIVE)
                ->where('application.loan.disbursed_at', now()->toDateString())
                ->where('application.approval.status', 'approved'),
            );
    }

    // -----------------------------------------------------------------
    // D. IDEMPOTENCY
    // -----------------------------------------------------------------

    public function test_second_disbursement_is_rejected_without_second_loan_or_audit(): void
    {
        $application = $this->approvedApplication();

        $this->disburse($application)->assertSessionHasNoErrors();
        $this->assertSame(1, $this->disburseAuditCount());

        // Percobaan kedua (double click / request ulang) → business error.
        $this->disburse($application)
            ->assertSessionHasErrors('application');

        $this->assertSame(1, ShmLoan::query()->count(), 'Tidak boleh ada loan kedua.');
        $this->assertSame(1, $this->disburseAuditCount(), 'Tidak boleh ada audit kedua.');

        // Orang ketiga dengan shm.disburse juga tidak bisa mengulang.
        $other = $this->userWithPermissions(['shm.view', 'shm.disburse', 'shm.view_all']);
        $this->disburse($application, $other)
            ->assertSessionHasErrors('application');

        $this->assertSame(1, ShmLoan::query()->count());
        $this->assertSame(1, $this->disburseAuditCount());
    }

    // -----------------------------------------------------------------
    // E. AUDIT
    // -----------------------------------------------------------------

    public function test_successful_disbursement_writes_exactly_one_audit_with_actor(): void
    {
        $application = $this->approvedApplication();

        $this->disburse($application, $this->admin)->assertSessionHasNoErrors();

        $logs = AuditLog::query()->where('action', self::AUDIT_ACTION)->get();

        $this->assertCount(1, $logs, 'Pencairan harus menulis tepat satu audit.');
        $this->assertSame($this->admin->id, $logs->first()->actor_id, 'Actor = pelaku pencairan.');
        $this->assertSame(ShmLoanApplication::class, $logs->first()->object_type);
        $this->assertSame($application->id, $logs->first()->object_id);
        $this->assertSame($application->application_number, $logs->first()->object_label);

        $loan = ShmLoan::query()->sole();
        $this->assertSame($loan->loan_number, data_get($logs->first()->changes, 'loan_number'));
    }

    // -----------------------------------------------------------------
    // F. TRANSACTION
    // -----------------------------------------------------------------

    public function test_loan_creation_failure_rolls_back_loan_and_audit(): void
    {
        $application = $this->approvedApplication();

        $this->withoutExceptionHandling();

        ShmLoan::creating(function (): void {
            throw new RuntimeException('Loan creation gagal (uji T69).');
        });

        try {
            $this->disburse($application);
            $this->fail('Exception pembuatan loan seharusnya muncul.');
        } catch (RuntimeException) {
            // Diharapkan — transaksi harus rollback seluruhnya.
        }

        $this->assertSame(0, ShmLoan::query()->count(), 'Loan harus ikut rollback.');
        $this->assertSame(0, $this->disburseAuditCount(), 'Audit tidak boleh tertulis bila loan gagal.');

        $application->refresh();
        $this->assertSame('pending', $application->status);
        $this->assertNull($application->loan);
    }

    public function test_audit_failure_rolls_back_loan(): void
    {
        $application = $this->approvedApplication();

        $this->withoutExceptionHandling();

        AuditLog::creating(function (): void {
            throw new RuntimeException('Audit persistence gagal (uji T69).');
        });

        try {
            $this->disburse($application);
            $this->fail('Exception audit seharusnya muncul.');
        } catch (RuntimeException) {
            // Diharapkan — transaksi harus rollback seluruhnya.
        }

        $this->assertSame(0, ShmLoan::query()->count(), 'Loan harus ikut rollback saat audit gagal.');
        $this->assertSame(0, AuditLog::query()->where('action', self::AUDIT_ACTION)->count());

        $application->refresh();
        $this->assertNull($application->loan);
    }

    // -----------------------------------------------------------------
    // G. GUARD TAMBAHAN & REGRESSION PENDUKUNG
    // -----------------------------------------------------------------

    public function test_application_without_product_cannot_be_disbursed(): void
    {
        // Produk nullable di pengajuan; field wajib ShmLoan tidak boleh dikarang.
        $application = $this->makeApplication([
            'pengajuan' => array_merge($this->validPayload(1)['pengajuan'], ['product_id' => '']),
        ]);
        $this->submit($application);
        $this->decide($application, 'approve');

        $this->disburse($application)
            ->assertSessionHasErrors('application');

        $this->assertSame(0, ShmLoan::query()->count());
        $this->assertSame(0, $this->disburseAuditCount());
    }

    public function test_disbursed_loan_appears_in_existing_loan_list(): void
    {
        $application = $this->approvedApplication();
        $this->disburse($application)->assertSessionHasNoErrors();

        $loan = ShmLoan::query()->sole();

        $this->actingAs($this->admin)
            ->get(route('shm.loans.index', ['search' => $loan->loan_number]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Index')
                ->where('loans.total', 1)
                ->where('loans.data.0.loan_number', $loan->loan_number)
                ->where('loans.data.0.status', ShmLoan::STATUS_ACTIVE),
            );

        // Detail pinjaman existing juga membuka hasil pencairan.
        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $loan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Show')
                ->where('loan.loan_number', $loan->loan_number),
            );
    }

    // -----------------------------------------------------------------
    // H. KONTRAK FINANSIAL T81 (stamping baseline T79)
    // -----------------------------------------------------------------

    /**
     * AC-01 (T82): setiap pinjaman hasil pencairan menerima rate hasil
     * resolusi EXAKT produk+tenor — bukan universal 2% — beserta kontrak
     * baseline monthly + flat dan first due date dari pengajuan.
     */
    public function test_disbursement_stamps_product_derived_financial_contract(): void
    {
        $application = $this->approvedApplication();

        $this->disburse($application)->assertSessionHasNoErrors();

        $loan = ShmLoan::query()->sole();

        // Tenor 48 pada produk fixture → tier 48–52 → 1.60% (bukan 2%).
        $this->assertSame(1.6, (float) $loan->rate_percent);
        $this->assertSame('monthly', $loan->rate_period);
        $this->assertSame('flat', $loan->calculation_method);
        $this->assertNotSame(ShmLoan::OFFICIAL_RATE_PERCENT, (float) $loan->rate_percent);

        // Sumber kebenaran: kontrak T79 monthly/flat + resolver produk T82.
        $this->assertSame(
            config('shm-business-rule-contract.rate.interest_period.value'),
            $loan->rate_period,
        );
        $this->assertSame(
            config('shm-business-rule-contract.rate.calculation_method.value'),
            $loan->calculation_method,
        );

        // Kontrak tereksplicit pada record — prasyarat T80 terpenuhi.
        $this->assertTrue($loan->hasExplicitCalculation());
    }

    /**
     * AC-13.10 + AC-08: T81 HANYA menstempel kontrak. Tidak ada satu pun
     * baris angsuran yang dibuat oleh pencairan — pembangkitan jadwal tetap
     * menjadi tanggung jawab ShmRepaymentScheduleService (T80).
     */
    public function test_disbursement_does_not_generate_repayment_schedule(): void
    {
        $application = $this->approvedApplication();

        $this->disburse($application)->assertSessionHasNoErrors();

        $loan = ShmLoan::query()->sole();

        $this->assertSame(0, $loan->installments()->count(), 'Pencairan tidak boleh membuat jadwal.');
        $this->assertSame(0, ShmLoanInstallment::query()->count(), 'Tabel angsuran harus tetap kosong.');
    }

    /**
     * §14 — Integrasi T80 ↔ T81: temuan T80 (pinjaman cair tanpa kontrak)
     * kini tertutup. Alur penuh: approve → disburse → kontrak bulanan+flat
     * terstempel → pembangkitan jadwal T80 berhasil dan idempoten.
     */
    public function test_disbursed_loan_passes_t80_schedule_generation_contract(): void
    {
        $application = $this->approvedApplication();

        $this->disburse($application)->assertSessionHasNoErrors();

        $loan = ShmLoan::query()->sole();

        $this->assertSame('monthly', $loan->rate_period);
        $this->assertSame('flat', $loan->calculation_method);
        $this->assertSame(1.6, (float) $loan->rate_percent, 'Rate = tier produk 48–52 (1.60%), bukan universal 2%.');

        $schedule = app(ShmRepaymentScheduleService::class)->generate($loan);

        // 48 tenor → 48 angsuran; pokok terjumlah tepat (termasuk angsuran
        // penyeimbang terakhir).
        $this->assertCount($loan->tenor_months, $schedule);
        $this->assertSame($loan->tenor_months, $loan->installments()->count());
        $this->assertSame(
            460000000.0,
            round((float) $schedule->sum('principal_amount'), 2),
        );

        // Idempoten: panggilan kedua tidak menambah/mengubah baris.
        app(ShmRepaymentScheduleService::class)->generate($loan);

        $this->assertSame($loan->tenor_months, $loan->installments()->count());
    }

    /**
     * AC-06: pencairan baru TIDAK pernah menyentuh pinjaman historis yang
     * rate_period/calculation_method-nya masih null (tanpa backfill).
     */
    public function test_disbursement_does_not_modify_existing_loans_without_contract(): void
    {
        $legacy = ShmLoan::factory()->create([
            'rate_percent' => ShmLoan::OFFICIAL_RATE_PERCENT,
            'rate_period' => null,
            'calculation_method' => null,
            'status' => ShmLoan::STATUS_ACTIVE,
            'disbursed_at' => now()->subYear()->toDateString(),
        ]);

        $application = $this->approvedApplication();
        $this->disburse($application)->assertSessionHasNoErrors();

        $legacy->refresh();

        $this->assertSame(2, ShmLoan::query()->count());
        $this->assertNull($legacy->rate_period, 'Loan historis tidak boleh di-mass-update.');
        $this->assertNull($legacy->calculation_method, 'Loan historis tidak boleh di-mass-update.');

        $fresh = ShmLoan::query()->whereKeyNot($legacy->id)->sole();
        $this->assertSame('monthly', $fresh->rate_period);
        $this->assertSame('flat', $fresh->calculation_method);
    }

    /**
     * T82 — pencairan DITOLAK untuk tenor di luar seluruh range eksak
     * produk (gap 19–23): tanpa interpolasi/fallback/nearest/default rate.
     */
    public function test_disbursement_rejects_tenor_outside_product_range(): void
    {
        $application = $this->approvedApplication();

        // Ubah tenor pengajuan menjadi gap (19–23 tidak dicakup tier mana
        // pun) — bypass guard form; pencairan harus menolak di backend.
        $application->forceFill(['tenor_months' => 22])->save();

        $this->disburse($application)
            ->assertSessionHasErrors('pengajuan.tenor_months');

        $this->assertSame(0, ShmLoan::query()->count(), 'Tidak boleh ada loan tanpa rate valid.');
        $this->assertSame(0, $this->disburseAuditCount(), 'Tidak boleh ada audit pencairan gagal.');
    }

    /**
     * T82 — first_due_date WAJIB diisi Marketing sebelum pencairan; tidak
     * pernah dihitung otomatis dari tanggal pencairan.
     */
    public function test_disbursement_requires_first_due_date_from_marketing(): void
    {
        $application = $this->approvedApplication();
        $application->forceFill(['first_due_date' => null])->save();

        $this->disburse($application)
            ->assertSessionHasErrors('application');

        $this->assertSame(0, ShmLoan::query()->count(), 'Pencairan tanpa first_due_date harus rollback penuh.');
        $this->assertSame(0, $this->disburseAuditCount());
    }
}
