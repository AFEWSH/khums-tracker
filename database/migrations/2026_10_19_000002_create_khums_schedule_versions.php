<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('khums_schedule_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->unsignedInteger('effective_start_year')->unique();
            $table->unsignedTinyInteger('end_month');
            $table->unsignedTinyInteger('end_day');
            $table->string('end_local_clock', 5);
            $table->string('timezone', 40)->default('Asia/Tehran');
            $table->string('reason', 240);
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('khums_schedule_versions');
    }
};
