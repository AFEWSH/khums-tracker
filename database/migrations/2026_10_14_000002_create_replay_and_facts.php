<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projection_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trigger_operation_id')->constrained('operations')->restrictOnDelete();
            $table->date('from_effective_date');
            $table->char('input_hash', 64);
            $table->string('policy_version', 40);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', 6);
        });
        Schema::create('lot_allocation_projections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('projection_revision_id')->constrained()->restrictOnDelete();
            $table->foreignId('exit_operation_id')->constrained('operations')->restrictOnDelete();
            $table->foreignId('source_lot_id')->constrained('lots')->restrictOnDelete();
            $table->string('atoms', 80);
            $table->bigInteger('allocated_cost_rial');
            $table->unique(['projection_revision_id', 'exit_operation_id', 'source_lot_id'], 'lot_projection_unique');
        });
        Schema::create('replay_cash_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('projection_revision_id')->constrained()->restrictOnDelete();
            $table->foreignId('operation_id')->constrained('operations')->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->bigInteger('balance_after_rial');
            $table->bigInteger('protected_min_after_rial');
            $table->bigInteger('protected_max_after_rial');
            $table->unique(['projection_revision_id', 'operation_id', 'account_id'], 'replay_cash_position_unique');
        });
        Schema::create('replay_lot_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('projection_revision_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_id')->constrained()->restrictOnDelete();
            $table->string('remaining_atoms', 80);
            $table->bigInteger('remaining_cost_rial');
            $table->string('protected_min_atoms', 80);
            $table->string('protected_max_atoms', 80);
            $table->unique(['projection_revision_id', 'lot_id']);
        });
        Schema::create('replay_adjustments', function (Blueprint $table): void {
            $table->foreignId('adjustment_operation_id')->primary()->constrained('operations')->restrictOnDelete();
            $table->foreignId('projection_revision_id')->constrained()->restrictOnDelete();
            $table->foreignId('affected_operation_id')->constrained('operations')->restrictOnDelete();
            $table->bigInteger('cost_delta_rial');
        });
        Schema::create('lot_basis_adjustments', function (Blueprint $table): void {
            $table->foreignId('operation_id')->primary()->constrained('operations')->restrictOnDelete();
            $table->foreignId('lot_id')->constrained()->restrictOnDelete();
            $table->bigInteger('delta_rial');
        });
        Schema::create('asset_fact_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lot_id')->constrained()->restrictOnDelete();
            $table->date('valid_from')->nullable();
            $table->string('kind', 40);
            $table->string('value', 160)->nullable();
            $table->string('evidence_note', 240);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('supersedes_id')->nullable()->constrained('asset_fact_versions')->restrictOnDelete();
            $table->timestamp('recorded_at', 6);
        });
        Schema::create('amendment_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 40);
            $table->string('status', 24);
            $table->string('reason', 240);
            $table->json('proposal');
            $table->char('proposal_hash', 64);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_operation_id')->nullable()->unique()->constrained('operations')->restrictOnDelete();
            $table->timestamp('approved_at', 6)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['amendment_cases', 'asset_fact_versions', 'lot_basis_adjustments', 'replay_adjustments', 'replay_lot_positions', 'replay_cash_positions', 'lot_allocation_projections', 'projection_revisions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
