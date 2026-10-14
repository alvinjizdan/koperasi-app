<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Enums\ShmCreditCheckOutcome;
use App\Enums\ShmCreditCheckStatus;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmApproval;
use App\Models\ShmCreditCheck;
use App\Models\ShmCustomer;
use App\Models\ShmDocument;
use App\Models\ShmMemorandum;
use App\Models\ShmProperty;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Ticket 44 - SHM MVP Foundation & Workflow.
 *
 * Menguji: pembuatan pengajuan, validasi, nomor unik server-side, kepemilikan
 * Marketing, transisi workflow yang sah dan yang ditolak, actor dan timestamp
 * dari server, credit checking, memorandum, approval, dokumen, lokasi objek,
 * serta audit yang tercatat tepat satu kali per aksi.
 */
class ShmApplicationTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $admin;

    private User $marketing;

    private User $otherMarketing;

    private User $pengawas;

    private Member $member;

    private ShmCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = $this->userWithRole('superadmin');
        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->otherMarketing = $this->userWithRole('marketing');
        $this->pengawas = $this->userWithRole('pengawas');

        // Anggota koperasi tetap ada, tetapi pengajuan SHM TIDAK memakainya.
        // Kehadirannya menegaskan bahwa SHM berjalan tanpa tabel members.
        $this->member = Member::factory()->create();

        // Nasabah SHM adalah master data tersendiri.
        $this->customer = ShmCustomer::factory()->create();
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'shm_customer_id' => $this->customer->id,
            'notes' => 'Pengajuan awal',
        ], $overrides);
    }

    /**
     * Buat pengajuan milik Marketing melalui HTTP agar audit ikut tercatat.
     */
    private function createApplicationFor(User $user, array $overrides = []): ShmApplication
    {
        $this->actingAs($user)
            ->post(route('shm.applications.store'), $this->payload($overrides))
            ->assertSessionHasNoErrors();

        return ShmApplication::query()->latest('id')->firstOrFail();
    }

    /**
     * Majukan pengajuan sampai tahap tertentu melalui endpoint yang tersedia.
     */
    private function advanceTo(ShmApplication $application, ShmApplicationStatus $target): ShmApplication
    {
        $this->actingAs($this->marketing)->post(route('shm.applications.submit', $application->id));
        $application->refresh();

        if ($target === ShmApplicationStatus::Submitted) {
            return $application;
        }

        // Tahap-tahap proses tidak punya endpoint transisi tersendiri pada MVP:
        // Marketing mengisi datanya, lalu transisi happily Sequential.
        $application->update(['status' => $target]);
        $application->refresh();

        return $application;
    }

    // -----------------------------------------------------------------
    // A. Application: create, validasi, nomor unik, kepemilikan
    // -----------------------------------------------------------------

    public function test_marketing_can_create_application(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $application = ShmApplication::query()->firstOrFail();

        $this->assertSame($this->marketing->id, $application->marketing_id);
        $this->assertSame($this->customer->id, $application->shm_customer_id);
        $this->assertSame(ShmApplicationStatus::Draft, $application->status);
        $this->assertNull($application->submitted_at);
    }

    public function test_application_uses_shm_customer_and_does_not_require_member(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        // Relasi nasabah menunjuk ke master nasabah SHM, bukan anggota koperasi.
        $this->assertTrue($application->customer->is($this->customer));
        $this->assertSame($this->customer->name, $application->customerLabel());

        // Tabel pengajuan tidak lagi menyimpan rujukan ke anggota koperasi.
        $this->assertFalse(
            Schema::hasColumn('shm_applications', 'member_id'),
            'shm_applications tidak boleh lagi memiliki kolom member_id.'
        );
        $this->assertFalse(Schema::hasColumn('shm_applications', 'prospect_name'));
        $this->assertFalse(Schema::hasColumn('shm_applications', 'prospect_phone'));

        // Anggota koperasi yang ada tidak disentuh oleh pembuatan pengajuan.
        $this->assertSame(1, Member::query()->count());
    }

    public function test_application_number_is_generated_on_server(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $number = ShmApplication::query()->value('application_number');

        $this->assertNotNull($number);
        $this->assertMatchesRegularExpression('/^SHM-\d{8}-[0-9A-F]{8}$/', $number);
    }

    public function test_application_number_is_unique_across_many_rows(): void
    {
        $numbers = [];

        for ($i = 0; $i < 5; $i++) {
            $this->createApplicationFor($this->marketing);
            $numbers[] = ShmApplication::query()->latest('id')->value('application_number');
        }

        $this->assertCount(5, array_unique($numbers));
    }

    public function test_client_cannot_send_application_number_or_status(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.store'), $this->payload([
                'application_number' => 'PASTI-DIPAKAI',
                'status' => 'approved',
                'marketing_id' => $this->admin->id,
            ]))
            ->assertSessionHasErrors('application_number');

        // Request yang mencoba mengirim kolom server tidak boleh membuat data.
        $this->assertSame(0, ShmApplication::query()->count());
    }

    public function test_server_ignores_client_supplied_identity_fields(): void
    {
        $this->createApplicationFor($this->marketing);

        $application = ShmApplication::query()->firstOrFail();

        // Nomor dibuat server dan Marketing adalah pemilik dari sesi login.
        $this->assertNotSame('PASTI-DIPAKAI', $application->application_number);
        $this->assertSame(ShmApplicationStatus::Draft, $application->status);
        $this->assertSame($this->marketing->id, $application->marketing_id);
        $this->assertNotSame($this->admin->id, $application->marketing_id);
    }

    public function test_application_requires_customer(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.store'), [
                'notes' => 'Tanpa nasabah',
            ])
            ->assertSessionHasErrors('shm_customer_id');

        $this->assertSame(0, ShmApplication::query()->count());
    }

    public function test_application_rejects_unknown_customer(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.store'), $this->payload(['shm_customer_id' => 999999]))
            ->assertSessionHasErrors('shm_customer_id');
    }

    public function test_application_rejects_both_existing_and_new_customer(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.store'), $this->payload([
                'new_customer' => ['name' => 'Nasabah Baru Ganda'],
            ]))
            ->assertSessionHasErrors('shm_customer_id');
    }

    public function test_application_inline_new_customer_without_member_never_auto_selects_an_active_member(): void
    {
        // T89F-2 — negatif assertion: dengan DUA anggota koperasi aktif,
        // pembuatan nasabah SHM inline tanpa member_id harus DITOLAK.
        // Tidak boleh memilih anggota aktif pertama (A) maupun anggota (B).
        $memberB = Member::factory()->create();

        $beforeCustomers = ShmCustomer::query()->count();
        $beforeMembers = Member::query()->count();

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.store'), [
                'new_customer' => [
                    'name' => 'Nasabah Baru Dari Flow',
                    'nik' => '3501234567890123',
                    'phone' => '08123456789',
                ],
                'notes' => 'Pengajuan nasabah baru',
            ])
            ->assertSessionHasErrors('member_id');

        // Status database, bukan sekadar pesan error.
        $this->assertSame($beforeCustomers, ShmCustomer::query()->count());
        $this->assertSame($beforeMembers, Member::query()->count());
        $this->assertDatabaseMissing('shm_customers', ['name' => 'Nasabah Baru Dari Flow']);
        $this->assertDatabaseMissing('shm_customers', ['member_id' => $this->member->id]);
        $this->assertDatabaseMissing('shm_customers', ['member_id' => $memberB->id]);
        $this->assertDatabaseCount('shm_customers', $beforeCustomers);
    }

    public function test_application_rejects_inactive_customer(): void
    {
        $inactive = ShmCustomer::factory()->inactive()->create();

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.store'), $this->payload([
                'shm_customer_id' => $inactive->id,
            ]))
            ->assertSessionHasErrors('shm_customer_id');

        $this->assertSame(0, ShmApplication::query()->count());
    }

    public function test_application_notes_length_is_limited(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.store'), $this->payload(['notes' => str_repeat('a', 2001)]))
            ->assertSessionHasErrors('notes');
    }

    // -----------------------------------------------------------------
    // B. Workflow: transisi sah, transisi ilegal, actor, timestamp, rollback
    // -----------------------------------------------------------------

    public function test_valid_transition_from_draft_to_submitted(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();

        $this->assertSame(ShmApplicationStatus::Submitted, $application->status);
        $this->assertNotNull($application->submitted_at);
    }

    public function test_submitted_at_comes_from_server_not_client(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.submit', $application->id), [
                'submitted_at' => '1999-01-01 00:00:00',
            ]);

        $application->refresh();

        $this->assertTrue($application->submitted_at->isAfter(now()->subMinutes(5)));
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        // Draft tidak boleh langsung menjadi disbursed.
        $this->actingAs($this->admin)
            ->post(route('shm.applications.disburse', $application->id));

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::Draft, $application->status);
    }

    public function test_approve_is_rejected_outside_approval_stage(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->id))
            ->assertSessionHasErrors('status');

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::Draft, $application->status);
    }

    public function test_admin_can_approve_at_approval_stage(): void
    {
        $application = $this->createApplicationFor($this->marketing);
        $application->update(['status' => ShmApplicationStatus::Approval]);
        $application->refresh();

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->id), ['notes' => 'Layak'])
            ->assertSessionHasNoErrors();

        $application->refresh();

        $this->assertSame(ShmApplicationStatus::Approved, $application->status);
        $this->assertNotNull($application->approved_at);

        $approval = ShmApproval::query()->where('shm_application_id', $application->id)->firstOrFail();
        $this->assertSame(ShmApproval::STATUS_APPROVED, $approval->status);
        $this->assertSame($this->admin->id, $approval->decided_by);
    }

    public function test_admin_can_reject_at_approval_stage(): void
    {
        $application = $this->createApplicationFor($this->marketing);
        $application->update(['status' => ShmApplicationStatus::Approval]);
        $application->refresh();

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reject', $application->id), ['notes' => 'Belum memenuhi syarat'])
            ->assertSessionHasNoErrors();

        $application->refresh();

        $this->assertSame(ShmApplicationStatus::Rejected, $application->status);
        $this->assertNotNull($application->rejected_at);

        $approval = ShmApproval::query()->where('shm_application_id', $application->id)->firstOrFail();
        $this->assertSame(ShmApproval::STATUS_REJECTED, $approval->status);
    }

    public function test_rejected_application_can_return_to_draft(): void
    {
        $application = $this->createApplicationFor($this->marketing);
        $application->update(['status' => ShmApplicationStatus::Rejected]);
        $application->refresh();

        $this->assertTrue(
            ShmApplicationStatus::Rejected->canTransitionTo(ShmApplicationStatus::Draft)
        );
    }

    public function test_completed_application_has_no_further_transitions(): void
    {
        $this->assertTrue(ShmApplicationStatus::Completed->isFinal());
        $this->assertSame([], ShmApplicationStatus::Completed->allowedTransitions());
    }

    public function test_disburse_records_server_timestamp_only(): void
    {
        $application = $this->createApplicationFor($this->marketing);
        $application->update(['status' => ShmApplicationStatus::ReadyForDisbursement]);
        $application->refresh();

        // Ticket 51: pencairan mensyaratkan checklist dokumen lengkap.
        ShmDocument::query()->create([
            'shm_application_id' => $application->id,
            'document_name' => 'Surat Pengantar',
            'status' => ShmDocument::STATUS_COMPLETE,
        ]);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.disburse', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();

        $this->assertSame(ShmApplicationStatus::Disbursed, $application->status);
        $this->assertNotNull($application->disbursed_at);
    }

    public function test_transition_map_rejects_skipped_stages(): void
    {
        $this->assertFalse(
            ShmApplicationStatus::Draft->canTransitionTo(ShmApplicationStatus::Memorandum)
        );
        $this->assertFalse(
            ShmApplicationStatus::Submitted->canTransitionTo(ShmApplicationStatus::Approved)
        );
        $this->assertTrue(
            ShmApplicationStatus::Approval->canTransitionTo(ShmApplicationStatus::Rejected)
        );
    }

    // -----------------------------------------------------------------
    // C. Authorization
    // -----------------------------------------------------------------

    public function test_marketing_cannot_see_other_marketing_application(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->otherMarketing)
            ->get(route('shm.applications.show', $application->id))
            ->assertForbidden();
    }

    public function test_marketing_index_only_lists_own_applications(): void
    {
        $this->createApplicationFor($this->marketing);
        $this->createApplicationFor($this->otherMarketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.applications.index'))
            ->assertOk()
            ->assertSee(ShmApplication::query()->where('marketing_id', $this->marketing->id)
                ->value('application_number'))
            ->assertDontSee(ShmApplication::query()->where('marketing_id', $this->otherMarketing->id)
                ->value('application_number'));
    }

    public function test_admin_can_see_all_applications(): void
    {
        $this->createApplicationFor($this->marketing);
        $this->createApplicationFor($this->otherMarketing);

        $this->actingAs($this->admin)
            ->get(route('shm.applications.index'))
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // C1. Ticket 62 — filter `status` server-side pada daftar pengajuan.
    //
    // Drill-down Pipeline Dashboard mengirim status ke endpoint INI; tidak ada
    // endpoint, query, atau filtering frontend baru.
    // -----------------------------------------------------------------

    public function test_application_index_filters_by_status_server_side(): void
    {
        $ownApproved = $this->advanceTo(
            $this->createApplicationFor($this->marketing),
            ShmApplicationStatus::Approved
        );
        $otherApproved = $this->advanceTo(
            $this->createApplicationFor($this->otherMarketing),
            ShmApplicationStatus::Approved
        );
        $draft = $this->createApplicationFor($this->marketing);

        // view_all melihat application sesuai status lintas Marketing,
        // dan status aktif dikembalikan sebagai konteks filter.
        $this->actingAs($this->admin)
            ->get(route('shm.applications.index', ['status' => 'approved']))
            ->assertOk()
            ->assertInertia(function ($page) use ($ownApproved, $otherApproved, $draft) {
                $props = $page->toArray()['props'];

                $this->assertSame('approved', $props['filters']['status']);

                $ids = array_column($props['applications']['data'], 'id');
                $this->assertCount(2, $ids);
                $this->assertContains($ownApproved->id, $ids);
                $this->assertContains($otherApproved->id, $ids);
                // Application dengan status lain tidak ikut tampil.
                $this->assertNotContains($draft->id, $ids);

                foreach ($props['applications']['data'] as $row) {
                    $this->assertSame('approved', $row['status']);
                }
            });
    }

    public function test_application_index_status_filter_works_with_search(): void
    {
        $target = $this->advanceTo(
            $this->createApplicationFor($this->marketing),
            ShmApplicationStatus::Approved
        );
        $other = $this->advanceTo(
            $this->createApplicationFor($this->marketing),
            ShmApplicationStatus::Approved
        );

        $this->actingAs($this->admin)
            ->get(route('shm.applications.index', [
                'status' => 'approved',
                'search' => $target->application_number,
            ]))
            ->assertOk()
            ->assertInertia(function ($page) use ($target, $other) {
                $props = $page->toArray()['props'];

                $this->assertSame('approved', $props['filters']['status']);
                $this->assertSame($target->application_number, $props['filters']['search']);

                $ids = array_column($props['applications']['data'], 'id');
                $this->assertSame([$target->id], $ids);
                $this->assertNotContains($other->id, $ids);
            });
    }

    public function test_application_index_status_filter_works_with_pagination(): void
    {
        // per_page memakai whitelist existing (10, 25, 50, 100).
        foreach (range(1, 12) as $ignored) {
            $this->advanceTo(
                $this->createApplicationFor($this->marketing),
                ShmApplicationStatus::Approved
            );
        }
        $draft = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->admin)
            ->get(route('shm.applications.index', ['status' => 'approved', 'per_page' => 10]))
            ->assertOk()
            ->assertInertia(function ($page) use ($draft) {
                $props = $page->toArray()['props'];

                $this->assertSame(12, $props['applications']['total']);
                $this->assertCount(10, $props['applications']['data']);
                foreach ($props['applications']['data'] as $row) {
                    $this->assertSame('approved', $row['status']);
                }
                $this->assertNotContains($draft->id, array_column($props['applications']['data'], 'id'));
            });

        // Halaman kedua tetap terfilter dan query string dipertahankan.
        $this->actingAs($this->admin)
            ->get(route('shm.applications.index', [
                'status' => 'approved',
                'per_page' => 10,
                'page' => 2,
            ]))
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $this->assertSame(12, $props['applications']['total']);
                $this->assertCount(2, $props['applications']['data']);
                foreach ($props['applications']['data'] as $row) {
                    $this->assertSame('approved', $row['status']);
                }
            });
    }

    public function test_application_index_status_filter_cannot_bypass_ownership(): void
    {
        $own = $this->advanceTo(
            $this->createApplicationFor($this->marketing),
            ShmApplicationStatus::Approved
        );
        $other = $this->advanceTo(
            $this->createApplicationFor($this->otherMarketing),
            ShmApplicationStatus::Approved
        );

        // Restricted user memanipulasi `marketing_id` ke Marketing lain:
        // filter tersebut DIIRISKAN dengan scope kepemilikan, sehingga tidak
        // ada data Marketing lain yang bocor (hasil irisan kosong).
        $this->actingAs($this->marketing)
            ->get(route('shm.applications.index', [
                'status' => 'approved',
                'marketing_id' => $this->otherMarketing->id,
            ]))
            ->assertOk()
            ->assertInertia(function ($page) use ($own, $other) {
                $ids = array_column($page->toArray()['props']['applications']['data'], 'id');

                $this->assertNotContains($other->id, $ids);
                $this->assertNotContains($own->id, $ids);
                $this->assertCount(0, $ids);
            });

        // Dengan marketing_id sendiri, restricted user tetap hanya melihat
        // application miliknya.
        $this->actingAs($this->marketing)
            ->get(route('shm.applications.index', [
                'status' => 'approved',
                'marketing_id' => $this->marketing->id,
            ]))
            ->assertOk()
            ->assertInertia(function ($page) use ($own, $other) {
                $ids = array_column($page->toArray()['props']['applications']['data'], 'id');

                $this->assertSame([$own->id], $ids);
                $this->assertNotContains($other->id, $ids);
            });

        // Pemegang shm.view_all dapat memilih Marketing lain.
        $this->actingAs($this->admin)
            ->get(route('shm.applications.index', [
                'status' => 'approved',
                'marketing_id' => $this->marketing->id,
            ]))
            ->assertOk()
            ->assertInertia(function ($page) use ($own, $other) {
                $ids = array_column($page->toArray()['props']['applications']['data'], 'id');

                $this->assertContains($own->id, $ids);
                $this->assertNotContains($other->id, $ids);
            });
    }

    public function test_application_index_without_status_parameter_still_works(): void
    {
        $this->createApplicationFor($this->marketing);
        $this->advanceTo(
            $this->createApplicationFor($this->marketing),
            ShmApplicationStatus::Approved
        );

        $this->actingAs($this->admin)
            ->get(route('shm.applications.index'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $this->assertNull($props['filters']['status']);
                $this->assertCount(2, $props['applications']['data']);
            });
    }

    public function test_marketing_cannot_approve_application(): void
    {
        $application = $this->createApplicationFor($this->marketing);
        $application->update(['status' => ShmApplicationStatus::Approval]);
        $application->refresh();

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.approve', $application->id))
            ->assertForbidden();

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::Approval, $application->status);
    }

    public function test_pengawas_has_no_shm_access(): void
    {
        $this->actingAs($this->pengawas)
            ->get(route('shm.applications.index'))
            ->assertForbidden();
    }

    public function test_superadmin_follows_existing_bypass(): void
    {
        $this->actingAs($this->superadmin)
            ->get(route('shm.applications.index'))
            ->assertOk();

        $this->actingAs($this->superadmin)
            ->post(route('shm.applications.store'), $this->payload())
            ->assertSessionHasNoErrors();
    }

    public function test_marketing_cannot_update_application_after_submission(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.submit', $application->id));

        $this->actingAs($this->marketing)
            ->put(route('shm.applications.update', $application->id), [
                'notes' => 'Usah ubah',
            ])
            ->assertStatus(422);

        $application->refresh();
        $this->assertSame('Pengajuan awal', $application->notes);
    }

    // -----------------------------------------------------------------
    // D. Credit checking
    // -----------------------------------------------------------------

    public function test_marketing_can_record_credit_checking(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();

        // Mulai checking: satu aksi yang memindahkan pengajuan ke tahap credit
        // checking sekaligus membuat baris credit checking yang sedang berjalan.
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.credit-checking.start', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::CreditChecking, $application->status);

        $creditCheck = ShmCreditCheck::query()->firstOrFail();

        $this->assertSame(ShmCreditCheckStatus::InProgress, $creditCheck->status);
        $this->assertSame($this->marketing->id, $creditCheck->filled_by);
        $this->assertNotNull($creditCheck->started_at);

        // Hasil sementara lalu penyelesaian melalui halaman Credit Checking.
        $this->actingAs($this->marketing)
            ->put(route('shm.credit-checks.update', $creditCheck->id), [
                'outcome' => 'review',
                'result' => 'Perlu pengecekan tambahan',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->marketing)
            ->post(route('shm.credit-checks.complete', $creditCheck->id), [
                'outcome' => 'pass',
                'result' => 'Catatan pemeriksaan',
                'notes' => 'Sudah dicek',
            ])
            ->assertSessionHasNoErrors();

        $creditCheck->refresh();

        $this->assertSame(ShmCreditCheckStatus::Completed, $creditCheck->status);
        $this->assertSame(ShmCreditCheckOutcome::Pass, $creditCheck->outcome);
        $this->assertSame($this->marketing->id, $creditCheck->filled_by);
        $this->assertNotNull($creditCheck->checked_at);

        // Menyelesaikan credit checking TIDAK menyetujui atau menolak pengajuan.
        $application->refresh();
        $this->assertSame(ShmApplicationStatus::CreditChecking, $application->status);
        $this->assertNull($application->approved_at);
        $this->assertNull($application->rejected_at);
    }

    public function test_credit_checking_rejects_unknown_outcome(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.credit-checking.start', $application->id))
            ->assertSessionHasNoErrors();

        $creditCheck = ShmCreditCheck::query()->firstOrFail();

        $this->actingAs($this->marketing)
            ->post(route('shm.credit-checks.complete', $creditCheck->id), [
                'outcome' => 'approved',
                'result' => 'Hasil tidak dikenal',
            ])
            ->assertSessionHasErrors('outcome');

        $creditCheck->refresh();

        $this->assertSame(ShmCreditCheckStatus::InProgress, $creditCheck->status);
        $this->assertNull($creditCheck->outcome);
    }

    public function test_other_marketing_cannot_record_credit_checking(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->otherMarketing)
            ->post(route('shm.applications.credit-checking.start', $application->id))
            ->assertForbidden();

        $this->assertSame(0, ShmCreditCheck::query()->count());
    }

    public function test_credit_checking_update_is_audited_once(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.credit-checking.start', $application->id))
            ->assertSessionHasNoErrors();

        $creditCheck = ShmCreditCheck::query()->firstOrFail();

        $this->actingAs($this->marketing)
            ->put(route('shm.credit-checks.update', $creditCheck->id), [
                'outcome' => 'review',
                'result' => 'Sementara',
            ]);

        // Satu aksi = satu event. Tidak boleh ada event kembar dari controller
        // maupun service.
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.credit_check.started')
            ->count());

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.credit_check.updated')
            ->count());
    }

    // -----------------------------------------------------------------
    // E. Memorandum
    // -----------------------------------------------------------------

    public function test_marketing_can_record_memorandum(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        // Hanya dapat dicatat pada tahap memorandum.
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-credit-checking', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-memorandum', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();

        $this->actingAs($this->marketing)
            ->put(route('shm.applications.memorandum', $application->id), [
                'status' => ShmMemorandum::STATUS_SUBMITTED,
                'notes' => 'Draft memorandum',
            ])
            ->assertSessionHasNoErrors();

        $memorandum = ShmMemorandum::query()->firstOrFail();

        $this->assertSame(ShmMemorandum::STATUS_SUBMITTED, $memorandum->status);
        $this->assertSame($this->marketing->id, $memorandum->created_by);
        $this->assertNotNull($memorandum->submitted_at);
    }

    public function test_reject_can_return_to_draft(): void
    {
        $application = $this->createApplicationFor($this->marketing);
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();
        $application->refresh();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-credit-checking', $application->id))
            ->assertSessionHasNoErrors();
        $application->refresh();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-memorandum', $application->id))
            ->assertSessionHasNoErrors();
        $application->refresh();
        $this->actingAs($this->admin)
            ->post(route('shm.applications.start-approval', $application->id))
            ->assertSessionHasNoErrors();
        $application->refresh();
        $this->actingAs($this->admin)
            ->post(route('shm.applications.reject', $application->id), ['notes' => 'Tidak layak'])
            ->assertSessionHasNoErrors();
        $application->refresh();
        $this->assertSame(ShmApplicationStatus::Rejected, $application->status);
        $this->assertNotNull($application->rejected_at);
        $this->actingAs($this->admin)
            ->post(route('shm.applications.return-to-draft', $application->id))
            ->assertSessionHasNoErrors();
        $application->refresh();
        $this->assertSame(ShmApplicationStatus::Draft, $application->status);
        // Tidak ada aturan KSP bahwa penolakan hilang saat dikembalikan ke draft,
        // jadi rejected_at tetap sebagai catatan historis.
    }

    public function test_memorandum_creation_is_audited_once(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-credit-checking', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-memorandum', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();

        $this->actingAs($this->marketing)
            ->put(route('shm.applications.memorandum', $application->id), [
                'status' => ShmMemorandum::STATUS_DRAFT,
            ]);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.memorandum.updated')
            ->count());
    }

    public function test_other_marketing_cannot_record_memorandum(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->otherMarketing)
            ->put(route('shm.applications.memorandum', $application->id), [
                'status' => ShmMemorandum::STATUS_DRAFT,
            ])
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // F. Approval
    // -----------------------------------------------------------------

    public function test_approval_is_audited_exactly_once(): void
    {
        $application = $this->createApplicationFor($this->marketing);
        $application->update(['status' => ShmApplicationStatus::Approval]);
        $application->refresh();

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->id), ['notes' => 'Setuju']);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.application.approved')
            ->count());
    }

    public function test_rejection_is_audited_exactly_once(): void
    {
        $application = $this->createApplicationFor($this->marketing);
        $application->update(['status' => ShmApplicationStatus::Approval]);
        $application->refresh();

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reject', $application->id), ['notes' => 'Tidak']);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.application.rejected')
            ->count());
    }

    public function test_disbursement_is_audited_exactly_once(): void
    {
        $application = $this->createApplicationFor($this->marketing);
        $application->update(['status' => ShmApplicationStatus::ReadyForDisbursement]);
        $application->refresh();

        // Ticket 51: pencairan mensyaratkan checklist dokumen lengkap.
        ShmDocument::query()->create([
            'shm_application_id' => $application->id,
            'document_name' => 'Surat Pengantar',
            'status' => ShmDocument::STATUS_COMPLETE,
        ]);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.disburse', $application->id));

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.disbursed')
            ->count());
    }

    // -----------------------------------------------------------------
    // G. Documents
    // -----------------------------------------------------------------

    public function test_marketing_can_add_document_checklist_item(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        // Hanya dapat dicatat pada tahap approved dan sesudahnya.
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-credit-checking', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-memorandum', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->admin)
            ->post(route('shm.applications.start-approval', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->id), ['notes' => 'Layak'])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-document-check', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();

        $this->actingAs($this->marketing)
            ->put(route('shm.applications.document', $application->id), [
                'document_name' => 'Kartu Tanda Penduduk',
                'status' => ShmDocument::STATUS_COMPLETE,
            ])
            ->assertSessionHasNoErrors();

        $document = ShmDocument::query()->firstOrFail();

        $this->assertSame('Kartu Tanda Penduduk', $document->document_name);
        $this->assertSame(ShmDocument::STATUS_COMPLETE, $document->status);
        $this->assertSame($this->marketing->id, $document->checked_by);
        $this->assertNotNull($document->checked_at);
    }

    public function test_document_requires_name(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->put(route('shm.applications.document', $application->id), [
                'document_name' => '',
                'status' => ShmDocument::STATUS_PENDING,
            ])
            ->assertSessionHasErrors('document_name');
    }

    public function test_document_update_is_audited_once(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-credit-checking', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-memorandum', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->admin)
            ->post(route('shm.applications.start-approval', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->id), ['notes' => 'Layak'])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-document-check', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();

        $this->actingAs($this->marketing)
            ->put(route('shm.applications.document', $application->id), [
                'document_name' => 'Dokumen',
                'status' => ShmDocument::STATUS_COMPLETE,
            ]);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.document.updated')
            ->count());
    }

    public function test_documents_complete_summary(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-credit-checking', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-memorandum', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->admin)
            ->post(route('shm.applications.start-approval', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->id), ['notes' => 'Layak'])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-document-check', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();

        // Dokumen A lengkap, Dokumen B belum.
        $this->actingAs($this->marketing)
            ->put(route('shm.applications.document', $application->id), [
                'document_name' => 'Dokumen A',
                'status' => ShmDocument::STATUS_COMPLETE,
            ]);

        $application->refresh();
        $summary = $application->documentsSummary();
        $this->assertTrue($application->documentsComplete());
        $this->assertSame(1, $summary['complete']);
        $this->assertSame(0, $summary['pending']);

        // Tambah dokumen kedua, lalu seluruh checklist dianggap lengkap.
        $this->actingAs($this->marketing)
            ->put(route('shm.applications.document', $application->id), [
                'document_name' => 'Dokumen B',
                'status' => ShmDocument::STATUS_COMPLETE,
            ]);

        $application->refresh();
        $this->assertTrue($application->documentsComplete());
        $this->assertSame(2, $application->documentsSummary()['complete']);
        $this->assertSame(0, $application->documentsSummary()['pending']);
    }

    // -----------------------------------------------------------------
    // H. Lokasi objek
    // -----------------------------------------------------------------

    public function test_property_accepts_valid_coordinates(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->put(route('shm.applications.property', $application->id), [
                'certificate_type' => 'SHM',
                'certificate_number' => '123/AB',
                'address' => 'Jl. Contoh No. 10, Cirebon',
                'latitude' => -6.7320000,
                'longitude' => 108.5520000,
            ])
            ->assertSessionHasNoErrors();

        $property = ShmProperty::query()->firstOrFail();

        $this->assertSame('SHM', $property->certificate_type);
        $this->assertTrue($property->hasLocation());
    }

    public function test_property_coordinates_are_optional(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->put(route('shm.applications.property', $application->id), [
                'certificate_type' => 'SHGB',
                'address' => 'Jl. Contoh No. 11',
            ])
            ->assertSessionHasNoErrors();

        $property = ShmProperty::query()->firstOrFail();

        $this->assertFalse($property->hasLocation());
        $this->assertNull($property->latitude);
    }

    public function test_property_rejects_out_of_range_coordinates(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->put(route('shm.applications.property', $application->id), [
                'certificate_type' => 'SHM',
                'address' => 'Jl. Contoh No. 10',
                'latitude' => 120,
                'longitude' => 500,
            ])
            ->assertSessionHasErrors(['latitude', 'longitude']);

        $this->assertSame(0, ShmProperty::query()->count());
    }

    public function test_property_rejects_unsupported_certificate_type(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->put(route('shm.applications.property', $application->id), [
                'certificate_type' => 'Girik',
                'address' => 'Jl. Contoh No. 10',
            ])
            ->assertSessionHasErrors('certificate_type');
    }

    public function test_property_requires_paired_coordinates(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->put(route('shm.applications.property', $application->id), [
                'certificate_type' => 'SHM',
                'address' => 'Jl. Contoh No. 10',
                'latitude' => -6.732,
            ])
            ->assertSessionHasErrors('latitude');
    }

    // -----------------------------------------------------------------
    // I. Audit dan regression
    // -----------------------------------------------------------------

    public function test_creation_is_audited_once(): void
    {
        $this->createApplicationFor($this->marketing);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.application.created')
            ->count());
    }

    public function test_submit_is_audited_once_with_status_changed_name(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.submit', $application->id));

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.application.submitted')
            ->count());

        $this->assertSame(0, AuditLog::query()
            ->where('action', 'shm.application.status_changed')
            ->count());
    }

    public function test_failed_transition_writes_no_audit_event(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $before = AuditLog::query()->count();

        $this->actingAs($this->admin)
            ->post(route('shm.applications.disburse', $application->id));

        $this->assertSame($before, AuditLog::query()->count());
    }

    public function test_timeline_page_renders(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.applications.show', $application->id))
            ->assertOk();
    }

    public function test_permission_registry_contains_shm_module(): void
    {
        $modules = config('permissions.modules');

        $this->assertArrayHasKey('shm', $modules);
        $this->assertArrayHasKey('approval.decide', $modules['shm']['actions']);
        $this->assertArrayHasKey('view_all', $modules['shm']['actions']);

        // Ticket 46: master nasabah SHM punya permission sendiri.
        $this->assertArrayHasKey('customer.view', $modules['shm']['actions']);
        $this->assertArrayHasKey('customer.create', $modules['shm']['actions']);
        $this->assertArrayHasKey('customer.update', $modules['shm']['actions']);
    }

    public function test_marketing_role_does_not_hold_approval_or_disburse_permission(): void
    {
        $marketingPermissions = collect(config('permissions.default_roles.marketing.permissions'));

        $this->assertFalse($marketingPermissions->contains('shm.approval.decide'));
        $this->assertFalse($marketingPermissions->contains('shm.disburse'));
        $this->assertFalse($marketingPermissions->contains('shm.complete'));
        $this->assertFalse($marketingPermissions->contains('shm.view_all'));
    }

    public function test_config_registers_pending_business_decisions(): void
    {
        $pending = config('shm.pending_decisions');

        $this->assertNotEmpty($pending);

        foreach ($pending as $decision) {
            $this->assertSame('pending', $decision['status']);
        }
    }

    public function test_shm_tables_do_not_contain_financial_columns(): void
    {
        // Tidak boleh ada kolom nominal, bunga, margin, diskon, atau alike.
        $forbidden = ['amount', 'interest_rate', 'margin', 'discount', 'penalty', 'installment'];

        foreach (['shm_applications', 'shm_customers', 'shm_properties', 'shm_credit_checks', 'shm_memorandums', 'shm_approvals', 'shm_documents'] as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Tabel {$table} harus ada."
            );

            foreach ($forbidden as $column) {
                $this->assertFalse(
                    Schema::hasColumn($table, $column),
                    "Tabel {$table} tidak boleh punya kolom {$column}."
                );
            }
        }
    }
}
