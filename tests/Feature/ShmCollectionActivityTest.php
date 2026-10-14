<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\ShmCollectionActivity;
use App\Models\ShmCollectionCase;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanSettlement;
use App\Models\User;
use App\Services\Shm\ShmCollectionActivityService;
use App\Services\Shm\ShmCollectionCaseService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Ticket 93 — Collection Activity persistence & operational history.
 *
 * Mengunci: pencatatan lewat domain service (tipe & hasil persis contract
 * T91), relasi induk wajib ke Collection Case (tanpa jalur langsung ke
 * pinjaman), actor = user existing (Marketing pemilik case), otorisasi
 * mutation = PEMILIK case saja (tanpa fallback admin/shm.view_all), audit
 * transactional `shm.collection.activity.created` dengan konteks
 * activity/case/loan/actor, riwayat per case terurut deterministik tanpa
 * kebocoran antar-case, serta JAMINAN keras tanpa efek samping lifecycle:
 * status case tidak berubah, PROMISE_TO_PAY tidak membuat PTP record,
 * PAID tidak membuat pembayaran/menutup case, UNABLE_TO_PAY tidak
 * menescalate.
 *
 * Tidak ada pengujian detail PTP domain, escalation authority BM/PD,
 * penalty, notification, cadence, maupun assignment — semuanya masih PENDING
 * dan sengaja tidak diimplementasikan di T93.
 */
class ShmCollectionActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketing;

    private User $marketing2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        // admin = pemegang shm.view_all; marketing tanpa view_all. Keduanya
        // tidak otomatis berhak mencatat aktivitas pada case bukan miliknya.
        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->marketing2 = $this->userWithRole('marketing');
    }

    // ------------------------------------------------------------------
    // Fixtures (identik dengan T90/T92 — kelayakan & owner pakai pola sama)
    // ------------------------------------------------------------------

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(
            Role::where('name', $roleName)->firstOrFail(),
        );

        return $user;
    }

    private function applicationOwnedBy(User $user): ShmLoanApplication
    {
        $customer = ShmCustomer::factory()->create();

        return ShmLoanApplication::query()->create([
            'application_number' => 'PENGAJ-PIN-'.Str::upper(Str::random(10)),
            'shm_customer_id' => $customer->id,
            'application_date' => now()->toDateString(),
            'principal' => '100000000',
            'tenor_months' => 12,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $user->id,
            'status' => 'approved',
        ]);
    }

    private function loanForApplication(ShmLoanApplication $application): ShmLoan
    {
        return ShmLoan::factory()->create([
            'shm_customer_id' => $application->shm_customer_id,
            'shm_loan_application_id' => $application->id,
            'principal' => $application->principal,
            'tenor_months' => $application->tenor_months,
            'first_due_date' => $application->first_due_date,
        ]);
    }

    /**
     * Collection Case aktif milik $owner (lewat service T92 — jalur resmi).
     */
    private function caseOf(User $owner, int $daysOverdue = 30): ShmCollectionCase
    {
        $application = $this->applicationOwnedBy($owner);
        $loan = $this->loanForApplication($application);

        $loan->installments()->create([
            'installment_number' => 1,
            'due_date' => today()->subDays($daysOverdue)->toDateString(),
            'principal_amount' => '1000000',
            'interest_amount' => '200000',
            'total_amount' => '1200000',
            'paid_amount' => 0,
            'paid_interest_amount' => 0,
            'paid_principal_amount' => 0,
            'remaining_amount' => '1200000',
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        return app(ShmCollectionCaseService::class)->createOrGetForLoan($loan, (int) $owner->id);
    }

    /**
     * Payload valid minimum — waktu aktivitas berbeda dari waktu pencatatan.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'activity_type' => ShmCollectionActivity::TYPE_PHONE_CALL,
            'result' => ShmCollectionActivity::RESULT_CONTACTED,
            'activity_at' => now()->subDay()->startOfMinute()->format('Y-m-d H:i:s'),
        ], $overrides);
    }

    private function service(): ShmCollectionActivityService
    {
        return app(ShmCollectionActivityService::class);
    }

    private function postActivity(ShmCollectionCase $case, User $as, array $payload): TestResponse
    {
        return $this->actingAs($as)->post(
            route('shm.collection.activities.store', $case),
            $payload,
        );
    }

    // ------------------------------------------------------------------
    // Pembuatan & relasi induk (butir 1–4, 28–29)
    // ------------------------------------------------------------------

    public function test_owner_can_create_activity_for_own_case(): void
    {
        $case = $this->caseOf($this->marketing);
        $payload = $this->validPayload([
            'notes' => 'Menelpon nasabah, menanyakan tunggakan.',
            'next_action' => 'Hubungi kembali setelah tanggal gajian.',
        ]);

        $activity = $this->service()->create($case, (int) $this->marketing->id, $payload);

        $this->assertDatabaseHas('shm_collection_activities', [
            'id' => $activity->id,
            'shm_collection_case_id' => $case->id,
            'user_id' => $this->marketing->id,
            'activity_type' => ShmCollectionActivity::TYPE_PHONE_CALL,
            'result' => ShmCollectionActivity::RESULT_CONTACTED,
            'notes' => 'Menelpon nasabah, menanyakan tunggakan.',
            'next_action' => 'Hubungi kembali setelah tanggal gajian.',
        ]);
        $this->assertSame($case->id, $activity->shm_collection_case_id);
        $this->assertSame($this->marketing->id, $activity->user_id);
        $this->assertSame($case->id, $activity->case()->first()->id);
        $this->assertSame($this->marketing->id, $activity->actor()->first()->id);
    }

    public function test_activity_requires_existing_case_and_never_attaches_directly_to_loan(): void
    {
        $case = $this->caseOf($this->marketing);

        $activity = $this->service()->create($case, (int) $this->marketing->id, $this->validPayload());

        // Relasi induk = case; TIDAK ada kolom/jalur langsung ke pinjaman.
        $this->assertDatabaseHas('shm_collection_activities', [
            'id' => $activity->id,
            'shm_collection_case_id' => $case->id,
        ]);
        $this->assertArrayNotHasKey('shm_loan_id', $activity->getAttributes());
        $this->assertSame($case->id, $activity->case->id);
    }

    public function test_multiple_activities_can_be_recorded_for_one_case(): void
    {
        $case = $this->caseOf($this->marketing);

        $this->service()->create($case, (int) $this->marketing->id, $this->validPayload());
        $this->service()->create($case, (int) $this->marketing->id, $this->validPayload([
            'activity_type' => ShmCollectionActivity::TYPE_VISIT,
        ]));
        $this->service()->create($case, (int) $this->marketing->id, $this->validPayload([
            'activity_type' => ShmCollectionActivity::TYPE_FOLLOW_UP,
        ]));

        $this->assertSame(3, $case->activities()->count());
    }

    // ------------------------------------------------------------------
    // Tipe & hasil persis contract T91 (butir 5–11, 26)
    // ------------------------------------------------------------------

    public function test_model_constants_match_t91_contract_values(): void
    {
        $contract = Config::get('shm-business-rule-contract.collection_workflow');

        $this->assertSame($contract['activities']['types'], ShmCollectionActivity::TYPES);
        $this->assertSame($contract['results']['values'], ShmCollectionActivity::RESULTS);
    }

    public function test_all_contract_activity_types_are_accepted(): void
    {
        $case = $this->caseOf($this->marketing);

        foreach (ShmCollectionActivity::TYPES as $type) {
            $activity = $this->service()->create($case, (int) $this->marketing->id, $this->validPayload([
                'activity_type' => $type,
            ]));

            $this->assertSame($type, $activity->activity_type, "Tipe {$type} harus diterima.");
        }

        $this->assertSame(count(ShmCollectionActivity::TYPES), $case->activities()->count());
    }

    public function test_all_contract_result_values_are_accepted(): void
    {
        $case = $this->caseOf($this->marketing);

        foreach (ShmCollectionActivity::RESULTS as $result) {
            $activity = $this->service()->create($case, (int) $this->marketing->id, $this->validPayload([
                'result' => $result,
            ]));

            $this->assertSame($result, $activity->result, "Hasil {$result} harus diterima.");
        }

        $this->assertSame(count(ShmCollectionActivity::RESULTS), $case->activities()->count());
    }

    public function test_invalid_activity_type_is_rejected(): void
    {
        $case = $this->caseOf($this->marketing);

        try {
            $this->service()->create($case, (int) $this->marketing->id, $this->validPayload([
                'activity_type' => 'whatsapp',
            ]));
            $this->fail('Tipe di luar kontrak harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collection.aktivitas.tipe', $exception->errors());
        }

        $this->assertDatabaseCount('shm_collection_activities', 0);
    }

    public function test_invalid_result_is_rejected(): void
    {
        $case = $this->caseOf($this->marketing);

        try {
            $this->service()->create($case, (int) $this->marketing->id, $this->validPayload([
                'result' => 'BROKEN_PROMISE',
            ]));
            $this->fail('Hasil di luar kontrak harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collection.aktivitas.hasil', $exception->errors());
        }

        $this->assertDatabaseCount('shm_collection_activities', 0);
    }

    // ------------------------------------------------------------------
    // activity_at / notes / next_action (butir 12–14, 21 timestamp)
    // ------------------------------------------------------------------

    public function test_activity_at_is_required_on_http_endpoint(): void
    {
        $case = $this->caseOf($this->marketing);

        $response = $this->postActivity($case, $this->marketing, [
            'activity_type' => ShmCollectionActivity::TYPE_PHONE_CALL,
            'result' => ShmCollectionActivity::RESULT_CONTACTED,
        ]);

        $response->assertSessionHasErrors('activity_at');
        $this->assertDatabaseCount('shm_collection_activities', 0);
    }

    public function test_activity_at_is_independent_from_created_at(): void
    {
        $case = $this->caseOf($this->marketing);
        $yesterday = now()->subDay()->startOfMinute();

        $activity = $this->service()->create($case, (int) $this->marketing->id, $this->validPayload([
            'activity_at' => $yesterday->format('Y-m-d H:i:s'),
        ]));

        $this->assertTrue($activity->activity_at->equalTo($yesterday));
        $this->assertTrue($activity->created_at->greaterThan($activity->activity_at));
    }

    public function test_future_activity_at_is_not_rejected_by_guessed_policy(): void
    {
        // Contract T91 tidak mendefinisikan kebijakan tanggal aktivitas —
        // T93 dilarang menebak (backdate/masa depan sama sekali tidak dibatasi).
        $case = $this->caseOf($this->marketing);

        $activity = $this->service()->create($case, (int) $this->marketing->id, $this->validPayload([
            'activity_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ]));

        $this->assertTrue($activity->activity_at->isFuture());
    }

    public function test_notes_and_next_action_are_optional(): void
    {
        $case = $this->caseOf($this->marketing);

        $activity = $this->service()->create($case, (int) $this->marketing->id, $this->validPayload([
            'notes' => null,
            'next_action' => null,
        ]));

        $this->assertNull($activity->notes);
        $this->assertNull($activity->next_action);
    }

    // ------------------------------------------------------------------
    // Otorisasi pemilik case (butir 17–18, 19 via HTTP)
    // ------------------------------------------------------------------

    public function test_unauthorized_marketing_cannot_create_activity(): void
    {
        $case = $this->caseOf($this->marketing);

        try {
            $this->service()->create($case, (int) $this->marketing2->id, $this->validPayload());
            $this->fail('Marketing non-pemilik harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collection.otorisasi', $exception->errors());
        }

        $this->assertDatabaseCount('shm_collection_activities', 0);
    }

    public function test_shm_view_all_holder_cannot_bypass_owner_authorization(): void
    {
        $case = $this->caseOf($this->marketing);

        $response = $this->postActivity($case, $this->admin, $this->validPayload());

        $response->assertSessionHasErrors('collection.otorisasi');
        $this->assertDatabaseCount('shm_collection_activities', 0);
    }

    public function test_non_owner_direct_post_is_rejected_even_with_module_access(): void
    {
        // Marketing lain memiliki `shm.view` (gate modul Collection) tetapi
        // bukan pemilik case — POST langsung harus tetap ditolak di domain.
        $case = $this->caseOf($this->marketing);

        $response = $this->postActivity($case, $this->marketing2, $this->validPayload([
            'result' => ShmCollectionActivity::RESULT_NOT_REACHED,
        ]));

        $response->assertSessionHasErrors('collection.otorisasi');
        $this->assertDatabaseCount('shm_collection_activities', 0);
    }

    public function test_activity_for_nonexistent_case_is_rejected(): void
    {
        $response = $this->actingAs($this->marketing)->post(
            '/shm/collection/999999/activities',
            $this->validPayload(),
        );

        $response->assertNotFound();
        $this->assertDatabaseCount('shm_collection_activities', 0);
    }

    public function test_owner_can_create_activity_via_http(): void
    {
        $case = $this->caseOf($this->marketing);

        $response = $this->postActivity($case, $this->marketing, $this->validPayload([
            'activity_at' => now()->subHours(2)->format('Y-m-d H:i:s'),
        ]));

        $response->assertRedirect(route('shm.collection.show', $case->shm_loan_id));
        $response->assertSessionHas('success');
        $this->assertDatabaseCount('shm_collection_activities', 1);
        $this->assertDatabaseHas('shm_collection_activities', [
            'shm_collection_case_id' => $case->id,
            'user_id' => $this->marketing->id,
            'result' => ShmCollectionActivity::RESULT_CONTACTED,
        ]);
    }

    // ------------------------------------------------------------------
    // Audit transactional (butir 15–16, 27)
    // ------------------------------------------------------------------

    public function test_audit_is_written_on_creation(): void
    {
        $case = $this->caseOf($this->marketing);

        $activity = $this->service()->create($case, (int) $this->marketing->id, $this->validPayload());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'shm.collection.activity.created',
            'object_type' => ShmCollectionActivity::class,
            'object_id' => $activity->id,
        ]);
    }

    public function test_audit_contains_activity_case_loan_actor_context(): void
    {
        $case = $this->caseOf($this->marketing);
        $activityAt = now()->subDay()->startOfMinute();

        $activity = $this->service()->create($case, (int) $this->marketing->id, $this->validPayload([
            'activity_at' => $activityAt->format('Y-m-d H:i:s'),
        ]));

        $log = AuditLog::query()
            ->where('action', 'shm.collection.activity.created')
            ->where('object_id', $activity->id)
            ->firstOrFail();

        $this->assertSame($activity->id, $log->changes['collection_activity_id']);
        $this->assertSame($case->id, $log->changes['shm_collection_case_id']);
        $this->assertSame($case->shm_loan_id, $log->changes['shm_loan_id']);
        $this->assertSame($this->marketing->id, $log->changes['actor_id']);
        $this->assertSame(ShmCollectionActivity::TYPE_PHONE_CALL, $log->changes['activity_type']);
        $this->assertSame(ShmCollectionActivity::RESULT_CONTACTED, $log->changes['result']);
        $this->assertSame($activityAt->toDateTimeString(), $log->changes['activity_at']);
    }

    public function test_audit_failure_rolls_back_activity_creation(): void
    {
        $case = $this->caseOf($this->marketing);

        AuditLog::creating(function (): void {
            throw new RuntimeException('Audit sengaja gagal (uji rollback T93).');
        });

        try {
            $this->service()->create($case, (int) $this->marketing->id, $this->validPayload());
            $this->fail('Kegagalan audit harus membatalkan transaksi.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit sengaja gagal (uji rollback T93).', $exception->getMessage());
        }

        $this->assertDatabaseCount('shm_collection_activities', 0);
        // Tidak ada audit aktivitas; audit pembuatan case fixture (T92) tetap ada.
        $this->assertDatabaseMissing('audit_logs', ['action' => 'shm.collection.activity.created']);
    }

    // ------------------------------------------------------------------
    // Tanpa efek samping lifecycle (butir 20–24)
    // ------------------------------------------------------------------

    public function test_activity_does_not_change_case_status(): void
    {
        $case = $this->caseOf($this->marketing);

        $this->service()->create($case, (int) $this->marketing->id, $this->validPayload());

        $case->refresh();
        $this->assertSame(ShmCollectionCase::STATUS_OPEN, $case->status);
        $this->assertNull($case->resolved_at);
    }

    public function test_promise_to_pay_result_does_not_create_ptp_or_change_status(): void
    {
        // Tabel PTP tidak ada di T93 (domain PTP = ticket terpisah); hasil
        // PROMISE_TO_PAY hanya catatan historis — status case tidak berubah
        // menjadi PROMISE_TO_PAY dan tidak ada record baru di tabel terkait.
        $case = $this->caseOf($this->marketing);

        $this->service()->create($case, (int) $this->marketing->id, $this->validPayload([
            'result' => ShmCollectionActivity::RESULT_PROMISE_TO_PAY,
        ]));

        $case->refresh();
        $this->assertSame(ShmCollectionCase::STATUS_OPEN, $case->status);
        $this->assertDatabaseCount('shm_loan_settlements', 0);
    }

    public function test_paid_result_does_not_create_payment_or_resolve_case(): void
    {
        $case = $this->caseOf($this->marketing);

        $this->service()->create($case, (int) $this->marketing->id, $this->validPayload([
            'result' => ShmCollectionActivity::RESULT_PAID,
        ]));

        $this->assertDatabaseCount('shm_loan_payments', 0);
        $this->assertSame(0, ShmLoanPayment::query()->count());
        $this->assertSame(0, ShmLoanSettlement::query()->count());

        $case->refresh();
        $this->assertSame(ShmCollectionCase::STATUS_OPEN, $case->status);
        $this->assertNull($case->resolved_at);
    }

    public function test_unable_to_pay_result_does_not_escalate(): void
    {
        $case = $this->caseOf($this->marketing);

        $this->service()->create($case, (int) $this->marketing->id, $this->validPayload([
            'result' => ShmCollectionActivity::RESULT_UNABLE_TO_PAY,
        ]));

        $case->refresh();
        $this->assertSame(ShmCollectionCase::STATUS_OPEN, $case->status);
        $this->assertNotSame(ShmCollectionCase::STATUS_ESCALATED, $case->status);
    }

    // ------------------------------------------------------------------
    // Riwayat pada halaman detail (butir 25–26, 30)
    // ------------------------------------------------------------------

    public function test_detail_page_returns_activity_history_for_its_case(): void
    {
        $case = $this->caseOf($this->marketing);
        $this->service()->create($case, (int) $this->marketing->id, $this->validPayload([
            'notes' => 'Catatan kunjungan pertama.',
        ]));

        $response = $this->actingAs($this->marketing)
            ->get(route('shm.collection.show', $case->shm_loan_id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Collection/Show')
            ->where('collection_case.id', $case->id)
            ->where('collection_case.can_record', true)
            ->has('collection_case.activities', 1)
            ->where('collection_case.activities.0.result', ShmCollectionActivity::RESULT_CONTACTED)
            ->where('collection_case.activities.0.notes', 'Catatan kunjungan pertama.')
            ->where('collection_case.activities.0.actor.id', $this->marketing->id));
    }

    public function test_detail_page_history_does_not_leak_other_case_activities(): void
    {
        $caseA = $this->caseOf($this->marketing);
        $caseB = $this->caseOf($this->marketing2);

        $activityA = $this->service()->create($caseA, (int) $this->marketing->id, $this->validPayload());
        $this->service()->create($caseB, (int) $this->marketing2->id, $this->validPayload());

        $response = $this->actingAs($this->marketing)
            ->get(route('shm.collection.show', $caseA->shm_loan_id));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Collection/Show')
            ->where('collection_case.id', $caseA->id)
            ->has('collection_case.activities', 1)
            ->where('collection_case.activities.0.id', $activityA->id));
    }

    public function test_detail_page_shows_can_record_false_for_non_owner(): void
    {
        $case = $this->caseOf($this->marketing);
        $this->service()->create($case, (int) $this->marketing->id, $this->validPayload());

        // admin memiliki shm.view_all sehingga BOLEH membuka detail — tetapi
        // flag pencatatan tetap false (bukan pemilik).
        $response = $this->actingAs($this->admin)
            ->get(route('shm.collection.show', $case->shm_loan_id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Collection/Show')
            ->where('collection_case.can_record', false)
            ->has('collection_case.activities', 1));
    }

    public function test_detail_page_without_active_case_has_null_case_payload(): void
    {
        $application = $this->applicationOwnedBy($this->marketing);
        $loan = $this->loanForApplication($application);

        $loan->installments()->create([
            'installment_number' => 1,
            'due_date' => today()->subDays(30)->toDateString(),
            'principal_amount' => '1000000',
            'interest_amount' => '200000',
            'total_amount' => '1200000',
            'paid_amount' => 0,
            'paid_interest_amount' => 0,
            'paid_principal_amount' => 0,
            'remaining_amount' => '1200000',
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        // Eligible Collection tetapi belum ada case (T93 tidak membuat case).
        $response = $this->actingAs($this->marketing)
            ->get(route('shm.collection.show', $loan->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Collection/Show')
            ->where('collection_case', null));
    }

    public function test_activity_history_order_is_deterministic(): void
    {
        $case = $this->caseOf($this->marketing);

        $first = $this->service()->create($case, (int) $this->marketing->id, $this->validPayload([
            'activity_at' => now()->subHours(3)->format('Y-m-d H:i:s'),
        ]));
        $second = $this->service()->create($case, (int) $this->marketing->id, $this->validPayload([
            'activity_at' => now()->subHours(1)->format('Y-m-d H:i:s'),
        ]));
        // activity_at kembar dengan $second — tie-breaker = id terbesar dulu.
        $third = $this->service()->create($case, (int) $this->marketing->id, $this->validPayload([
            'activity_at' => now()->subHours(1)->format('Y-m-d H:i:s'),
        ]));

        $response = $this->actingAs($this->marketing)
            ->get(route('shm.collection.show', $case->shm_loan_id));

        $response->assertInertia(function ($page) use ($first, $second, $third): void {
            $ids = array_column($page->toArray()['props']['collection_case']['activities'], 'id');

            $this->assertSame([$third->id, $second->id, $first->id], $ids);
        });
    }

    // ------------------------------------------------------------------
    // Integritas foreign key (butir 28)
    // ------------------------------------------------------------------

    public function test_foreign_key_integrity_is_enforced(): void
    {
        $case = $this->caseOf($this->marketing);
        $activity = $this->service()->create($case, (int) $this->marketing->id, $this->validPayload());

        // Case dengan riwayat aktivitas tidak dapat dihapus (RESTRICT).
        try {
            $case->delete();
            $this->fail('Penghapusan case berisi aktivitas harus ditolak FK RESTRICT.');
        } catch (QueryException) {
            $this->assertNotNull(ShmCollectionCase::query()->find($case->id));
        }

        // Case id tidak ada → insert ditolak database.
        try {
            ShmCollectionActivity::query()->create([
                'shm_collection_case_id' => 999999,
                'user_id' => $this->marketing->id,
                'activity_type' => ShmCollectionActivity::TYPE_PHONE_CALL,
                'result' => ShmCollectionActivity::RESULT_CONTACTED,
                'activity_at' => now(),
            ]);
            $this->fail('FK case tidak valid harus ditolak database.');
        } catch (QueryException) {
            $this->assertSame(1, ShmCollectionActivity::query()->count());
        }

        // User id tidak ada → insert ditolak database.
        try {
            ShmCollectionActivity::query()->create([
                'shm_collection_case_id' => $case->id,
                'user_id' => 999999,
                'activity_type' => ShmCollectionActivity::TYPE_PHONE_CALL,
                'result' => ShmCollectionActivity::RESULT_CONTACTED,
                'activity_at' => now(),
            ]);
            $this->fail('FK user tidak valid harus ditolak database.');
        } catch (QueryException) {
            $this->assertSame(1, ShmCollectionActivity::query()->count());
        }

        $this->assertNotNull($activity);
    }
}
