<?php

namespace App\Actions\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Stores an immutable review snapshot; never creates an assessment. */
final class KhumsPeriodReview
{
    public function history(): array
    {
        Gate::authorize('access-app');

        return DB::table('khums_period_reviews')->orderByDesc('id')->limit(30)
            ->get(['id', 'start_year', 'status', 'created_at', 'input_hash'])
            ->map(fn ($row): array => (array) $row)->all();
    }

    public function save(int $startYear, string $seenInputHash): int
    {
        Gate::authorize('access-app');
        if (! preg_match('/^[a-f0-9]{64}$/D', $seenInputHash)) {
            throw new FinanceInputException('Review a fresh period report before saving.');
        }

        return DB::transaction(function () use ($startYear, $seenInputHash): int {
            // Serialize against financial postings and other reviews without
            // manufacturing a financial revision for a read-only snapshot.
            DB::table('financial_state')->where('id', 1)->update(['updated_at' => now()]);
            $report = app(KhumsPeriodReport::class)->forYear($startYear);
            if (! hash_equals($report['input_hash'], $seenInputHash)
                || (int) DB::table('financial_state')->where('id', 1)->value('revision') !== $report['financial_revision']) {
                throw new FinanceInputException('Financial data changed; review the period again.');
            }

            $existing = DB::table('khums_period_reviews')->where('start_year', $startYear)
                ->where('input_hash', $seenInputHash)->first();
            if ($existing !== null) {
                return (int) $existing->id;
            }

            $snapshot = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $hash = hash('sha256', $snapshot);
            $id = DB::table('khums_period_reviews')->insertGetId([
                'start_year' => $startYear,
                'start_at_utc' => $report['period']['start_at_utc'],
                'end_exclusive_at_utc' => $report['period']['end_exclusive_at_utc'],
                'as_of_date' => $report['as_of_date'],
                'financial_revision' => $report['financial_revision'],
                'rule_manifest_hash' => $report['rule_manifest_hash'],
                'input_hash' => $seenInputHash, 'snapshot_hash' => $hash,
                'snapshot_json' => $snapshot, 'status' => $report['status'],
                'created_by' => auth()->id(), 'created_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => auth()->id(), 'action' => 'khums_period_review_saved',
                'facts_hash' => $hash, 'recorded_at' => now(),
            ]);

            return $id;
        });
    }

    public function read(int $id): array
    {
        Gate::authorize('access-app');
        $row = DB::table('khums_period_reviews')->where('id', $id)->first();
        if ($row === null || ! hash_equals($row->snapshot_hash, hash('sha256', $row->snapshot_json))) {
            throw new FinanceInputException('Review snapshot is missing or damaged.');
        }

        $snapshot = json_decode($row->snapshot_json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($snapshot)
            || ($snapshot['purpose'] ?? null) !== 'period_report'
            || ! in_array($snapshot['status'] ?? null, ['incomplete', 'computed'], true)
            || ($snapshot['status'] ?? null) !== $row->status
            || ! array_key_exists('total_due_exact_rial', $snapshot)
            || ($snapshot['status'] === 'incomplete' && $snapshot['total_due_exact_rial'] !== null)
            || ($snapshot['status'] === 'computed' && ($snapshot['total_due_exact_rial'] === null || $snapshot['issues'] !== []))
            || ($snapshot['input_hash'] ?? null) !== $row->input_hash
            || ($snapshot['financial_revision'] ?? null) !== (int) $row->financial_revision
            || ($snapshot['rule_manifest_hash'] ?? null) !== $row->rule_manifest_hash
            || ($snapshot['period']['start_at_utc'] ?? null) !== $row->start_at_utc
            || (int) substr((string) ($snapshot['period']['start_persian'] ?? ''), 0, 4) !== (int) $row->start_year
            || ($snapshot['period']['end_exclusive_at_utc'] ?? null) !== $row->end_exclusive_at_utc
            || ($snapshot['as_of_date'] ?? null) !== $row->as_of_date) {
            throw new FinanceInputException('Review snapshot does not match its recorded metadata.');
        }

        return $snapshot;
    }
}
