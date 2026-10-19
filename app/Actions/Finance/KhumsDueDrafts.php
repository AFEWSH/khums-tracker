<?php

namespace App\Actions\Finance;

use Illuminate\Support\Facades\Gate;

/** Creates idempotent review drafts for due years, never a closure or payment. */
final class KhumsDueDrafts
{
    public function ensure(): array
    {
        Gate::authorize('access-app');
        $ids = [];
        $now = now('UTC')->format('Y-m-d\TH:i:s.u\Z');
        foreach (app(KhumsPeriodCalendar::class)->pendingPeriods($now) as $candidate) {
            $year = (int) substr($candidate['period']['start_persian'], 0, 4);
            $report = app(KhumsPeriodReport::class)->forYear($year);
            if ($report['status'] !== 'incomplete') {
                continue;
            }
            $ids[$year] = app(KhumsPeriodReview::class)->save($year, $report['input_hash']);
        }

        return $ids;
    }
}
