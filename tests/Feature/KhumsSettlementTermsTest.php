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
use App\Actions\Finance\KhumsSettlementTerms;
use App\Actions\Finance\OpeningSetup;
use App\Livewire\KhumsWorkspace;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('requires owner access and a specific active rule for fractional payment agreements', function (): void {
    expect(fn () => app(KhumsSettlementTerms::class)->preview(1))->toThrow(AuthorizationException::class);
    $this->actingAs(User::factory()->create());
    expect(fn () => app(KhumsSettlementTerms::class)->factLabels('K-EXCESS'))->toThrow(LogicException::class)
        ->and(DB::table('user_decisions')->where('kind', 'khums_settlement_terms')->count())->toBe(0);
    config()->set('khums_rules.rules', [['id' => 'K-EXCESS', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['پرونده']]]);
    expect(fn () => app(KhumsSettlementTerms::class)->factLabels('K-EXCESS'))->toThrow(FinanceInputException::class);
});

it('keeps an exact fractional calculation immutable while independently approving, paying and revising evidenced whole-rial terms', function (): void {
    $this->actingAs(User::factory()->create());
    $this->travelTo(new DateTimeImmutable('2027-08-10T20:30:00+00:00'));
    config()->set('khums_rules.rules', [
        ['id' => 'SYNTHETIC', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['منشأ']],
        ['id' => 'K01', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['پایه']],
        ['id' => 'SYNTHETIC-AGREEMENT', 'status' => 'active', 'review' => 'validated',
            'capabilities' => [KhumsSettlementTerms::CAPABILITY], 'required_facts' => ['پرونده']],
    ]);
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک پروندهٔ کسری ساختگی', '200', '0', 'unknown');
    $setup->confirm((int) $setup->session()->revision, 1, 'terms-opening-synthetic-001');
    $account = (int) DB::table('accounts')->where('name', 'بانک پروندهٔ کسری ساختگی')->value('id');
    $coverage = app(CoverageConfirmation::class);
    $seen = $coverage->preview('2027-08-10');
    $coverage->confirm('2027-08-10', $seen['revision'], 'terms-coverage-synthetic-001', true);
    $reports = app(KhumsPeriodReport::class);
    $report = $reports->forYear(1405);
    app(KhumsComponentDecision::class)->confirm(1405, $account, $report['component_input_hash'], '1001', 'SYNTHETIC',
        ['منشأ' => 'اظهار کاملاً ساختگی برای آزمون کسری ریال'], 'terms-component-synthetic-001');
    $report = $reports->forYear(1405);
    $review = app(KhumsPeriodReview::class)->save(1405, $report['input_hash']);
    $backup = Mockery::mock(KhumsBackupVerifier::class);
    $backup->shouldReceive('verifyReview')->andReturn(str_repeat('a', 64));
    app()->instance(KhumsBackupVerifier::class, $backup);
    $closures = app(KhumsPeriodClosure::class);
    $closure = $closures->close($review, $report['input_hash'], '/synthetic/terms.khfb', 'synthetic-key', true);
    $assessment = (int) DB::table('khums_assessments')->where('closure_id', $closure)->value('id');
    $terms = app(KhumsSettlementTerms::class);
    $oldPreview = $terms->preview($assessment);
    $payments = app(KhumsSettlement::class);
    $payments->pay($assessment, $account, '2027-08-11', '200', 'from_same_asset', 'رسید ساختگی بخش صحیح پرداخت', 'terms-integer-payment-001', true);
    expect(fn () => $payments->pay($assessment, $account, '2027-08-11', '1', 'from_same_asset',
        'نباید بدون مستند گرد شود', 'terms-unapproved-rounding-001', true))->toThrow(FinanceInputException::class);
    $facts = array_fill_keys($terms->factLabels('SYNTHETIC-AGREEMENT'), 'مستند کاملاً ساختگی اجازهٔ همین مبلغ در همین پروندهٔ آزمون');
    $reference = 'پرونده و مرجع کاملاً ساختگی فقط برای آزمون';
    expect(fn () => $terms->confirm($assessment, $oldPreview['hash'], '201', 'SYNTHETIC-AGREEMENT', $facts, $reference, true))
        ->toThrow(FinanceInputException::class);
    $preview = $terms->preview($assessment);
    foreach (['200', '202'] as $invalid) {
        expect(fn () => $terms->confirm($assessment, $preview['hash'], $invalid, 'SYNTHETIC-AGREEMENT', $facts, $reference, true))
            ->toThrow(FinanceInputException::class);
    }
    DB::unprepared("CREATE TEMP TRIGGER fail_terms_audit BEFORE INSERT ON audit_events WHEN NEW.action = 'khums_settlement_terms_confirmed' BEGIN SELECT RAISE(ABORT, 'synthetic agreement failure'); END");
    try {
        expect(fn () => $terms->confirm($assessment, $preview['hash'], '201', 'SYNTHETIC-AGREEMENT', $facts, $reference, true))
            ->toThrow(QueryException::class);
        expect(DB::table('user_decisions')->where('kind', 'khums_settlement_terms')->count())->toBe(0)
            ->and(DB::table('khums_assessments')->where('id', $assessment)->value('status'))->toBe('partially_paid');
    } finally {
        DB::unprepared('DROP TRIGGER fail_terms_audit');
    }
    $ui = Livewire::test(KhumsWorkspace::class)->call('showClosedPeriod', $closure)->call('prepareSettlementTerms')
        ->set('termsRuleId', 'SYNTHETIC-AGREEMENT')->set('termsPayableRial', '۲۰۱')
        ->set('termsCaseReference', $reference)->set('termsFacts', array_values($facts))
        ->call('confirmSettlementTerms')->assertHasErrors(['settlementTerms']);
    $ui->set('termsApproved', true)->call('confirmSettlementTerms')->assertHasNoErrors()
        ->assertSet('termsApproved', false)->assertSee('مبلغ پرداختیِ مورد تأیید پرونده');
    $decision = (int) DB::table('user_decisions')->where('kind', 'khums_settlement_terms')->value('id');
    expect($terms->confirm($assessment, $preview['hash'], '201', 'SYNTHETIC-AGREEMENT', $facts, $reference, true))->toBe($decision)
        ->and($terms->forAssessment($assessment)['payable_due_exact_rial'])->toBe('201')
        ->and($terms->forAssessment($assessment)['computed_due_exact_rial'])->toBe('200.2')
        ->and(DB::table('operations')->where('type', 'expense')->count())->toBe(1);
    // A later catalog change cannot rewrite this already evidenced agreement.
    $rules = config('khums_rules.rules');
    config()->set('khums_rules.rules', []);
    $payments->pay($assessment, $account, '2027-08-11', '1', 'from_same_asset', 'رسید تکمیلی ساختگی پس از مجوز', 'terms-authorized-payment-001', true);
    expect(DB::table('khums_assessments')->where('id', $assessment)->value('status'))->toBe('settled')
        ->and((string) DB::table('khums_assessments')->where('id', $assessment)->value('settled_rial'))->toBe('201')
        ->and(DB::table('khums_assessments')->where('id', $assessment)->value('due_exact_rial'))->toBe('200.2')
        ->and($closures->read($closure)['report']['total_due_exact_rial'])->toBe('200.2')
        ->and(DB::table('user_decisions')->where('kind', 'khums_payment_terms')->count())->toBe(1);
    config()->set('khums_rules.rules', $rules);
    $basis = app(KhumsBasisResolution::class)->confirm($closure, $account, 'K01', ['پایه' => 'بررسی ساختگی مستقل پایه پس از پرداخت موردی'], true);
    $basisPayload = json_decode(DB::table('user_decisions')->where('id', $basis)->value('payload'), true);
    expect($basisPayload['protected_basis_rial'])->toBe('800')
        ->and($basisPayload['settlement_terms_decision_id'])->toBe($decision);
    $oldPayload = DB::table('user_decisions')->where('id', $decision)->value('payload');
    $revisedPreview = $terms->preview($assessment);
    $revised = $terms->confirm($assessment, $revisedPreview['hash'], '201', 'SYNTHETIC-AGREEMENT', $facts,
        'اصلاح مستند پروندهٔ ساختگی بدون تغییر مبلغ', true, $decision, 'اصلاح مستقل مرجع پرونده در آزمون');
    expect($revised)->not->toBe($decision)
        ->and(DB::table('user_decisions')->where('id', $decision)->value('payload'))->toBe($oldPayload)
        ->and(DB::table('khums_basis_carryovers')->where('closure_id', $closure)->value('status'))->toBe('settled_basis_review');
    $saved = DB::table('user_decisions')->where('id', $revised)->value('payload');
    $tampered = json_decode($saved, true);
    $tampered['payable_rial'] = '202';
    DB::table('user_decisions')->where('id', $revised)->update(['payload' => json_encode($tampered, JSON_UNESCAPED_UNICODE)]);
    expect(fn () => $terms->forAssessment($assessment))->toThrow(FinanceInputException::class);
    Livewire::test(KhumsWorkspace::class)->call('showClosedPeriod', $closure)
        ->assertSee('مستند مبلغ پرداختی با تعهد سازگار نیست');
    DB::table('user_decisions')->where('id', $revised)->update(['payload' => $saved]);
    $fresh = $reports->forYear(1405);
    app(KhumsComponentDecision::class)->confirm(1405, $account, $fresh['component_input_hash'], '801', 'SYNTHETIC',
        ['منشأ' => 'اصلاح کاملاً ساختگی مبلغ مشمول در همین سال'], 'terms-amended-component-001');
    $amended = $reports->forYear(1405);
    $amendedReview = app(KhumsPeriodReview::class)->save(1405, $amended['input_hash']);
    $amendments = app(KhumsPeriodAmendment::class);
    $diff = $amendments->preview($closure, $amendedReview);
    $newClosure = $amendments->approve($closure, $amendedReview, $diff['diff_hash'], 'اصلاحیه ساختگی برای بررسی کسری و اضافه‌پرداخت',
        '/synthetic/amended-terms.khfb', 'synthetic-key', true);
    $newAssessment = (int) DB::table('khums_assessments')->where('closure_id', $newClosure)->value('id');
    expect($terms->forAssessment($newAssessment)['decision_id'])->toBeNull();
    $reconciliation = app(KhumsAmendmentReconciliation::class);
    $prior = $reconciliation->preview($newClosure);
    $reconciliation->approve($newClosure, $prior['hash'], 'تطبیق ساختگی پیش از مستند جدید', true);
    expect(DB::table('khums_assessments')->where('id', $newAssessment)->value('status'))->toBe('reconciliation_review');
    $newPreview = $terms->preview($newAssessment);
    $terms->confirm($newAssessment, $newPreview['hash'], '161', 'SYNTHETIC-AGREEMENT', $facts,
        'پروندهٔ ساختگی مستقل برای نسخهٔ جدید تعهد', true);
    $revisedReconciliation = $reconciliation->preview($newClosure);
    expect($revisedReconciliation['computed_due_exact_rial'])->toBe('160.2')
        ->and($revisedReconciliation['due_exact_rial'])->toBe('161')
        ->and($revisedReconciliation['remaining_exact_rial'])->toBe('0')
        ->and($revisedReconciliation['excess_unallocated_rial'])->toBe('40');
    $reconciliation->approve($newClosure, $revisedReconciliation['hash'], 'تطبیق مستقل پس از مستند جدید', true);
    expect(DB::table('khums_assessments')->where('id', $newAssessment)->value('status'))->toBe('settled_excess_review')
        ->and(DB::table('operations')->where('type', 'expense')->count())->toBe(2)
        ->and($closures->read($closure)['report']['total_due_exact_rial'])->toBe('200.2')
        ->and($closures->read($newClosure)['report']['total_due_exact_rial'])->toBe('160.2');
});
