<?php

namespace Database\Seeders;

use App\Models\CashierSession;
use App\Models\CashTransaction;
use App\Models\ChartOfAccount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmAccountingMapping;
use App\Models\ShmApplication;
use App\Models\ShmApproval;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Models\ShmLoanSettlement;
use App\Models\ShmProductAccounting;
use App\Models\ShmProperty;
use App\Models\User;
use App\Services\Shm\ShmDisbursementCostService;
use App\Services\Shm\ShmProductRateService;
use App\Services\Shm\ShmRepaymentScheduleService;
use App\Support\AccountingContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Local-only development dataset.
 *
 * This seeder intentionally does not call any legacy Demo seeder. It writes
 * master data and operational fixtures directly, so no automatic journal,
 * POSTED accounting record, disbursement workflow, or payment workflow is
 * triggered while seeding.
 *
 * Safety rules:
 * - Refuses to run outside APP_ENV=local.
 * - Uses firstOrCreate for fixture records and updateOrCreate for master data.
 * - Never truncates, deletes, or resets existing records.
 * - Does not create a Superadmin; that remains the responsibility of the
 *   production/default DatabaseSeeder or a separate approved process.
 * - Does not create refund records: a refund requires a completed settlement
 *   and overpayment lifecycle, which would be artificial in a development seed.
 */
class LocalDevelopmentSeeder extends Seeder
{
    private const LOCAL_PASSWORD = 'password';

    /**
     * Makro role matrix mirrors the current physical Makro role seeder, without
     * assigning hard-coded user IDs 10, 11, or 12. The local seeder owns the
     * users it creates and never mutates pre-existing users.
     *
     * @var array<string, array{display_name: string, description: string, permissions: array<int, string>|null}>
     */
    private const MAKRO_ROLES = [
        'admin_makro' => [
            'display_name' => 'Admin Makro',
            'description' => 'Pengelola sistem operasional dan administrasi BSM Makro (SHM).',
            'permissions' => null,
        ],
        'marketing_makro' => [
            'display_name' => 'Marketing Makro',
            'description' => 'Aktivitas pemasaran, akuisisi nasabah, credit checking, dan pengajuan SHM.',
            'permissions' => [
                'marketing.view',
                'marketing.create',
                'marketing.result.update',
                'shm.view',
                'shm.create',
                'shm.update',
                'shm.submit',
                'shm.property.manage',
                'shm.credit_checking.view',
                'shm.credit_checking.update',
                'shm.memorandum.update',
                'shm.memorandum.submit',
                'shm.document.update',
                'shm.customer.view',
                'shm.customer.create',
                'shm.customer.update',
                'shm.agent.view',
                'shm.collection.view',
                'shm.collection.transition',
                'shm.collection.activity.create',
                'shm.collection.ptp.create',
                'shm.collection.ptp.transition',
            ],
        ],
        'branch_manager_makro' => [
            'display_name' => 'Branch Manager Makro',
            'description' => 'Pimpinan cabang BSM Makro: pengawasan portofolio SHM, review memorandum, dan monitoring collection.',
            'permissions' => [
                'shm.view',
                'shm.view_all',
                'shm.collection.view',
                'shm.customer.view',
                'shm.agent.view',
                'shm.credit_checking.view',
                'shm.memorandum.update',
                'shm.memorandum.submit',
                'shm.memorandum.review',
                'shm.memorandum.history.view',
                'shm.approval.bm',
                'shm.savings.view',
                'shm.export',
            ],
        ],
        'direktur_utama_makro' => [
            'display_name' => 'President Director (Direktur Utama Makro)',
            'description' => 'Otoritas approval tertinggi pembiayaan dan memorandum BSM Makro (SHM).',
            'permissions' => [
                'shm.view',
                'shm.view_all',
                'shm.approval.decide',
                'approval.decide',
                'shm.memorandum.approve',
                'shm.memorandum.reject',
                'shm.memorandum.history.view',
                'shm.customer.view',
                'shm.collection.view',
                'accounting.shm.reports.view',
                'shm.refunds.view',
                'shm.refunds.approve',
                'shm.refunds.reject',
            ],
        ],
        'finance_operational_makro' => [
            'display_name' => 'Finance Operational Makro',
            'description' => 'Pencatatan dan pengelolaan operasional akuntansi SHM (COA, Mapping, Jurnal, dan Laporan).',
            'permissions' => [
                'accounting.shm.view',
                'accounting.shm.accounts.manage',
                'accounting.shm.mappings.view',
                'accounting.shm.mappings.manage',
                'accounting.shm.period.close',
                'accounting.shm.journals.view',
                'accounting.shm.journals.create',
                'accounting.shm.journals.post',
                'accounting.shm.journals.reverse',
                'accounting.shm.ledger.view',
                'accounting.shm.reports.view',
                'shm.view',
                'shm.view_all',
                'shm.disburse',
                'shm.payment.create',
                'shm.settlement.create',
                'shm.refunds.view',
                'shm.refunds.create',
                'shm.refunds.cancel',
                'shm.refunds.execute',
                'shm.refunds.reverse',
                'shm.refunds.confirm_recovery',
            ],
        ],
    ];

