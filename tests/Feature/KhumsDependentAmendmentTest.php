<?php

use App\Actions\Finance\CoverageConfirmation;
use App\Actions\Finance\FinanceInputException;
use App\Actions\Finance\KhumsBackupVerifier;
use App\Actions\Finance\KhumsComponentDecision;
use App\Actions\Finance\KhumsPeriodAmendment;
use App\Actions\Finance\KhumsPeriodCalendar;
use App\Actions\Finance\KhumsPeriodClosure;
use App\Actions\Finance\KhumsPeriodReport;
use App\Actions\Finance\KhumsPeriodReview;
use App\Actions\Finance\OpeningSetup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('invalidates every dependent year and repairs them in order with separately approved revisions', function (): void {
    $this->actingAs(User::factory()->create());
    config()->set('khums_rules.rules', [[
        'id' => 'SYNTHETIC', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['منشأ'],
    ]]);
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک زنجیره ساختگی', '100', '0', 'unknown');
    $setup->confirm((int) $setup->session()->revision, 1, 'khums-dependent-opening-001');
    $accountId = (int) DB::table('accounts')->where('name', 'بانک زنجیره ساختگی')->value('id');
    $lastPeriod = app(KhumsPeriodCalendar::class)->forYear(1407);
    $this->travelTo(new DateTimeImmutable($lastPeriod['end_exclusive_at_utc']));
    $lastDate = (new DateTimeImmutable($lastPeriod['end_exclusive_date']))->modify('-1 day')->format('Y-m-d');
    $coverage = app(CoverageConfirmation::class);
    $coveragePreview = $coverage->preview($lastDate);
    $coverage->confirm($lastDate, $coveragePreview['revision'], 'khums-dependent-coverage-001', true);
    $backup = Mockery::mock(KhumsBackupVerifier::class);
    $backup->shouldReceive('verifyReview')->andReturn(str_repeat('a', 64));
    app()->instance(KhumsBackupVerifier::class, $backup);

    $prepareReview = function (int $year, string $key) use ($accountId): array {
        $reports = app(KhumsPeriodReport::class);
        $base = $reports->forYear($year);
        app(KhumsComponentDecision::class)->confirm($year, $accountId, $base['component_input_hash'], '0', 'SYNTHETIC', [
            'منشأ' => 'اظهار ساختگی مستقل برای نسخه '.$key,
        ], $key);
        $report = $reports->forYear($year);
        expect($report['status'])->toBe('computed');

        return [app(KhumsPeriodReview::class)->save($year, $report['input_hash']), $report];
    };
    $closures = app(KhumsPeriodClosure::class);
    $originals = [];
    $reviews = [];
    $snapshots = [];
    foreach ([1405, 1406, 1407] as $year) {
        [$reviewId, $report] = $prepareReview($year, 'khums-dependent-initial-'.$year);
        $reviews[$year] = $reviewId;
        $originals[$year] = $closures->close($reviewId, $report['input_hash'], '/synthetic/backup.khfb', 'synthetic-password', true);
        $snapshots[$year] = $closures->read($originals[$year]);
    }
    $secondBeforeChange = app(KhumsPeriodReport::class)->forYear(1406);
    [$replacementReview] = $prepareReview(1405, 'khums-dependent-replace-1405');
    $amendments = app(KhumsPeriodAmendment::class);
    $diff = $amendments->preview($originals[1405], $replacementReview);
    expect($diff['dependent_closure_ids'])->toBe([$originals[1406], $originals[1407]]);
    $newFirst = $amendments->approve($originals[1405], $replacementReview, $diff['diff_hash'],
        'بازبینی ساختگی سال نخست زنجیره', '/synthetic/backup.khfb', 'synthetic-password', true);
    expect(DB::table('khums_period_closures')->whereIn('id', [$originals[1406], $originals[1407]])->pluck('dependency_stale')->all())
        ->toBe([1, 1])
        ->and(app(KhumsPeriodReport::class)->forYear(1406)['status'])->toBe('incomplete')
        ->and(fn () => $amendments->preview($originals[1406], $reviews[1406]))->toThrow(FinanceInputException::class);
    $secondAfterChange = app(KhumsPeriodReport::class)->forYear(1406);
    expect($secondAfterChange['simulation_input_hash'])->toBe($secondBeforeChange['simulation_input_hash'])
        ->and($secondAfterChange['component_input_hash'])->not->toBe($secondBeforeChange['component_input_hash'])
        ->and(fn () => app(KhumsComponentDecision::class)->confirm(1406, $accountId,
            $secondBeforeChange['component_input_hash'], '0', 'SYNTHETIC', ['منشأ' => 'تأیید ساختگی با وابستگی کهنه'],
            'khums-dependent-stale-1406'))->toThrow(FinanceInputException::class);
    [$secondReview] = $prepareReview(1406, 'khums-dependent-repair-1406');
    $secondDiff = $amendments->preview($originals[1406], $secondReview);
    expect($secondDiff['new_dependency_closure_id'])->toBe($newFirst);
    $newSecond = $amendments->approve($originals[1406], $secondReview, $secondDiff['diff_hash'],
        'ترمیم مستقل وابستگی سال دوم', '/synthetic/backup.khfb', 'synthetic-password', true);
    [$thirdReview] = $prepareReview(1407, 'khums-dependent-repair-1407');
    $thirdDiff = $amendments->preview($originals[1407], $thirdReview);
    $newThird = $amendments->approve($originals[1407], $thirdReview, $thirdDiff['diff_hash'],
        'ترمیم مستقل وابستگی سال سوم', '/synthetic/backup.khfb', 'synthetic-password', true);
    expect(DB::table('khums_period_closures')->where('id', $newSecond)->value('depends_on_closure_id'))->toBe($newFirst)
        ->and(DB::table('khums_period_closures')->where('id', $newThird)->value('depends_on_closure_id'))->toBe($newSecond)
        ->and(DB::table('khums_period_closures')->where('active', true)->pluck('dependency_stale')->all())->toBe([0, 0, 0]);
    foreach ($originals as $year => $id) {
        expect($closures->read($id))->toBe($snapshots[$year]);
    }
});
