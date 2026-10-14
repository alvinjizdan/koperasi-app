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
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * T83 — ACTION EKSPLISIT pembangkitan jadwal angsuran pada Detail Pinjaman.
 *
 * Yang diuji:
 * - ACTION: hanya POST route eksplisit yang membuat jadwal; pencairan TIDAK
 *   pernah auto-generate; membuka halaman detail TIDAK pernah generate.
 * - ANCHOR: angsuran #1 = first_due_date hasil pencairan (bukan disbursed_at).
 * - FLASH: sukses → "Jadwal angsuran berhasil dibuat."; idempoten → "sudah
 *   tersedia" tanpa baris/audit ganda; anchor null → error eksplisit, nol
 *   baris.
 * - AUTHZ: permission shm.view + ownership scope identik dengan halaman
 *   detail (tanpa view_all → hanya pinjaman sendiri; kasir 403; guest login
 *   redirect).
 * - AUDIT: tepat SATU baris audit untuk pembangkitan nyata, dengan payload
 *   identifikasi (loan, jumlah angsuran, first_due_date, rate, tenor).
 * - UI: first due date tampil; tombol muncul hanya saat jadwal belum ada;
 *   tabel jadwal tampil setelahnya; tombol hilang (tanpa regenerasi).
 */
class ShmLoanScheduleActionTest extends TestCase
{
    use RefreshDatabase;

    private const AUDIT_ACTION = 'shm.loan.schedule.generated';

    private const FIRST_DUE_DATE = '2026-11-25';

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
     * @param  array<int, string>  $permissionNames
     */
    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $role = Role::query()->create([
            'name' => 'scheduler-'.Str::uuid(),
            'display_name' => 'Scheduler Test',
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id')
        );

        $user->roles()->attach($role->id);

        return $user;
    }

    /** Pinjaman hasil pencairan sungguhan (alur T69→T82 lengkap). */
    private function disbursedLoan(): ShmLoan
    {
        $customer = ShmCustomer::factory()->create();

        $product = ShmLoanProduct::factory()->create();
        $product->rateTiers()->delete();

        foreach ([[12, 18, 1.30], [24, 30, 1.50], [36, 42, 1.50], [48, 52, 1.60]] as [$min, $max, $rate]) {
            $product->rateTiers()->create([
                'min_tenor_months' => $min,
                'max_tenor_months' => $max,
                'rate_percent' => $rate,
                'rate_period' => 'monthly',
                'calculation_method' => 'flat',
            ]);
        }

        $payload = $this->validPayload($customer->id, $product->id);

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasNoErrors();

        $application = ShmLoanApplication::query()->latest('id')->firstOrFail();

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.submit-approval', $application))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->refresh()->shm_application_id), [
                'notes' => 'Disetujui (uji T83).',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('shm.loans.pengajuan.disburse', $application))
            ->assertSessionHasNoErrors();

