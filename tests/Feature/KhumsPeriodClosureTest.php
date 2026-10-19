<?php

use App\Actions\Finance\CoverageConfirmation;
use App\Actions\Finance\FinanceInputException;
use App\Actions\Finance\KhumsAmendmentReconciliation;
use App\Actions\Finance\KhumsBackupVerifier;
use App\Actions\Finance\KhumsBasisResolution;
use App\Actions\Finance\KhumsComponentDecision;
use App\Actions\Finance\KhumsPeriodAmendment;
use App\Actions\Finance\KhumsPeriodClosure;
use App\Actions\Finance\KhumsPeriodReport;
use App\Actions\Finance\KhumsPeriodReview;
use App\Actions\Finance\KhumsSettlement;
use App\Actions\Finance\OpeningSetup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('closes a fully reviewed synthetic period once and leaves payment separate', function (): void {
    $this->actingAs(User::factory()->create());
    config()->set('khums_rules.rules', [
        ['id' => 'SYNTHETIC', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['منشأ']],
        ['id' => 'K01', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['پایه']],
    ]);
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک بستن ساختگی', '100', '0', 'unknown');
    $setup->confirm((int) $setup->session()->revision, 1, 'khums-closure-opening-001');
    $accountId = (int) DB::table('accounts')->where('name', 'بانک بستن ساختگی')->value('id');
    $this->travelTo(new DateTimeImmutable('2027-08-10T20:30:00+00:00'));
    $coverage = app(CoverageConfirmation::class);
    $preview = $coverage->preview('2027-08-10');
    $coverage->confirm('2027-08-10', $preview['revision'], 'khums-closure-coverage-001', true);
    $report = app(KhumsPeriodReport::class)->forYear(1405);
    app(KhumsComponentDecision::class)->confirm(1405, $accountId, $report['simulation_input_hash'], '1000', 'SYNTHETIC', [
        'منشأ' => 'اظهار ساختگی برای آزمون حساب',
    ], 'khums-closure-component-001');
    $computed = app(KhumsPeriodReport::class)->forYear(1405);
    $reviewId = app(KhumsPeriodReview::class)->save(1405, $computed['input_hash']);
    $backup = Mockery::mock(KhumsBackupVerifier::class);
    $backup->shouldReceive('verifyReview')->with($reviewId, '/private/test.khfb', 'test-password')
        ->andReturn(str_repeat('a', 64));
    app()->instance(KhumsBackupVerifier::class, $backup);
    $closures = app(KhumsPeriodClosure::class);
    expect(fn () => $closures->close($reviewId, $computed['input_hash'], '/private/test.khfb', 'test-password', false))
        ->toThrow(FinanceInputException::class);
    $id = $closures->close($reviewId, $computed['input_hash'], '/private/test.khfb', 'test-password', true);
    expect($closures->close($reviewId, $computed['input_hash'], '/private/test.khfb', 'test-password', true))->toBe($id)
        ->and(DB::table('khums_period_closures')->count())->toBe(1)
        ->and(DB::table('khums_assessments')->where('closure_id', $id)->value('due_exact_rial'))->toBe('200')
        ->and(DB::table('khums_assessments')->where('closure_id', $id)->value('status'))->toBe('unpaid')
        ->and(DB::table('khums_settlements')->count())->toBe(0)
        ->and(DB::table('khums_basis_carryovers')->where('closure_id', $id)->value('status'))->toBe('assessed_unsettled')
        ->and($closures->read($id)['report']['input_hash'])->toBe($computed['input_hash']);
    $rules = config('khums_rules.rules');
    config()->set('khums_rules.rules', []);
    expect($closures->read($id)['report']['total_due_exact_rial'])->toBe('200')
        ->and(app(KhumsPeriodReport::class)->forYear(1405)['status'])->toBe('incomplete');
    config()->set('khums_rules.rules', $rules);

    $assessmentId = (int) DB::table('khums_assessments')->where('closure_id', $id)->value('id');
    $settlements = app(KhumsSettlement::class);
    expect(fn () => $settlements->pay($assessmentId, $accountId, '2027-08-10', '100', 'from_same_asset', 'رسید ساختگی پیش از موعد', 'khums-settlement-too-early-001', true))
        ->toThrow(FinanceInputException::class)
        ->and(fn () => $settlements->pay($assessmentId, $accountId, '2027-08-12', '100', 'from_same_asset', 'رسید ساختگی آینده', 'khums-settlement-future-001', true))
        ->toThrow(FinanceInputException::class);
    expect(fn () => $settlements->pay($assessmentId, $accountId, '2027-08-11', '100', 'from_other_protected', 'ادعای ساختگی بدون پشتوانه', 'khums-settlement-invalid-origin-001', true))
        ->toThrow(FinanceInputException::class);
    $first = $settlements->pay($assessmentId, $accountId, '2027-08-11', '100', 'from_same_asset', 'رسید ساختگی پرداخت اول', 'khums-settlement-first-001', true);
    expect($settlements->pay($assessmentId, $accountId, '2027-08-11', '100', 'from_same_asset', 'رسید ساختگی پرداخت اول', 'khums-settlement-first-001', true))->toBe($first)
        ->and(DB::table('khums_assessments')->where('id', $assessmentId)->value('status'))->toBe('partially_paid')
        ->and(DB::table('khums_assessments')->where('id', $assessmentId)->value('settled_rial'))->toBe(100)
        ->and(DB::table('khums_basis_carryovers')->where('closure_id', $id)->value('status'))->toBe('assessed_unsettled')
        ->and(fn () => $settlements->pay($assessmentId, $accountId, '2027-08-11', '101', 'from_same_asset', 'رسید ساختگی پرداخت دوم', 'khums-settlement-over-001', true))->toThrow(FinanceInputException::class);
    $settlements->pay($assessmentId, $accountId, '2027-08-11', '100', 'from_same_asset', 'رسید ساختگی پرداخت دوم', 'khums-settlement-second-001', true);
    expect(DB::table('khums_assessments')->where('id', $assessmentId)->value('status'))->toBe('settled')
        ->and(DB::table('khums_basis_carryovers')->where('closure_id', $id)->value('status'))->toBe('settled_basis_review')
        ->and(DB::table('khums_settlements')->count())->toBe(2)
        ->and(DB::table('operations')->where('type', 'expense')->count())->toBe(2);
    $basisId = app(KhumsBasisResolution::class)->confirm($id, $accountId, 'K01', [
        'پایه' => 'بررسی ساختگی مانده پس از دو پرداخت',
    ], true);
    $basis = json_decode(DB::table('user_decisions')->where('id', $basisId)->value('payload'), true);
    expect($basis['protected_basis_rial'])->toBe('800')
        ->and(DB::table('khums_basis_carryovers')->where('closure_id', $id)->value('status'))->toBe('resolved_protected');

    $this->travelTo(new DateTimeImmutable('2028-08-10T20:30:00+00:00'));
    $nextCoverage = $coverage->preview('2028-08-10');
    $coverage->confirm('2028-08-10', $nextCoverage['revision'], 'khums-next-coverage-001', true);
    $nextBase = app(KhumsPeriodReport::class)->forYear(1406);
    expect(fn () => app(KhumsComponentDecision::class)->confirm(1406, $accountId, $nextBase['simulation_input_hash'], '1', 'SYNTHETIC', [
        'منشأ' => 'افزایش ساختگی ناسازگار با پایه',
    ], 'khums-next-invalid-001'))->toThrow(FinanceInputException::class);
    app(KhumsComponentDecision::class)->confirm(1406, $accountId, $nextBase['simulation_input_hash'], '0', 'SYNTHETIC', [
        'منشأ' => 'بدون درآمد جدید و با پایه مخمّس آزمون',
    ], 'khums-next-component-001');
    $nextComputed = app(KhumsPeriodReport::class)->forYear(1406);
    expect($nextComputed['total_due_exact_rial'])->toBe('0');
    $nextReviewId = app(KhumsPeriodReview::class)->save(1406, $nextComputed['input_hash']);
    $nextBackup = Mockery::mock(KhumsBackupVerifier::class);
    $nextBackup->shouldReceive('verifyReview')->with($nextReviewId, '/private/next.khfb', 'test-password')
        ->andReturn(str_repeat('e', 64));
    app()->instance(KhumsBackupVerifier::class, $nextBackup);
    $nextClosureId = $closures->close($nextReviewId, $nextComputed['input_hash'], '/private/next.khfb', 'test-password', true);
    expect(DB::table('khums_period_closures')->where('id', $nextClosureId)->value('depends_on_closure_id'))->toBe($id)
        ->and(DB::table('khums_assessments')->where('closure_id', $nextClosureId)->value('due_exact_rial'))->toBe('0');

    $baseAfterPayment = app(KhumsPeriodReport::class)->forYear(1405);
    app(KhumsComponentDecision::class)->confirm(1405, $accountId, $baseAfterPayment['simulation_input_hash'], '800', 'SYNTHETIC', [
        'منشأ' => 'اصلاح اظهار ساختگی برای آزمون',
    ], 'khums-amendment-component-001');
    $revised = app(KhumsPeriodReport::class)->forYear(1405);
    $revisedReviewId = app(KhumsPeriodReview::class)->save(1405, $revised['input_hash']);
    $amendment = app(KhumsPeriodAmendment::class);
    $diff = $amendment->preview($id, $revisedReviewId);
    expect($diff['delta_exact_rial'])->toBe('-40')
        ->and($diff['prior_settled_rial'])->toBe('200');
    $amendmentBackup = Mockery::mock(KhumsBackupVerifier::class);
    $amendmentBackup->shouldReceive('verifyReview')->with($revisedReviewId, '/private/amend.khfb', 'test-password')
        ->andReturn(str_repeat('b', 64));
    app()->instance(KhumsBackupVerifier::class, $amendmentBackup);
    $newId = $amendment->approve($id, $revisedReviewId, $diff['diff_hash'], 'اصلاح منشأ ساختگی برای آزمون', '/private/amend.khfb', 'test-password', true);
    expect(DB::table('khums_period_closures')->where('id', $id)->value('active'))->toBe(0)
        ->and($amendment->approve($id, $revisedReviewId, $diff['diff_hash'], 'اصلاح منشأ ساختگی برای آزمون', '/private/amend.khfb', 'test-password', true))->toBe($newId)
        ->and(DB::table('khums_period_closures')->where('id', $newId)->value('revision'))->toBe(2)
        ->and(DB::table('khums_assessments')->where('closure_id', $newId)->value('due_exact_rial'))->toBe('160')
        ->and(DB::table('khums_assessments')->where('closure_id', $newId)->value('status'))->toBe('reconciliation_required')
        ->and(DB::table('khums_settlements')->count())->toBe(2)
        ->and($closures->read($id)['report']['total_due_exact_rial'])->toBe('200')
        ->and($closures->read($newId)['report']['total_due_exact_rial'])->toBe('160')
        ->and(DB::table('khums_period_closures')->where('id', $nextClosureId)->value('dependency_stale'))->toBe(1)
        ->and($closures->read($nextClosureId)['report']['total_due_exact_rial'])->toBe('0');
    $reconciliation = app(KhumsAmendmentReconciliation::class);
    $reconciliationPreview = $reconciliation->preview($newId);
    expect($reconciliationPreview['allocated_rial'])->toBe('160')
        ->and($reconciliationPreview['excess_unallocated_rial'])->toBe('40');
    $reconciledId = $reconciliation->approve($newId, $reconciliationPreview['hash'], 'تطبیق ساختگی پرداخت‌های قبلی', true);
    expect(DB::table('khums_assessments')->where('closure_id', $newId)->value('status'))->toBe('settled_excess_review')
        ->and($reconciliation->approve($newId, $reconciliationPreview['hash'], 'تطبیق ساختگی پرداخت‌های قبلی', true))->toBe($reconciledId)
        ->and(DB::table('khums_settlements')->count())->toBe(2)
        ->and(DB::table('operations')->where('type', 'expense')->count())->toBe(2);
});
