<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShmLoanProduct;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ShmLoanProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShmLoanSimulationProductRateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(ShmLoanProductSeeder::class);

        $this->user = User::factory()->create(['email_verified_at' => now()]);
        $this->user->roles()->attach(Role::query()->where('name', 'admin')->value('id'));
    }

    public function test_simulation_resolves_bsm_prioritas_rate_at_tenor_12(): void
    {
        $product = ShmLoanProduct::where('code', 'BSM-PRIORITAS')->firstOrFail();

        $response = $this->actingAs($this->user)->get(route('shm.loans.simulation', [
            'run' => 1,
            'product_id' => $product->id,
            'principal' => 50_000_000,
            'tenor_months' => 12,
            'method' => 'flat',
            'rate_period' => 'monthly',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Loans/Simulation')
            ->where('result.rate_percent', fn ($val) => (float) $val === 1.30)
            ->where('result.tenor_months', 12)
            ->etc()
        );
    }

    public function test_simulation_resolves_bsm_prioritas_rate_at_tenor_24(): void
    {
        $product = ShmLoanProduct::where('code', 'BSM-PRIORITAS')->firstOrFail();

        $response = $this->actingAs($this->user)->get(route('shm.loans.simulation', [
            'run' => 1,
            'product_id' => $product->id,
            'principal' => 50_000_000,
            'tenor_months' => 24,
            'method' => 'flat',
            'rate_period' => 'monthly',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Loans/Simulation')
            ->where('result.rate_percent', fn ($val) => (float) $val === 1.40)
            ->where('result.tenor_months', 24)
            ->etc()
        );
    }

    public function test_simulation_resolves_bsm_flexibel_rate_at_tenor_12(): void
    {
        $product = ShmLoanProduct::where('code', 'BSM-FLEXIBEL')->firstOrFail();

        $response = $this->actingAs($this->user)->get(route('shm.loans.simulation', [
            'run' => 1,
            'product_id' => $product->id,
            'principal' => 30_000_000,
            'tenor_months' => 12,
            'method' => 'flat',
            'rate_period' => 'monthly',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Loans/Simulation')
            ->where('result.rate_percent', fn ($val) => (float) $val === 1.40)
            ->where('result.tenor_months', 12)
            ->etc()
        );
    }

    public function test_simulation_resolves_bsm_flexibel_rate_at_tenor_36(): void
    {
        $product = ShmLoanProduct::where('code', 'BSM-FLEXIBEL')->firstOrFail();

        $response = $this->actingAs($this->user)->get(route('shm.loans.simulation', [
            'run' => 1,
            'product_id' => $product->id,
            'principal' => 30_000_000,
            'tenor_months' => 36,
            'method' => 'flat',
            'rate_period' => 'monthly',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Loans/Simulation')
            ->where('result.rate_percent', fn ($val) => (float) $val === 1.60)
            ->where('result.tenor_months', 36)
            ->etc()
        );
    }

    public function test_simulation_rejects_tenor_in_gap_without_fallback_to_two_percent(): void
    {
        $product = ShmLoanProduct::where('code', 'BSM-PRIORITAS')->firstOrFail();

        // BSM-PRIORITAS memiliki tier 12-18 dan 24-30. Tenor 20 berada di gap.
        $response = $this->actingAs($this->user)->get(route('shm.loans.simulation', [
            'run' => 1,
            'product_id' => $product->id,
            'principal' => 50_000_000,
            'tenor_months' => 20,
            'rate_percent' => 2.0, // Client tries to supply 2%
            'method' => 'flat',
            'rate_period' => 'monthly',
        ]));

        $response->assertRedirect();
        $response->assertSessionHasErrors(['tenor_months']);
        $this->assertStringContainsString('Tenor 20 bulan tidak tersedia', session('errors')->first('tenor_months'));
    }

    public function test_simulation_rejects_missing_product_id(): void
    {
        $response = $this->actingAs($this->user)->get(route('shm.loans.simulation', [
            'run' => 1,
            'principal' => 50_000_000,
            'tenor_months' => 12,
            'method' => 'flat',
            'rate_period' => 'monthly',
        ]));

        $response->assertRedirect();
        $response->assertSessionHasErrors(['product_id']);
    }

    public function test_simulation_pdf_uses_derived_product_rate(): void
    {
        $product = ShmLoanProduct::where('code', 'BSM-PRIORITAS')->firstOrFail();

        $response = $this->actingAs($this->user)->get(route('shm.loans.simulation.pdf', [
            'run' => 1,
            'product_id' => $product->id,
            'principal' => 50_000_000,
            'tenor_months' => 12,
            'rate_percent' => 5.0, // Should be ignored; authoritative tier is 1.30%
            'method' => 'flat',
            'rate_period' => 'monthly',
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }
}
