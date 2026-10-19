<?php

namespace App\Actions\Finance;

use App\Support\Khums\KhumsDraft;
use App\Support\Khums\KhumsYearSchedule;
use App\Support\Khums\RuleCatalog;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** A fresh, read-only period review; an incomplete review can never be closed. */
final class KhumsPeriodReport
{
    public function forYear(int $startYear): array
    {
        Gate::authorize('access-app');
        $period = app(KhumsPeriodCalendar::class)->forYear($startYear);
        $nowUtc = now('UTC')->format('Y-m-d\TH:i:s.u\Z');
        if (KhumsYearSchedule::dueStatus($period, $nowUtc) !== 'due') {
            throw new InvalidArgumentException('The khums period is not due.');
        }

        // With the approved midnight boundary, the preceding local date is the
        // final complete day of this period. Date-only operations on the new
        // 20 Mordad belong to the next period.
        $lastDate = (new DateTimeImmutable($period['end_exclusive_date'], new DateTimeZone('Asia/Tehran')))
            ->modify('-1 day')->format('Y-m-d');
        $simulator = app(KhumsSimulation::class);
        $simulation = $simulator->asOf($lastDate);
        $catalog = app(RuleCatalog::class);
        $decisions = DB::table('user_decisions')->where('kind', 'khums_component')
            ->orderBy('id')->get(['id', 'payload']);
        $byComponent = [];
        foreach ($decisions as $decision) {
            $payload = json_decode($decision->payload, true, flags: JSON_THROW_ON_ERROR);
            if (($payload['start_year'] ?? null) === $startYear
                && ($payload['base_hash'] ?? null) === $simulation['input_hash']
                && ($payload['rule_manifest_hash'] ?? null) === $catalog->manifestHash()) {
                $byComponent[$payload['component_id']] = ['id' => (int) $decision->id, 'payload' => $payload];
            }
        }
        $components = [];
        foreach ($simulation['items'] as $item) {
            $decision = $byComponent[$item['component_id']] ?? null;
            $components[] = [
                'component_id' => $item['component_id'], 'value_rial' => $item['value_rial'],
                'eligible_rial' => $decision['payload']['eligible_rial'] ?? null,
                'rule_id' => $decision['payload']['rule_id'] ?? null,
                'evidence_status' => $decision === null ? 'unknown' : 'confirmed',
            ];
        }
        $draft = KhumsDraft::evaluate($components, array_column($catalog->active(), 'id'), true);
        $nonComponentIssues = array_values(array_filter($simulation['issues'], static fn (string $issue): bool => in_array($issue, [
            'opening_not_confirmed', 'coverage_not_confirmed',
        ], true)));
        $issues = array_values(array_unique(array_merge($draft['issues'], $nonComponentIssues)));
        if ($period['local_clock'] !== '00:00') {
            $issues[] = 'intraday_boundary_requires_review';
        }
        foreach ($simulation['positions'] as $position) {
            if ($position['subtype'] === 'holding') {
                $issues[] = 'quantity_basis_requires_review:'.$position['component_id'];
            }
        }
        if ($startYear > 1405) {
            $previous = DB::table('khums_period_closures')->where('start_year', $startYear - 1)
                ->where('active', true)->first();
            if ($previous === null || $previous->dependency_stale) {
                $issues[] = 'previous_period_unresolved';
            } elseif (DB::table('khums_basis_carryovers')->where('closure_id', $previous->id)
                ->whereIn('status', ['assessed_unsettled', 'settled_basis_review'])->exists()) {
                $issues[] = 'carryover_basis_requires_review';
            }
        }
        $status = $issues === [] ? 'computed' : 'incomplete';
        $decisionIds = array_values(array_map(static fn (array $decision): int => $decision['id'], $byComponent));
        sort($decisionIds);

        return array_replace($simulation, [
            'purpose' => 'period_report', 'status' => $status,
            'period' => $period, 'as_of_date' => $lastDate,
            'items' => $draft['items'], 'issues' => $issues,
            'known_subtotal_exact_rial' => $draft['known_subtotal_exact_rial'],
            'total_due_exact_rial' => $status === 'computed' ? $draft['total_due_exact_rial'] : null,
            'simulation_input_hash' => $simulation['input_hash'],
            'component_decision_ids' => $decisionIds,
            'issue_labels' => array_values(array_unique(array_map(
                fn (string $issue): string => $simulator->label(explode(':', $issue, 2)[0]), $issues
            ))),
            'input_hash' => hash('sha256', json_encode([
                $period, $simulation['input_hash'], $catalog->manifestHash(), $decisionIds, $draft['items'], $issues,
            ], JSON_THROW_ON_ERROR)),
        ]);
    }
}
