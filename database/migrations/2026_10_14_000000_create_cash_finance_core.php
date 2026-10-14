<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_state', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamp('opening_confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('setup_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('status', 16)->default('draft');
            $table->unsignedBigInteger('revision')->default(0);
            $table->string('step', 32)->default('settings');
            $table->date('opening_date')->default('2026-08-11');
            $table->boolean('previous_khums_settled')->default(false);
            $table->string('confirm_key', 100)->nullable()->unique();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('opening_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('setup_session_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('name', 120);
            $table->bigInteger('amount_rial');
            $table->bigInteger('protected_rial');
            $table->string('evidence', 24)->default('unknown');
            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->string('class', 16);
            $table->string('subtype', 24);
            $table->bigInteger('balance_rial')->default(0);
            $table->bigInteger('protected_min_rial')->default(0);
            $table->bigInteger('protected_max_rial')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('kind', 16);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'name']);
        });

        Schema::create('operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('type', 24);
            $table->date('effective_date')->index();
            $table->timestamp('effective_at', 6)->nullable();
            $table->string('time_precision', 8)->default('date');
            $table->string('boundary_relation', 8)->default('unknown');
            $table->unsignedBigInteger('sequence');
            $table->string('idempotency_key', 100)->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('operations')->restrictOnDelete();
            $table->string('reason', 240)->nullable();
            $table->timestamp('posted_at', 6);
            $table->timestamps();
            $table->unique(['effective_date', 'sequence']);
        });

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('side', 8);
            $table->bigInteger('amount_rial');
            $table->unsignedInteger('line_no');
            $table->unique(['operation_id', 'line_no']);
        });

        Schema::create('provenance_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('origin_kind', 24);
            $table->bigInteger('balance_after_rial');
            $table->bigInteger('protected_min_after_rial');
            $table->bigInteger('protected_max_after_rial');
            $table->string('evidence', 24)->default('unknown');
            $table->timestamps();
        });

        Schema::create('obligations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('counterparty', 120)->nullable();
            $table->date('due_date')->nullable();
            $table->string('purpose', 240)->nullable();
            $table->timestamps();
        });

        Schema::create('user_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 48);
            $table->string('target_type', 40);
            $table->unsignedBigInteger('target_id');
            $table->json('payload');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at');
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 48);
            $table->char('facts_hash', 64);
            $table->timestamp('recorded_at', 6);
        });

        DB::table('financial_state')->insert(['id' => 1, 'revision' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        foreach (['audit_events', 'user_decisions', 'obligations', 'provenance_events', 'journal_lines', 'operations', 'categories', 'accounts', 'opening_items', 'setup_sessions', 'financial_state'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
