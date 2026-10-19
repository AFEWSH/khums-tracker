<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('khums_period_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('start_year');
            $table->string('start_at_utc', 27);
            $table->string('end_exclusive_at_utc', 27);
            $table->date('as_of_date');
            $table->unsignedBigInteger('financial_revision');
            $table->char('rule_manifest_hash', 64);
            $table->char('input_hash', 64);
            $table->char('snapshot_hash', 64);
            $table->json('snapshot_json');
            $table->string('status', 24);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', 6);
            $table->unique(['start_year', 'input_hash']);
            $table->index(['start_year', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('khums_period_reviews');
    }
};
