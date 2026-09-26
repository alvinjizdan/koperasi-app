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
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('member_number', 50)->unique();
            $table->string('full_name');
            $table->string('identity_number', 50)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->date('joined_at');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index('full_name');
            $table->index('identity_number');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
