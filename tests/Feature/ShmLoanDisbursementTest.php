<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Models\User;
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
 * - G. REGRESSION PENDUKUNG: loan muncul di Daftar Pinjaman existing.
 *
 * Yang TIDAK diuji: formula bunga/cicilan/jadwal (PENDING), resubmit setelah
 * reject (PENDING BUSINESS CONFIRMATION), collection/pembayaran.
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
     * Payload 11 section yang valid — bentuk sama dengan state form T66/T68.
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

        // Lifecycle: active + disbursed_at terisi; rate resmi tanpa periode.
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->status);
        $this->assertNotNull($loan->disbursed_at);
        $this->assertSame(now()->toDateString(), $loan->disbursed_at->toDateString());
        $this->assertSame(ShmLoan::OFFICIAL_RATE_PERCENT, (float) $loan->rate_percent);
        $this->assertNull($loan->rate_period, 'Periode rate masih PENDING — tidak boleh ditebak.');

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
}
