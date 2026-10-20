<?php

namespace App\Actions\Finance;

use App\Support\Khums\KhumsClosureGate;
use App\Support\Khums\RuleCatalog;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** One atomic approval of a fresh, fully computed period; no payment is posted. */
final class KhumsPeriodClosure
{
    public function closeWithLocalBackup(int $reviewId, string $seenInputHash, bool $explicitApproval): int
    {
        Gate::authorize('access-app');
        if (! $explicitApproval) {
            throw new FinanceInputException('Explicit approval is required.');
        }
        $saved = app(KhumsPeriodReview::class)->read($reviewId);
        if ($saved['status'] !== 'computed' || ! hash_equals($saved['input_hash'], $seenInputHash)) {
            throw new FinanceInputException('Review a complete, current period before closing.');
        }
        $existing = DB::table('khums_period_closures')->where('review_id', $reviewId)->where('active', true)->first();
        if ($existing !== null && hash_equals($existing->input_hash, $seenInputHash)) {
            return (int) $existing->id;
        }
        $proof = app(KhumsBackupVerifier::class)->createForReview($reviewId);

        return $this->close($reviewId, $seenInputHash, $proof['path'], $proof['password'], true);
    }

    public function close(int $reviewId, string $seenInputHash, string $backupPath, string $backupPassword, bool $explicitApproval): int
    {
        Gate::authorize('access-app');
        if (! $explicitApproval || ! preg_match('/^[a-f0-9]{64}$/D', $seenInputHash)) {
            throw new FinanceInputException('Explicit approval of the reviewed input is required.');
        }
        $saved = app(KhumsPeriodReview::class)->read($reviewId);
        if (! hash_equals($saved['input_hash'], $seenInputHash) || $saved['status'] !== 'computed') {
            throw new FinanceInputException('Only a complete, reviewed period can be closed.');
        }
        $backupHash = app(KhumsBackupVerifier::class)->verifyReview($reviewId, $backupPath, $backupPassword);

        return DB::transaction(function () use ($reviewId, $seenInputHash, $backupHash): int {
            DB::table('financial_state')->where('id', 1)->update(['updated_at' => now()]);
            $saved = app(KhumsPeriodReview::class)->read($reviewId);
            $year = (int) substr($saved['period']['start_persian'], 0, 4);
            $existing = DB::table('khums_period_closures')->where('start_year', $year)->where('active', true)->first();
            if ($existing !== null) {
                if ((int) $existing->review_id === $reviewId && hash_equals($existing->input_hash, $seenInputHash)) {
                    return (int) $existing->id;
                }
                throw new FinanceInputException('This period is already closed; use an amendment.');
            }
            $fresh = app(KhumsPeriodReport::class)->forYear($year);
            if (! hash_equals($fresh['input_hash'], $seenInputHash)
                || ! hash_equals($saved['input_hash'], $seenInputHash)) {
                throw new FinanceInputException('Financial data or decisions changed; review the period again.');
            }
            $dependencies = $fresh['dependencies'];
            $catalog = app(RuleCatalog::class);
            $active = array_column($catalog->active(), 'id');
            $rulesValid = $fresh['rule_manifest_hash'] === $catalog->manifestHash();
            foreach ($fresh['items'] as $item) {
                if ($item['status'] !== 'computed' || ! in_array($item['rule_id'], $active, true)) {
                    $rulesValid = false;
                }
            }
            $source = $this->sourceSnapshot($fresh);
            $blockers = KhumsClosureGate::blockers(
                $fresh['period'], 'due', $fresh,
                [
                    'input_current' => true,
                    'coverage_confirmed' => ! in_array('coverage_not_confirmed', $fresh['issues'], true),
                    'dependencies_valid' => $dependencies['issues'] === [],
                    'rules_validated' => $rulesValid,
                    'backup_verified' => true,
                    'snapshot_complete' => $source !== [],
                ], true
            );
            if ($blockers !== []) {
                throw new FinanceInputException('Closure blocked: '.implode(', ', $blockers));
            }
            $due = BigDecimal::of($fresh['total_due_exact_rial'] ?? throw new InvalidArgumentException('Missing amount.'));
            if ($due->isNegative()) {
                throw new FinanceInputException('A negative assessment cannot be closed.');
            }
            $snapshot = json_encode([
                'schema' => 1, 'report' => $fresh, 'sources' => $source,
                'rule_manifest' => config('khums_rules'), 'backup_sha256' => $backupHash,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $snapshotHash = hash('sha256', $snapshot);
            $closureId = DB::table('khums_period_closures')->insertGetId([
                'start_year' => $year, 'revision' => 1, 'review_id' => $reviewId,
                'depends_on_closure_id' => $dependencies['previous_closure_id'],
                'input_hash' => $seenInputHash, 'snapshot_hash' => $snapshotHash,
                'snapshot_json' => $snapshot, 'due_exact_rial' => (string) $due,
                'backup_sha256' => $backupHash, 'active' => true,
                'approved_by' => auth()->id(), 'approved_at' => now(),
            ]);
            DB::table('khums_assessments')->insert([
                'closure_id' => $closureId, 'due_exact_rial' => (string) $due,
                'settled_rial' => 0, 'status' => $due->isZero() ? 'settled' : 'unpaid',
                'created_at' => now(),
            ]);
            foreach ($fresh['items'] as $item) {
                if (BigDecimal::of($item['eligible_rial'])->isZero()) {
                    continue;
                }
                DB::table('khums_basis_carryovers')->insert([
                    'closure_id' => $closureId,
                    'account_id' => (int) substr($item['component_id'], strlen('account:')),
                    'status' => 'assessed_unsettled',
                    'eligible_rial' => $item['eligible_rial'], 'rule_id' => $item['rule_id'],
                ]);
            }
            DB::table('audit_events')->insert([
                'actor_id' => auth()->id(), 'action' => 'khums_period_closed',
                'facts_hash' => $snapshotHash, 'recorded_at' => now(),
            ]);

            return $closureId;
        });
    }

    public function read(int $id): array
    {
        Gate::authorize('access-app');
        $row = DB::table('khums_period_closures')->where('id', $id)->first();
        if ($row === null || ! hash_equals($row->snapshot_hash, hash('sha256', $row->snapshot_json))) {
            throw new FinanceInputException('Closed period snapshot is missing or damaged.');
        }

        return json_decode($row->snapshot_json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function sourceSnapshot(array $report): array
    {
        $date = $report['as_of_date'];
        $operations = DB::table('operations')->where('effective_date', '<=', $date)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $ids = array_column($operations, 'id');
        $rows = [];
        foreach (['journal_lines', 'provenance_events', 'quantity_entries'] as $table) {
            $rows[$table] = $ids === [] ? [] : DB::table($table)->whereIn('operation_id', $ids)
                ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }
        $rows['operations'] = $operations;
        foreach (['opening_items', 'asset_opening_items', 'foreign_obligation_opening_items', 'accounts', 'instruments'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }
        $rows['lots'] = $ids === [] ? [] : DB::table('lots')->whereIn('acquisition_operation_id', $ids)
            ->orderBy('id')->get()->map(function ($row): array {
                $lot = (array) $row;
                unset($lot['remaining_atoms'], $lot['remaining_cost_rial']);

                return $lot;
            })->all();
        $rows['lot_allocations'] = $ids === [] ? [] : DB::table('lot_allocations')->whereIn('exit_operation_id', $ids)
            ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $rows['asset_origin_events'] = $ids === [] ? [] : DB::table('asset_origin_events')->whereIn('operation_id', $ids)
            ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $rows['asset_prices'] = DB::table('asset_prices')->where('effective_date', '<=', $date)
            ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $decisionIds = $report['component_decision_ids'];
        $rows['decisions'] = $decisionIds === [] ? [] : DB::table('user_decisions')->whereIn('id', $decisionIds)
            ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();

        return $rows;
    }
}
