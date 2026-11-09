<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShmAgent;
use App\Models\ShmCustomer;
use App\Models\ShmLoanApplication;
use App\Models\User;
use App\Services\Shm\ShmAgentService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShmAgentMasterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketing;

    private User $koperasiUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id);
        }

        $this->marketing = User::factory()->create();
        $marketingRole = Role::where('name', 'marketing')->first();
        if ($marketingRole) {
            $this->marketing->roles()->attach($marketingRole->id);
        }

        $this->koperasiUser = User::factory()->create();
        $tellerRole = Role::where('name', 'teller')->first();
        if ($tellerRole) {
            $this->koperasiUser->roles()->attach($tellerRole->id);
        }
    }

    public function test_can_create_agent_with_generated_code(): void
    {
        $response = $this->actingAs($this->admin)->post(route('shm.agents.store'), [
            'name' => 'Budi Santoso',
            'nik' => '3201234567890001',
            'is_active' => true,
        ]);

        $response->assertRedirect(route('shm.agents.index'));
        $response->assertSessionHas('success');

        $agent = ShmAgent::where('nik', '3201234567890001')->first();
        $this->assertNotNull($agent);
        $this->assertSame('AGEN-001', $agent->code);
        $this->assertSame('Budi Santoso', $agent->name);
        $this->assertTrue($agent->is_active);
    }

    public function test_agent_code_increments_consecutively(): void
    {
        $agentService = app(ShmAgentService::class);

        $agent1 = $agentService->create([
            'name' => 'Agen Satu',
            'nik' => '3201234567890001',
        ]);
        $agent2 = $agentService->create([
            'name' => 'Agen Dua',
            'nik' => '3201234567890002',
        ]);
        $agent3 = $agentService->create([
            'name' => 'Agen Tiga',
            'nik' => '3201234567890003',
        ]);

        $this->assertSame('AGEN-001', $agent1->code);
        $this->assertSame('AGEN-002', $agent2->code);
        $this->assertSame('AGEN-003', $agent3->code);
    }

    public function test_nik_must_be_unique(): void
    {
        ShmAgent::create([
            'code' => 'AGEN-001',
            'name' => 'Agen Pertama',
            'nik' => '3201234567890001',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->post(route('shm.agents.store'), [
            'name' => 'Agen Kedua',
            'nik' => '3201234567890001',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('nik');
        $this->assertSame(1, ShmAgent::count());
    }

    public function test_update_agent_does_not_change_code(): void
    {
        $agent = ShmAgent::create([
            'code' => 'AGEN-001',
            'name' => 'Nama Awal',
            'nik' => '3201234567890001',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->put(route('shm.agents.update', $agent->id), [
            'name' => 'Nama Diubah',
            'nik' => '3201234567890002',
            'is_active' => false,
        ]);

        $response->assertRedirect(route('shm.agents.index'));

        $agent->refresh();
        $this->assertSame('AGEN-001', $agent->code, 'Kode agen tidak boleh berubah saat update.');
        $this->assertSame('Nama Diubah', $agent->name);
        $this->assertSame('3201234567890002', $agent->nik);
        $this->assertFalse($agent->is_active);
    }

    public function test_toggle_active_status(): void
    {
        $agent = ShmAgent::create([
            'code' => 'AGEN-001',
            'name' => 'Budi',
            'nik' => '3201234567890001',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->patch(route('shm.agents.toggle-active', $agent->id), [
            'is_active' => false,
        ])->assertSessionHas('success');

        $this->assertFalse($agent->fresh()->is_active);

        $this->actingAs($this->admin)->patch(route('shm.agents.toggle-active', $agent->id), [
            'is_active' => true,
        ])->assertSessionHas('success');

        $this->assertTrue($agent->fresh()->is_active);
        $this->assertSame('AGEN-001', $agent->fresh()->code);
    }

    public function test_active_agent_can_be_selected_in_loan_application(): void
    {
        $agent = ShmAgent::create([
            'code' => 'AGEN-001',
            'name' => 'Budi Agent',
            'nik' => '3201234567890001',
            'is_active' => true,
        ]);

        $customer = ShmCustomer::create([
            'customer_number' => '00001-ANG',
            'name' => 'Nasabah Satu',
            'nik' => '3201000000000001',
            'is_active' => true,
        ]);

        $payload = $this->validLoanPayload($customer->id, $agent->id);

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasNoErrors();

        $application = ShmLoanApplication::sole();
        $this->assertSame($agent->id, $application->agent_id);
        $this->assertSame('AGEN-001', $application->agent->code);
    }

    public function test_inactive_agent_is_rejected_for_new_loan_application(): void
    {
        $agent = ShmAgent::create([
            'code' => 'AGEN-001',
            'name' => 'Budi Agent',
            'nik' => '3201234567890001',
            'is_active' => false,
        ]);

        $customer = ShmCustomer::create([
            'customer_number' => '00001-ANG',
            'name' => 'Nasabah Satu',
            'nik' => '3201000000000001',
            'is_active' => true,
        ]);

        $payload = $this->validLoanPayload($customer->id, $agent->id);

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasErrors('pengajuan.agent_id');
        $this->assertSame(0, ShmLoanApplication::count());
    }

    public function test_invalid_agent_id_is_rejected(): void
    {
        $customer = ShmCustomer::create([
            'customer_number' => '00001-ANG',
            'name' => 'Nasabah Satu',
            'nik' => '3201000000000001',
            'is_active' => true,
        ]);

        $payload = $this->validLoanPayload($customer->id, 999999);

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasErrors('pengajuan.agent_id');
        $this->assertSame(0, ShmLoanApplication::count());
    }

    public function test_loan_application_without_agent_is_valid(): void
    {
        $customer = ShmCustomer::create([
            'customer_number' => '00001-ANG',
            'name' => 'Nasabah Satu',
            'nik' => '3201000000000001',
            'is_active' => true,
        ]);

        $payload = $this->validLoanPayload($customer->id, null);

        $response = $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);
        $response->assertSessionHasNoErrors();

        $application = ShmLoanApplication::sole();
        $this->assertNull($application->agent_id);
        $this->assertNull($application->agent);
    }

    public function test_existing_agent_assignment_is_preserved_after_agent_is_deactivated(): void
    {
        $agent = ShmAgent::create([
            'code' => 'AGEN-001',
            'name' => 'Budi Agent',
            'nik' => '3201234567890001',
            'is_active' => true,
        ]);

        $customer = ShmCustomer::create([
            'customer_number' => '00001-ANG',
            'name' => 'Nasabah Satu',
            'nik' => '3201000000000001',
            'is_active' => true,
        ]);

        $payload = $this->validLoanPayload($customer->id, $agent->id);
        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);

        $application = ShmLoanApplication::sole();
        $agent->update(['is_active' => false]);

        $editResponse = $this->actingAs($this->marketing)
            ->get(route('shm.loans.pengajuan.edit', $application));
        $editResponse->assertOk();
        $agents = $editResponse->viewData('page')['props']['agents'];
        $this->assertTrue(collect($agents)->contains('id', $agent->id));

        $updateResponse = $this->actingAs($this->marketing)
            ->put(route('shm.loans.pengajuan.update', $application), $payload);
        $updateResponse->assertSessionHasNoErrors();

        $this->assertSame($agent->id, $application->fresh()->agent_id);
    }

    public function test_historical_application_without_agent_can_be_viewed(): void
    {
        $customer = ShmCustomer::create([
            'customer_number' => '00001-ANG',
            'name' => 'Nasabah Satu',
            'nik' => '3201000000000001',
            'is_active' => true,
        ]);

        $application = ShmLoanApplication::create([
            'application_number' => 'SHM-APP-HISTORICAL',
            'shm_customer_id' => $customer->id,
            'application_date' => now()->format('Y-m-d'),
            'principal' => 50000000,
            'tenor_months' => 12,
            'status' => 'pending',
            'marketing_id' => $this->marketing->id,
            'agent_id' => null, // Historical record without agent
        ]);

        $response = $this->actingAs($this->marketing)->get(route('shm.loans.pengajuan.show', $application->id));
        $response->assertOk();
        $this->assertNull($application->agent);
    }

    public function test_detail_application_shows_assigned_agent_even_if_agent_becomes_inactive(): void
    {
        $agent = ShmAgent::create([
            'code' => 'AGEN-001',
            'name' => 'Budi Agent',
            'nik' => '3201234567890001',
            'is_active' => true,
        ]);

        $customer = ShmCustomer::create([
            'customer_number' => '00001-ANG',
            'name' => 'Nasabah Satu',
            'nik' => '3201000000000001',
            'is_active' => true,
        ]);

        $payload = $this->validLoanPayload($customer->id, $agent->id);
        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);

        $application = ShmLoanApplication::sole();

        // Agent dinonaktifkan kemudian
        $agent->update(['is_active' => false]);

        $response = $this->actingAs($this->marketing)->get(route('shm.loans.pengajuan.show', $application->id));
        $response->assertOk();

        $pageProps = $response->viewData('page')['props']['application'];
        $this->assertNotNull($pageProps['agent']);
        $this->assertSame('AGEN-001', $pageProps['agent']['code']);
        $this->assertSame('Budi Agent', $pageProps['agent']['name']);
        $this->assertFalse($pageProps['agent']['is_active']);
    }

    public function test_koperasi_only_user_cannot_access_shm_agents(): void
    {
        $response = $this->actingAs($this->koperasiUser)->get(route('shm.agents.index'));
        $response->assertForbidden();

        $createResponse = $this->actingAs($this->koperasiUser)->post(route('shm.agents.store'), [
            'name' => 'Agen Ilegal',
            'nik' => '3201234567890099',
        ]);
        $createResponse->assertForbidden();
    }

    public function test_agent_with_loans_cannot_be_deleted(): void
    {
        $agent = ShmAgent::create([
            'code' => 'AGEN-001',
            'name' => 'Budi Agent',
            'nik' => '3201234567890001',
            'is_active' => true,
        ]);

        $customer = ShmCustomer::create([
            'customer_number' => '00001-ANG',
            'name' => 'Nasabah Satu',
            'nik' => '3201000000000001',
            'is_active' => true,
        ]);

        $payload = $this->validLoanPayload($customer->id, $agent->id);
        $this->actingAs($this->marketing)->post(route('shm.loans.pengajuan.store'), $payload);

        $response = $this->actingAs($this->admin)->delete(route('shm.agents.destroy', $agent->id));
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('shm_agents', ['id' => $agent->id]);
    }

    private function validLoanPayload(int $customerId, ?int $agentId = null): array
    {
        return [
            'pribadi' => [
                'customer_id' => $customerId,
                'unit_kerja' => 'PNS',
                'nip' => '198001012005011001',
                'telepon_rumah' => '021-123456',
                'tempat_lahir' => 'Jakarta',
                'tanggal_lahir' => '1980-01-01',
                'jumlah_tanggungan' => 2,
                'saldo_modal' => '10000000',
                'saldo_non_modal' => '5000000',
                'batas_hutang' => '100000000',
            ],
            'pengajuan' => [
                'agent_id' => $agentId,
                'product_id' => null,
                'principal' => '50000000',
                'tenor_months' => 12,
                'first_due_date' => now()->addMonth()->format('Y-m-d'),
                'purpose' => 'Modal Usaha',
                'loan_sequence' => 1,
                'disbursement_method' => 'Tunai',
            ],
            'income' => [
                'tetap' => '5000000',
                'pasangan' => '2000000',
                'usaha' => '3000000',
            ],
            'expense' => [
                'potongan' => '500000',
                'rumahTangga' => '2000000',
                'pendidikan' => '1000000',
                'sewa' => '0',
                'listrik' => '300000',
                'transportasi' => '500000',
                'lain' => '200000',
            ],
            'tambahan' => [
                'koperasi_loan_balance' => '0',
                'other_facility' => 'Tidak Ada',
                'institution_name' => '',
            ],
            'agunan' => [],
            'jaminan' => [],
            'catatan' => [
                'date' => now()->format('Y-m-d'),
                'name' => 'Petugas',
                'notes' => 'Catatan',
            ],
            'analisa' => [
                'analyst_code' => 'A1',
                'analyst_office' => 'Pusat',
                'officer_code' => 'O1',
                'officer_office' => 'Pusat',
                'date' => now()->format('Y-m-d'),
                'value' => '50000000',
                'notes' => 'Layak',
            ],
            'kolektor' => [
                'name' => 'Kolektor 1',
            ],
            'persetujuan' => [
                'approved_amount' => '50000000',
                'provision_fee' => '500000',
                'administration_fee' => '250000',
                'notary_fee' => '100000',
                'method' => 'flat',
                'notes' => 'Disetujui',
            ],
            'persyaratan' => [
                ['text' => 'KTP', 'done' => true],
            ],
        ];
    }
}
