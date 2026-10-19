<?php

use App\Actions\Finance\KhumsDueDrafts;
use App\Actions\Finance\KhumsPeriodCalendar;
use App\Actions\Finance\KhumsPeriodReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('creates one incomplete draft per overdue year without closing or assessing either', function (): void {
    $this->actingAs(User::factory()->create());
    $end = app(KhumsPeriodCalendar::class)->forYear(1406)['end_exclusive_at_utc'];
    $this->travelTo(new DateTimeImmutable($end));
    $drafts = app(KhumsDueDrafts::class);
    $first = $drafts->ensure();
    expect(array_keys($first))->toBe([1405, 1406])
        ->and($drafts->ensure())->toBe($first)
        ->and(DB::table('khums_period_reviews')->count())->toBe(2)
        ->and(DB::table('khums_period_reviews')->pluck('status')->all())->toBe(['incomplete', 'incomplete'])
        ->and(app(KhumsPeriodReport::class)->forYear(1406)['issues'])->toContain('previous_period_unresolved')
        ->and(DB::table('khums_period_closures')->count())->toBe(0)
        ->and(DB::table('khums_assessments')->count())->toBe(0);
});
