<?php

use App\Actions\Finance\CashBook;
use App\Actions\Finance\FinanceInputException;
use App\Actions\Finance\KhumsPeriodReport;
use App\Actions\Finance\KhumsPeriodReview;
use App\Actions\Finance\OpeningSetup;
use App\Livewire\KhumsWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('saves one immutable incomplete review and rejects stale confirmation', function (): void {
    $this->actingAs(User::factory()->create());
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک مرور ساختگی', '100', '0', 'unknown');
    $setup->confirm((int) $setup->session()->revision, 1, 'khums-review-opening-001');
    $bankId = (int) DB::table('accounts')->where('name', 'بانک مرور ساختگی')->value('id');
    $this->travelTo(new DateTimeImmutable('2027-08-10T20:30:00+00:00'));

    $report = app(KhumsPeriodReport::class)->forYear(1405);
    $reviews = app(KhumsPeriodReview::class);
    $id = $reviews->save(1405, $report['input_hash']);
    expect($reviews->save(1405, $report['input_hash']))->toBe($id)
        ->and(DB::table('khums_period_reviews')->count())->toBe(1)
        ->and(DB::table('khums_period_reviews')->where('id', $id)->value('status'))->toBe('incomplete')
        ->and($reviews->read($id)['input_hash'])->toBe($report['input_hash'])
        ->and(DB::table('audit_events')->where('action', 'khums_period_review_saved')->count())->toBe(1);
    Livewire::test(KhumsWorkspace::class)->call('reviewPeriod', 1405)
        ->assertSee('جزئیات اقلام گزارش دوره')
        ->assertSee('بانک مرور ساختگی')
        ->assertSee('موارد لازم برای تکمیل گزارش')
        ->call('savePeriodReview')
        ->assertSee('پیش‌نویس شمارهٔ '.$id.' ذخیره شد');
    Livewire::test(KhumsWorkspace::class)->call('loadPeriodReview', $id)
        ->assertSee('پیش‌نویس شمارهٔ '.$id.' ذخیره شد');
    expect(DB::table('khums_period_reviews')->count())->toBe(1);

    app(CashBook::class)->post([
        'type' => 'income', 'date' => '2027-08-11', 'amount_toman' => '10',
        'target_id' => $bankId, 'idempotency_key' => 'khums-review-income-001',
    ]);
    expect(fn () => $reviews->save(1405, $report['input_hash']))->toThrow(FinanceInputException::class)
        ->and(DB::table('khums_period_reviews')->count())->toBe(1)
        ->and($reviews->read($id)['input_hash'])->toBe($report['input_hash']);

    DB::table('khums_period_reviews')->where('id', $id)->update(['as_of_date' => '2027-08-09']);
    expect(fn () => $reviews->read($id))->toThrow(FinanceInputException::class);
    DB::table('khums_period_reviews')->where('id', $id)->update(['as_of_date' => $report['as_of_date']]);

    DB::table('khums_period_reviews')->where('id', $id)->update(['snapshot_json' => '{}']);
    expect(fn () => $reviews->read($id))->toThrow(FinanceInputException::class);
});

it('refuses a saved review before due or with a forged hash', function (): void {
    $this->actingAs(User::factory()->create());
    $reviews = app(KhumsPeriodReview::class);
    expect(fn () => $reviews->save(1405, str_repeat('a', 64)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $reviews->save(1405, 'not-a-hash'))->toThrow(FinanceInputException::class)
        ->and(DB::table('khums_period_reviews')->count())->toBe(0);
});
