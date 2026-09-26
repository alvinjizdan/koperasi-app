<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail lintas modul; Ticket 9 dapat memakai tabel yang sama.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Aktor boleh null untuk peristiwa sistem (seeder, konsol).
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->string('object_type', 60);
            $table->unsignedBigInteger('object_id')->nullable();
            $table->string('object_label', 150)->nullable();
            $table->text('description')->nullable();
            // Ringkasan perubahan yang aman untuk audit: tanpa password/token.
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['object_type', 'object_id']);
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
