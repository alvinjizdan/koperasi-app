<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TICKET 14 — Pengajuan-Centric Document Management & SHGB Validity Dates Migration.
 *
 * Migration ini:
 * 1. Menambahkan kolom `shgb_start_date` dan `shgb_end_date` ke tabel `shm_applications`.
 * 2. Melakukan backfill tanggal SHGB dari `shm_memorandums` bertipe 'shm_shgb' ke `shm_applications`.
 * 3. Menambahkan kolom `shm_application_id` ke tabel `shm_buyback_documents` yang berelasi ke `shm_applications`.
 * 4. Mengubah foreign key `shm_memorandum_id` pada `shm_buyback_documents` agar nullable (agar penghapusan memorandum tidak menghapus dokumen aplikasi).
 * 5. Melakukan backfill `shm_application_id` pada `shm_buyback_documents` dari data memorandum yang ada.
 * 6. Menambahkan index dan unique constraint baru pada `(shm_application_id, document_type)` di `shm_buyback_documents`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Tambah kolom tanggal SHGB ke tabel shm_applications jika belum ada
        Schema::table('shm_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('shm_applications', 'shgb_start_date')) {
                $table->date('shgb_start_date')->nullable()->after('completed_at');
            }
            if (! Schema::hasColumn('shm_applications', 'shgb_end_date')) {
                $table->date('shgb_end_date')->nullable()->after('shgb_start_date');
            }
        });

        // 2. Backfill tanggal SHGB secara independen & deteksi konflik dari multiple memorandums
        $memorandums = DB::table('shm_memorandums')
            ->where('type', 'shm_shgb')
            ->whereNotNull('shm_application_id')
            ->where(function ($query) {
                $query->whereNotNull('shgb_start_date')
                    ->orWhereNotNull('shgb_end_date');
            })
            ->select('shm_application_id', 'shgb_start_date', 'shgb_end_date')
            ->get();

        $groupedByApp = $memorandums->groupBy('shm_application_id');

        foreach ($groupedByApp as $appId => $memos) {
            $distinctStartDates = $memos->pluck('shgb_start_date')->filter()->unique()->values();
            $distinctEndDates = $memos->pluck('shgb_end_date')->filter()->unique()->values();

            if ($distinctStartDates->count() > 1) {
                throw new RuntimeException("Konflik migrasi tanggal SHGB pada pengajuan ID {$appId}: ditemukan beberapa nilai shgb_start_date yang berbeda (".implode(', ', $distinctStartDates->all()).') pada Memorandum SHM/SHGB.');
            }

            if ($distinctEndDates->count() > 1) {
                throw new RuntimeException("Konflik migrasi tanggal SHGB pada pengajuan ID {$appId}: ditemukan beberapa nilai shgb_end_date yang berbeda (".implode(', ', $distinctEndDates->all()).') pada Memorandum SHM/SHGB.');
            }

            $candidateStartDate = $distinctStartDates->first();
            $candidateEndDate = $distinctEndDates->first();

            $existingApp = DB::table('shm_applications')->where('id', $appId)->first(['shgb_start_date', 'shgb_end_date']);
            if (! $existingApp) {
                continue;
            }

            $updates = [];
            if ($existingApp->shgb_start_date === null && $candidateStartDate !== null) {
                $updates['shgb_start_date'] = $candidateStartDate;
            }
            if ($existingApp->shgb_end_date === null && $candidateEndDate !== null) {
                $updates['shgb_end_date'] = $candidateEndDate;
            }

            if ($updates !== []) {
                DB::table('shm_applications')->where('id', $appId)->update($updates);
            }
        }

        // 3. Tambah kolom shm_application_id ke shm_buyback_documents jika belum ada
        // Menggunakan restrictOnDelete() agar ShmApplication yang memiliki dokumen legalitas
        // TIDAK DAPAT dihapus secara tidak sengaja demi menjaga integritas dokumen hukum.
        if (! Schema::hasColumn('shm_buyback_documents', 'shm_application_id')) {
            Schema::table('shm_buyback_documents', function (Blueprint $table) {
                $table->foreignId('shm_application_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('shm_applications')
                    ->restrictOnDelete();
            });
        }

        // 4. Periksa potensi duplikasi kombinasi (application_id, document_type) dari shm_memorandums
        // SEBELUM melakukan penulisan/update ke shm_buyback_documents.
        $memoDocs = DB::table('shm_buyback_documents as d')
            ->join('shm_memorandums as m', 'd.shm_memorandum_id', '=', 'm.id')
            ->whereNull('d.shm_application_id')
            ->select('d.id', 'm.shm_application_id', 'd.document_type')
            ->get();

        // Gabungkan dokumen yang sudah punya shm_application_id dengan calon backfill
        $existingAppDocs = DB::table('shm_buyback_documents')
            ->whereNotNull('shm_application_id')
            ->select('id', 'shm_application_id', 'document_type')
            ->get();

        $allPlannedDocs = $existingAppDocs->concat($memoDocs);

        $duplicates = $allPlannedDocs
            ->groupBy(fn ($item) => "{$item->shm_application_id}:{$item->document_type}")
            ->filter(fn ($group) => $group->count() > 1);

        if ($duplicates->isNotEmpty()) {
            $details = $duplicates->map(function ($group) {
                $first = $group->first();

                return "Pengajuan ID {$first->shm_application_id} (jenis dokumen '{$first->document_type}': {$group->count()} berkas)";
            })->implode(', ');

            throw new RuntimeException(
                "Migrasi dihentikan: Ditemukan duplikasi dokumen legalitas untuk kombinasi (shm_application_id, document_type) pada: {$details}. Unique constraint tidak dapat dibuat tanpa menyelesaikan duplikasi berkas terlebih dahulu secara manual."
            );
        }

        foreach ($memoDocs as $doc) {
            DB::table('shm_buyback_documents')
                ->where('id', $doc->id)
                ->update(['shm_application_id' => $doc->shm_application_id]);
        }

        // 5. Ubah shm_memorandum_id menjadi nullable dan ubah foreign key constraint
        // agar onDelete SET NULL atau tidak cascade delete dokumen aplikasi saat memorandum dihapus
        $existingIndexes = array_column(Schema::getIndexes('shm_buyback_documents'), 'name');

        Schema::table('shm_buyback_documents', function (Blueprint $table) use ($existingIndexes) {
            if (in_array('shm_buyback_documents_shm_memorandum_id_document_type_unique', $existingIndexes, true)) {
                $table->dropUnique(['shm_memorandum_id', 'document_type']);
            }

            $table->dropForeign(['shm_memorandum_id']);
            $table->unsignedBigInteger('shm_memorandum_id')->nullable()->change();
            $table->foreign('shm_memorandum_id')
                ->references('id')
                ->on('shm_memorandums')
                ->nullOnDelete();

            if (! in_array('shm_buyback_documents_shm_application_id_document_type_unique', $existingIndexes, true)) {
                $table->unique(['shm_application_id', 'document_type']);
            }
        });
    }

    public function down(): void
    {
        // ISSUE B — Pemeriksaan konflik tanggal SHGB antara shm_applications dan seluruh Memorandum SHM/SHGB terkait
        // sebelum melakukan modifikasi data atau perubahan skema apapun pada rollback.
        $appsWithDates = DB::table('shm_applications')
            ->where('type', 'shgb')
            ->where(function ($query) {
                $query->whereNotNull('shgb_start_date')
                    ->orWhereNotNull('shgb_end_date');
            })
            ->get(['id', 'shgb_start_date', 'shgb_end_date']);

        foreach ($appsWithDates as $app) {
            $existingMemos = DB::table('shm_memorandums')
                ->where('shm_application_id', $app->id)
                ->where('type', 'shm_shgb')
                ->get(['id', 'shgb_start_date', 'shgb_end_date']);

            foreach ($existingMemos as $memo) {
                $conflictDetails = [];

                if ($app->shgb_start_date !== null && $memo->shgb_start_date !== null && $app->shgb_start_date !== $memo->shgb_start_date) {
                    $conflictDetails[] = "shgb_start_date (Pengajuan: '{$app->shgb_start_date}', Memorandum: '{$memo->shgb_start_date}')";
                }

                if ($app->shgb_end_date !== null && $memo->shgb_end_date !== null && $app->shgb_end_date !== $memo->shgb_end_date) {
                    $conflictDetails[] = "shgb_end_date (Pengajuan: '{$app->shgb_end_date}', Memorandum: '{$memo->shgb_end_date}')";
                }

                if ($conflictDetails !== []) {
                    $conflictStr = implode(' dan ', $conflictDetails);

                    throw new RuntimeException(
                        "Rollback dibatalkan demi keamanan data: Ditemukan konflik tanggal SHGB antara Pengajuan ID {$app->id} dan Memorandum ID {$memo->id}: {$conflictStr}. Rollback dihentikan sebelum perubahan data atau skema dilakukan."
                    );
                }
            }
        }

        // 1. Inventarisasi seluruh dokumen Buyback sebelum rollback
        $allDocs = DB::table('shm_buyback_documents')->get(['id', 'shm_application_id', 'shm_memorandum_id', 'document_type', 'file_name']);

        // Periksa dokumen yang shm_memorandum_id-nya NULL
        $unlinkedDocs = $allDocs->whereNull('shm_memorandum_id');

        foreach ($unlinkedDocs as $doc) {
            // Cari memorandum Buyback yang bersesuaian dengan shm_application_id dokumen
            $candidateMemos = DB::table('shm_memorandums')
                ->where('shm_application_id', $doc->shm_application_id)
                ->where('type', 'buyback')
                ->get(['id']);

            if ($candidateMemos->isEmpty()) {
                throw new RuntimeException(
                    "Rollback dibatalkan demi keamanan data: Dokumen Buyback ID {$doc->id} (jenis: '{$doc->document_type}', file: '{$doc->file_name}') pada Pengajuan ID {$doc->shm_application_id} tidak memiliki Memorandum Buyback pasangan dalam sistem lama. Rollback akan menyebabkan dokumen ini kehilangan referensi pengajuannya karena skema lama mewajibkan shm_memorandum_id NOT NULL."
                );
            }

            if ($candidateMemos->count() > 1) {
                throw new RuntimeException(
                    "Rollback dibatalkan demi keamanan data: Ditemukan {$candidateMemos->count()} Memorandum Buyback untuk Pengajuan ID {$doc->shm_application_id}. Tidak dapat menentukan memorandum pasangan secara deterministik untuk Dokumen ID {$doc->id}."
                );
            }

            // Hubungkan kembali secara aman
            DB::table('shm_buyback_documents')
                ->where('id', $doc->id)
                ->update(['shm_memorandum_id' => $candidateMemos->first()->id]);
        }

        // 2. Backfill balik tanggal SHGB dari shm_applications ke shm_memorandums (tipe shm_shgb)
        // sebelum kolom tanggal pada shm_applications di-drop, untuk mencegah hilangnya data tanggal saat rollback.
        foreach ($appsWithDates as $app) {
            $existingMemos = DB::table('shm_memorandums')
                ->where('shm_application_id', $app->id)
                ->where('type', 'shm_shgb')
                ->get(['id', 'shgb_start_date', 'shgb_end_date']);

            foreach ($existingMemos as $memo) {
                $updates = [];
                if ($memo->shgb_start_date === null && $app->shgb_start_date !== null) {
                    $updates['shgb_start_date'] = $app->shgb_start_date;
                }
                if ($memo->shgb_end_date === null && $app->shgb_end_date !== null) {
                    $updates['shgb_end_date'] = $app->shgb_end_date;
                }
                if ($updates !== []) {
                    DB::table('shm_memorandums')->where('id', $memo->id)->update($updates);
                }
            }
        }

        // 3. Kembalikan struktur tabel shm_buyback_documents ke skema lama
        Schema::table('shm_buyback_documents', function (Blueprint $table) {
            $table->dropUnique(['shm_application_id', 'document_type']);
            $table->dropForeign(['shm_application_id']);
            $table->dropColumn('shm_application_id');

            $table->dropForeign(['shm_memorandum_id']);
            $table->unsignedBigInteger('shm_memorandum_id')->nullable(false)->change();
            $table->foreign('shm_memorandum_id')
                ->references('id')
                ->on('shm_memorandums')
                ->cascadeOnDelete();
            $table->unique(['shm_memorandum_id', 'document_type']);
        });

        // 4. Drop kolom tanggal pada shm_applications
        Schema::table('shm_applications', function (Blueprint $table) {
            $table->dropColumn(['shgb_start_date', 'shgb_end_date']);
        });
    }
};
