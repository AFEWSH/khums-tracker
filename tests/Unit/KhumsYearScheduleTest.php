<?php

use App\Support\Khums\KhumsClosureGate;
use App\Support\Khums\KhumsYearSchedule;

it('creates the first Persian-year period without inventing a clock', function (): void {
    $period = KhumsYearSchedule::period(1405);
    expect($period['start_date'])->toBe('2026-08-11')
        ->and($period['end_exclusive_date'])->toBe('2027-08-11')
        ->and($period['start_at_utc'])->toBeNull()
        ->and($period['end_exclusive_at_utc'])->toBeNull()
        ->and($period['boundary_status'])->toBe('unconfirmed')
        ->and(KhumsYearSchedule::dueStatus($period, '2027-08-10T20:29:59.000000Z'))->toBe('not_due')
        ->and(KhumsYearSchedule::dueStatus($period, '2027-08-10T20:30:00.000000Z'))->toBe('boundary_day_unconfirmed')
        ->and(KhumsYearSchedule::dueStatus($period, '2027-08-11T20:30:00.000000Z'))->toBe('past_due_boundary_unconfirmed');
});

it('uses the owner-confirmed midnight boundary in Tehran as the exclusive end', function (): void {
    $first = KhumsYearSchedule::confirmedPeriod(1405);
    $second = KhumsYearSchedule::confirmedPeriod(1406);
    expect($first['local_clock'])->toBe('00:00')
        ->and($first['boundary_status'])->toBe('confirmed')
        ->and($first['start_at_utc'])->toBe('2026-08-10T20:30:00.000000Z')
        ->and($first['end_exclusive_at_utc'])->toBe('2027-08-10T20:30:00.000000Z')
        ->and($second['start_at_utc'])->toBe($first['end_exclusive_at_utc'])
        ->and(KhumsYearSchedule::dueStatus($first, '2027-08-10T20:29:59.999999Z'))->toBe('not_due')
        ->and(KhumsYearSchedule::dueStatus($first, '2027-08-10T20:30:00.000000Z'))->toBe('due');
});

it('resolves an explicitly confirmed Tehran clock to UTC and keeps the end exclusive', function (): void {
    $period = KhumsYearSchedule::period(1405, '12:30');
    expect($period['start_at_utc'])->toBe('2026-08-11T09:00:00.000000Z')
        ->and($period['end_exclusive_at_utc'])->toBe('2027-08-11T09:00:00.000000Z')
        ->and(KhumsYearSchedule::dueStatus($period, '2027-08-11T08:59:59.999999Z'))->toBe('not_due')
        ->and(KhumsYearSchedule::dueStatus($period, '2027-08-11T09:00:00.000000Z'))->toBe('due');
});

it('keeps the suggested nine o’clock planning time separate from a confirmed boundary', function (): void {
    $period = KhumsYearSchedule::scenarioPeriod(1405, '09:00');
    $checks = array_fill_keys(['input_current', 'coverage_confirmed', 'dependencies_valid', 'rules_validated', 'backup_verified', 'snapshot_complete'], true);
    $complete = ['purpose' => 'period_report', 'status' => 'computed', 'total_due_exact_rial' => '6', 'issues' => []];

    expect($period['local_clock'])->toBe('09:00')
        ->and($period['end_exclusive_at_utc'])->toBe('2027-08-11T05:30:00.000000Z')
        ->and($period['boundary_status'])->toBe('scenario')
        ->and(KhumsYearSchedule::dueStatus($period, '2027-08-11T05:30:00.000000Z'))->toBe('boundary_day_unconfirmed')
        ->and(KhumsClosureGate::blockers($period, 'boundary_day_unconfirmed', $complete, $checks, true))
        ->toContain('boundary_unconfirmed', 'period_not_due');
});

it('uses Persian calendar conversion across later leap years rather than adding 365 days', function (): void {
    $first = KhumsYearSchedule::period(1406);
    $next = KhumsYearSchedule::period(1407);
    $leap = KhumsYearSchedule::period(1408);
    expect($first['end_exclusive_date'])->toBe($next['start_date'])
        ->and($first['end_exclusive_persian'])->toBe('1407-05-20')
        ->and($next['end_exclusive_persian'])->toBe('1408-05-20')
        ->and($leap['start_date'])->toBe('2029-08-10')
        ->and($leap['end_exclusive_date'])->toBe('2030-08-11');
});

it('finds missed periods in order without promoting an unknown clock to a due instant', function (): void {
    expect(KhumsYearSchedule::pendingPeriods('2027-08-10T20:29:59.000000Z', null))->toBe([]);
    $boundaryDay = KhumsYearSchedule::pendingPeriods('2027-08-10T20:30:00.000000Z', null);
    expect($boundaryDay)->toHaveCount(1)
        ->and($boundaryDay[0]['due_status'])->toBe('boundary_day_unconfirmed')
        ->and($boundaryDay[0]['period']['end_exclusive_at_utc'])->toBeNull();

    $missed = KhumsYearSchedule::pendingPeriods('2029-09-01T00:00:00.000000Z', null);
    expect($missed)->toHaveCount(3)
        ->and(array_column(array_column($missed, 'period'), 'start_persian'))->toBe(['1405-05-20', '1406-05-20', '1407-05-20'])
        ->and(array_column($missed, 'due_status'))->toBe([
            'past_due_boundary_unconfirmed', 'past_due_boundary_unconfirmed', 'past_due_boundary_unconfirmed',
        ]);
    $confirmed = KhumsYearSchedule::pendingPeriods('2027-08-11T05:29:59.000000Z', '09:00');
    expect($confirmed)->toBe([]);
    $confirmed = KhumsYearSchedule::pendingPeriods('2027-08-11T05:30:00.000000Z', '09:00');
    expect($confirmed)->toHaveCount(1)->and($confirmed[0]['due_status'])->toBe('due');
    expect(KhumsYearSchedule::pendingPeriods('2027-08-10T20:30:00.000000Z'))->toHaveCount(1)
        ->and(KhumsYearSchedule::pendingPeriods('2027-08-10T20:30:00.000000Z')[0]['due_status'])->toBe('due');
});

it('never closes a simulation or an incomplete report even with an approval flag', function (): void {
    $period = KhumsYearSchedule::period(1405, '12:30');
    $complete = ['purpose' => 'period_report', 'status' => 'computed', 'total_due_exact_rial' => '6', 'issues' => []];
    $checks = array_fill_keys(['input_current', 'coverage_confirmed', 'dependencies_valid', 'rules_validated', 'backup_verified', 'snapshot_complete'], true);
    expect(KhumsClosureGate::blockers($period, 'due', ['purpose' => 'simulation'] + $complete, $checks, true))
        ->toContain('new_period_report_required');
    expect(KhumsClosureGate::blockers($period, 'due', $complete, $checks, false))
        ->toContain('explicit_approval_required');
    expect(KhumsClosureGate::blockers(KhumsYearSchedule::period(1405), 'past_due_boundary_unconfirmed', $complete, $checks, true))
        ->toContain('boundary_unconfirmed', 'period_not_due');
    expect(KhumsClosureGate::blockers($period, 'due', array_replace($complete, ['issues' => ['missing']]), $checks, true))
        ->toContain('calculation_incomplete');
    expect(KhumsClosureGate::blockers($period, 'due', $complete, [], true))
        ->toContain('input_current_required', 'backup_verified_required');
    expect(KhumsClosureGate::blockers($period, 'due', $complete, $checks, true))->toBe([]);
});
