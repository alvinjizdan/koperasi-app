<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

/**
 * Ticket 18 — data awal Chart of Accounts.
 *
 * PENTING: seluruh akun di sini adalah TEMPLATE awal (is_template = true),
 * BUKAN COA resmi koperasi. Kode dan nama mengikuti struktur umum akuntansi
 * koperasi dan wajib dikonfirmasi/disesuaikan oleh pengelola sebelum dipakai
 * untuk pembukuan nyata. Akun dapat diedit/dinonaktifkan dari halaman COA.
 */
class ChartOfAccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            // Aset
            ['code' => '1-0000', 'name' => 'ASET (Kelompok Induk)', 'category' => 'asset', 'normal_balance' => 'debit', 'parent' => null],
            ['code' => '1-1100', 'name' => 'Kas', 'category' => 'asset', 'normal_balance' => 'debit', 'parent' => '1-0000'],
            ['code' => '1-1200', 'name' => 'Bank', 'category' => 'asset', 'normal_balance' => 'debit', 'parent' => '1-0000'],
            ['code' => '1-1300', 'name' => 'Piutang Pinjaman Anggota', 'category' => 'asset', 'normal_balance' => 'debit', 'parent' => '1-0000'],
            ['code' => '1-1400', 'name' => 'Piutang Lain-lain', 'category' => 'asset', 'normal_balance' => 'debit', 'parent' => '1-0000'],

            // Liabilitas
            ['code' => '2-0000', 'name' => 'LIABILITAS (Kelompok Induk)', 'category' => 'liability', 'normal_balance' => 'credit', 'parent' => null],
            ['code' => '2-1100', 'name' => 'Simpanan Pokok Anggota', 'category' => 'liability', 'normal_balance' => 'credit', 'parent' => '2-0000'],
            ['code' => '2-1200', 'name' => 'Simpanan Wajib Anggota', 'category' => 'liability', 'normal_balance' => 'credit', 'parent' => '2-0000'],
            ['code' => '2-1300', 'name' => 'Simpanan Sukarela Anggota', 'category' => 'liability', 'normal_balance' => 'credit', 'parent' => '2-0000'],
            ['code' => '2-1400', 'name' => 'Hutang Jangka Pendek', 'category' => 'liability', 'normal_balance' => 'credit', 'parent' => '2-0000'],

            // Ekuitas
            ['code' => '3-0000', 'name' => 'EKUITAS (Kelompok Induk)', 'category' => 'equity', 'normal_balance' => 'credit', 'parent' => null],
            ['code' => '3-1100', 'name' => 'Hibah/Donasi', 'category' => 'equity', 'normal_balance' => 'credit', 'parent' => '3-0000'],
            ['code' => '3-1200', 'name' => 'Saldo Laba Ditahan', 'category' => 'equity', 'normal_balance' => 'credit', 'parent' => '3-0000'],

            // Pendapatan
            ['code' => '4-0000', 'name' => 'PENDAPATAN (Kelompok Induk)', 'category' => 'revenue', 'normal_balance' => 'credit', 'parent' => null],
            ['code' => '4-1100', 'name' => 'Pendapatan Jasa Pinjaman', 'category' => 'revenue', 'normal_balance' => 'credit', 'parent' => '4-0000'],
            ['code' => '4-1200', 'name' => 'Pendapatan Administrasi', 'category' => 'revenue', 'normal_balance' => 'credit', 'parent' => '4-0000'],
            ['code' => '4-1300', 'name' => 'Pendapatan Lain-lain', 'category' => 'revenue', 'normal_balance' => 'credit', 'parent' => '4-0000'],

            // Beban
            ['code' => '5-0000', 'name' => 'BEBAN (Kelompok Induk)', 'category' => 'expense', 'normal_balance' => 'debit', 'parent' => null],
            ['code' => '5-1100', 'name' => 'Beban Operasional', 'category' => 'expense', 'normal_balance' => 'debit', 'parent' => '5-0000'],
            ['code' => '5-1200', 'name' => 'Beban Gaji & Honor', 'category' => 'expense', 'normal_balance' => 'debit', 'parent' => '5-0000'],
            ['code' => '5-1300', 'name' => 'Beban Lain-lain', 'category' => 'expense', 'normal_balance' => 'debit', 'parent' => '5-0000'],
        ];

        $idsByCode = [];

        foreach ($accounts as $account) {
            $parentId = $account['parent'] !== null ? $idsByCode[$account['parent']] ?? null : null;

            $model = ChartOfAccount::query()->updateOrCreate(
                ['code' => $account['code']],
                [
                    'name' => $account['name'],
                    'category' => $account['category'],
                    'normal_balance' => $account['normal_balance'],
                    'parent_id' => $parentId,
                    'is_active' => true,
                    'is_template' => true,
                ]
            );

            $idsByCode[$account['code']] = $model->id;
        }
    }
}
