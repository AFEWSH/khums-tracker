<?php

use App\Actions\Finance\AssetCatalog;
use App\Actions\Finance\AssetDisposal;
use App\Actions\Finance\CoverageConfirmation;
use App\Actions\Finance\FinanceInputException;
use App\Actions\Finance\KhumsAssetBasisTrace;
use App\Actions\Finance\KhumsBackupVerifier;
use App\Actions\Finance\KhumsBasisResolution;
use App\Actions\Finance\KhumsComponentDecision;
use App\Actions\Finance\KhumsPeriodClosure;
use App\Actions\Finance\KhumsPeriodReport;
use App\Actions\Finance\KhumsPeriodReview;
use App\Actions\Finance\KhumsSettlement;
use App\Actions\Finance\OpeningSetup;
use App\Livewire\KhumsWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('carries the reviewed market basis after settlement into a next-year sale without rewriting economic cost', function (): void {
    $this->actingAs(User::factory()->create());
    config()->set('khums_rules.rules', [
        ['id' => 'SYNTHETIC', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['منشأ']],
        ['id' => 'K01', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['پایه']],
    ]);
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'حساب مخمّس پرداخت', '100', '100', 'user_declared');
    $setup->addItem('bank', 'حساب دریافت فروش', '0', '0', 'unknown');
    $catalog = app(AssetCatalog::class);
    $instrument = $catalog->createInstrument('SETTLED-LOT', 'سهم تسویه ساختگی', 'stock', 0);
    $catalog->addOpeningItem($instrument, 'سبد تسویه ساختگی', '3', '80', '3', '100', 'user_declared');
    $setup->confirm((int) $setup->session()->revision, 1, 'khums-settled-lot-opening-001');
    $payer = (int) DB::table('accounts')->where('name', 'حساب مخمّس پرداخت')->value('id');
    $cash = (int) DB::table('accounts')->where('name', 'حساب دریافت فروش')->value('id');
    $holding = (int) DB::table('accounts')->where('name', 'سبد تسویه ساختگی')->value('id');
    $this->travelTo(new DateTimeImmutable('2027-08-10T20:30:00+00:00'));
    $catalog->recordPrice($instrument, '2027-08-10', '500', 'قیمت پایان سال ساختگی');
    $coverage = app(CoverageConfirmation::class);
    $cover = $coverage->preview('2027-08-10');
    $coverage->confirm('2027-08-10', $cover['revision'], 'khums-settled-lot-coverage-001', true);
    $reports = app(KhumsPeriodReport::class);
    $base = $reports->forYear(1405);
    $decisions = app(KhumsComponentDecision::class);
    $facts = ['منشأ' => 'بررسی ساختگی منشأ و افزایش واجد این قلم'];
    $decisions->confirm(1405, $payer, $base['component_input_hash'], '0', 'SYNTHETIC', $facts, 'khums-settled-lot-payer-001');
    $decisions->confirm(1405, $holding, $base['component_input_hash'], '500', 'SYNTHETIC', $facts, 'khums-settled-lot-holding-001');
    $report = $reports->forYear(1405);
    expect($report['total_due_exact_rial'])->toBe('100');
    $review = app(KhumsPeriodReview::class)->save(1405, $report['input_hash']);
    $backup = Mockery::mock(KhumsBackupVerifier::class);
    $backup->shouldReceive('verifyReview')->andReturn(str_repeat('a', 64));
    app()->instance(KhumsBackupVerifier::class, $backup);
    $closures = app(KhumsPeriodClosure::class);
    $closure = $closures->close($review, $report['input_hash'], '/synthetic/lot.khfb', 'synthetic-password', true);
    $assessment = (int) DB::table('khums_assessments')->where('closure_id', $closure)->value('id');
    app(KhumsSettlement::class)->pay($assessment, $payer, '2027-08-11', '100', 'from_other_protected',
        'رسید ساختگی از حساب مخمّس دیگر', 'khums-settled-lot-payment-001', true);
    DB::table('khums_period_closures')->where('id', $closure)->update(['dependency_stale' => true]);
    $decisionCount = DB::table('user_decisions')->count();
    expect(fn () => app(KhumsBasisResolution::class)->confirm($closure, $holding, 'K01', [
        'پایه' => 'تأیید ساختگی پایه با حفظ تمام مقدار دارایی',
    ], true))->toThrow(FinanceInputException::class);
    expect(DB::table('user_decisions')->count())->toBe($decisionCount)
        ->and(DB::table('khums_basis_carryovers')->where('closure_id', $closure)->where('account_id', $holding)->value('status'))
        ->toBe('settled_basis_review');
    DB::table('khums_period_closures')->where('id', $closure)->update(['dependency_stale' => false]);
    $basisDecision = app(KhumsBasisResolution::class)->confirm($closure, $holding, 'K01', [
        'پایه' => 'تأیید ساختگی پایه با حفظ تمام مقدار دارایی',
    ], true);
    $basis = json_decode(DB::table('user_decisions')->where('id', $basisDecision)->value('payload'), true);
    expect($basis['protected_basis_rial'])->toBe('1500')
        ->and($basis['lot_bases'][0]['covered_atoms'])->toBe('3');
    app(AssetDisposal::class)->sell([
        'source_id' => $holding, 'cash_id' => $cash, 'date' => '2027-09-01',
        'quantity' => '3', 'gross_toman' => '180', 'fee_toman' => '0',
        'idempotency_key' => 'khums-settled-lot-sale-001',
    ]);
    $this->travelTo(new DateTimeImmutable('2028-08-10T20:30:00+00:00'));
    $trace = app(KhumsAssetBasisTrace::class)->asOf('2028-08-10');
    expect($trace['sales'][0]['allocated_basis_exact_rial'])->toBe('1500')
        ->and($trace['sales'][0]['economic_cost_rial'])->toBe('800')
        ->and($trace['sales'][0]['increase_candidate_exact_rial'])->toBe('300');
    $cover = $coverage->preview('2028-08-10');
    $coverage->confirm('2028-08-10', $cover['revision'], 'khums-settled-lot-coverage-002', true);
    $next = $reports->forYear(1406);
    $decisions->confirm(1406, $payer, $next['component_input_hash'], '0', 'SYNTHETIC', $facts, 'khums-settled-lot-payer-002');
    $decisions->confirm(1406, $cash, $next['component_input_hash'], '300', 'SYNTHETIC', $facts, 'khums-settled-lot-proceeds-001');
    expect($reports->forYear(1406)['total_due_exact_rial'])->toBe('60')
        ->and($closures->read($closure)['report']['total_due_exact_rial'])->toBe('100')
        ->and((string) DB::table('lots')->value('cost_rial'))->toBe('800');
    Livewire::test(KhumsWorkspace::class)->call('showClosedPeriod', $closure)
        ->assertSee('نسخهٔ ثابت گزارش بسته‌شده')->assertSet('closedReport.total_due_exact_rial', '100');
});
