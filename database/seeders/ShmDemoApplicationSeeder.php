<?php

namespace Database\Seeders;

use App\Enums\ShmApplicationStatus;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmProperty;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Ticket 12 — Local SHGB & Buyback Demo Application Seeder.
 *
 * Menghasilkan tepat 10 pengajuan dummy SHM (BSM Makro) pada database lokal:
 * - 5 Pengajuan SHGB (3 Completed, 2 Approved)
 * - 5 Pengajuan Buyback (3 Completed, 2 Approved)
 *
 * Tujuan:
 * - Demonstrasi pengajuan SHGB dan Buyback.
 * - Uji kelayakan picker/sumber Memorandum SHM/SHGB (hanya 3 SHGB Completed yang eligible).
 * - Uji kelayakan picker/sumber Memorandum Buyback (hanya 3 Buyback Completed yang eligible).
 *
 * Aturan Bisnis & Idempotensi:
 * - Idempoten: Berjalan berulang kali tanpa duplikasi data (menggunakan firstOrCreate dengan identifier stabil).
 * - Menjaga data existing: Tidak menghapus, mereset, atau mengubah data yang sudah ada.
 * - Tidak membuat record ShmMemorandum maupun Approval History Memorandum.
 */
class ShmDemoApplicationSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Tentukan marketing penanggung jawab (default ke marketing demo atau user pertama)
        $marketing = User::query()->where('email', 'marketing.demo@ksp.local')->first()
            ?? User::query()->firstOrFail();

        // 2. Definisi 10 dataset demo (5 SHGB, 5 Buyback)
        $demoDatasets = [
            // --- 5 Pengajuan SHGB ---
            [
                'app_number' => 'APP-DEMO-SHGB-01',
                'type' => ShmApplication::TYPE_SHGB,
                'status' => ShmApplicationStatus::Completed,
                'customer_number' => 'CUST-DEMO-SHGB-01',
                'customer_name' => 'Demo SHGB Customer 01',
                'customer_nik' => '3271011101900001',
                'phone' => '081211110001',
                'address' => 'Jl. Pajajaran No. 101, Kota Bogor',
                'cert_type' => 'SHGB',
                'cert_number' => 'SHGB/BGR/2026/001',
                'notes' => 'Pengajuan SHGB Demo 01 — Status Selesai (Completed), eligible sumber Memorandum SHM/SHGB.',
            ],
            [
                'app_number' => 'APP-DEMO-SHGB-02',
                'type' => ShmApplication::TYPE_SHGB,
                'status' => ShmApplicationStatus::Completed,
                'customer_number' => 'CUST-DEMO-SHGB-02',
                'customer_name' => 'Demo SHGB Customer 02',
                'customer_nik' => '3271011101900002',
                'phone' => '081211110002',
                'address' => 'Jl. Pajajaran No. 102, Kota Bogor',
                'cert_type' => 'SHGB',
                'cert_number' => 'SHGB/BGR/2026/002',
                'notes' => 'Pengajuan SHGB Demo 02 — Status Selesai (Completed), eligible sumber Memorandum SHM/SHGB.',
            ],
            [
                'app_number' => 'APP-DEMO-SHGB-03',
                'type' => ShmApplication::TYPE_SHGB,
                'status' => ShmApplicationStatus::Completed,
                'customer_number' => 'CUST-DEMO-SHGB-03',
                'customer_name' => 'Demo SHGB Customer 03',
                'customer_nik' => '3271011101900003',
                'phone' => '081211110003',
                'address' => 'Jl. Pajajaran No. 103, Kota Bogor',
                'cert_type' => 'SHGB',
                'cert_number' => 'SHGB/BGR/2026/003',
                'notes' => 'Pengajuan SHGB Demo 03 — Status Selesai (Completed), eligible sumber Memorandum SHM/SHGB.',
            ],
            [
                'app_number' => 'APP-DEMO-SHGB-04',
                'type' => ShmApplication::TYPE_SHGB,
                'status' => ShmApplicationStatus::Approved,
                'customer_number' => 'CUST-DEMO-SHGB-04',
                'customer_name' => 'Demo SHGB Customer 04',
                'customer_nik' => '3271011101900004',
                'phone' => '081211110004',
                'address' => 'Jl. Pajajaran No. 104, Kota Bogor',
                'cert_type' => 'SHGB',
                'cert_number' => 'SHGB/BGR/2026/004',
                'notes' => 'Pengajuan SHGB Demo 04 — Status Disetujui (Approved), tidak eligible untuk Memorandum.',
            ],
            [
                'app_number' => 'APP-DEMO-SHGB-05',
                'type' => ShmApplication::TYPE_SHGB,
                'status' => ShmApplicationStatus::Approved,
                'customer_number' => 'CUST-DEMO-SHGB-05',
                'customer_name' => 'Demo SHGB Customer 05',
                'customer_nik' => '3271011101900005',
                'phone' => '081211110005',
                'address' => 'Jl. Pajajaran No. 105, Kota Bogor',
                'cert_type' => 'SHGB',
                'cert_number' => 'SHGB/BGR/2026/005',
                'notes' => 'Pengajuan SHGB Demo 05 — Status Disetujui (Approved), tidak eligible untuk Memorandum.',
            ],

            // --- 5 Pengajuan Buyback ---
            [
                'app_number' => 'APP-DEMO-BUYBACK-01',
                'type' => ShmApplication::TYPE_BUYBACK,
                'status' => ShmApplicationStatus::Completed,
                'customer_number' => 'CUST-DEMO-BB-01',
                'customer_name' => 'Demo Buyback Customer 01',
                'customer_nik' => '3271012202900001',
                'phone' => '081222220001',
                'address' => 'Jl. Surya Kencana No. 201, Kota Bogor',
                'cert_type' => 'SHM',
                'cert_number' => 'SHM/BGR/2026/101',
                'notes' => 'Pengajuan Buyback Demo 01 — Status Selesai (Completed), eligible sumber Memorandum Buyback.',
            ],
            [
                'app_number' => 'APP-DEMO-BUYBACK-02',
                'type' => ShmApplication::TYPE_BUYBACK,
                'status' => ShmApplicationStatus::Completed,
                'customer_number' => 'CUST-DEMO-BB-02',
                'customer_name' => 'Demo Buyback Customer 02',
                'customer_nik' => '3271012202900002',
                'phone' => '081222220002',
                'address' => 'Jl. Surya Kencana No. 202, Kota Bogor',
                'cert_type' => 'SHM',
                'cert_number' => 'SHM/BGR/2026/102',
                'notes' => 'Pengajuan Buyback Demo 02 — Status Selesai (Completed), eligible sumber Memorandum Buyback.',
            ],
            [
                'app_number' => 'APP-DEMO-BUYBACK-03',
                'type' => ShmApplication::TYPE_BUYBACK,
                'status' => ShmApplicationStatus::Completed,
                'customer_number' => 'CUST-DEMO-BB-03',
                'customer_name' => 'Demo Buyback Customer 03',
                'customer_nik' => '3271012202900003',
                'phone' => '081222220003',
                'address' => 'Jl. Surya Kencana No. 203, Kota Bogor',
                'cert_type' => 'SHM',
                'cert_number' => 'SHM/BGR/2026/103',
                'notes' => 'Pengajuan Buyback Demo 03 — Status Selesai (Completed), eligible sumber Memorandum Buyback.',
            ],
            [
                'app_number' => 'APP-DEMO-BUYBACK-04',
                'type' => ShmApplication::TYPE_BUYBACK,
                'status' => ShmApplicationStatus::Approved,
                'customer_number' => 'CUST-DEMO-BB-04',
                'customer_name' => 'Demo Buyback Customer 04',
                'customer_nik' => '3271012202900004',
                'phone' => '081222220004',
                'address' => 'Jl. Surya Kencana No. 204, Kota Bogor',
                'cert_type' => 'SHM',
                'cert_number' => 'SHM/BGR/2026/104',
                'notes' => 'Pengajuan Buyback Demo 04 — Status Disetujui (Approved), tidak eligible untuk Memorandum.',
            ],
            [
                'app_number' => 'APP-DEMO-BUYBACK-05',
                'type' => ShmApplication::TYPE_BUYBACK,
                'status' => ShmApplicationStatus::Approved,
                'customer_number' => 'CUST-DEMO-BB-05',
                'customer_name' => 'Demo Buyback Customer 05',
                'customer_nik' => '3271012202900005',
                'phone' => '081222220005',
                'address' => 'Jl. Surya Kencana No. 205, Kota Bogor',
                'cert_type' => 'SHM',
                'cert_number' => 'SHM/BGR/2026/105',
                'notes' => 'Pengajuan Buyback Demo 05 — Status Disetujui (Approved), tidak eligible untuk Memorandum.',
            ],
        ];

        // 3. Iterasi dan simpan record secara idempoten
        foreach ($demoDatasets as $data) {
            // A. Customer (idempoten via customer_number)
            $customer = ShmCustomer::query()->firstOrCreate(
                ['customer_number' => $data['customer_number']],
                [
                    'name' => $data['customer_name'],
                    'nik' => $data['customer_nik'],
                    'phone' => $data['phone'],
                    'email' => strtolower(str_replace(' ', '.', $data['customer_name'])).'@ksp.demo',
                    'address' => $data['address'],
                    'is_active' => true,
                ]
            );

            // B. ShmApplication (idempoten via application_number)
            $application = ShmApplication::query()->firstOrCreate(
                ['application_number' => $data['app_number']],
                [
                    'type' => $data['type'],
                    'status' => $data['status'],
                    'shm_customer_id' => $customer->id,
                    'marketing_id' => $marketing->id,
                    'notes' => $data['notes'],
                    'submitted_at' => now()->subDays(5),
                    'approved_at' => now()->subDays(3),
                    'completed_at' => $data['status'] === ShmApplicationStatus::Completed ? now()->subDay() : null,
                ]
            );

            // C. Property agunan (idempoten via shm_application_id dan certificate_number)
            ShmProperty::query()->firstOrCreate(
                [
                    'shm_application_id' => $application->id,
                    'certificate_number' => $data['cert_number'],
                ],
                [
                    'certificate_type' => $data['cert_type'],
                    'owner_name' => $data['customer_name'],
                    'address' => $data['address'],
                    'notes' => 'Objek jaminan untuk '.$data['app_number'],
                ]
            );
        }
    }
}
