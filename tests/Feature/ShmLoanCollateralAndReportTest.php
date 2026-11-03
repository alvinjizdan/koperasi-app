<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanApplicationCollateral;
use App\Models\ShmLoanProduct;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShmLoanCollateralAndReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketing1;

    private User $marketing2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->createUserWithRole('admin');
        $this->marketing1 = $this->createUserWithRole('marketing');
        $this->marketing2 = $this->createUserWithRole('marketing');
    }

    private function createUserWithRole(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $roleRecord = Role::where('name', $role)->first();
        if ($roleRecord) {
            $user->roles()->attach($roleRecord->id);
        }

        return $user;
    }

    private function createLoanWithCollateral(User $marketing, string $loanNumber, string $customerName, string $collateralName): array
    {
        $customer = ShmCustomer::create([
            'customer_number' => 'CUST-'.uniqid(),
            'name' => $customerName,
            'nik' => '3271234567890001',
            'phone' => '08123456789',
            'is_active' => true,
        ]);

        $product = ShmLoanProduct::create([
            'code' => 'PROD-'.uniqid(),
            'name' => 'Pinjaman Multiguna SHM',
            'category' => ShmLoanProduct::CATEGORY_PERUMAHAN,
            'is_active' => true,
        ]);

        $application = ShmLoanApplication::create([
            'application_number' => 'APP-'.uniqid(),
            'shm_customer_id' => $customer->id,
            'shm_loan_product_id' => $product->id,
            'marketing_id' => $marketing->id,
            'application_date' => now()->toDateString(),
            'principal' => 100_000_000,
            'tenor_months' => 12,
            'purpose' => 'Renovasi Rumah',
            'status' => 'approved',
        ]);

        $collateral = ShmLoanApplicationCollateral::create([
            'shm_loan_application_id' => $application->id,
            'collateral_date' => now()->toDateString(),
            'collateral_type' => 'SHM',
            'collateral_name' => $collateralName,
            'estimated_value' => 250_000_000,
            'binding_status' => 'apht',
            'notes' => 'Sertifikat tanah dan bangunan',
        ]);

        $loan = ShmLoan::create([
            'loan_number' => $loanNumber,
            'shm_customer_id' => $customer->id,
            'shm_loan_product_id' => $product->id,
            'shm_loan_application_id' => $application->id,
            'principal' => 100_000_000,
            'tenor_months' => 12,
            'rate_percent' => 2.0,
            'status' => ShmLoan::STATUS_ACTIVE,
            'disbursed_at' => now()->toDateString(),
        ]);

        return compact('customer', 'product', 'application', 'collateral', 'loan');
    }

    public function test_collaterals_page_displays_customer_loan_collateral_relationship(): void
    {
        $data = $this->createLoanWithCollateral(
            $this->marketing1,
            'PJ-SHM-001',
            'Budi Santoso',
            'SHM No. 1234 Cirebon'
        );

        $response = $this->actingAs($this->admin)->get(route('shm.loans.collaterals.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Loans/Collaterals')
            ->has('collaterals.data', 1)
            ->where('collaterals.data.0.collateral_name', 'SHM No. 1234 Cirebon')
            ->where('collaterals.data.0.customer.name', 'Budi Santoso')
            ->where('collaterals.data.0.loan.loan_number', 'PJ-SHM-001')
        );
    }

    public function test_collaterals_search_filter(): void
    {
        $this->createLoanWithCollateral($this->marketing1, 'PJ-SHM-001', 'Budi Santoso', 'Sertifikat A');
        $this->createLoanWithCollateral($this->marketing1, 'PJ-SHM-002', 'Dewi Lestari', 'Sertifikat B');

        $response = $this->actingAs($this->admin)->get(route('shm.loans.collaterals.index', ['search' => 'Dewi']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->has('collaterals.data', 1)
            ->where('collaterals.data.0.customer.name', 'Dewi Lestari')
        );
    }

    public function test_loan_report_page_loads_and_shows_loan_data(): void
    {
        $data = $this->createLoanWithCollateral($this->marketing1, 'PJ-SHM-001', 'Budi Santoso', 'SHM Cirebon');
        $loan = $data['loan'];

        $response = $this->actingAs($this->admin)->get(route('shm.loans.report.index', ['loan_id' => $loan->id]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Loans/Report')
            ->where('selectedLoanId', $loan->id)
            ->where('reportData.identitas.nomor_pinjaman', 'PJ-SHM-001')
            ->where('reportData.nasabah.nama', 'Budi Santoso')
            ->has('reportData.jaminan', 1)
            ->where('reportData.jaminan.0.nama', 'SHM Cirebon')
        );
    }

    public function test_loan_report_pdf_download(): void
    {
        $data = $this->createLoanWithCollateral($this->marketing1, 'PJ-SHM-001', 'Budi Santoso', 'SHM Cirebon');
        $loan = $data['loan'];

        $response = $this->actingAs($this->admin)->get(route('shm.loans.report.pdf', $loan));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('laporan-pinjaman-PJ-SHM-001.pdf', $response->headers->get('content-disposition'));
    }

    public function test_marketing_cannot_access_other_marketing_loan_report_without_view_all(): void
    {
        $data = $this->createLoanWithCollateral($this->marketing1, 'PJ-SHM-001', 'Budi Santoso', 'SHM Cirebon');
        $loan = $data['loan'];

        $this->actingAs($this->marketing2)
            ->get(route('shm.loans.report.pdf', $loan))
            ->assertForbidden();
    }
}
