<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmApproval;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanApplicationRequirement;
use App\Models\ShmLoanProduct;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Ticket 68 — daftar, update, dan integrasi approval Pengajuan Pinjaman SHM.
 *
 * Yang diuji:
 * - A. LIST: server-side search/filter/sort/pagination + guest/403;
 * - B. OWNERSHIP: marketing scope dari server; manipulasi marketing_id tidak
 *   membypass; detail/edit/update/ajukan cross-owner = 403;
 * - C. UPDATE: pending dapat diedit, validasi server-side, child terekonsiliasi
 *   (update/create/delete by id), audit tepat 1x, rollback penuh;
 * - D. LOCK: setelah ajukan approval/approved/rejected pengajuan terkunci
 *   (server-side, bukan hanya tombol disembunyikan);
 * - E. APPROVAL: REUSE ShmWorkflowService::startApproval + ShmApproval
 *   existing (mirror shm_applications) — atomik, idempoten, tanpa approval
 *   ganda; keputusan lewat endpoint approval existing; TIDAK pernah membuat
 *   ShmLoan aktif; status pengajuan tetap 'pending' (§6);
 * - F. PDF: menampilkan data terbaru setelah update; authorization.
 *
 * Yang TIDAK diuji: formula bunga (PENDING), disbursement (ticket berikutnya),
 * resubmit setelah reject (PENDING BUSINESS CONFIRMATION).
 */
class ShmLoanApplicationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $admin;

    private User $marketing;

    private User $marketing2;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = $this->userWithRole('superadmin');
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
                'institution_name' => 'Koperasi Lain',
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
                'name' => 'Kasir Uji T68',
                'notes' => 'Catatan kasir uji T68.',
            ],
            'analisa' => [
                'analyst_code' => 'AN-01',
                'analyst_office' => 'Kantor Pusat',
                'officer_code' => 'AO-01',
                'officer_office' => 'Kantor Pusat',
                'date' => now()->format('Y-m-d'),
                'value' => '460000000',
                'notes' => 'Analisa uji Ticket 68.',
            ],
            'kolektor' => ['name' => 'Kolektor Uji T68'],
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

    private function makeApplication(?User $as = null): ShmLoanApplication
    {
        $customer = ShmCustomer::factory()->create();
        $this->storeAs($as ?? $this->admin, $this->validPayload($customer->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return ShmLoanApplication::query()->latest('id')->firstOrFail();
    }

    // -----------------------------------------------------------------
    // A. LIST
    // -----------------------------------------------------------------

    public function test_list_guest_is_redirected_and_unauthorized_is_forbidden(): void
    {
        $this->get(route('shm.loans.pengajuan.daftar'))
            ->assertRedirect(route('shm.login'));

        $this->actingAs($this->kasir)
            ->get(route('shm.loans.pengajuan.daftar'))
            ->assertForbidden();
    }

    public function test_authorized_user_can_see_list_with_server_side_search(): void
    {
        $application = $this->makeApplication($this->marketing);

        // Search nomor pengajuan.
        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.daftar', ['search' => $application->application_number]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/PengajuanDaftar')
                ->where('applications.total', 1)
                ->has('applications.data', 1)
                ->where('applications.data.0.application_number', $application->application_number)
                ->where('applications.data.0.editable', true)
                ->where('applications.data.0.approval_status', null),
            );

        // Search nama nasabah (server-side, bukan filtering React).
        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.daftar', ['search' => $application->customer->name]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('applications.total', 1),
            );

        // Search tanpa hasil → 0 baris.
        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.daftar', ['search' => 'TIDAK-ADA-XYZ']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('applications.total', 0)
                ->has('applications.data', 0),
            );
    }

    public function test_filter_status_pagination_and_sorting_work_server_side(): void
    {
        $cheap = $this->makeApplication($this->admin);
        $cheap->update(['principal' => '100000000']);

        $customer = ShmCustomer::factory()->create();
        $this->storeAs($this->admin, array_merge(
            $this->validPayload($customer->id),
            ['pengajuan' => array_merge($this->validPayload($customer->id)['pengajuan'], ['principal' => '900000000'])],
        ))->assertSessionHasNoErrors();

        // Filter status.
        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.daftar', ['status' => 'pending']))
            ->assertInertia(fn (Assert $page) => $page->where('applications.total', 2));

        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.daftar', ['status' => 'approved']))
            ->assertInertia(fn (Assert $page) => $page->where('applications.total', 0));

        // Sorting whitelist + arah.
        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.daftar', ['sort' => 'principal', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('applications.data.0.principal', fn (string $principal): bool => (float) $principal === 900000000.0),
            );

        // Sorting di luar whitelist TIDAK diterapkan (fallback tetap aman).
        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.daftar', ['sort' => 'password', 'direction' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('applications.data', 2)
                ->where('sort.key', null),
            );

        // withQueryString(): filter bertahan pada URL pagination.
        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.daftar', ['search' => 'ZZKU']))
            ->assertInertia(fn (Assert $page) => $page
                ->where(
                    'applications.first_page_url',
                    fn ($url): bool => is_string($url) && str_contains($url, 'search=ZZKU'),
                ),
            );
    }

    // -----------------------------------------------------------------
    // B. OWNERSHIP
    // -----------------------------------------------------------------

    public function test_store_sets_marketing_from_server_and_ignores_payload_manipulation(): void
    {
        $customer = ShmCustomer::factory()->create();
        $payload = $this->validPayload($customer->id);
        // Manipulasi: klien mencoba menetapkan pemilik lain.
        $payload['marketing_id'] = $this->marketing2->id;

        $this->storeAs($this->marketing, $payload)->assertSessionHasNoErrors();

        $application = ShmLoanApplication::query()->latest('id')->firstOrFail();
        $this->assertSame($this->marketing->id, $application->marketing_id);
    }

    public function test_restricted_marketing_sees_only_own_scope(): void
    {
        $own = $this->makeApplication($this->marketing);
        $other = $this->makeApplication($this->marketing2);

        $this->actingAs($this->marketing)
            ->get(route('shm.loans.pengajuan.daftar'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('applications.total', 1)
                ->where('applications.data.0.application_number', $own->application_number)
                ->where('applications.data', fn ($rows): bool => ! collect($rows)
                    ->contains('application_number', $other->application_number)),
            );
    }

    public function test_cross_owner_detail_edit_update_pdf_and_start_approval_are_forbidden(): void
    {
        $application = $this->makeApplication($this->marketing);
        $payload = $this->validPayload($application->shm_customer_id);

        // marketing2 punya shm.view/update/submit tapi BUKAN pemilik.
        $this->actingAs($this->marketing2)
            ->get(route('shm.loans.pengajuan.show', $application))
            ->assertForbidden();

        $this->actingAs($this->marketing2)
            ->get(route('shm.loans.pengajuan.edit', $application))
            ->assertForbidden();

        $this->actingAs($this->marketing2)
            ->put(route('shm.loans.pengajuan.update', $application), $payload)
            ->assertForbidden();

        $this->actingAs($this->marketing2)
            ->get(route('shm.loans.pengajuan.pdf', $application))
            ->assertForbidden();

        $this->actingAs($this->marketing2)
            ->post(route('shm.loans.pengajuan.submit-approval', $application))
            ->assertForbidden();

        // Tidak ada perubahan sama sekali.
        $application->refresh();
        $this->assertSame('460000000.00', $application->principal);
        $this->assertNull($application->shm_application_id);
        $this->assertSame(0, ShmApproval::query()->count());
    }

    public function test_write_routes_forbidden_without_permission(): void
    {
        $application = $this->makeApplication($this->marketing);
        $payload = $this->validPayload($application->shm_customer_id);

        // Kasir tidak punya shm.update maupun shm.submit.
        $this->actingAs($this->kasir)
            ->put(route('shm.loans.pengajuan.update', $application), $payload)
            ->assertForbidden();

        $this->actingAs($this->kasir)
            ->post(route('shm.loans.pengajuan.submit-approval', $application))
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // C. UPDATE
    // -----------------------------------------------------------------

    public function test_pending_application_can_be_updated_with_child_reconciliation(): void
    {
        $application = $this->makeApplication($this->marketing);
        $collateralId = $application->collaterals()->first()->id;
        $assetId = $application->assets()->first()->id;
        $oldRequirementId = $application->requirements()->first()->id;

        $payload = $this->validPayload($application->shm_customer_id);
        $payload['pengajuan']['principal'] = '500000000';
        $payload['pengajuan']['tenor_months'] = 36;
        // Jaminan lama ikut id → di-update; baris baru tanpa id → create.
        $payload['jaminan'][0]['id'] = $collateralId;
        $payload['jaminan'][0]['nama'] = 'Motor Honda Vario';
        $payload['jaminan'][] = [
            'tanggal' => now()->format('Y-m-d'),
            'jenis' => 'Sertifikat',
            'nama' => 'SHM Rumah',
            'keterangan' => 'Jaminan tambahan',
            'nilai_perkiraan' => '300000000',
            'status_pengikatan' => 'Sudah diikat',
            'tanggal_pengikatan' => now()->format('Y-m-d'),
            'nilai_hak_tanggungan' => '300000000',
        ];
        // Agunan tidak berubah: sertakan id agar tidak di-recreate.
        $payload['agunan'][0]['id'] = $assetId;
        // Persyaratan lama DIHAPUS dari payload → di-delete; baru tanpa id.
        $payload['persyaratan'] = [
            ['text' => 'Bukti penghasilan terbaru', 'done' => true],
        ];

        $this->actingAs($this->marketing)
            ->put(route('shm.loans.pengajuan.update', $application), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('shm.loans.pengajuan.show', $application));

        $application->refresh();
        $this->assertSame('500000000.00', $application->principal);
        $this->assertSame(36, $application->tenor_months);
        // Identitas & pemilik tidak berubah saat update.
        $this->assertSame($this->marketing->id, $application->marketing_id);
        $this->assertNull($application->shm_application_id);

        $this->assertSame(2, $application->collaterals()->count());
        $this->assertSame('Motor Honda Vario', $application->collaterals()->findOrFail($collateralId)->collateral_name);

        $this->assertSame(1, $application->assets()->count());
        $this->assertSame($assetId, $application->assets()->first()->id);

        $this->assertSame(1, $application->requirements()->count());
        $this->assertNull(
            $application->requirements()->find($oldRequirementId),
            'Baris persyaratan yang hilang dari payload harus dihapus.',
        );
        $this->assertSame(
            'Bukti penghasilan terbaru',
            $application->requirements()->first()->requirement,
        );
        $this->assertTrue((bool) $application->requirements()->first()->is_done);

        // Detail menampilkan data terbaru setelah refresh.
        $this->actingAs($this->marketing)
            ->get(route('shm.loans.pengajuan.show', $application))
            ->assertInertia(fn (Assert $page) => $page
                ->where('application.pengajuan.principal', fn (string $value): bool => (float) $value === 500000000.0)
                ->where('application.pengajuan.tenor_months', 36),
            );
    }

    public function test_edit_page_renders_same_form_prefilled_with_existing_data(): void
    {
        $application = $this->makeApplication($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.loans.pengajuan.edit', $application))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Komponen yang SAMA dengan create — tanpa halaman edit terpisah.
                ->component('Shm/Loans/Pengajuan')
                ->where('application.id', $application->id)
                ->where('application.application_number', $application->application_number)
                ->where('formData.pribadi.customer_id', (string) $application->shm_customer_id)
                ->where('formData.pengajuan.principal', '460000000')
                ->where('formData.pengajuan.tenor_months', '48')
                ->where('formData.income.tetap', '5000000')
                ->where('formData.tambahan.institution_name', 'Koperasi Lain')
                // Child membawa id untuk rekonsiliasi.
                ->where('formData.jaminan.0.id', fn ($id): bool => $id !== null)
                ->where('formData.persyaratan.0.id', fn ($id): bool => $id !== null)
                ->has('customers')
                ->has('options.methods'),
            );
    }

    public function test_update_is_rejected_by_server_side_validation(): void
    {
        $application = $this->makeApplication($this->marketing);

        // Nasabah tidak valid (bukan shm_customers).
        $payload = $this->validPayload($application->shm_customer_id);
        $payload['pribadi']['customer_id'] = 999999;
        $this->actingAs($this->marketing)
            ->put(route('shm.loans.pengajuan.update', $application), $payload)
            ->assertSessionHasErrors('pribadi.customer_id');

        // Nominal negatif ditolak.
        $payload = $this->validPayload($application->shm_customer_id);
        $payload['pengajuan']['principal'] = '-1000';
        $this->actingAs($this->marketing)
            ->put(route('shm.loans.pengajuan.update', $application), $payload)
            ->assertSessionHasErrors('pengajuan.principal');

        // Metode di luar supportedMethods() ditolak.
        $payload = $this->validPayload($application->shm_customer_id);
        $payload['persetujuan']['method'] = 'bogus-method';
        $this->actingAs($this->marketing)
            ->put(route('shm.loans.pengajuan.update', $application), $payload)
            ->assertSessionHasErrors('persetujuan.method');

        $application->refresh();
        $this->assertSame('460000000.00', $application->principal, 'DB tidak boleh berubah bila validasi gagal.');
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan_application.updated')->count());
    }

    public function test_update_writes_exactly_one_audit_log(): void
    {
        $application = $this->makeApplication($this->marketing);
        $payload = $this->validPayload($application->shm_customer_id);
        $payload['pengajuan']['principal'] = '470000000';

        $this->actingAs($this->marketing)
            ->put(route('shm.loans.pengajuan.update', $application), $payload)
            ->assertSessionHasNoErrors();

        $logs = AuditLog::query()
            ->where('action', 'shm.loan_application.updated')
            ->get();

        $this->assertCount(1, $logs);
        $this->assertSame($this->marketing->id, $logs->first()->actor_id);
        $this->assertSame(ShmLoanApplication::class, $logs->first()->object_type);
        $this->assertSame($application->id, $logs->first()->object_id);
        $this->assertSame($application->application_number, $logs->first()->object_label);
    }

    public function test_update_rolls_back_all_changes_when_child_persistence_fails(): void
    {
        $application = $this->makeApplication($this->marketing);
        $originalPrincipal = $application->principal;
        $originalCollateralName = $application->collaterals()->first()->collateral_name;

        $payload = $this->validPayload($application->shm_customer_id);
        $payload['pengajuan']['principal'] = '600000000';
        $payload['jaminan'][0]['id'] = $application->collaterals()->first()->id;
        $payload['jaminan'][0]['nama'] = 'Nama Baru Yang Harus Rollback';
        // Menambah child baru → kegagalan terjadi SETELAH induk di-update.
        $payload['persyaratan'][] = ['text' => 'Persyaratan pemicu gagal', 'done' => false];

        $this->withoutExceptionHandling();

        ShmLoanApplicationRequirement::creating(function (): void {
            throw new RuntimeException('Child persistence gagal (uji T68).');
        });

        try {
            $this->actingAs($this->marketing)
                ->put(route('shm.loans.pengajuan.update', $application), $payload);
            $this->fail('Exception child persistence seharusnya muncul.');
        } catch (RuntimeException) {
            // Diharapkan — transaksi harus rollback seluruhnya.
        }

        $application->refresh();
        $this->assertSame($originalPrincipal, $application->principal, 'Induk harus rollback.');
        $this->assertSame(
            $originalCollateralName,
            $application->collaterals()->first()->collateral_name,
            'Update child harus rollback.',
        );
        $this->assertSame(1, $application->requirements()->count(), 'Child baru tidak boleh tertinggal.');
        $this->assertSame(
            0,
            AuditLog::query()->where('action', 'shm.loan_application.updated')->count(),
            'Audit tidak boleh tertulis bila update gagal.',
        );
    }

    // -----------------------------------------------------------------
    // D. APPROVAL LOCK
    // -----------------------------------------------------------------

    public function test_application_is_locked_after_start_approval(): void
    {
        $application = $this->makeApplication($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.submit-approval', $application))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('shm.loans.pengajuan.show', $application));

        $payload = $this->validPayload($application->shm_customer_id);
        $payload['pengajuan']['principal'] = '999000000';

        $this->actingAs($this->marketing)
            ->put(route('shm.loans.pengajuan.update', $application), $payload)
            ->assertSessionHasErrors('application');

        $application->refresh();
        $this->assertSame('460000000.00', $application->principal, 'Pengajuan terkunci: tidak boleh berubah.');

        // Edit page mengarahkan kembali ke detail (bukan form).
        $this->actingAs($this->marketing)
            ->get(route('shm.loans.pengajuan.edit', $application))
            ->assertRedirect(route('shm.loans.pengajuan.show', $application));

        // Daftar menandai baris tidak editable.
        $this->actingAs($this->marketing)
            ->get(route('shm.loans.pengajuan.daftar'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('applications.data.0.editable', false),
            );
    }

    public function test_application_is_locked_after_decision(): void
    {
        $application = $this->makeApplication($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.submit-approval', $application))
            ->assertSessionHasNoErrors();

        $mirrorId = $application->refresh()->shm_application_id;
        $this->assertNotNull($mirrorId);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $mirrorId), ['notes' => 'OK'])
            ->assertSessionHasNoErrors();

        $payload = $this->validPayload($application->shm_customer_id);
        $this->actingAs($this->marketing)
            ->put(route('shm.loans.pengajuan.update', $application), $payload)
            ->assertSessionHasErrors('application');

        $application->refresh();
        $this->assertSame('460000000.00', $application->principal);
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan_application.updated')->count());
    }

    // -----------------------------------------------------------------
    // E. APPROVAL INTEGRATION (REUSE existing workflow)
    // -----------------------------------------------------------------

    public function test_start_approval_creates_single_shm_approval_via_existing_workflow(): void
    {
        $application = $this->makeApplication($this->marketing);

        $auditsBefore = AuditLog::query()->count();

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.submit-approval', $application))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertNotNull($application->shm_application_id, 'Tautan approval harus terpasang.');

        // Mirror membawa nomor & nasabah yang sama dan berada di tahap
        // approval pada state machine existing.
        $mirror = ShmApplication::query()->findOrFail($application->shm_application_id);
        $this->assertSame($application->application_number, $mirror->application_number);
        $this->assertSame($application->shm_customer_id, $mirror->shm_customer_id);
        $this->assertSame($this->marketing->id, $mirror->marketing_id);
        $this->assertSame('approval', $mirror->status->value);

        // SATU baris ShmApproval (tabel existing — bukan tabel baru).
        $this->assertSame(1, ShmApproval::query()->count());
        $approval = ShmApproval::query()->sole();
        $this->assertSame($mirror->id, $approval->shm_application_id);
        $this->assertSame(ShmApproval::STATUS_PENDING, $approval->status);
        $this->assertNull($approval->decided_by);

        // TEPAT SATU audit untuk seluruh aksi start (dari workflow existing).
        $this->assertSame(
            $auditsBefore + 1,
            AuditLog::query()->count(),
            'Start approval harus menulis tepat satu audit.',
        );

        // Badge pending existing ikut menghitung pengajuan pinjaman.
        $this->assertGreaterThanOrEqual(
            1,
            ShmApproval::pendingCountForUser(true, $this->admin->id),
        );

        // Status pengajuan TIDAK berubah menjadi active/keluar dari pending.
        $this->assertSame('pending', $application->status);
        $this->assertSame(0, ShmLoan::query()->count());
    }

    public function test_start_approval_is_idempotent_without_duplicate(): void
    {
        $application = $this->makeApplication($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.submit-approval', $application))
            ->assertSessionHasNoErrors();

        $mirrorId = $application->refresh()->shm_application_id;

        // Panggilan berulang (double click / retry) tidak membuat mirror
        // maupun approval kedua.
        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.submit-approval', $application))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('shm.loans.pengajuan.show', $application));

        $application->refresh();
        $this->assertSame($mirrorId, $application->shm_application_id);
        $this->assertSame(1, ShmApplication::query()->where('application_number', $application->application_number)->count());
        $this->assertSame(1, ShmApproval::query()->count());
    }

    public function test_approval_pending_appears_in_existing_approval_module(): void
    {
        $application = $this->makeApplication($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.submit-approval', $application))
            ->assertSessionHasNoErrors();

        // Approval module existing (daftar) menampilkan pengajuan ini.
        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Approvals/Index')
                ->where('approvals.data', fn ($rows): bool => collect($rows)
                    ->contains('application_number', $application->application_number)),
            );

        // Halaman keputusan existing terbuka untuk mirror.
        $application->refresh();
        $this->actingAs($this->admin)
            ->get(route('shm.approvals.show', $application->shm_application_id))
            ->assertOk();
    }

    public function test_approve_uses_existing_workflow_and_never_creates_active_loan(): void
    {
        $application = $this->makeApplication($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.submit-approval', $application))
            ->assertSessionHasNoErrors();

        $mirrorId = $application->refresh()->shm_application_id;

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $mirrorId), ['notes' => 'Disetujui pimpinan'])
            ->assertSessionHasNoErrors();

        // ShmApproval existing yang diperbarui — bukan tabel baru.
        $approval = ShmApproval::query()->sole();
        $this->assertSame(ShmApproval::STATUS_APPROVED, $approval->status);
        $this->assertSame($this->admin->id, $approval->decided_by);
        $this->assertNotNull($approval->decided_at);
        $this->assertSame('Disetujui pimpinan', $approval->notes);

        // APPROVED ≠ active loan: tidak ada record pinjaman yang dibuat.
        $this->assertSame(0, ShmLoan::query()->count());

        // Status pengajuan pinjaman tetap vocabulary §6 ('pending') —
        // keputusan hidup di ShmApproval, bukan di kolom status.
        $application->refresh();
        $this->assertSame('pending', $application->status);
        $this->assertSame($mirrorId, $application->shm_application_id);

        // Detail menampilkan info keputusan.
        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan.show', $application))
            ->assertInertia(fn (Assert $page) => $page
                ->where('application.approval.status', ShmApproval::STATUS_APPROVED)
                ->where('application.approval.decider', $this->admin->name)
                ->where('application.can_edit', false)
                ->where('application.can_submit_approval', false),
            );
    }

    public function test_reject_uses_existing_workflow_and_keeps_application_locked(): void
    {
        $application = $this->makeApplication($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.submit-approval', $application))
            ->assertSessionHasNoErrors();

        $mirrorId = $application->refresh()->shm_application_id;

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reject', $mirrorId), ['notes' => 'Collateral kurang'])
            ->assertSessionHasNoErrors();

        $approval = ShmApproval::query()->sole();
        $this->assertSame(ShmApproval::STATUS_REJECTED, $approval->status);
        $this->assertSame($this->admin->id, $approval->decided_by);

        // Tidak ada loan; pengajuan tetap 'pending' & terkunci (resubmit PENDING).
        $this->assertSame(0, ShmLoan::query()->count());
        $application->refresh();
        $this->assertSame('pending', $application->status);

        $payload = $this->validPayload($application->shm_customer_id);
        $this->actingAs($this->marketing)
            ->put(route('shm.loans.pengajuan.update', $application), $payload)
            ->assertSessionHasErrors('application');

        // Tombol ajukan/edit tidak muncul lagi.
        $this->actingAs($this->marketing)
            ->get(route('shm.loans.pengajuan.show', $application))
            ->assertInertia(fn (Assert $page) => $page
                ->where('application.approval.status', ShmApproval::STATUS_REJECTED)
                ->where('application.can_edit', false)
                ->where('application.can_submit_approval', false),
            );
    }

    // -----------------------------------------------------------------
    // F. PDF
    // -----------------------------------------------------------------

    public function test_pdf_shows_latest_data_after_update(): void
    {
        $application = $this->makeApplication($this->marketing);

        $payload = $this->validPayload($application->shm_customer_id);
        $payload['pengajuan']['principal'] = '777000000';
        $payload['analisa']['value'] = '777000000';

        $this->actingAs($this->marketing)
            ->put(route('shm.loans.pengajuan.update', $application), $payload)
            ->assertSessionHasNoErrors();

        $response = $this->actingAs($this->marketing)
            ->get(route('shm.loans.pengajuan.pdf', $application));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');

        // Teks dalam PDF ditulis dompdf sebagai UTF-16BE (pola T67).
        $needle = fn (string $text): string => mb_convert_encoding($text, 'UTF-16BE');
        $content = (string) $response->getContent();

        $this->assertStringContainsString($needle('777.000.000'), $content, 'PDF harus menampilkan nominal terbaru.');
        $this->assertStringContainsString($needle($application->application_number), $content);
        $this->assertStringContainsString($needle('Rate resmi SHM: 2%'), $content);
    }
}
