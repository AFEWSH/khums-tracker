<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class CoverageConfirmation
{
    public function preview(string $throughDate): array
    {
        Gate::authorize('access-app');
        EffectiveTime::dateOnly($throughDate);
        if ($throughDate < OpeningSetup::DATE || $throughDate > now()->toDateString()) {
            throw new InvalidArgumentException('Coverage date is outside the recorded period.');
        }
        $state = DB::table('financial_state')->where('id', 1)->first();
        if ($state->opening_confirmed_at === null) {
            throw new FinanceInputException('Confirm the opening first.');
        }
        $facts = DB::table('operations')->where('effective_date', '<=', $throughDate)
            ->where('type', '!=', 'replay_adjustment')->orderBy('effective_date')->orderBy('sequence')->orderBy('id')
            ->get(['id', 'type', 'effective_date', 'payload_hash']);
        $hash = hash('sha256', json_encode($facts->map(fn ($row) => [$row->id, $row->type, $row->effective_date, $row->payload_hash])->all(), JSON_THROW_ON_ERROR));

        return [
            'through_date' => $throughDate, 'revision' => (int) $state->revision,
            'operation_count' => $facts->count(), 'input_hash' => $hash,
            'latest_operation_date' => $facts->last()?->effective_date,
        ];
    }

    public function confirm(string $throughDate, int $seenRevision, string $idempotencyKey, bool $reconciled): int
    {
        Gate::authorize('access-app');
        if (! $reconciled || ! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $idempotencyKey)) {
            throw new InvalidArgumentException('Explicit reconciliation and a unique key are required.');
        }

        return DB::transaction(function () use ($throughDate, $seenRevision, $idempotencyKey): int {
            $existing = DB::table('user_decisions')->where('kind', 'coverage_confirmed')->get(['id', 'payload']);
            foreach ($existing as $decision) {
                $payload = json_decode($decision->payload, true, flags: JSON_THROW_ON_ERROR);
                if (($payload['idempotency_key'] ?? null) === $idempotencyKey) {
                    if (($payload['through_date'] ?? null) !== $throughDate) {
                        throw new FinanceInputException('Coverage key was used with a different date.');
                    }

                    return (int) $decision->id;
                }
            }
            $preview = $this->preview($throughDate);
            if ($preview['revision'] !== $seenRevision) {
                throw new FinanceInputException('Financial data changed; review coverage again.');
            }
            $lastDate = DB::table('user_decisions')->where('kind', 'coverage_confirmed')->orderByDesc('id')->get(['payload'])
                ->map(fn ($row) => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)['through_date'] ?? null)->first();
            if ($lastDate !== null && $throughDate < $lastDate) {
                throw new FinanceInputException('Coverage date cannot move backward.');
            }
            $updated = DB::table('financial_state')->where('id', 1)->where('revision', $seenRevision)
                ->update(['revision' => $seenRevision + 1, 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new FinanceInputException('Financial data changed; review coverage again.');
            }
            $id = DB::table('user_decisions')->insertGetId([
                'kind' => 'coverage_confirmed', 'target_type' => 'setup_session', 'target_id' => 1,
                'payload' => json_encode([
                    'through_date' => $throughDate, 'input_hash' => $preview['input_hash'],
                    'operation_count' => $preview['operation_count'], 'idempotency_key' => $idempotencyKey,
                    'meaning' => 'user_declared_entries_and_balances_reconciled',
                ], JSON_THROW_ON_ERROR),
                'actor_id' => auth()->id(), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => auth()->id(), 'action' => 'coverage_confirmed',
                'facts_hash' => $preview['input_hash'], 'recorded_at' => now(),
            ]);

            return $id;
        });
    }

    public function latest(): ?array
    {
        Gate::authorize('access-app');
        $row = DB::table('user_decisions')->where('kind', 'coverage_confirmed')->orderByDesc('id')->first();

        return $row === null ? null : json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
    }
}
