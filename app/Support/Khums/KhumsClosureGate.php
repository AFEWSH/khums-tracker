<?php

namespace App\Support\Khums;

/** Fail-closed preflight; authorization and atomic persistence remain separate. */
final class KhumsClosureGate
{
    public static function blockers(array $period, string $dueStatus, array $calculation, array $checks, bool $explicitApproval): array
    {
        $blockers = [];
        if ($period['boundary_status'] !== 'confirmed' || $period['end_exclusive_at_utc'] === null) {
            $blockers[] = 'boundary_unconfirmed';
        }
        if ($dueStatus !== 'due') {
            $blockers[] = 'period_not_due';
        }
        if (($calculation['purpose'] ?? null) !== 'period_report') {
            $blockers[] = 'new_period_report_required';
        }
        if (($calculation['status'] ?? null) !== 'computed'
            || ($calculation['total_due_exact_rial'] ?? null) === null
            || ($calculation['issues'] ?? []) !== []) {
            $blockers[] = 'calculation_incomplete';
        }
        foreach (['input_current', 'coverage_confirmed', 'dependencies_valid', 'rules_validated', 'backup_verified', 'snapshot_complete'] as $check) {
            if (($checks[$check] ?? false) !== true) {
                $blockers[] = $check.'_required';
            }
        }
        if (! $explicitApproval) {
            $blockers[] = 'explicit_approval_required';
        }

        return $blockers;
    }
}
