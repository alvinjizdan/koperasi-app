<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Models\User;
use App\Services\Shm\ShmLoanSimulationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithShmDisbursement;
use Tests\TestCase;

/**
 * Ticket 76-FIX — Data Pribadi adalah DATA PENGAJUAN, bukan master nasabah.
 *
 * Kontrak yang diuji:
 * - sembilan field Data Pribadi tersimpan pada `shm_loan_applications`
 *   (AC-02) dan TIDAK ada pada `shm_customers` (AC-03);
 * - menyunting pengajuan TIDAK PERNAH mengubah master nasabah (AC-04);
 * - pemilihan nasabah tetap memakai `shm_customers` — tanpa fallback ke
 *   `members` koperasi (AC-05/AC-06);
 * - dua pengajuan untuk nasabah yang sama dapat memiliki nilai berbeda
 *   (AC-07 — regresi kritis);
 * - detail & PDF membaca nilai milik pengajuan (AC-08/AC-09);
 * - pengajuan lama tanpa nilai tetap terbaca (AC-10);
 * - approval lock tetap berlaku (AC-11);
 * - approval & disbursement workflow tetap berjalan (AC-12).
 */
class ShmCustomerPersonalDataTest extends TestCase
{
    use InteractsWithShmDisbursement;
    use RefreshDatabase;

    private User $marketing;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->marketing = $this->userWithRole('marketing');
        $this->admin = $this->userWithRole('admin');

        // T002C — prasyarat pencairan fail-closed (AC-12): produk simpanan
        // aktif + sesi kasir aktif milik pelaku pencairan (admin).
        $this->seedShmSavingsProducts();
        $this->openCashierSessionFor($this->admin);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /** @return array<string, mixed> */
    private function personalData(): array
    {
        return [
            'unit_kerja' => 'Koperasi Sejahtera',
            'nip' => '0012345678', // leading zero — tidak boleh hilang
            'telepon_rumah' => '0265 123456',
            'tempat_lahir' => 'Cirebon',
            'tanggal_lahir' => '1990-05-17',
            'jumlah_tanggungan' => 3,
            'saldo_modal' => '1500000',
            'saldo_non_modal' => '750000',
            'batas_hutang' => '10000000',
        ];
    }

    /**
     * Payload valid — bentuk sama dengan ShmLoanCollateralPhotoTest::validPayload.
     *
     * @return array<string, mixed>
     */
    private function validPayload(int $customerId): array
    {
        return [
            'pribadi' => ['customer_id' => $customerId],
            'pengajuan' => [
                'product_id' => ShmLoanProduct::factory()->create()->id,
                'principal' => '150000000',
                'tenor_months' => 24,
                // T82 — jatuh tempo pertama wajib diisi Marketing sebelum
                // pencairan (fixture mengisinya secara eksplisit).
                'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
                'purpose' => 'Modal usaha',
                'loan_sequence' => 1,
                'disbursement_method' => 'Tunai',
            ],
            'income' => ['tetap' => '3000000', 'pasangan' => '', 'usaha' => '500000'],
            'expense' => [
                'potongan' => '', 'rumahTangga' => '1500000', 'pendidikan' => '',
                'sewa' => '', 'listrik' => '', 'transportasi' => '', 'lain' => '',
            ],
            'tambahan' => [
                'koperasi_loan_balance' => '0', 'other_facility' => '', 'institution_name' => '',
            ],
            'agunan' => [],
            'jaminan' => [],
            'catatan' => ['date' => now()->format('Y-m-d'), 'name' => '', 'notes' => ''],
            'analisa' => [
                'analyst_code' => '', 'analyst_office' => '', 'officer_code' => '',
                'officer_office' => '', 'date' => '', 'value' => '', 'notes' => '',
            ],
            'kolektor' => ['name' => ''],
            'persetujuan' => [
                'approved_amount' => '', 'provision_fee' => '', 'administration_fee' => '',
                'method' => 'flat', 'notes' => '',
            ],
            'persyaratan' => [],
        ];
    }

