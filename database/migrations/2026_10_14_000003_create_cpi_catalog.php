<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cpi_series', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 60);
            $table->string('coverage', 40);
            $table->unsignedSmallInteger('base_year');
            $table->string('methodology_version', 80);
            $table->string('source_url', 500);
            $table->string('verification_status', 24)->default('unverified');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['provider', 'coverage', 'base_year', 'methodology_version'], 'cpi_series_identity_unique');
        });
        Schema::create('cpi_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('series_id')->constrained('cpi_series')->restrictOnDelete();
            $table->unsignedSmallInteger('jalali_year');
            $table->unsignedTinyInteger('jalali_month');
            $table->date('month_start_date');
            $table->date('next_month_start_date');
            $table->timestamps();
            $table->unique(['series_id', 'jalali_year', 'jalali_month']);
        });
        Schema::create('cpi_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('point_id')->constrained('cpi_points')->restrictOnDelete();
            $table->unsignedInteger('revision_no');
            $table->string('value_decimal', 40);
            $table->string('source_url', 500);
            $table->date('publication_date')->nullable();
            $table->string('reason', 240)->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at', 6);
            $table->unique(['point_id', 'revision_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cpi_revisions');
        Schema::dropIfExists('cpi_points');
        Schema::dropIfExists('cpi_series');
    }
};
