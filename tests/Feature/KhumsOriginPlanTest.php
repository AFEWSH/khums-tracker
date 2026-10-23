<?php

use App\Actions\Finance\AssetCatalog;
use App\Actions\Finance\AssetDisposal;
use App\Actions\Finance\AssetTrading;
use App\Actions\Finance\CashBook;
use App\Actions\Finance\CoverageConfirmation;
use App\Actions\Finance\FinanceInputException;
use App\Actions\Finance\KhumsAssetBasisTrace;
use App\Actions\Finance\KhumsBackupVerifier;
use App\Actions\Finance\KhumsBasisResolution;
use App\Actions\Finance\KhumsComponentDecision;
use App\Actions\Finance\KhumsOriginPlan;
use App\Actions\Finance\KhumsPeriodClosure;
use App\Actions\Finance\KhumsPeriodReport;
use App\Actions\Finance\KhumsPeriodReview;
use App\Actions\Finance\KhumsSettlement;
use App\Actions\Finance\OpeningSetup;
use App\Livewire\KhumsWorkspace;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('keeps origin plans private', function (): void {
    expect(fn () => app(KhumsOriginPlan::class)->preview('2027-08-10'))->toThrow(AuthorizationException::class);
});

it('never executes origin plans with the real inactive rule catalog', function (): void {
    $this->actingAs(User::factory()->create());
    expect(fn () => app(KhumsOriginPlan::class)->confirm(OpeningSetup::DATE, str_repeat('a', 64), [], [], true))
        ->toThrow(LogicException::class)
        ->and(DB::table('user_decisions')->where('kind', 'khums_origin_plan')->count())->toBe(0);
});

it('neutralizes an exactly matching adjacent reversal without deleting or counting its protected spending', function (): void {
    $this->actingAs(User::factory()->create());
    $this->travelTo(new DateTimeImmutable('2027-08-10T20:30:00+00:00'));
    config()->set('khums_rules.rules', [
        ['id' => 'K01', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['پایه']],
        ['id' => 'K12', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['اختلاط']],
    ]);
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک برگشت منشأ ساختگی', '100', '100', 'user_declared');
    $setup->confirm((int) $setup->session()->revision, 1, 'origin-neutral-opening-001');
    $account = (int) DB::table('accounts')->where('name', 'بانک برگشت منشأ ساختگی')->value('id');
    $book = app(CashBook::class);
    $expense = $book->post(['type' => 'expense', 'source_id' => $account, 'date' => '2027-01-01',
        'amount_toman' => '20', 'idempotency_key' => 'origin-neutral-expense-001']);
    $reversal = $book->reverseLatest($expense, 'برگشت کامل ساختگی هزینهٔ ثبت‌شده', 'origin-neutral-reversal-001');
    $book->post(['type' => 'income', 'target_id' => $account, 'date' => '2027-01-02',
        'amount_toman' => '10', 'idempotency_key' => 'origin-neutral-income-001']);
    $plans = app(KhumsOriginPlan::class);
    $preview = $plans->preview('2027-08-10');
    $facts = array_fill_keys($plans->factLabels(), 'شواهد ساختگی برگشت کامل بدون فرض مصرف اصل مخمّس');
    $plans->confirm('2027-08-10', $preview['hash'], [], $facts, true);
    expect(app(KhumsAssetBasisTrace::class)->asOf('2027-08-10')['cash_positions'][0]['protected_basis_exact_rial'])->toBe('1000')
        ->and(DB::table('operations')->whereIn('id', [$expense, $reversal])->count())->toBe(2)
        ->and((string) DB::table('accounts')->where('id', $account)->value('balance_rial'))->toBe('1100');
});