    public function run(): void
    {
        $this->validateEnvironmentAndTarget();

        $this->preflightCollisionCheck();

        DB::transaction(function (): void {
            $this->call([
                PermissionSeeder::class,
                RoleSeeder::class,
                SettingsSeeder::class,
                ShmLoanProductSeeder::class,
                ShmSavingProductSeeder::class,
                // Seed Koperasi CoA before SHM CoA so both contexts have
                // deterministic, independent rows.
                ChartOfAccountSeeder::class,
                ShmChartOfAccountSeeder::class,
            ]);

            $this->seedMakroRoles();

            $users = $this->seedLocalUsers();

            $this->seedKoperasi($users);
            $this->seedShm($users);
            $this->seedShmAccounting($users);
        });
    }

    /**
     * Target database expectations (default values for production/staging/local safety).
     */
    protected string $expectedDriver = 'pgsql';

    protected string $expectedDatabase = 'ksp_bima_sakti';

    protected ?string $expectedHost = '127.0.0.1';

    protected ?string $expectedPort = '5432';

    /**
     * Set target database expectations for isolated testing environments.
     * Guard: Strictly prohibited when running outside unit/feature automated tests.
     */
    public function setExpectedTarget(string $driver, string $database, ?string $host, ?string $port): void
    {
        if (! defined('PHPUNIT_COMPOSER_INSTALL') && ! app()->environment('testing')) {
            throw new RuntimeException('setExpectedTarget() hanya diizinkan untuk eksekusi automated test.');
        }

        $this->expectedDriver = $driver;
        $this->expectedDatabase = $database;
        $this->expectedHost = $host;
        $this->expectedPort = $port;
    }

    /**
     * Task A — Database Target Guard:
     * Validates both app environment and active database connection configuration
     * before any seeder logic executes.
     */
    public function validateEnvironmentAndTarget(): void
    {
        // 1. Environment Guard
        if (! app()->environment('local')) {
            throw new RuntimeException(sprintf(
                'LocalDevelopmentSeeder hanya boleh dijalankan pada environment local. Environment saat ini: %s.',
                app()->environment(),
            ));
        }

        // 2. Active Connection Target Guard
        $defaultConnection = config('database.default');
        $connectionConfig = config("database.connections.{$defaultConnection}", []);

        $driver = $connectionConfig['driver'] ?? null;
        $database = $connectionConfig['database'] ?? null;
        $host = $connectionConfig['host'] ?? null;
        $port = (string) ($connectionConfig['port'] ?? '');

        if ($driver !== $this->expectedDriver) {
            throw new RuntimeException(sprintf(
                'Target database driver tidak valid untuk LocalDevelopmentSeeder. Diperlukan: "%s", terdeteksi: "%s".',
                $this->expectedDriver,
                $driver ?? 'null'
            ));
        }

        if ($database !== $this->expectedDatabase) {
            throw new RuntimeException(sprintf(
                'Target database name tidak valid untuk LocalDevelopmentSeeder. Diperlukan: "%s", terdeteksi: "%s".',
                $this->expectedDatabase,
                $database ?? 'null'
            ));
        }

        if ($host !== $this->expectedHost) {
            throw new RuntimeException(sprintf(
                'Target database host tidak valid untuk LocalDevelopmentSeeder. Diperlukan: "%s", terdeteksi: "%s".',
                $this->expectedHost ?? 'null',
                $host ?? 'null'
            ));
        }

        if ($port !== $this->expectedPort) {
            throw new RuntimeException(sprintf(
                'Target database port tidak valid untuk LocalDevelopmentSeeder. Diperlukan: "%s", terdeteksi: "%s".',
                $this->expectedPort ?? 'null',
                $port !== '' ? $port : 'null'
            ));
        }
    }

