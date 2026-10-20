<?php

use App\Actions\Finance\CashBook;
use App\Actions\Finance\CoverageConfirmation;
use App\Actions\Finance\FinanceInputException;
use App\Actions\Finance\KhumsBackupVerifier;
use App\Actions\Finance\KhumsBasisResolution;
use App\Actions\Finance\KhumsComponentDecision;
use App\Actions\Finance\KhumsPeriodClosure;
use App\Actions\Finance\KhumsPeriodReport;
use App\Actions\Finance\KhumsPeriodReview;
use App\Actions\Finance\KhumsSettlement;
use App\Actions\Finance\OpeningSetup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('keeps the assessed asset intact when its khums is paid from a separately protected account', function (): void {
    $this->actingAs(User::factory()->create());
    config()->set('khums_rules.rules', [
        ['id' => 'SYNTHETIC', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['منشأ']],
        ['id' => 'K01', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['پایه']],
    ]);
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک مبنا', '100', '0', 'unknown');
    $setup->addItem('bank', 'بانک مخمّس دیگر', '100', '100', 'user_declared');
    $setup->confirm((int) $setup->session()->revision, 1, 'khums-other-protected-opening-001');
    $assessedAccountId = (int) DB::table('accounts')->where('name', 'بانک مبنا')->value('id');
    $protectedAccountId = (int) DB::table('accounts')->where('name', 'بانک مخمّس دیگر')->value('id');
    $this->travelTo(new DateTimeImmutable('2027-08-10T20:30:00+00:00'));
    $coverage = app(CoverageConfirmation::class);
    $preview = $coverage->preview('2027-08-10');
    $coverage->confirm('2027-08-10', $preview['revision'], 'khums-other-protected-coverage-001', true);
    $base = app(KhumsPeriodReport::class)->forYear(1405);
    $decisions = app(KhumsComponentDecision::class);
    $facts = ['منشأ' => 'شرح ساختگی منشأ حساب برای آزمون'];
    $decisions->confirm(1405, $assessedAccountId, $base['component_input_hash'], '1000', 'SYNTHETIC', $facts, 'khums-other-protected-first-001');
    $decisions->confirm(1405, $protectedAccountId, $base['component_input_hash'], '0', 'SYNTHETIC', $facts, 'khums-other-protected-second-001');
    $report = app(KhumsPeriodReport::class)->forYear(1405);
    expect($report['total_due_exact_rial'])->toBe('200');
    $reviewId = app(KhumsPeriodReview::class)->save(1405, $report['input_hash']);
    $backup = Mockery::mock(KhumsBackupVerifier::class);
    $backup->shouldReceive('verifyReview')->with($reviewId, '/private/other.khfb', 'test-password')
        ->andReturn(str_repeat('a', 64));
    app()->instance(KhumsBackupVerifier::class, $backup);
    $closureId = app(KhumsPeriodClosure::class)->close($reviewId, $report['input_hash'], '/private/other.khfb', 'test-password', true);
    $assessmentId = (int) DB::table('khums_assessments')->where('closure_id', $closureId)->value('id');
    app(KhumsSettlement::class)->pay($assessmentId, $protectedAccountId, '2027-08-11', '200', 'from_other_protected', 'رسید ساختگی از مال مخمّس دیگر', 'khums-other-protected-pay-001', true);
    $basisId = app(KhumsBasisResolution::class)->confirm($closureId, $assessedAccountId, 'K01', [
        'پایه' => 'شرح ساختگی پایه بدون خروج از این حساب',
    ], true);
    $basis = json_decode(DB::table('user_decisions')->where('id', $basisId)->value('payload'), true);
    expect($basis['protected_basis_rial'])->toBe('1000')
        ->and((string) DB::table('accounts')->where('id', $assessedAccountId)->value('balance_rial'))->toBe('1000')
        ->and((string) DB::table('accounts')->where('id', $protectedAccountId)->value('balance_rial'))->toBe('800')
        ->and((string) DB::table('accounts')->where('id', $protectedAccountId)->value('protected_min_rial'))->toBe('800');
    app(CashBook::class)->post([
        'type' => 'income', 'date' => '2027-08-12', 'amount_toman' => '10',
        'target_id' => $assessedAccountId, 'idempotency_key' => 'khums-later-income-001',
    ]);
    $this->travelTo(new DateTimeImmutable('2028-08-10T20:30:00+00:00'));
    $next = app(CoverageConfirmation::class)->preview('2028-08-10');
    app(CoverageConfirmation::class)->confirm('2028-08-10', $next['revision'], 'khums-other-next-coverage-001', true);
    $reportNext = app(KhumsPeriodReport::class)->forYear(1406);
    expect(fn () => app(KhumsComponentDecision::class)->confirm(1406, $assessedAccountId,
        $reportNext['component_input_hash'], '100', 'SYNTHETIC', $facts, 'khums-other-next-decision-001'))
        ->toThrow(FinanceInputException::class);
});
