<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instruments', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->string('type', 24);
            $table->string('unit', 24);
            $table->unsignedTinyInteger('quantity_scale');
            $table->string('symbol', 32)->nullable();
            $table->unsignedSmallInteger('fineness_parts_per_10000')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('asset_opening_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('setup_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('instrument_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('quantity_atoms', 80);
            $table->string('protected_atoms', 80)->default('0');
            $table->bigInteger('opening_value_rial');
            $table->bigInteger('protected_basis_rial')->nullable();
            $table->string('evidence', 24)->default('unknown');
            $table->timestamps();
        });
        Schema::create('foreign_obligation_opening_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('setup_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('instrument_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('name', 120);
            $table->string('principal_atoms', 80);
            $table->bigInteger('opening_value_rial');
            $table->timestamps();
        });
        Schema::create('asset_accounts', function (Blueprint $table): void {
            $table->foreignId('account_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignId('instrument_id')->constrained()->restrictOnDelete();
            $table->foreignId('opening_item_id')->nullable()->unique()->constrained('asset_opening_items')->restrictOnDelete();
            $table->unique(['account_id', 'instrument_id']);
        });
        Schema::create('foreign_obligations', function (Blueprint $table): void {
            $table->foreignId('account_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignId('instrument_id')->constrained()->restrictOnDelete();
            $table->foreignId('opening_item_id')->unique()->constrained('foreign_obligation_opening_items')->restrictOnDelete();
        });
        Schema::create('quantity_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('instrument_id')->constrained()->restrictOnDelete();
            $table->string('signed_atoms', 80);
            $table->unsignedInteger('line_no');
            $table->unique(['operation_id', 'line_no']);
        });
        Schema::create('lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('acquisition_operation_id')->constrained('operations')->restrictOnDelete();
            $table->foreignId('opening_item_id')->nullable()->unique()->constrained('asset_opening_items')->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('instrument_id')->constrained()->restrictOnDelete();
            $table->string('acquired_atoms', 80);
            $table->string('remaining_atoms', 80);
            $table->bigInteger('cost_rial');
            $table->bigInteger('remaining_cost_rial');
            $table->bigInteger('historical_cost_rial')->nullable();
            $table->string('cost_basis_kind', 24);
            $table->date('acquired_date')->nullable();
            $table->string('acquisition_method', 24);
            $table->timestamps();
        });
        Schema::create('lot_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exit_operation_id')->constrained('operations')->restrictOnDelete();
            $table->foreignId('source_lot_id')->constrained('lots')->restrictOnDelete();
            $table->string('atoms', 80);
            $table->bigInteger('allocated_cost_rial');
            $table->unique(['exit_operation_id', 'source_lot_id']);
        });
        Schema::create('asset_origin_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_id')->constrained()->restrictOnDelete();
            $table->string('kind', 24);
            $table->string('protected_min_atoms', 80);
            $table->string('protected_max_atoms', 80);
            $table->bigInteger('protected_basis_rial')->nullable();
            $table->string('evidence', 24);
            $table->timestamps();
        });
        Schema::create('asset_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instrument_id')->constrained()->restrictOnDelete();
            $table->date('effective_date');
            $table->unsignedInteger('revision');
            $table->string('price_rial_per_unit', 40);
            $table->string('source_note', 240);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['instrument_id', 'effective_date', 'revision']);
        });
    }

    public function down(): void
    {
        foreach (['asset_prices', 'asset_origin_events', 'lot_allocations', 'lots', 'quantity_entries', 'foreign_obligations', 'asset_accounts', 'foreign_obligation_opening_items', 'asset_opening_items', 'instruments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
