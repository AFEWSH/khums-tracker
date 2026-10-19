<?php

use App\Actions\Finance\FinanceInputException;
use App\Actions\Finance\KhumsPeriodCalendar;
use App\Actions\Finance\KhumsPeriodReport;
use App\Livewire\KhumsWorkspace;
use App\Models\User;
use Livewire\Livewire;

it('makes a future schedule change through an explicit transition without rewriting the first year', function (): void {
    $this->actingAs(User::factory()->create());
    $this->travelTo(new DateTimeImmutable('2026-10-19T10:00:00+00:00'));
    $calendar = app(KhumsPeriodCalendar::class);
    $first = $calendar->forYear(1405);
    $oldNext = $calendar->forYear(1406);
    $preview = $calendar->previewChange(1406, 6, 1, '00:00');
    expect($preview['new_start_at_utc'])->toBe($first['end_exclusive_at_utc'])
        ->and($preview['new_end_persian'])->toBe('1407-06-01');
    Livewire::test(KhumsWorkspace::class)
        ->set('scheduleStartYear', '۱۴۰۶')->set('scheduleEndMonth', '۶')
        ->set('scheduleEndDay', '۱')->set('scheduleEndClock', '۰۰:۰۰')
        ->call('previewScheduleChange')->assertSet('schedulePreview.new_end_persian', '1407-06-01');
    $calendar->approveChange(1406, 6, 1, '00:00', $preview['hash'], 'تغییر آزمایشی سررسید آینده', true);
    $next = $calendar->forYear(1406);
    $after = $calendar->forYear(1407);
    expect($calendar->forYear(1405))->toBe($first)
        ->and($next['start_at_utc'])->toBe($first['end_exclusive_at_utc'])
        ->and($next['end_exclusive_at_utc'])->not->toBe($oldNext['end_exclusive_at_utc'])
        ->and($next['schedule_version'])->toBe(2)
        ->and($after['start_at_utc'])->toBe($next['end_exclusive_at_utc'])
        ->and(fn () => $calendar->approveChange(1406, 6, 1, '00:00', $preview['hash'], 'تکرار تغییر آزمایشی', true))
        ->toThrow(FinanceInputException::class);
});

it('keeps a non-midnight future boundary out of a date-only final calculation', function (): void {
    $this->actingAs(User::factory()->create());
    $this->travelTo(new DateTimeImmutable('2026-10-19T10:00:00+00:00'));
    $calendar = app(KhumsPeriodCalendar::class);
    $preview = $calendar->previewChange(1406, 6, 1, '09:00');
    $calendar->approveChange(1406, 6, 1, '09:00', $preview['hash'], 'آزمون تغییر ساعت مرز آینده', true);
    $this->travelTo(new DateTimeImmutable($calendar->forYear(1406)['end_exclusive_at_utc']));
    $report = app(KhumsPeriodReport::class)->forYear(1406);
    expect($report['status'])->toBe('incomplete')
        ->and($report['issues'])->toContain('intraday_boundary_requires_review');
});