    /**
     * Task B — Identifier Collision Preflight:
     * Inspects all deterministic identifiers before performing any database writes.
     * Aborts with RuntimeException listing all detected conflicts if unowned records exist.
     */
    protected function preflightCollisionCheck(): void
    {
        $conflicts = [];

        // 1. Users Check
        $localUserEmails = [
            'admin.local@ksp.local' => 'Local Development Admin',
            'marketing.local@ksp.local' => 'Local Development Marketing',
            'bm.local@ksp.local' => 'Local Development Branch Manager',
            'direktur.local@ksp.local' => 'Local Development President Director',
            'finance.local@ksp.local' => 'Local Development Finance Operational',
        ];

        foreach ($localUserEmails as $email => $expectedName) {
            $existing = User::query()->where('email', $email)->first();
            if ($existing !== null && $existing->name !== $expectedName) {
                $conflicts[] = sprintf(
                    'Table [users]: email "%s" sudah ada dengan nama "%s" (ekspektasi fixture: "%s").',
                    $email,
                    $existing->name,
                    $expectedName
                );
            }
        }

        // 2. Koperasi Members Check (if table exists)
        if (Schema::hasTable('members')) {
            $localMemberNumbers = [
                '90001-ANG' => 'Local Member Satu',
                '90002-ANG' => 'Local Member Dua',
                '90003-ANG' => 'Local Member Tiga',
            ];

            foreach ($localMemberNumbers as $num => $expectedName) {
                $existing = DB::table('members')->where('member_number', $num)->first();
                if ($existing !== null && $existing->full_name !== $expectedName) {
                    $conflicts[] = sprintf(
                        'Table [members]: member_number "%s" sudah ada dengan nama "%s" (ekspektasi fixture: "%s").',
                        $num,
                        $existing->full_name,
                        $expectedName
                    );
                }
            }
        }

        // 3. SHM Customers Check
        $localCustomerNumbers = [
            '90001-ANG' => 'Local SHM Customer Satu',
            '90002-ANG' => 'Local SHM Customer Dua',
            '90003-ANG' => 'Local SHM Customer Tiga',
        ];

        foreach ($localCustomerNumbers as $num => $expectedName) {
            $existing = ShmCustomer::query()->where('customer_number', $num)->first();
            if ($existing !== null && $existing->name !== $expectedName) {
                $conflicts[] = sprintf(
                    'Table [shm_customers]: customer_number "%s" sudah ada dengan nama "%s" (ekspektasi fixture: "%s").',
                    $num,
                    $existing->name,
                    $expectedName
                );
            }
        }

        // 4. Koperasi Loan Check (if table exists)
        if (Schema::hasTable('loans')) {
            $existingLoan = DB::table('loans')->where('loan_number', 'DEV-PINJ-90001-001')->first();
            if ($existingLoan !== null && ! str_contains((string) $existingLoan->notes, 'Local development fixture')) {
                $conflicts[] = sprintf(
                    'Table [loans]: loan_number "DEV-PINJ-90001-001" sudah ada dengan notes berbeda (bukan fixture seeder).'
                );
            }
        }

        // 5. Koperasi Loan Disbursement Check (if table exists)
        if (Schema::hasTable('loan_disbursements')) {
            $existingDisb = DB::table('loan_disbursements')->where('disbursement_number', 'DEV-CAIR-90001-001')->first();
            if ($existingDisb !== null && ! str_contains((string) $existingDisb->notes, 'Local development fixture')) {
                $conflicts[] = sprintf(
                    'Table [loan_disbursements]: disbursement_number "DEV-CAIR-90001-001" sudah ada dengan notes berbeda.'
                );
            }
        }

        // 6. Cashier Session & Cash Transaction Check
        $existingSession = CashierSession::query()->where('session_number', 'DEV-SESI-KASIR-001')->first();
        if ($existingSession !== null && ! str_contains((string) $existingSession->opening_notes, 'Local development cashier fixture')) {
            $conflicts[] = sprintf(
                'Table [cashier_sessions]: session_number "DEV-SESI-KASIR-001" sudah ada dengan notes berbeda.'
            );
        }

        $existingTx = CashTransaction::query()->where('idempotency_key', 'local-development-cashier-opening')->first();
        if ($existingTx !== null && $existingTx->transaction_number !== 'DEV-KMASUK-KASIR-001') {
            $conflicts[] = sprintf(
                'Table [cash_transactions]: idempotency_key "local-development-cashier-opening" sudah terikat ke "%s" (ekspektasi "DEV-KMASUK-KASIR-001").',
                $existingTx->transaction_number
            );
        }

        // 7. SHM Applications Check
        $localShmApps = [
            'DEV-SHM-APP-001',
            'DEV-SHM-APP-002',
            'DEV-SHM-APP-003',
        ];
        foreach ($localShmApps as $appNum) {
            $existing = ShmApplication::query()->where('application_number', $appNum)->first();
            if ($existing !== null && ! str_contains((string) $existing->notes, 'Local development fixture')) {
                $conflicts[] = sprintf(
                    'Table [shm_applications]: application_number "%s" sudah ada dengan notes berbeda.',
                    $appNum
                );
            }
        }

        // 8. SHM Loan Applications Check
        $localShmLoanApps = [
            'DEV-SHM-PENGAJ-001',
            'DEV-SHM-PENGAJ-002',
            'DEV-SHM-PENGAJ-003',
        ];
        foreach ($localShmLoanApps as $pengajNum) {
            $existing = ShmLoanApplication::query()->where('application_number', $pengajNum)->first();
            if ($existing !== null && ! str_contains((string) $existing->purpose, 'Local development SHM loan application')) {
                $conflicts[] = sprintf(
                    'Table [shm_loan_applications]: application_number "%s" sudah ada dengan purpose berbeda.',
                    $pengajNum
                );
            }
        }

        // 9. SHM Loan Check
        $existingShmLoan = ShmLoan::query()->where('loan_number', 'DEV-SHM-LOAN-001')->first();
        if ($existingShmLoan !== null && ! str_contains((string) $existingShmLoan->note, 'Local development fixture')) {
            $conflicts[] = sprintf(
                'Table [shm_loans]: loan_number "DEV-SHM-LOAN-001" sudah ada dengan note berbeda.'
            );
        }

        // 10. SHM Settlement Check
        $existingSettlement = ShmLoanSettlement::query()->where('settlement_reference', 'DEV-SHM-SET-001')->first();
        if ($existingSettlement !== null && ! str_contains((string) $existingSettlement->notes, 'Local development pending settlement fixture')) {
            $conflicts[] = sprintf(
                'Table [shm_loan_settlements]: settlement_reference "DEV-SHM-SET-001" sudah ada dengan notes berbeda.'
            );
        }

        if (! empty($conflicts)) {
            throw new RuntimeException(
                "Identifier collision preflight mendeteksi konflik kepemilikan data fixture:\n- "
                .implode("\n- ", $conflicts)
            );
        }
    }

