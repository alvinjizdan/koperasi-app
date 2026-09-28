<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanBalanceSnapshot;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\LoanService;
use App\Services\Snapshots\LoanBalanceSnapshotService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 26 — halaman laporan Posisi Pinjaman per Tanggal (Inertia).
 *
 * Fokus: otorisasi, filter, integritas historis (tanggal posisi vs waktu
 * snapshot, tidak ada fallback saldo hari ini), dan read-only finansial.
 */
class LoanPositionReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private LoanBalanceSnapshotService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Permission table harus terisi untuk pengujian otorisasi non-superadmin.
        $this->seed(PermissionSeeder::class);
        $this->user = User::factory()->superadmin()->create();
        $this->service = app(LoanBalanceSnapshotService::class);
    }

    private function activeLoan(float $amount = 2_000_000): Loan
    {
        $loan = Loan::factory()->approved()->create(['amount' => $amount, 'tenor_months' => 2]);
        app(LoanService::class)->disburse($loan, [
            'disbursement_date' => now()->subMonths(2)->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'TRANSFER',
        ], $this->user->id);

        return $loan->fresh();
    }

    private function route(array $query = []): string
    {
        return route('laporan.posisi-pinjaman').($query !== [] ? '?'.http_build_query($query) : '');
    }

    // =====================================================================
    // 1. Otorisasi
    // =====================================================================

    public function test_authorized_user_can_access_report(): void
    {
        $this->actingAs($this->user)->get($this->route())->assertOk();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $viewer = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'report_viewer_'.uniqid(),
            'display_name' => 'Report Viewer',
            'is_system' => false,
        ]);
        // Tidak ada permission apa pun — hanya role kosong.
        $viewer->roles()->attach($role->id);

        $this->actingAs($viewer)->get($this->route())->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get($this->route())->assertRedirect();
    }

    // =====================================================================
    // 2. Data dari snapshot — tanpa fallback saldo hari ini
    // =====================================================================

    public function test_report_shows_snapshot_exactly_on_requested_date(): void
    {
        $loan = $this->activeLoan();
        $today = now()->format('Y-m-d');
        $this->service->capture($loan, $today, $this->user->id);

        $response = $this->actingAs($this->user)->get($this->route(['as_of_date' => $today]));
        $response->assertOk();

        $snapshot = LoanBalanceSnapshot::where('loan_id', $loan->id)->first();
        $response->assertInertia(fn ($page) => $page
            ->component('Laporan/PosisiPinjaman')
            ->has('rows.data', 1)
            ->where('rows.data.0.total_owed', (int) $snapshot->total_owed)
            ->where('rows.data.0.as_of_date', $today)
            ->where('rows.data.0.loan.loan_number', $loan->loan_number)
            ->where('rows.data.0.loan.member.full_name', $loan->member->full_name)
            ->where('availability.snapshots_on_date', 1));
    }

    public function test_report_does_not_fall_back_to_current_balance_when_snapshot_missing(): void
    {
        $loan = $this->activeLoan(); // pinjaman aktif dengan saldo nyata, TANPA snapshot

        $response = $this->actingAs($this->user)
            ->get($this->route(['as_of_date' => now()->format('Y-m-d')]))
            ->assertOk();

        // Tidak ada baris snapshot — saldo hari ini TIDAK ditampilkan sebagai ganti.
        $response->assertInertia(fn ($page) => $page
            ->where('rows.data', [])
            ->where('summary.total_owed', 0)
            ->where('availability.snapshots_on_date', 0)
            ->etc());
    }

    public function test_nearest_snapshot_is_not_disguised_as_requested_date(): void
    {
        $loan = $this->activeLoan();
        $this->service->capture($loan, now()->subDays(5)->format('Y-m-d'), $this->user->id);

        $requestedDate = now()->format('Y-m-d');

        $this->actingAs($this->user)
            ->get($this->route(['as_of_date' => $requestedDate]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rows.data', []) // tidak ada baris nilai untuk tanggal diminta
                ->where('availability.snapshots_on_date', 0)
                ->where('availability.nearest_as_of_date', now()->subDays(5)->format('Y-m-d')));
    }

    public function test_backdated_snapshot_is_marked_not_verified(): void
    {
        $loan = $this->activeLoan();
        $past = now()->subDays(10)->format('Y-m-d');
        $this->service->capture($loan, $past, $this->user->id);

        $this->actingAs($this->user)
            ->get($this->route(['as_of_date' => $past]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rows.data.0.as_of_date', $past)
                // Snapshot dibuat hari ini untuk tanggal lampau → bukan histori terverifikasi.
                ->where('rows.data.0.captured_after_as_of', true)
                ->where('rows.data.0.verified_historical', false));
    }

    public function test_filters_search_and_date_work(): void
    {
        $loanA = $this->activeLoan();
        $loanB = $this->activeLoan(amount: 3_000_000);
        $today = now()->format('Y-m-d');
        $this->service->capture($loanA, $today, $this->user->id);
        $this->service->capture($loanB, $today, $this->user->id);

        // Filter pencarian nomor pinjaman.
        $this->actingAs($this->user)
            ->get($this->route(['search' => $loanA->loan_number, 'as_of_date' => $today]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows.data', 1));

        // Filter tanggal berbeda → kosong.
        $this->actingAs($this->user)
            ->get($this->route(['as_of_date' => now()->subYear()->format('Y-m-d')]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows.data', 0));
    }

    public function test_invalid_date_filter_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->get($this->route(['as_of_date' => 'bukan-tanggal']))
            ->assertSessionHasErrors();
    }

    // =====================================================================
    // 3. Read-only finansial
    // =====================================================================

    public function test_opening_report_does_not_mutate_anything(): void
    {
        config(['business-rules.penalty.method' => 'per_day', 'business-rules.penalty.amount_per_day' => 1_000]);

        $loan = $this->activeLoan();
        $today = now()->format('Y-m-d');
        $this->service->capture($loan, $today, $this->user->id);

        $schedulesBefore = $loan->schedules()->get()->map(fn ($s) => $s->only(['id', 'penalty_accrued', 'paid_amount', 'status']))->all();
        $snapshotsBefore = LoanBalanceSnapshot::orderBy('id')->get()->map(fn ($s) => $s->only(['id', 'total_owed', 'as_of_date']))->all();
        $journalsBefore = JournalEntry::count();
        $cashBefore = CashTransaction::count();
        $auditBefore = AuditLog::where('action', 'like', 'loan.penalty.%')->count();

        // Buka laporan berulang (refresh/navigasi).
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->user)->get($this->route(['as_of_date' => $today]))->assertOk();
        }

        $this->assertSame($schedulesBefore, $loan->schedules()->get()->map(fn ($s) => $s->only(['id', 'penalty_accrued', 'paid_amount', 'status']))->all());
        $this->assertSame($snapshotsBefore, LoanBalanceSnapshot::orderBy('id')->get()->map(fn ($s) => $s->only(['id', 'total_owed', 'as_of_date']))->all());
        $this->assertSame($journalsBefore, JournalEntry::count());
        $this->assertSame($cashBefore, CashTransaction::count());
        $this->assertSame($auditBefore, AuditLog::where('action', 'like', 'loan.penalty.%')->count());
    }

    public function test_report_summary_only_sums_same_date_snapshots(): void
    {
        $loan = $this->activeLoan();
        $today = now()->format('Y-m-d');
        $this->service->capture($loan, $today, $this->user->id);
        $this->service->capture($loan, now()->subDays(3)->format('Y-m-d'), $this->user->id);

        $this->actingAs($this->user)
            ->get($this->route(['as_of_date' => $today]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.snapshots_count', 1) // hanya snapshot tanggal yang sama
                ->where('summary.total_owed', (int) LoanBalanceSnapshot::where('as_of_date', $today)->value('total_owed'))
                ->where('rows.data.0.verified_historical', true));
    }

    // =====================================================================
    // 4. Ekspor CSV (Ticket 27)
    // =====================================================================

    private function exportUrl(array $query = []): string
    {
        return route('laporan.posisi-pinjaman.export').($query !== [] ? '?'.http_build_query($query) : '');
    }

    public function test_export_requires_export_permission(): void
    {
        $role = Role::query()->create([
            'name' => 'posisi_viewer_'.uniqid(),
            'display_name' => 'Posisi Viewer',
            'is_system' => false,
        ]);
        // Viewer: hanya laporan.view — TIDAK otomatis mendapat hak ekspor.
        $role->permissions()->attach(
            Permission::query()->where('name', 'laporan.view')->pluck('id')
        );
        $viewer = User::factory()->create();
        $viewer->roles()->attach($role->id);

        $this->actingAs($viewer)->get($this->exportUrl())->assertForbidden();

        // User dengan permission ekspor → sukses.
        $exporter = User::factory()->create();
        $exportRole = Role::query()->create([
            'name' => 'posisi_exporter_'.uniqid(),
            'display_name' => 'Posisi Exporter',
            'is_system' => false,
        ]);
        $exportRole->permissions()->attach(
            Permission::query()->whereIn('name', ['laporan.view', 'laporan.export'])->pluck('id')
        );
        $exporter->roles()->attach($exportRole->id);

        $this->actingAs($exporter)->get($this->exportUrl())->assertOk();
    }

    public function test_export_guest_is_redirected(): void
    {
        $this->get($this->exportUrl())->assertRedirect();
    }

    public function test_export_contains_snapshot_rows_with_headers(): void
    {
        $loan = $this->activeLoan();
        $today = now()->format('Y-m-d');
        $this->service->capture($loan, $today, $this->user->id);

        $response = $this->actingAs($this->user)->get($this->exportUrl(['as_of_date' => $today]));
        $response->assertOk();

        $content = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", $content), fn ($l) => trim($l) !== ''));

        // BOM + header + 1 baris data.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Nomor Pinjaman', $lines[0]);
        $this->assertStringContainsString('Total Kewajiban', $lines[0]);
        $this->assertStringContainsString('Status Histori Terverifikasi', $lines[0]);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString($loan->loan_number, $lines[1]);
        $this->assertStringContainsString($loan->member->full_name, $lines[1]);
        $this->assertStringContainsString('Terverifikasi', $lines[1]);
    }

    public function test_export_content_type_and_filename(): void
    {
        $today = now()->format('Y-m-d');

        $response = $this->actingAs($this->user)->get($this->exportUrl(['as_of_date' => $today]));

        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('posisi-pinjaman-'.$today.'.csv', $response->headers->get('Content-Disposition'));
    }

    public function test_export_without_snapshots_has_headers_only_and_no_fallback(): void
    {
        $this->activeLoan(); // saldo nyata, TANPA snapshot

        $response = $this->actingAs($this->user)
            ->get($this->exportUrl(['as_of_date' => now()->format('Y-m-d')]));

        $content = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", $content), fn ($l) => trim($l) !== ''));

        // Hanya header — tidak ada baris saldo fiktif/nol dari saldo hari ini.
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('Nomor Pinjaman', $lines[0]);
        $this->assertStringNotContainsString('2000000', $content);
    }

    public function test_export_filters_match_ui_dataset(): void
    {
        $loanA = $this->activeLoan();
        $loanB = $this->activeLoan(amount: 3_000_000);
        $today = now()->format('Y-m-d');
        $this->service->capture($loanA, $today, $this->user->id);
        $this->service->capture($loanB, $today, $this->user->id);
        $this->service->capture($loanA, now()->subDays(2)->format('Y-m-d'), $this->user->id);

        // Filter pencarian: hanya pinjaman A pada tanggal yang diminta.
        $content = $this->actingAs($this->user)
            ->get($this->exportUrl(['as_of_date' => $today, 'search' => $loanA->loan_number]))
            ->streamedContent();

        $dataLines = array_slice(array_values(array_filter(explode("\n", $content), fn ($l) => trim($l) !== '')), 1);
        $this->assertCount(1, $dataLines); // tidak campur tanggal, tidak duplikat
        $this->assertStringContainsString($loanA->loan_number, $dataLines[0]);
        $this->assertStringNotContainsString($loanB->loan_number, $content);
    }

    public function test_export_backdated_snapshot_keeps_unverified_status(): void
    {
        $loan = $this->activeLoan();
        $past = now()->subDays(10)->format('Y-m-d');
        $this->service->capture($loan, $past, $this->user->id);

        $content = $this->actingAs($this->user)
            ->get($this->exportUrl(['as_of_date' => $past]))
            ->streamedContent();

        $this->assertStringContainsString('Tidak Terverifikasi', $content);
        $this->assertStringNotContainsString('Terverifikasi,', $content);
    }

    public function test_export_sanitizes_formula_injection_in_member_name(): void
    {
        $loan = $this->activeLoan();
        $loan->member->update(['full_name' => '=HYPERLINK("http://evil","klik")']);
        $today = now()->format('Y-m-d');
        $this->service->capture($loan, $today, $this->user->id);

        $content = $this->actingAs($this->user)
            ->get($this->exportUrl(['as_of_date' => $today]))
            ->streamedContent();

        // Nama diawali '=' → diberi awalan kutip tunggal oleh exporter.
        $this->assertStringContainsString("'=HYPERLINK", $content);
    }

    public function test_export_does_not_mutate_anything(): void
    {
        config(['business-rules.penalty.method' => 'per_day', 'business-rules.penalty.amount_per_day' => 1_000]);

        $loan = $this->activeLoan();
        $today = now()->format('Y-m-d');
        $this->service->capture($loan, $today, $this->user->id);

        $schedulesBefore = $loan->schedules()->get()->map(fn ($s) => $s->only(['id', 'penalty_accrued', 'paid_amount', 'status']))->all();
        $snapshotsBefore = LoanBalanceSnapshot::orderBy('id')->get()->map(fn ($s) => $s->only(['id', 'total_owed', 'as_of_date']))->all();
        $journalsBefore = JournalEntry::count();
        $cashBefore = CashTransaction::count();

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->user)->get($this->exportUrl(['as_of_date' => $today]))->assertOk();
        }

        $this->assertSame($schedulesBefore, $loan->schedules()->get()->map(fn ($s) => $s->only(['id', 'penalty_accrued', 'paid_amount', 'status']))->all());
        $this->assertSame($snapshotsBefore, LoanBalanceSnapshot::orderBy('id')->get()->map(fn ($s) => $s->only(['id', 'total_owed', 'as_of_date']))->all());
        $this->assertSame($journalsBefore, JournalEntry::count());
        $this->assertSame($cashBefore, CashTransaction::count());
    }
}
