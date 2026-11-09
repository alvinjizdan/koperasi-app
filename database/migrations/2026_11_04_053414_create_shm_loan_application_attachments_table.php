<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shm_loan_application_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shm_loan_application_id')
                ->constrained('shm_loan_applications')
                ->cascadeOnDelete();
            $table->string('file_name', 255);
            $table->string('file_path', 500);
            $table->string('mime_type', 100)->default('application/pdf');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->timestamps();

            $table->index('shm_loan_application_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shm_loan_application_attachments');
    }
};