    /**
     * @return array<string, User>
     */
    private function seedLocalUsers(): array
    {
        $definitions = [
            'admin' => [
                'name' => 'Local Development Admin',
                'email' => 'admin.local@ksp.local',
                'roles' => ['admin', 'admin_makro'],
            ],
            'marketing' => [
                'name' => 'Local Development Marketing',
                'email' => 'marketing.local@ksp.local',
                'roles' => ['marketing_makro'],
            ],
            'bm' => [
                'name' => 'Local Development Branch Manager',
                'email' => 'bm.local@ksp.local',
                'roles' => ['branch_manager_makro'],
            ],
            'director' => [
                'name' => 'Local Development President Director',
                'email' => 'direktur.local@ksp.local',
                'roles' => ['direktur_utama_makro'],
            ],
            'finance' => [
                'name' => 'Local Development Finance Operational',
                'email' => 'finance.local@ksp.local',
                'roles' => ['finance_operational_makro'],
            ],
        ];

        $users = [];

        foreach ($definitions as $key => $definition) {
            $user = User::query()->firstOrCreate(
                ['email' => $definition['email']],
                [
                    'name' => $definition['name'],
                    'password' => self::LOCAL_PASSWORD,
                    'is_active' => true,
                ],
            );

            $roleIds = Role::query()
                ->whereIn('name', $definition['roles'])
                ->where('is_active', true)
                ->pluck('id')
                ->all();

            $user->roles()->syncWithoutDetaching($roleIds);

            $users[$key] = $user;
        }

        return $users;
    }

    private function seedMakroRoles(): void
    {
        $adminRole = Role::query()->updateOrCreate(
            ['name' => 'admin_makro', 'entity' => 'makro'],
            [
                'display_name' => self::MAKRO_ROLES['admin_makro']['display_name'],
                'description' => self::MAKRO_ROLES['admin_makro']['description'],
                'is_system' => true,
                'is_active' => true,
            ],
        );

        $adminPermissions = Permission::query()
            ->where('entity', 'makro')
            ->whereNotIn('name', [
                'shm.approval.decide',
                'approval.decide',
                'shm.memorandum.approve',
                'shm.memorandum.reject',
            ])
            ->pluck('id')
            ->all();

        $adminRole->permissions()->sync($adminPermissions);

        foreach (self::MAKRO_ROLES as $name => $definition) {
            if ($name === 'admin_makro') {
                continue;
            }

            $role = Role::query()->updateOrCreate(
                ['name' => $name, 'entity' => 'makro'],
                [
                    'display_name' => $definition['display_name'],
                    'description' => $definition['description'],
                    'is_system' => true,
                    'is_active' => true,
                ],
            );

            $permissionIds = Permission::query()
                ->where('entity', 'makro')
                ->whereIn('name', $definition['permissions'] ?? [])
                ->pluck('id')
                ->all();

            $role->permissions()->sync($permissionIds);
        }
    }