    /** @param  array<string, mixed>  $personal */
    private function storeWithPersonal(ShmCustomer $customer, array $personal): ShmLoanApplication
    {
        $payload = $this->validPayload($customer->id);
        $payload['pribadi'] = array_merge($payload['pribadi'], $personal);

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return ShmLoanApplication::query()->latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------------
    // Skema — kolom pada pengajuan, TIDAK ada pada master nasabah
    // ------------------------------------------------------------------

    public function test_personal_data_columns_live_on_loan_applications_not_customers(): void
    {
        $fields = [
            'unit_kerja', 'nip', 'telepon_rumah', 'tempat_lahir', 'tanggal_lahir',
            'jumlah_tanggungan', 'saldo_modal', 'saldo_non_modal', 'batas_hutang',
        ];

        foreach ($fields as $field) {
            $this->assertTrue(
                Schema::hasColumn('shm_loan_applications', $field),
                "Kolom {$field} harus ada pada shm_loan_applications.",
            );
            $this->assertFalse(
                Schema::hasColumn('shm_customers', $field),
                "Kolom {$field} tidak boleh ada pada shm_customers (T76-FIX).",
            );
        }
    }

    public function test_member_schema_is_untouched(): void
    {
        $this->assertFalse(Schema::hasColumn('members', 'unit_kerja'));
        $this->assertFalse(Schema::hasColumn('members', 'saldo_modal'));
        $this->assertFalse(Schema::hasColumn('members', 'batas_hutang'));
    }

    // ------------------------------------------------------------------
    // Store — sembilan field tersimpan pada pengajuan
    // ------------------------------------------------------------------

    public function test_application_store_saves_all_nine_personal_fields(): void
    {
        $customer = ShmCustomer::factory()->create();
        $application = $this->storeWithPersonal($customer, $this->personalData());

        $this->assertSame($customer->id, $application->shm_customer_id);
        $this->assertSame('Koperasi Sejahtera', $application->unit_kerja);
        $this->assertSame('0012345678', $application->nip);
        $this->assertSame('0265 123456', $application->telepon_rumah);
        $this->assertSame('Cirebon', $application->tempat_lahir);
        $this->assertSame('1990-05-17', $application->tanggal_lahir?->format('Y-m-d'));
        $this->assertSame(3, $application->jumlah_tanggungan);
        $this->assertSame('1500000.00', (string) $application->saldo_modal);
        $this->assertSame('750000.00', (string) $application->saldo_non_modal);
        $this->assertSame('10000000.00', (string) $application->batas_hutang);
    }

    public function test_application_store_keeps_nip_leading_zero(): void
    {
        $application = $this->storeWithPersonal(
            ShmCustomer::factory()->create(),
            ['nip' => '0009876543'],
        );

        $this->assertSame('0009876543', $application->nip);
    }

    public function test_negative_jumlah_tanggungan_is_rejected(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['jumlah_tanggungan'] = -1;

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasErrors('pribadi.jumlah_tanggungan');
    }

    public function test_negative_saldo_is_rejected(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi']['saldo_modal'] = '-1000';

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasErrors('pribadi.saldo_modal');
    }

    // ------------------------------------------------------------------
    // Update — menyunting pengajuan tidak menyentuh master nasabah
    // ------------------------------------------------------------------

    public function test_application_update_changes_all_nine_personal_fields(): void
    {
        $customer = ShmCustomer::factory()->create();
        $application = $this->storeWithPersonal($customer, $this->personalData());

        $payload = $this->validPayload($customer->id);
        $payload['pribadi'] = array_merge($payload['pribadi'], [
            'unit_kerja' => 'Instansi Baru',
            'nip' => '0022334455',
            'telepon_rumah' => '021 999 888',
            'tempat_lahir' => 'Bandung',
            'tanggal_lahir' => '1992-08-20',
            'jumlah_tanggungan' => 5,
            'saldo_modal' => '2000000',
            'saldo_non_modal' => '125000',
            'batas_hutang' => '50000000',
        ]);

        $this->actingAs($this->marketing)
            ->put(route('shm.loans.pengajuan.update', $application->id), $payload)
            ->assertSessionHasNoErrors();

        $application = $application->fresh();

        $this->assertSame('Instansi Baru', $application->unit_kerja);
        $this->assertSame('0022334455', $application->nip);
        $this->assertSame('021 999 888', $application->telepon_rumah);
        $this->assertSame('Bandung', $application->tempat_lahir);
        $this->assertSame('1992-08-20', $application->tanggal_lahir?->format('Y-m-d'));
        $this->assertSame(5, $application->jumlah_tanggungan);
        $this->assertSame('2000000.00', (string) $application->saldo_modal);
        $this->assertSame('125000.00', (string) $application->saldo_non_modal);
        $this->assertSame('50000000.00', (string) $application->batas_hutang);
    }

    public function test_editing_application_never_updates_customer_master(): void
    {
        $customer = ShmCustomer::factory()->create([
            'name' => 'Nasabah Isolasi',
            'phone' => '0811 111 222',
        ]);
        $application = $this->storeWithPersonal($customer, $this->personalData());

        $payload = $this->validPayload($customer->id);
        $payload['pribadi'] = array_merge($payload['pribadi'], $this->personalData(), [
            'unit_kerja' => 'PT DIUBAH PENGAJUAN',
            'saldo_modal' => '9999999',
        ]);

        $this->actingAs($this->marketing)
            ->put(route('shm.loans.pengajuan.update', $application->id), $payload)
            ->assertSessionHasNoErrors();

        $customer = $customer->fresh();

        // Identitas master tidak berubah.
        $this->assertSame('Nasabah Isolasi', $customer->name);
        $this->assertSame('0811 111 222', $customer->phone);
        // Master tidak lagi menyimpan kolom Data Pribadi apa pun.
        $this->assertArrayNotHasKey('unit_kerja', $customer->getAttributes());
        $this->assertArrayNotHasKey('saldo_modal', $customer->getAttributes());
    }

    // ------------------------------------------------------------------
    // Isolasi — dua pengajuan, satu nasabah, nilai berbeda (regresi kritis)
    // ------------------------------------------------------------------

    public function test_same_customer_can_have_two_applications_with_different_personal_data(): void
    {
        $customer = ShmCustomer::factory()->create(['name' => 'Nasabah Ganda']);

        $applicationA = $this->storeWithPersonal($customer, [
            'unit_kerja' => 'PT A',
            'nip' => '001',
        ]);
        $applicationB = $this->storeWithPersonal($customer, [
            'unit_kerja' => 'PT B',
            'nip' => '002',
        ]);

        $this->assertSame('PT A', $applicationA->fresh()->unit_kerja);
        $this->assertSame('001', $applicationA->fresh()->nip);
        $this->assertSame('PT B', $applicationB->fresh()->unit_kerja);
        $this->assertSame('002', $applicationB->fresh()->nip);

        // A tidak terpengaruh oleh B (dan sebaliknya).
        $this->assertNotSame($applicationA->id, $applicationB->id);
        $this->assertSame($customer->id, $applicationA->shm_customer_id);
        $this->assertSame($customer->id, $applicationB->shm_customer_id);

        // Master nasabah tidak tertimpa nilai aplikasi mana pun.
        $this->assertArrayNotHasKey('unit_kerja', $customer->fresh()->getAttributes());
    }

    // ------------------------------------------------------------------
    // Selector & detail & PDF
    // ------------------------------------------------------------------

    public function test_pengajuan_customer_selector_uses_shm_customers_without_member_fields(): void
    {
        $member = Member::create([
            'member_number' => 'AGT-001',
            'full_name' => 'Nasabah Selector',
            'joined_at' => now()->toDateString(),
        ]);
        $customer = ShmCustomer::factory()->create(['name' => 'Nasabah Selector']);

        $this->actingAs($this->marketing)
            ->get(route('shm.loans.pengajuan'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Pengajuan')
                ->where('customers', function ($customers) use ($customer): bool {
                    $ids = collect($customers)->pluck('id')->all();

                    // Nasabah SHM tampil; anggota koperasi TIDAK pernah menjadi
                    // sumber daftar ini (dibuktikan lewat ketiadaan field
                    // member_* pada setiap baris — cek closure berikutnya).
                    return in_array((int) $customer->id, array_map('intval', $ids), true);
                })
                // Daftar nasabah tidak lagi membawa kolom Data Pribadi —
                // field tersebut adalah milik pengajuan, bukan master.
                ->where('customers', function ($customers): bool {
                    foreach ($customers as $row) {
                        foreach (['unit_kerja', 'nip', 'saldo_modal', 'member_id', 'member_number'] as $forbidden) {
                            if (array_key_exists($forbidden, (array) $row)) {
                                return false;
                            }
                        }
                    }

                    return true;
                })
            );
    }

    public function test_application_detail_reads_application_personal_data(): void
    {
        $customer = ShmCustomer::factory()->create();
        $application = $this->storeWithPersonal($customer, $this->personalData());

        $this->actingAs($this->marketing)
            ->get(route('shm.loans.pengajuan.show', $application->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/PengajuanShow')
                ->where('application.pribadi.unit_kerja', 'Koperasi Sejahtera')
                ->where('application.pribadi.nip', '0012345678')
                ->where('application.pribadi.telepon_rumah', '0265 123456')
                ->where('application.pribadi.tempat_lahir', 'Cirebon')
                ->where('application.pribadi.tanggal_lahir', '1990-05-17')
                ->where('application.pribadi.jumlah_tanggungan', 3)
                ->where('application.pribadi.batas_hutang', '10000000.00')
                // Nilai dibaca dari pengajuan, bukan dari relasi customer.
                ->missing('application.customer.unit_kerja')
                ->missing('application.customer.saldo_modal')
            );
    }

    public function test_pdf_reads_application_personal_data(): void
    {
        $customer = ShmCustomer::factory()->create();
        $application = $this->storeWithPersonal($customer, $this->personalData());

        $html = view('shm.loan-application.pdf', [
            'application' => $application->load(['customer', 'product', 'collaterals', 'assets', 'requirements']),
            'generatedAt' => now(),
            'methods' => ShmLoanSimulationService::supportedMethods(),
            'collateralPhotos' => [],
        ])->render();

        $this->assertStringContainsString('Koperasi Sejahtera', $html);
        $this->assertStringContainsString('0012345678', $html);
        $this->assertStringContainsString('Cirebon', $html);

        // Route PDF tetap menghasilkan berkas PDF.
        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.pdf', $application))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_existing_application_without_personal_data_remains_readable(): void
    {
        $customer = ShmCustomer::factory()->create();

        // Pengajuan lama: kolom nullable, tanpa nilai Data Pribadi.
        $application = ShmLoanApplication::query()->create([
            'application_number' => 'SHM-PDJ-LAMA-1',
            'shm_customer_id' => $customer->id,
            'marketing_id' => $this->marketing->id,
            'application_date' => now()->toDateString(),
            'principal' => '10000000.00',
            'tenor_months' => 12,
            'status' => 'pending',
        ]);

        $this->actingAs($this->marketing)
            ->get(route('shm.loans.pengajuan.show', $application->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/PengajuanShow')
                ->where('application.pribadi.unit_kerja', null)
                ->where('application.pribadi.saldo_modal', null)
            );

        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.pdf', $application))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    // ------------------------------------------------------------------
    // Approval lock & workflow
    // ------------------------------------------------------------------

    public function test_locked_application_rejects_personal_data_change(): void
    {
        $customer = ShmCustomer::factory()->create();
        $application = $this->storeWithPersonal($customer, $this->personalData());

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.submit-approval', $application->id))
            ->assertSessionHasNoErrors();

        $payload = $this->validPayload($customer->id);
        $payload['pribadi'] = array_merge($payload['pribadi'], [
            'unit_kerja' => 'TIDAK BOLEH TERSIMPAN',
        ]);

        $this->actingAs($this->marketing)
            ->put(route('shm.loans.pengajuan.update', $application->id), $payload)
            ->assertSessionHasErrors('application');

        $this->assertSame('Koperasi Sejahtera', $application->fresh()->unit_kerja);
    }

    public function test_approval_and_disbursement_workflow_still_works(): void
    {
        // T89C/T150 — nasabah wajib memiliki anggota sumber aktif.
        $customer = $this->createShmCustomerWithMember();
        $payload = $this->validPayload($customer->id);
        $payload['pribadi'] = array_merge($payload['pribadi'], $this->personalData());
        $payload['persetujuan']['approved_amount'] = '150000000';

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasNoErrors();

        $application = ShmLoanApplication::query()->sole();

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.submit-approval', $application->id))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->fresh()->shm_application_id), [
                'notes' => 'Disetujui (uji T76-FIX).',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('shm.loans.pengajuan.disburse', $application->id))
            ->assertSessionHasNoErrors();

        $this->assertNotNull($application->fresh()->loan);
        // Nilai Data Pribadi tetap utuh melewati workflow.
        $this->assertSame('Koperasi Sejahtera', $application->fresh()->unit_kerja);
    }

    // ------------------------------------------------------------------
    // Customer master UI tidak lagi menampilkan field aplikasi
    // ------------------------------------------------------------------

    public function test_customer_master_pages_no_longer_expose_application_fields(): void
    {
        $customer = ShmCustomer::factory()->create();

        $this->actingAs($this->marketing)
            ->get(route('shm.customers.edit', $customer->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Customers/Edit')
                ->missing('customer.unit_kerja')
                ->missing('customer.saldo_modal')
                ->missing('customer.batas_hutang')
            );

        $this->actingAs($this->marketing)
            ->get(route('shm.customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Customers/Show')
                ->missing('customer.unit_kerja')
                ->missing('customer.saldo_modal')
            );
    }

    public function test_shm_customer_table_still_has_no_member_reference(): void
    {
        // T89C: shm_customers kini menyimpan foreign key member_id ke anggota koperasi,
        // namun tetap tidak menduplikasi member_number (tetap entity terpisah).
        $columns = Schema::getColumnListing('shm_customers');

        $this->assertContains('member_id', $columns);
        $this->assertNotContains('member_number', $columns);
    }
}