        return ShmLoan::query()->sole();
    }

    /**
     * Payload 11 section valid dengan tenor 48 (tier 48–52 → 1.60%) dan
     * first due date yang dipilih Marketing.
     *
     * @return array<string, mixed>
     */
    private function validPayload(int $customerId, int $productId): array
    {
        return [
            'pribadi' => ['customer_id' => $customerId],
            'pengajuan' => [
                'product_id' => $productId,
                'principal' => '460000000',
                'tenor_months' => 48,
                'first_due_date' => self::FIRST_DUE_DATE,
                'purpose' => 'UNTUK MODAL USAHA',
                'loan_sequence' => 1,
                'disbursement_method' => 'Tunai',
            ],
            'income' => ['tetap' => '5000000'],
            'expense' => ['rumahTangga' => '2000000'],
            'persetujuan' => [
                'approved_amount' => '',
                'provision_fee' => '',
                'administration_fee' => '',
                'method' => 'flat',
                'notes' => 'Uji T83.',
            ],
        ];
    }

    private function generate(ShmLoan $loan, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)
            ->post(route('shm.loans.schedule.generate', $loan));
    }

    private function auditCount(): int
    {
        return AuditLog::query()->where('action', self::AUDIT_ACTION)->count();
    }

    // =================================================================
    // NO AUTOMATIC GENERATION (STEEP 13)
    // =================================================================

    public function test_disbursement_does_not_auto_generate_schedule(): void
    {
        $loan = $this->disbursedLoan();

        $this->assertSame(0, $loan->installments()->count(), 'Pencairan tidak boleh membuat jadwal.');
        $this->assertSame(0, $this->auditCount());

        // Halaman detail TIDAK pernah membangkitkan jadwal saat dibuka.
        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $loan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Show')
                ->where('schedule.generated', false)
                ->where('can_generate_schedule', true));

        $this->assertSame(0, $loan->installments()->count(), 'Membuka halaman detail tidak boleh generate.');
    }

    // =================================================================
    // ACTION (22, 24, 25) + AUDIT (STEP 11)
    // =================================================================

    public function test_authorized_user_can_generate_schedule_with_exactly_one_audit(): void
    {
        $loan = $this->disbursedLoan();

        $this->generate($loan)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('shm.loans.show', $loan))
            ->assertSessionHas('success', 'Jadwal angsuran berhasil dibuat.');

        $this->assertSame(48, $loan->installments()->count());

        // Ansuran #1 jatuh tepat pada first_due_date (anchor T83).
        $this->assertSame(
            self::FIRST_DUE_DATE,
            $loan->installments()->orderBy('installment_number')->first()->due_date->toDateString(),
        );

        // Tepat SATU audit dengan payload identifikasi yang lengkap.
        $logs = AuditLog::query()->where('action', self::AUDIT_ACTION)->get();
        $this->assertCount(1, $logs);

        $log = $logs->first();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame(ShmLoan::class, $log->object_type);
        $this->assertSame($loan->id, $log->object_id);
        $this->assertSame($loan->loan_number, $log->object_label);
        $this->assertSame(48, data_get($log->changes, 'installments_count'));
        $this->assertSame(self::FIRST_DUE_DATE, data_get($log->changes, 'first_due_date'));
        $this->assertSame('1.6000', data_get($log->changes, 'rate_percent'));
        $this->assertSame(48, data_get($log->changes, 'tenor_months'));
    }

    public function test_second_generate_attempt_is_idempotent_without_duplicate_audit(): void
    {
        $loan = $this->disbursedLoan();

        $this->generate($loan)->assertSessionHas('success', 'Jadwal angsuran berhasil dibuat.');
        $before = $loan->installments()->pluck('id')->all();

        $this->generate($loan)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Jadwal angsuran sudah tersedia.');

        $this->assertSame($before, $loan->installments()->orderBy('installment_number')->pluck('id')->all());
        $this->assertSame(48, $loan->installments()->count());
        $this->assertSame(1, $this->auditCount(), 'No-op bukan perubahan state — tidak ada audit kedua.');
    }

    public function test_generate_rejects_loan_without_first_due_date_explicitly(): void
    {
        $loan = $this->disbursedLoan();
        $loan->forceFill(['first_due_date' => null])->save();

        // Validation error eksplisit di-flash (errors + error message).
        $this->generate($loan)
            ->assertSessionHas('error')
            ->assertSessionHasErrors('loan');

        $this->assertStringContainsString(
            'tanggal jatuh tempo pertama belum ditentukan',
            (string) session('error'),
        );

        $this->assertSame(0, $loan->installments()->count(), 'Tanpa anchor: nol baris, tanpa fallback.');
        $this->assertSame(0, $this->auditCount());
    }

    // =================================================================
    // AUTHORIZATION (23)
    // =================================================================

    public function test_user_without_shm_view_permission_cannot_generate(): void
    {
        $loan = $this->disbursedLoan();

        $this->generate($loan, $this->kasir)->assertForbidden();

        $this->assertSame(0, $loan->installments()->count());
        $this->assertSame(0, $this->auditCount());
    }

    public function test_cross_owner_without_view_all_cannot_generate(): void
    {
        $loan = $this->disbursedLoan();

        $intruder = $this->userWithPermissions(['shm.view']);
        $this->generate($loan, $intruder)->assertForbidden();

        $this->assertSame(0, $loan->installments()->count());
    }

    public function test_guest_is_redirected_and_no_schedule_is_created(): void
    {
        $loan = $this->disbursedLoan();

        $this->app['auth']->forgetGuards();
        $this->post(route('shm.loans.schedule.generate', $loan))
            ->assertRedirect(route('shm.login'));

        $this->assertSame(0, $loan->installments()->count());
    }

    public function test_owner_marketing_can_generate_for_own_loan(): void
    {
        $loan = $this->disbursedLoan();

        // Pemilik pengajuan asal (marketing) berada dalam ownership scope
        // halaman detail — generation mengikuti scope yang sama.
        $this->generate($loan, $this->marketing)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Jadwal angsuran berhasil dibuat.');

        $this->assertSame(48, $loan->installments()->count());
    }

    // =================================================================
    // UI (27–31)
    // =================================================================

    public function test_loan_detail_shows_first_due_date_product_rate_and_generate_button(): void
    {
        $loan = $this->disbursedLoan();

        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $loan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Show')
                // 27 — first due date tampil; 31 — product/rate/tenor tampil.
                ->where('loan.first_due_date', self::FIRST_DUE_DATE)
                ->where('loan.rate_percent', '1.6000')
                ->where('loan.rate_period', 'monthly')
                ->where('loan.calculation_method', 'flat')
                ->where('loan.tenor_months', 48)
                // 28 — tombol ditawarkan saat jadwal belum ada.
                ->where('schedule.generated', false)
                ->where('can_generate_schedule', true)
                ->where('schedule.installments', []));

        $source = $this->showSource();
        $this->assertStringContainsString('Generate Jadwal Angsuran', $source);
        $this->assertStringContainsString('Belum dibuat', $source);
        $this->assertStringContainsString('Jatuh Tempo Pertama', $source);
    }

    public function test_loan_detail_shows_schedule_table_and_no_generate_button_after_generation(): void
    {
        $loan = $this->disbursedLoan();
        $this->generate($loan);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $loan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Show')
                // 29/30 — jadwal tampil; tombol tidak lagi ditawarkan.
                ->where('schedule.generated', true)
                ->has('schedule.installments', 48)
                ->where('schedule.installments.0.installment_number', 1)
                ->where('schedule.installments.0.due_date', self::FIRST_DUE_DATE)
                ->where('schedule.installments.0.status', ShmLoanInstallment::STATUS_SCHEDULED)
                ->where('can_generate_schedule', false));

        $source = $this->showSource();
        $this->assertStringContainsString('Sudah dibuat', $source);
        $this->assertStringContainsString('can_generate_schedule ? (', $source, 'Tombol generate harus berada di balik guard props.');
    }

    /**
     * Source JSX komponen detail — membuktikan tombol generate SELALU
     * berada di balik guard `can_generate_schedule` (tidak pernah dirender
     * saat jadwal sudah ada; visibilitas runtime sendiri dijamin props
     * Inertia yang diassert di atas).
     */
    private function showSource(): string
    {
        return (string) file_get_contents(
            base_path('resources/js/Pages/Shm/Loans/Show.jsx')
        );
    }
}