it('keeps an unknown opening origin unresolved instead of treating it as zero protected capital', function (): void {
    $this->actingAs(User::factory()->create());
    $this->travelTo(new DateTimeImmutable('2027-08-10T20:30:00+00:00'));
    config()->set('khums_rules.rules', [
        ['id' => 'K01', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['پایه']],
        ['id' => 'K12', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['اختلاط']],
    ]);
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'افتتاحیه با منشأ نامعلوم ساختگی', '100', '0', 'unknown');
    $setup->confirm((int) $setup->session()->revision, 1, 'origin-unknown-opening-001');
    $plans = app(KhumsOriginPlan::class);
    $preview = $plans->preview('2027-08-10');
    $facts = array_fill_keys($plans->factLabels(), 'توضیح ساختگی که نباید جای شواهد مفقود افتتاحیه را بگیرد');
    expect($preview['issues'])->not->toBe([])
        ->and(fn () => $plans->confirm('2027-08-10', $preview['hash'], [], $facts, true))->toThrow(FinanceInputException::class);
    Livewire::test(KhumsWorkspace::class)->call('reviewPeriod', 1405)->call('prepareOriginPlan')
        ->assertHasErrors(['origin'])->assertSee('منشأ افتتاحیهٔ یکی از حساب‌ها کامل نیست.');
});

