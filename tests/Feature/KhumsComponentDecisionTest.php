<?php

use App\Actions\Finance\CoverageConfirmation;
use App\Actions\Finance\FinanceInputException;
use App\Actions\Finance\KhumsComponentDecision;
use App\Actions\Finance\KhumsPeriodReport;
use App\Actions\Finance\KhumsPeriodReview;
use App\Actions\Finance\OpeningSetup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('requires an active rule and explicit facts for every component before a period is computed', function (): void {
    $this->actingAs(User::factory()->create());
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک آزمون مبنا', '100', '0', 'unknown');
    $setup->confirm((int) $setup->session()->revision, 1, 'khums-component-opening-001');
    $accountId = (int) DB::table('accounts')->where('name', 'بانک آزمون مبنا')->value('id');
    $this->travelTo(new DateTimeImmutable('2027-08-10T20:30:00+00:00'));
    $coverage = app(CoverageConfirmation::class);
    $preview = $coverage->preview('2027-08-10');
    $coverage->confirm('2027-08-10', $preview['revision'], 'khums-component-coverage-001', true);
    $reports = app(KhumsPeriodReport::class);
    $initial = $reports->forYear(1405);
    $decisions = app(KhumsComponentDecision::class);
    expect($initial['status'])->toBe('incomplete')
        ->and(fn () => $decisions->confirm(1405, $accountId, $initial['simulation_input_hash'], '1000', 'SYNTHETIC', [
            'منشأ' => 'اظهار ساختگی درباره منشأ این حساب',
        ], 'khums-component-choice-001'))->toThrow(LogicException::class);

    config()->set('khums_rules.rules', [[
        'id' => 'SYNTHETIC', 'status' => 'active', 'review' => 'validated',
        'required_facts' => ['منشأ', 'مؤونه'],
    ]]);
    $base = $reports->forYear(1405);
    expect(fn () => $decisions->confirm(1405, $accountId, $base['simulation_input_hash'], '1000', 'SYNTHETIC', [
        'منشأ' => 'اظهار ساختگی درباره منشأ این حساب',
    ], 'khums-component-choice-002'))->toThrow(FinanceInputException::class);
    $facts = ['منشأ' => 'اظهار ساختگی درباره منشأ این حساب', 'مؤونه' => 'اظهار ساختگی درباره مؤونه سال'];
    $id = $decisions->confirm(1405, $accountId, $base['simulation_input_hash'], '1000', 'SYNTHETIC', $facts, 'khums-component-choice-003');
    $computed = $reports->forYear(1405);
    expect($decisions->confirm(1405, $accountId, $base['simulation_input_hash'], '1000', 'SYNTHETIC', $facts, 'khums-component-choice-003'))->toBe($id)
        ->and($computed['status'])->toBe('computed')
        ->and($computed['issues'])->toBe([])
        ->and($computed['total_due_exact_rial'])->toBe('200')
        ->and(app(KhumsPeriodReview::class)->read(app(KhumsPeriodReview::class)->save(1405, $computed['input_hash']))['status'])->toBe('computed');
});