    /**
     * @param  array<string, User>  $users
     */
    private function seedKoperasi(array $users): void
    {
        $admin = $users['admin'];

        if (Schema::hasTable('saving_products') && Schema::hasTable('members') && Schema::hasTable('savings_accounts')) {
            $savingProducts = [
                'principal' => [
                    'code' => 'SP-POKOK',
                    'name' => 'Simpanan Pokok',
                    'description' => 'Simpanan Pokok Anggota KSP Bima Sakti Mandiri',
                ],
                'mandatory' => [
                    'code' => 'SW-WAJIB',
                    'name' => 'Simpanan Wajib',
                    'description' => 'Simpanan Wajib Anggota Bulanan',
                ],
                'voluntary' => [
                    'code' => 'SS-SUKARELA',
                    'name' => 'Simpanan Sukarela',
                    'description' => 'Simpanan Sukarela Fleksibel',
                ],
            ];

            $products = [];

            foreach ($savingProducts as $category => $definition) {
                $product = DB::table('saving_products')
                    ->where('code', $definition['code'])
                    ->orWhere('category', $category)
                    ->first();

                if ($product === null) {
                    $id = DB::table('saving_products')->insertGetId([
                        'code' => $definition['code'],
                        'name' => $definition['name'],
                        'category' => $category,
                        'description' => $definition['description'],
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $product = (object) ['id' => $id];
                }

                $products[$category] = $product;
            }

            $memberDefinitions = [
                ['member_number' => '90001-ANG', 'full_name' => 'Local Member Satu', 'identity_number' => '3271990000000001', 'phone' => '081299000001', 'address' => 'Jl. Development No. 1, Bogor'],
                ['member_number' => '90002-ANG', 'full_name' => 'Local Member Dua', 'identity_number' => '3271990000000002', 'phone' => '081299000002', 'address' => 'Jl. Development No. 2, Bogor'],
                ['member_number' => '90003-ANG', 'full_name' => 'Local Member Tiga', 'identity_number' => '3271990000000003', 'phone' => '081299000003', 'address' => 'Jl. Development No. 3, Bogor'],
            ];

            $members = [];

            foreach ($memberDefinitions as $definition) {
                $member = DB::table('members')
                    ->where('member_number', $definition['member_number'])
                    ->orWhere('identity_number', $definition['identity_number'])
                    ->first();

                if ($member === null) {
                    $id = DB::table('members')->insertGetId([
                        'member_number' => $definition['member_number'],
                        'full_name' => $definition['full_name'],
                        'identity_number' => $definition['identity_number'],
                        'phone' => $definition['phone'],
                        'address' => $definition['address'],
                        'joined_at' => now()->subYears(2)->toDateString(),
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $member = (object) ['id' => $id, 'member_number' => $definition['member_number']];
                }

                $members[] = $member;
            }

            $accountDefinitions = [
                ['product' => $products['principal'], 'suffix' => 'POKOK', 'balance' => 100_000.00],
                ['product' => $products['mandatory'], 'suffix' => 'WAJIB', 'balance' => 150_000.00],
            ];

            foreach ($members as $member) {
                foreach ($accountDefinitions as $definition) {
                    $existingAcc = DB::table('savings_accounts')
                        ->where('member_id', $member->id)
                        ->where('saving_product_id', $definition['product']->id)
                        ->first();

                    if ($existingAcc === null) {
                        DB::table('savings_accounts')->insert([
                            'member_id' => $member->id,
                            'saving_product_id' => $definition['product']->id,
                            'account_number' => sprintf('SA-DEV-%s-%s', str_replace('-ANG', '', $member->member_number), $definition['suffix']),
                            'opened_at' => now()->subYears(2)->toDateString(),
                            'status' => 'active',
                            'balance' => $definition['balance'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            if (Schema::hasTable('loans') && Schema::hasTable('loan_disbursements') && Schema::hasTable('installment_schedules')) {
                $existingLoan = DB::table('loans')->where('loan_number', 'DEV-PINJ-90001-001')->first();
                if ($existingLoan === null) {
                    $loanId = DB::table('loans')->insertGetId([
                        'loan_number' => 'DEV-PINJ-90001-001',
                        'member_id' => $members[0]->id,
                        'amount' => 5_000_000.00,
                        'tenor_months' => 12,
                        'interest_method' => 'flat',
                        'interest_rate_annual' => 12.0000,
                        'submission_date' => now()->subMonths(3)->toDateString(),
                        'status' => 'ACTIVE',
                        'approved_at' => now()->subMonths(3)->toDateString(),
                        'approved_by' => $admin->id,
                        'disbursed_at' => now()->subMonths(3)->toDateString(),
                        'disbursed_by' => $admin->id,
                        'first_due_date' => now()->startOfMonth()->addMonth()->toDateString(),
                        'notes' => 'Local development fixture. No automatic journal is created.',
                        'created_by' => $admin->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $existingLoan = (object) [
                        'id' => $loanId,
                        'tenor_months' => 12,
                        'amount' => 5_000_000.00,
                        'interest_rate_annual' => 12.0000,
                        'first_due_date' => now()->startOfMonth()->addMonth(),
                    ];
                }

                $existingDisb = DB::table('loan_disbursements')->where('disbursement_number', 'DEV-CAIR-90001-001')->first();
                if ($existingDisb === null) {
                    DB::table('loan_disbursements')->insert([
                        'disbursement_number' => 'DEV-CAIR-90001-001',
                        'loan_id' => $existingLoan->id,
                        'amount' => 5_000_000.00,
                        'disbursement_date' => now()->subMonths(3)->toDateString(),
                        'method' => 'CASH',
                        'processed_by' => $admin->id,
                        'notes' => 'Local development fixture.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $tenor = 12;
                $principal = 5_000_000.00;
                $regularPrincipal = round($principal / $tenor, 2);
                $monthlyInterest = round($principal * (12.0000 / 100) / 12, 2);
                $firstDueDate = now()->startOfMonth()->addMonth();

                for ($number = 1; $number <= $tenor; $number++) {
                    $principalAmount = $number === $tenor
                        ? round($principal - ($regularPrincipal * ($tenor - 1)), 2)
                        : $regularPrincipal;

                    $existingSched = DB::table('installment_schedules')
                        ->where('loan_id', $existingLoan->id)
                        ->where('installment_number', $number)
                        ->first();

                    if ($existingSched === null) {
                        DB::table('installment_schedules')->insert([
                            'loan_id' => $existingLoan->id,
                            'installment_number' => $number,
                            'due_date' => $firstDueDate->copy()->addMonthsNoOverflow($number - 1)->toDateString(),
                            'principal_amount' => $principalAmount,
                            'interest_amount' => $monthlyInterest,
                            'total_amount' => round($principalAmount + $monthlyInterest, 2),
                            'paid_amount' => 0,
                            'remaining_amount' => round($principalAmount + $monthlyInterest, 2),
                            'status' => 'UPCOMING',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }

        $session = CashierSession::query()->firstOrCreate(
            ['session_number' => 'DEV-SESI-KASIR-001'],
            [
                'operational_date' => now()->toDateString(),
                'opened_by' => $admin->id,
                'opening_balance' => 10_000_000.00,
                'opening_notes' => 'Local development cashier fixture.',
                'status' => CashierSession::STATUS_OPEN,
                'opened_at' => now(),
            ],
        );

        CashTransaction::query()->firstOrCreate(
            ['idempotency_key' => 'local-development-cashier-opening'],
            [
                'transaction_number' => 'DEV-KMASUK-KASIR-001',
                'cashier_session_id' => $session->id,
                'type' => CashTransaction::TYPE_INCOME,
                'category' => 'modal_awal',
                'amount' => 10_000_000.00,
                'transaction_date' => now()->toDateString(),
                'transacted_at' => now(),
                'description' => 'Saldo awal kasir untuk lingkungan local.',
                'processed_by' => $admin->id,
                'status' => CashTransaction::STATUS_POSTED,
                'domain' => CashTransaction::DOMAIN_KOPERASI,
            ],
        );
    }

    /**
     * @param  array<string, User>  $users
     */
    private function seedShm(array $users): void
    {
        $marketing = $users['marketing'];
        $bm = $users['bm'];
        $director = $users['director'];
        $finance = $users['finance'];

        $customerDefinitions = [
            [
                'customer_number' => '90001-ANG',
                'name' => 'Local SHM Customer Satu',
                'nik' => '3271990000000101',
                'phone' => '081299000101',
                'address' => 'Jl. Makro Development No. 1, Bogor',
                'member_id' => null,
            ],
            [
                'customer_number' => '90002-ANG',
                'name' => 'Local SHM Customer Dua',
                'nik' => '3271990000000102',
                'phone' => '081299000102',
                'address' => 'Jl. Makro Development No. 2, Bogor',
                'member_id' => null,
            ],
            [
                'customer_number' => '90003-ANG',
                'name' => 'Local SHM Customer Tiga',
                'nik' => '3271990000000103',
                'phone' => '081299000103',
                'address' => 'Jl. Makro Development No. 3, Bogor',
                'member_id' => null,
            ],
        ];

        if (Schema::hasTable('members')) {
            $members = DB::table('members')
                ->whereIn('member_number', ['90001-ANG', '90002-ANG', '90003-ANG'])
                ->get()
                ->keyBy('member_number');

            foreach ($customerDefinitions as $index => $definition) {
                $definition['member_id'] = $members->get(sprintf('9000%d-ANG', $index + 1))?->id;
                $customerDefinitions[$index] = $definition;
            }
        }

        $customers = [];

        foreach ($customerDefinitions as $definition) {
            $customers[] = ShmCustomer::query()->firstOrCreate(
                ['customer_number' => $definition['customer_number']],
                [
                    'member_id' => $definition['member_id'],
                    'name' => $definition['name'],
                    'nik' => $definition['nik'],
                    'phone' => $definition['phone'],
                    'email' => strtolower(str_replace(' ', '.', $definition['name'])).'@ksp.local',
                    'address' => $definition['address'],
                    'is_active' => true,
                ],
            );
        }

        $applicationDefinitions = [
            [
                'application_number' => 'DEV-SHM-APP-001',
                'customer' => $customers[0],
                'status' => 'approval',
                'submitted_at' => now()->subDays(14),
                'approved_at' => null,
                'notes' => 'Local development fixture: menunggu approval Branch Manager Makro.',
            ],
            [
                'application_number' => 'DEV-SHM-APP-002',
                'customer' => $customers[1],
                'status' => 'approval',
                'submitted_at' => now()->subDays(10),
                'approved_at' => null,
                'notes' => 'Local development fixture: sudah approval BM, menunggu approval Direktur Utama Makro.',
            ],
            [
                'application_number' => 'DEV-SHM-APP-003',
                'customer' => $customers[2],
                'status' => 'disbursed',
                'submitted_at' => now()->subDays(30),
                'approved_at' => now()->subDays(20),
                'notes' => 'Local development fixture: dua level approval lengkap dan pinjaman aktif.',
            ],
        ];

        $applications = [];

        foreach ($applicationDefinitions as $definition) {
            $application = ShmApplication::query()->firstOrCreate(
                ['application_number' => $definition['application_number']],
                [
                    'type' => ShmApplication::TYPE_SHGB,
                    'shm_customer_id' => $definition['customer']->id,
                    'marketing_id' => $marketing->id,
                    'status' => $definition['status'],
                    'notes' => $definition['notes'],
                    'submitted_at' => $definition['submitted_at'],
                    'approved_at' => $definition['approved_at'],
                ],
            );

            $applications[] = $application;

            ShmProperty::query()->firstOrCreate(
                [
                    'shm_application_id' => $application->id,
                    'certificate_number' => 'DEV-SHGB-'.$definition['application_number'],
                ],
                [
                    'certificate_type' => ShmProperty::TYPE_SHGB,
                    'owner_name' => $definition['customer']->name,
                    'address' => $definition['customer']->address,
                    'notes' => 'Local development property fixture.',
                ],
            );
        }

        $this->seedApproval($applications[0], null, ShmApproval::STATUS_PENDING, null, 'Menunggu keputusan Branch Manager Makro.');
        $this->seedApproval($applications[1], $bm, ShmApproval::STATUS_APPROVED_BM, now()->subDays(7), 'Disetujui Branch Manager Makro; menunggu Direktur Utama Makro.');

        $this->seedApproval($applications[2], $bm, ShmApproval::STATUS_APPROVED_BM, now()->subDays(24), 'Disetujui Branch Manager Makro.');
        $this->seedApproval($applications[2], $director, ShmApproval::STATUS_APPROVED, now()->subDays(20), 'Disetujui Direktur Utama Makro.');

        $flexible = ShmLoanProduct::query()->where('code', 'BSM-FLEXIBEL')->firstOrFail();
        $quote = app(ShmProductRateService::class)->resolve($flexible, 12);

        $loanApplicationDefinitions = [
            [
                'application_number' => 'DEV-SHM-PENGAJ-001',
                'customer' => $customers[0],
                'application' => $applications[0],
                'product' => ShmLoanProduct::query()->where('code', 'BSM-PRIORITAS')->firstOrFail(),
                'principal' => 50_000_000.00,
                'tenor_months' => 12,
                'status' => 'pending',
                'approved_amount' => null,
            ],
            [
                'application_number' => 'DEV-SHM-PENGAJ-002',
                'customer' => $customers[1],
                'application' => $applications[1],
                'product' => ShmLoanProduct::query()->where('code', 'BSM-PERKAMPUNGAN')->firstOrFail(),
                'principal' => 35_000_000.00,
                'tenor_months' => 12,
                'status' => 'pending',
                'approved_amount' => null,
            ],
            [
                'application_number' => 'DEV-SHM-PENGAJ-003',
                'customer' => $customers[2],
                'application' => $applications[2],
                'product' => $flexible,
                'principal' => 30_000_000.00,
                'tenor_months' => 12,
                'status' => 'approved',
                'approved_amount' => 30_000_000.00,
            ],
        ];

        $loanApplications = [];

        foreach ($loanApplicationDefinitions as $definition) {
            $loanApplications[] = ShmLoanApplication::query()->firstOrCreate(
                ['application_number' => $definition['application_number']],
                [
                    'shm_customer_id' => $definition['customer']->id,
                    'shm_loan_product_id' => $definition['product']->id,
                    'marketing_id' => $marketing->id,
                    'shm_application_id' => $definition['application']->id,
                    'application_date' => now()->subDays(30)->toDateString(),
                    'principal' => $definition['principal'],
                    'tenor_months' => $definition['tenor_months'],
                    'first_due_date' => now()->startOfMonth()->addMonth()->toDateString(),
                    'purpose' => 'Local development SHM loan application.',
                    'disbursement_method' => 'CASH',
                    'approved_amount' => $definition['approved_amount'],
                    'calculation_method' => 'flat',
                    'status' => $definition['status'],
                ],
            );
        }

        $costService = app(ShmDisbursementCostService::class);
        $costs = $costService->calculate(30_000_000.00, 12, 250_000.00, false);

        $loan = ShmLoan::query()->firstOrCreate(
            ['loan_number' => 'DEV-SHM-LOAN-001'],
            [
                'shm_customer_id' => $customers[2]->id,
                'shm_loan_product_id' => $flexible->id,
                'shm_loan_application_id' => $loanApplications[2]->id,
                'principal' => 30_000_000.00,
                ...$costs,
                'tenor_months' => 12,
                'first_due_date' => now()->startOfMonth()->addMonth()->toDateString(),
                'rate_percent' => $quote->ratePercent,
                'rate_period' => $quote->ratePeriod,
                'calculation_method' => $quote->calculationMethod,
                'location' => 'Bogor',
                'purpose' => 'Local development SHM active loan fixture.',
                'status' => ShmLoan::STATUS_ACTIVE,
                'disbursed_at' => now()->subDays(20)->toDateString(),
                'note' => 'Local development fixture. No automatic journal is created.',
            ],
        );

        if (! $loan->installments()->exists()) {
            app(ShmRepaymentScheduleService::class)->generate($loan);
        }

        $installments = $loan->installments()->get();

        if ($installments->isNotEmpty()) {
            $outstandingPrincipal = round((float) $installments->sum('principal_amount'), 2);
            $outstandingInterest = round((float) $installments->sum('interest_amount'), 2);
            $settlementAmount = round($outstandingPrincipal + $outstandingInterest, 2);

            ShmLoanSettlement::query()->firstOrCreate(
                ['settlement_reference' => 'DEV-SHM-SET-001'],
                [
                    'shm_loan_id' => $loan->id,
                    'settlement_date' => now()->toDateString(),
                    'outstanding_principal' => $outstandingPrincipal,
                    'outstanding_interest' => $outstandingInterest,
                    'outstanding_penalty' => 0,
                    'unallocated_payment_amount' => 0,
                    'applied_unallocated_amount' => 0,
                    'settlement_amount' => $settlementAmount,
                    'net_cash_due' => $settlementAmount,
                    'overpayment_amount' => 0,
                    'status' => ShmLoanSettlement::STATUS_PENDING,
                    'notes' => 'Local development pending settlement fixture. No payment or journal is created.',
                    'created_by' => $finance->id,
                ],
            );
        }
    }

    private function seedApproval(
        ShmApplication $application,
        ?User $decider,
        string $status,
        mixed $decidedAt,
        string $notes,
    ): void {
        if ($decider === null && $application->approvals()->exists()) {
            return;
        }

        $query = ShmApproval::query()->where('shm_application_id', $application->id);

        if ($decider !== null) {
            $query->where('decided_by', $decider->id);
        } else {
            $query->whereNull('decided_by');
        }

        if ($query->exists()) {
            return;
        }

        ShmApproval::query()->create([
            'shm_application_id' => $application->id,
            'decided_by' => $decider?->id,
            'status' => $status,
            'decided_at' => $decidedAt,
            'notes' => $notes,
        ]);
    }

    /**
     * @param  array<string, User>  $users
     */
    private function seedShmAccounting(array $users): void
    {
        $finance = $users['finance'];

        $accounts = ChartOfAccount::query()
            ->where('accounting_context', AccountingContext::SHM)
            ->whereIn('code', ['1-1100', '1-1300', '4-1101', '4-1103', '4-1901'])
            ->pluck('id', 'code');

        $products = ShmLoanProduct::query()->orderBy('id')->get();

        foreach ($products as $product) {
            ShmProductAccounting::query()->updateOrCreate(
                [
                    'shm_loan_product_id' => $product->id,
                    'accounting_context' => AccountingContext::SHM,
                ],
                [
                    'cash_account_id' => $accounts['1-1100'] ?? null,
                    'receivable_account_id' => $accounts['1-1300'] ?? null,
                    'interest_revenue_account_id' => $accounts['4-1101'] ?? null,
                    'penalty_revenue_account_id' => $accounts['4-1103'] ?? null,
                    'settlement_discount_account_id' => $accounts['4-1901'] ?? null,
                    'is_active' => true,
                    'notes' => 'Local development accounting configuration.',
                    'created_by' => $finance->id,
                    'updated_by' => $finance->id,
                ],
            );
        }

        $mappings = [
            ShmAccountingMapping::EVENT_DISBURSEMENT => [ShmAccountingMapping::ROLE_RECEIVABLE, ShmAccountingMapping::ROLE_CASH],
            ShmAccountingMapping::EVENT_REGULAR_PAYMENT => [ShmAccountingMapping::ROLE_CASH, ShmAccountingMapping::ROLE_RECEIVABLE],
            ShmAccountingMapping::EVENT_SETTLEMENT => [ShmAccountingMapping::ROLE_CASH, ShmAccountingMapping::ROLE_RECEIVABLE],
            ShmAccountingMapping::EVENT_SETTLEMENT_CORRECTION => [ShmAccountingMapping::ROLE_RECEIVABLE, ShmAccountingMapping::ROLE_CASH],
        ];

        foreach ($mappings as $event => [$debitRole, $creditRole]) {
            ShmAccountingMapping::query()->updateOrCreate(
                [
                    'accounting_context' => AccountingContext::SHM,
                    'business_event' => $event,
                    'shm_loan_product_id' => null,
                ],
                [
                    'debit_account_role' => $debitRole,
                    'credit_account_role' => $creditRole,
                    'is_active' => true,
                    'description' => 'Local development mapping for '.$event.'.',
                    'created_by' => $finance->id,
                    'updated_by' => $finance->id,
                ],
            );
        }
    }
}