it('conserves explicit protected funds across multiple lots, conversion, sale and a mixed cash transfer', function (): void {
    $this->actingAs(User::factory()->create());
    $this->travelTo(new DateTimeImmutable('2027-08-10T20:30:00+00:00'));
    config()->set('khums_rules.rules', [
        ['id' => 'SYNTHETIC', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['منشأ']],
        ['id' => 'K01', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['پایه']],
        ['id' => 'K12', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['اختلاط']],
    ]);
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'مبدأ زنجیره ساختگی', '100', '100', 'user_declared');
    $setup->addItem('bank', 'مقصد زنجیره ساختگی', '0', '0', 'unknown');
    $catalog = app(AssetCatalog::class);
    $firstInstrument = $catalog->createInstrument('ORIGIN-FIRST', 'دارایی اول ساختگی', 'stock', 0);
    $secondInstrument = $catalog->createInstrument('ORIGIN-SECOND', 'دارایی دوم ساختگی', 'crypto', 8);
    $catalog->addOpeningItem($firstInstrument, 'سبد اول ساختگی', '3', '80', '3', '90', 'user_declared');
    $setup->confirm((int) $setup->session()->revision, 1, 'origin-chain-opening-001');
    $source = (int) DB::table('accounts')->where('name', 'مبدأ زنجیره ساختگی')->value('id');
    $target = (int) DB::table('accounts')->where('name', 'مقصد زنجیره ساختگی')->value('id');
    $first = (int) DB::table('accounts')->where('name', 'سبد اول ساختگی')->value('id');
    $openingLot = (int) DB::table('lots')->where('account_id', $first)->value('id');
    $second = $catalog->createHolding($secondInstrument, 'سبد دوم ساختگی');
    $buy = app(AssetTrading::class)->buy(['account_id' => $first, 'cash_account_id' => $source,
        'date' => '2027-08-01', 'quantity' => '2', 'price_toman' => '20', 'fee_toman' => '0', 'idempotency_key' => 'origin-chain-buy-001']);
    $purchaseLot = (int) DB::table('lots')->where('acquisition_operation_id', $buy)->value('id');
    $disposal = app(AssetDisposal::class);
    $sale = $disposal->sell(['source_id' => $first, 'cash_id' => $source, 'date' => '2027-08-02',
        'quantity' => '1', 'gross_toman' => '40', 'fee_toman' => '0', 'idempotency_key' => 'origin-chain-sale-001']);
    $conversion = $disposal->convert(['source_id' => $first, 'target_id' => $second, 'date' => '2027-08-03',
        'quantity' => '1', 'target_quantity' => '0.00000006', 'gross_toman' => '150', 'fee_toman' => '0', 'idempotency_key' => 'origin-chain-convert-001']);
    $convertedLot = (int) DB::table('lots')->where('acquisition_operation_id', $conversion)->value('id');
    $lastSale = $disposal->sell(['source_id' => $second, 'cash_id' => $source, 'date' => '2027-08-04',
        'quantity' => '0.00000003', 'gross_toman' => '90', 'fee_toman' => '0', 'idempotency_key' => 'origin-chain-last-sale-001']);
    $transfer = app(CashBook::class)->post(['type' => 'transfer', 'source_id' => $source, 'target_id' => $target,
        'date' => '2027-08-05', 'amount_toman' => '200', 'idempotency_key' => 'origin-chain-transfer-001']);
    $catalog->recordPrice($firstInstrument, '2027-08-10', '400', 'قیمت ساختگی دارایی چندبخشی');
    $catalog->recordPrice($secondInstrument, '2027-08-10', '30000000000', 'قیمت ساختگی پایان زنجیره');
    $plan = [
        $buy => ['cash_spends' => [$source => '200'], 'acquisition_basis_rial' => '200', 'purchase_fee_rial' => '0'],
        $sale => ['lots' => [$purchaseLot => ['atoms' => '1', 'net_proceeds_rial' => '400']]],
        $conversion => ['lots' => [$openingLot => ['atoms' => '1', 'net_proceeds_rial' => '1500']]],
        $lastSale => ['lots' => [$convertedLot => ['atoms' => '3', 'net_proceeds_rial' => '900']]],
        $transfer => ['cash_spends' => [$source => '1050']],
    ];
    $coverage = app(CoverageConfirmation::class);
    $seen = $coverage->preview('2027-08-10');
    $coverage->confirm('2027-08-10', $seen['revision'], 'origin-chain-coverage-001', true);
    $plans = app(KhumsOriginPlan::class);
    $preview = $plans->preview('2027-08-10');
    $facts = array_fill_keys($plans->factLabels(), 'شواهد کاملاً ساختگی تخصیص فیزیکی و همگنی پایه برای آزمون');
    $economicAllocations = DB::table('lot_allocations')->orderBy('id')->get()->toJson();
    $invalid = $plan;
    $invalid[$transfer]['cash_spends'][$source] = '1051';
    expect(fn () => $plans->confirm('2027-08-10', $preview['hash'], $invalid, $facts, true))->toThrow(InvalidArgumentException::class);
    $invalid = $plan;
    $invalid[$sale]['lots'][$purchaseLot]['atoms'] = '3';
    expect(fn () => $plans->confirm('2027-08-10', $preview['hash'], $invalid, $facts, true))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('user_decisions')->where('kind', 'khums_origin_plan')->count())->toBe(0);
    $id = $plans->confirm('2027-08-10', $preview['hash'], $plan, $facts, true);
    expect($plans->confirm('2027-08-10', $preview['hash'], $plan, $facts, true))->toBe($id)
        ->and(DB::table('lot_allocations')->orderBy('id')->get()->toJson())->toBe($economicAllocations);
    $trace = app(KhumsAssetBasisTrace::class)->asOf('2027-08-10');
    expect($trace['origin_plan_decision_id'])->toBe($id)
        ->and(collect($trace['cash_positions'])->firstWhere('account_id', $source)['protected_basis_exact_rial'])->toBe('0')
        ->and(collect($trace['cash_positions'])->firstWhere('account_id', $target)['protected_basis_exact_rial'])->toBe('1050')
        ->and(collect($trace['holdings'])->firstWhere('account_id', $second)['protected_basis_exact_rial'])->toBe('150')
        ->and(collect($trace['holdings'])->firstWhere('account_id', $first)['protected_basis_exact_rial'])->toBe('700');
    $reports = app(KhumsPeriodReport::class);
    $report = $reports->forYear(1405);
    $displayPlan = $plan;
    $displayPlan[$lastSale]['lots'][$convertedLot]['atoms'] = '۰٫۰۰۰۰۰۰۰۳';
    $ui = Livewire::test(KhumsWorkspace::class)->set('periodReport', $report)->call('prepareOriginPlan')
        ->assertHasNoErrors()->assertSet('originPreview.hash', $preview['hash'])
        ->set('originPlan', $displayPlan)->set('originFacts', array_values($facts))->set('originApproved', true)
        ->set('originPlan.'.$buy.'.cash_spends.'.$source, '۱/۰')
        ->call('confirmOriginPlan')->assertHasErrors(['origin']);
    $ui->set('originPlan.'.$buy.'.cash_spends.'.$source, '۲۰۰')->call('confirmOriginPlan')
        ->assertHasNoErrors()->assertSet('originApproved', false)
        ->assertSet('periodReport.asset_basis_trace.origin_plan_decision_id', $id);
    $decisions = app(KhumsComponentDecision::class);
    foreach ([$source => '100', $target => '950', $first => '500', $second => '750'] as $account => $eligible) {
        $decisions->confirm(1405, $account, $report['component_input_hash'], $eligible, 'SYNTHETIC',
            ['منشأ' => 'تأیید ساختگی افزایش مستقل از اصل مخمّس ردیابی‌شده'], 'origin-chain-component-'.$account);
    }
    expect($reports->forYear(1405)['total_due_exact_rial'])->toBe('460');
    $computed = $reports->forYear(1405);
    $review = app(KhumsPeriodReview::class)->save(1405, $computed['input_hash']);
    $backup = Mockery::mock(KhumsBackupVerifier::class);
    $backup->shouldReceive('verifyReview')->andReturn(str_repeat('a', 64));
    app()->instance(KhumsBackupVerifier::class, $backup);
    $closures = app(KhumsPeriodClosure::class);
    $closure = $closures->close($review, $computed['input_hash'], '/synthetic/origin.khfb', 'synthetic-key', true);
    $assessment = (int) DB::table('khums_assessments')->where('closure_id', $closure)->value('id');
    // A conservative projection may know less than the separately evidenced plan.
    DB::table('accounts')->where('id', $target)->update(['protected_min_rial' => '0']);
    $settlement = app(KhumsSettlement::class)->pay($assessment, $target, '2027-08-11', '460', 'from_other_protected',
        'رسید ساختگی از بخش مخمّس حساب', 'origin-chain-tax-payment-001', true);
    $paymentOperation = (int) DB::table('khums_settlements')->where('id', $settlement)->value('payment_operation_id');
    expect(DB::table('user_decisions')->where('kind', 'khums_payment_origin')->where('target_id', $settlement)->count())->toBe(1);
    foreach ([$source, $target, $first, $second] as $account) {
        app(KhumsBasisResolution::class)->confirm($closure, $account, 'K01', ['پایه' => 'شاهد ساختگی تسویه و انتقال پایه مستقل از بهای اقتصادی'], true);
    }
    $this->travelTo(new DateTimeImmutable('2028-08-10T20:30:00+00:00'));
    $nextSale = $disposal->sell(['source_id' => $second, 'cash_id' => $source, 'date' => '2028-08-09',
        'quantity' => '0.00000003', 'gross_toman' => '120', 'fee_toman' => '0', 'idempotency_key' => 'origin-chain-next-year-sale-001']);
    $nextSeen = $coverage->preview('2028-08-10');
    $coverage->confirm('2028-08-10', $nextSeen['revision'], 'origin-chain-next-coverage-001', true);
    $nextPlan = $plan + [
        $paymentOperation => ['cash_spends' => [$target => '460']],
        $nextSale => ['lots' => [$convertedLot => ['atoms' => '3', 'net_proceeds_rial' => '1200']]],
    ];
    $nextAsOf = $reports->forYear(1406)['as_of_date'];
    $nextPreview = $plans->preview($nextAsOf);
    $nextPlanId = $plans->confirm($nextAsOf, $nextPreview['hash'], $nextPlan, $facts, true);
    $nextTrace = app(KhumsAssetBasisTrace::class)->asOf($nextAsOf);
    $carried = collect($nextTrace['holdings'])->firstWhere('account_id', $first);
    expect(array_column($carried['lot_positions'], 'protected_basis_exact_rial'))->toBe(['800', '400'])
        ->and(collect($nextTrace['cash_positions'])->firstWhere('account_id', $target)['protected_basis_exact_rial'])->toBe('1540')
        ->and(collect($nextTrace['sales'])->last()['allocated_basis_exact_rial'])->toBe('900')
        ->and(collect($nextTrace['sales'])->last()['increase_candidate_exact_rial'])->toBe('300');
    $nextReport = $reports->forYear(1406);
    foreach ([$source => '300', $target => '0', $first => '0'] as $account => $eligible) {
        $decisions->confirm(1406, $account, $nextReport['component_input_hash'], $eligible, 'SYNTHETIC',
            ['منشأ' => 'بررسی ساختگی افزایش پس از انتقال پایهٔ تسویه‌شده'], 'origin-chain-next-component-'.$account);
    }
    expect($reports->forYear(1406)['total_due_exact_rial'])->toBe('60')
        ->and($closures->read($closure)['report']['total_due_exact_rial'])->toBe('460');
    $nextComputed = $reports->forYear(1406);
    $nextReview = app(KhumsPeriodReview::class)->save(1406, $nextComputed['input_hash']);
    $nextClosure = $closures->close($nextReview, $nextComputed['input_hash'], '/synthetic/next-origin.khfb', 'synthetic-key', true);
    $oldPlanPayload = DB::table('user_decisions')->where('id', $nextPlanId)->value('payload');
    $changedFacts = $facts;
    $changedFacts[array_key_first($facts)] = 'اصلاح مستند ساختگی شواهد بدون حذف نسخهٔ تأییدشدهٔ قبلی';
    expect(fn () => $plans->confirm($nextAsOf, $nextPreview['hash'], $nextPlan, $changedFacts, true, $nextPlanId))
        ->toThrow(FinanceInputException::class);
    DB::unprepared("CREATE TEMP TRIGGER fail_origin_audit BEFORE INSERT ON audit_events WHEN NEW.action = 'khums_origin_plan_confirmed' BEGIN SELECT RAISE(ABORT, 'synthetic origin audit failure'); END");
    try {
        expect(fn () => $plans->confirm($nextAsOf, $nextPreview['hash'], $nextPlan, $changedFacts, true, $nextPlanId,
            'اصلاح مستقل شواهد منشأ در آزمون'))->toThrow(QueryException::class);
        expect(DB::table('user_decisions')->where('kind', 'khums_origin_plan')->count())->toBe(2)
            ->and(DB::table('user_decisions')->where('kind', 'khums_origin_plan_impact')->count())->toBe(0)
            ->and(DB::table('khums_period_closures')->where('id', $nextClosure)->value('dependency_stale'))->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER fail_origin_audit');
    }
    $revisedId = $plans->confirm($nextAsOf, $nextPreview['hash'], $nextPlan, $changedFacts, true, $nextPlanId,
        'اصلاح مستقل شواهد منشأ در آزمون');
    expect($revisedId)->not->toBe($nextPlanId)
        ->and(DB::table('user_decisions')->where('id', $nextPlanId)->value('payload'))->toBe($oldPlanPayload)
        ->and(DB::table('khums_period_closures')->where('id', $nextClosure)->value('dependency_stale'))->toBe(1)
        ->and(DB::table('khums_period_closures')->where('id', $closure)->value('dependency_stale'))->toBe(0)
        ->and($closures->read($nextClosure)['report']['total_due_exact_rial'])->toBe('60')
        ->and($reports->forYear(1406)['status'])->toBe('incomplete')
        ->and(fn () => $plans->confirm($nextAsOf, $nextPreview['hash'], $nextPlan, $facts, true))->toThrow(FinanceInputException::class);
    $recordedPayload = DB::table('user_decisions')->where('id', $revisedId)->value('payload');
    $tampered = json_decode($recordedPayload, true);
    $tampered['facts'][array_key_first($tampered['facts'])] = 'دست‌کاری ساختگی شواهد بدون ثبت حسابرسی متناظر';
    DB::table('user_decisions')->where('id', $revisedId)->update(['payload' => json_encode($tampered, JSON_UNESCAPED_UNICODE)]);
    expect(app(KhumsAssetBasisTrace::class)->asOf($nextAsOf)['cash_positions'][0]['status'])->toBe('needs_review');
    DB::table('user_decisions')->where('id', $revisedId)->update(['payload' => $recordedPayload]);
    DB::table('khums_period_closures')->where('id', $closure)->update(['dependency_stale' => true]);
    expect(app(KhumsAssetBasisTrace::class)->asOf($nextAsOf)['cash_positions'][0]['status'])->toBe('needs_review')
        ->and($plans->paymentSource($nextAsOf, $target))->toBeNull();
    DB::table('khums_period_closures')->where('id', $closure)->update(['dependency_stale' => false]);
    app(CashBook::class)->post(['type' => 'income', 'target_id' => $source, 'date' => '2028-08-10',
        'amount_toman' => '1', 'idempotency_key' => 'origin-chain-later-income-001']);
    expect(fn () => $plans->confirm('2027-08-10', $preview['hash'], $plan, $facts, true))->toThrow(FinanceInputException::class)
        ->and(app(KhumsAssetBasisTrace::class)->asOf('2027-08-10')['cash_positions'][0]['status'])->toBe('needs_review');
});
