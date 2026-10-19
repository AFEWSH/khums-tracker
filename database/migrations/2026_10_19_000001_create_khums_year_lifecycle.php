<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('khums_period_closures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('start_year');
            $table->unsignedInteger('revision');
            $table->foreignId('review_id')->unique()->constrained('khums_period_reviews')->restrictOnDelete();
            $table->foreignId('previous_closure_id')->nullable()->constrained('khums_period_closures')->restrictOnDelete();
            $table->foreignId('depends_on_closure_id')->nullable()->constrained('khums_period_closures')->restrictOnDelete();
            $table->char('input_hash', 64);
            $table->char('snapshot_hash', 64);
            $table->json('snapshot_json');
            $table->string('due_exact_rial', 40);
            $table->char('backup_sha256', 64);
            $table->boolean('active')->default(true);
            $table->boolean('dependency_stale')->default(false);
            $table->string('reason', 240)->nullable();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at', 6);
            $table->unique(['start_year', 'revision']);
            $table->index(['start_year', 'active']);
        });

        Schema::create('khums_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('closure_id')->unique()->constrained('khums_period_closures')->restrictOnDelete();
            $table->string('due_exact_rial', 40);
            $table->bigInteger('settled_rial')->default(0);
            $table->string('status', 24)->default('unpaid');
            $table->timestamp('created_at', 6);
        });

        Schema::create('khums_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assessment_id')->constrained('khums_assessments')->restrictOnDelete();
            $table->foreignId('payment_operation_id')->unique()->constrained('operations')->restrictOnDelete();
            $table->foreignId('source_account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('payment_date');
            $table->bigInteger('amount_rial');
            $table->string('method', 32);
            $table->string('evidence', 240);
            $table->string('idempotency_key', 100)->unique();
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at', 6);
        });

        Schema::create('khums_basis_carryovers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('closure_id')->constrained('khums_period_closures')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('status', 32);
            $table->bigInteger('eligible_rial');
            $table->string('rule_id', 32);
            $table->unique(['closure_id', 'account_id']);
        });
    }

    public function down(): void
    {
        foreach (['khums_basis_carryovers', 'khums_settlements', 'khums_assessments', 'khums_period_closures'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
