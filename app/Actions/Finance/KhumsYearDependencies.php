<?php

namespace App\Actions\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Current, fully fingerprinted inputs inherited from the preceding year. */
final class KhumsYearDependencies
{
    public function forYear(int $year): array
    {
        Gate::authorize('access-app');
        $previous = $year === 1405 ? null : DB::table('khums_period_closures')
            ->where('start_year', $year - 1)->where('active', true)->first();
        $assessment = $previous === null ? null : DB::table('khums_assessments')
            ->where('closure_id', $previous->id)->first();
        $carryovers = $previous === null ? [] : DB::table('khums_basis_carryovers')
            ->where('closure_id', $previous->id)->orderBy('id')->get()
            ->map(fn ($row): array => (array) $row)->all();
        $resolutions = [];
        if ($previous !== null) {
            foreach (DB::table('user_decisions')->where('kind', 'khums_basis_resolved')->orderBy('id')->get() as $decision) {
                $payload = json_decode($decision->payload, true, flags: JSON_THROW_ON_ERROR);
                if (($payload['closure_id'] ?? null) === (int) $previous->id) {
                    $resolutions[] = ['id' => (int) $decision->id, 'payload' => $payload];
                }
            }
        }
        $issues = [];
        if ($year > 1405) {
            if ($previous === null || $previous->dependency_stale) {
                $issues[] = 'previous_period_unresolved';
            }
            foreach ($carryovers as $carryover) {
                if ($carryover['status'] !== 'resolved_protected') {
                    $issues[] = 'carryover_basis_requires_review';
                    break;
                }
            }
            if ($assessment === null || in_array($assessment->status, [
                'reconciliation_required', 'reconciliation_review', 'settled_excess_review',
            ], true)) {
                $issues[] = 'previous_assessment_requires_review';
            }
        }

        return [
            'previous_closure_id' => $previous === null ? null : (int) $previous->id,
            'previous_snapshot_hash' => $previous?->snapshot_hash,
            'previous_dependency_stale' => $previous === null ? null : (bool) $previous->dependency_stale,
            'assessment' => $assessment === null ? null : [
                'id' => (int) $assessment->id, 'due_exact_rial' => $assessment->due_exact_rial,
                'settled_rial' => (string) $assessment->settled_rial, 'status' => $assessment->status,
                'settlement_terms' => app(KhumsSettlementTerms::class)->forAssessment((int) $assessment->id),
            ],
            'carryovers' => $carryovers, 'basis_resolutions' => $resolutions,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /** All descendants, including years reached through an older dependent revision. */
    public function affectedClosureIds(int $closureId): array
    {
        Gate::authorize('access-app');
        $seen = [$closureId];
        $frontier = [$closureId];
        $affected = [];
        while ($frontier !== []) {
            $children = DB::table('khums_period_closures')->whereIn('depends_on_closure_id', $frontier)
                ->orderBy('start_year')->orderBy('id')->get(['id', 'active']);
            $frontier = [];
            foreach ($children as $child) {
                $id = (int) $child->id;
                if (in_array($id, $seen, true)) {
                    throw new FinanceInputException('Closed-year dependency chain contains a cycle.');
                }
                $seen[] = $id;
                $frontier[] = $id;
                if ($child->active) {
                    $affected[] = $id;
                }
            }
        }

        return $affected;
    }
}
