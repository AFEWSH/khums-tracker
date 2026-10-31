<?php

use App\Actions\Finance\AssetCatalog;
use App\Actions\Finance\AssetOverview;
use App\Actions\Finance\BackdatedPosting;
use App\Actions\Finance\CashBook;
use App\Actions\Finance\FinanceInputException;
use App\Actions\Finance\ForeignObligationSettlement;
use App\Actions\Finance\OpeningAmendment;
use App\Actions\Finance\OpeningSetup;
use App\Actions\Finance\ReplayProjection;
use App\Models\User;
use App\Support\Dates\PersianDate;
use App\Support\Khums\KhumsYearSchedule;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;

// P1 verification: every expected figure is worked by hand in the comment beside it (toman
// inputs, rial assertions), never derived from the service under test.

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

function baseKey(): string
{
    static $counter = 0;

    return 'base-scenario-'.str_pad((string) ++$counter, 6, '0', STR_PAD_LEFT);
}

/** @param array<string,array{0:string,1:string,2?:string}> $items name => [kind, toman, protected toman]
 * @return array<string,int> */
function baseOpening(array $items): array
{
    $setup = app(OpeningSetup::class);
    foreach ($items as $name => $item) {
        $protected = $item[2] ?? '0';
        $setup->addItem($item[0], $name, $item[1], $protected, $protected === '0' ? 'unknown' : 'user_declared');
    }
    $setup->confirm((int) $setup->session()->revision, 1, baseKey());

    return array_map(fn (string $name): int => (int) DB::table('accounts')->where('name', $name)->value('id'), array_combine(array_keys($items), array_keys($items)));
}

function basePost(string $type, string $date, string $toman, ?int $source = null, ?int $target = null): int
{
    return app(BackdatedPosting::class)->cash(array_filter([
        'type' => $type, 'date' => $date, 'amount_toman' => $toman,
        'source_id' => $source, 'target_id' => $target, 'idempotency_key' => baseKey(),
    ], fn ($value): bool => $value !== null));
}

/** @return array{0:string,1:string,2:string} balance, protected minimum, protected maximum in rial */
function basePosition(int $accountId): array
{
    $account = DB::table('accounts')->find($accountId);

    return [(string) $account->balance_rial, (string) $account->protected_min_rial, (string) $account->protected_max_rial];
}

function baseHolding(string $code, int $scale = 0): int
{
    $catalog = app(AssetCatalog::class);

    return $catalog->createHolding($catalog->createInstrument($code, 'سهم ساختگی '.$code, 'stock', $scale), 'سبد ساختگی '.$code);
}

function baseBuy(int $holding, int $cash, string $date, string $quantity, string $price, string $fee = '0'): int
{
    return app(BackdatedPosting::class)->buy([
        'account_id' => $holding, 'cash_account_id' => $cash, 'date' => $date, 'quantity' => $quantity,
        'price_toman' => $price, 'fee_toman' => $fee, 'idempotency_key' => baseKey(),
    ]);
}

function baseSell(int $holding, int $cash, string $date, string $quantity, string $gross, string $fee = '0'): int
{
    return app(BackdatedPosting::class)->sell([
        'source_id' => $holding, 'cash_id' => $cash, 'date' => $date, 'quantity' => $quantity,
        'gross_toman' => $gross, 'fee_toman' => $fee, 'idempotency_key' => baseKey(),
    ]);
}

function baseSystemBalance(string $code): string
{
    $total = BigInteger::zero();
    foreach (DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.code', $code)->get(['side', 'amount_rial']) as $line) {
        $total = $line->side === 'credit' ? $total->plus($line->amount_rial) : $total->minus($line->amount_rial);
    }

    return (string) $total;
}

/** The P1 global invariants, including that a full replay reproduces the stored positions. */
function baseAssertInvariants(string $declaredProtectedRial = '0'): void
{
    foreach (DB::table('operations')->pluck('id') as $operationId) {
        $debit = BigInteger::zero();
        $credit = BigInteger::zero();
        foreach (DB::table('journal_lines')->where('operation_id', $operationId)->get() as $line) {
            $line->side === 'debit' ? $debit = $debit->plus($line->amount_rial) : $credit = $credit->plus($line->amount_rial);
        }
        expect((string) $debit)->toBe((string) $credit);
    }
    $accounts = DB::table('accounts')->whereIn('class', ['asset', 'liability'])->get();
    $protectedSum = BigInteger::zero();
    foreach ($accounts as $account) {
        $journal = BigInteger::zero();
        foreach (DB::table('journal_lines')->where('account_id', $account->id)->get() as $line) {
            $journal = ($line->side === 'debit') === ($account->class === 'asset') ? $journal->plus($line->amount_rial) : $journal->minus($line->amount_rial);
        }
        expect((string) $journal)->toBe((string) $account->balance_rial)
            ->and($journal->isNegative())->toBeFalse()
            ->and(BigInteger::of($account->protected_min_rial)->isGreaterThan($account->protected_max_rial))->toBeFalse()
            ->and(BigInteger::of($account->protected_max_rial)->isGreaterThan($account->balance_rial))->toBeFalse();
        if (in_array($account->subtype, ['bank', 'cash', 'broker_cash', 'wallet_cash', 'receivable'], true)) {
            $protectedSum = $protectedSum->plus($account->protected_min_rial);
        }
    }
    expect($protectedSum->isGreaterThan($declaredProtectedRial))->toBeFalse();
    foreach (DB::table('asset_accounts')->pluck('account_id') as $holding) {
        $atoms = BigInteger::zero();
        foreach (DB::table('quantity_entries')->where('account_id', $holding)->pluck('signed_atoms') as $entry) {
            $atoms = $atoms->plus((string) $entry);
        }
        $lotAtoms = BigInteger::zero();
        $lotCost = BigInteger::zero();
        foreach (DB::table('lots')->where('account_id', $holding)->get() as $lot) {
            expect(BigInteger::of((string) $lot->remaining_atoms)->isNegative())->toBeFalse()
                ->and(BigInteger::of((string) $lot->remaining_cost_rial)->isNegative())->toBeFalse();
            $lotAtoms = $lotAtoms->plus((string) $lot->remaining_atoms);
            $lotCost = $lotCost->plus((string) $lot->remaining_cost_rial);
        }
        expect((string) $lotAtoms)->toBe((string) $atoms)
            ->and((string) $lotCost)->toBe((string) DB::table('accounts')->find($holding)->balance_rial);
    }
    $stored = DB::table('accounts')->orderBy('id')->get(['id', 'balance_rial', 'protected_min_rial', 'protected_max_rial'])->toArray();
    $lots = DB::table('lots')->orderBy('id')->get(['id', 'remaining_atoms', 'remaining_cost_rial'])->toArray();
    $last = (int) DB::table('operations')->where('type', '!=', 'replay_adjustment')->max('id');
    if ($last !== 0) {
        app(ReplayProjection::class)->rebuild($last, OpeningSetup::DATE);
    }
    expect(DB::table('accounts')->orderBy('id')->get(['id', 'balance_rial', 'protected_min_rial', 'protected_max_rial'])->toArray())->toEqual($stored)
        ->and(DB::table('lots')->orderBy('id')->get(['id', 'remaining_atoms', 'remaining_cost_rial'])->toArray())->toEqual($lots);
}

it('opens cash, asset, debt and FX items with exact balances and declared protection', function (): void {
    $catalog = app(AssetCatalog::class);
    $setup = app(OpeningSetup::class);
    $setup->saveSettings(true);
    $gold = $catalog->createInstrument('BASE-GOLD', 'طلای پایه', 'gold', 3, 7500);
    $usd = $catalog->createInstrument('BASE-USD', 'دلار پایه', 'currency', 2);
    $setup->addItem('bank', 'بانک پایه', '1000', '600', 'user_declared');
    $setup->addItem('cash', 'نقد پایه', '200', '0', 'unknown');
    $setup->addItem('receivable', 'طلب پایه', '300', '0', 'unknown');
    $setup->addItem('payable', 'بدهی پایه', '150', '0', 'unknown');
    $catalog->addOpeningItem($gold, 'طلای خانه پایه', '10', '500', '6', '270', 'user_declared');
    $catalog->addForeignObligationOpening($usd, 'receivable', 'طلب ارزی پایه', '100', '400');
    $catalog->addForeignObligationOpening($usd, 'payable', 'بدهی ارزی پایه', '50', '200');
    $opening = $setup->confirm((int) $setup->session()->revision, 1, baseKey());

    // Assets 1000+200+300+500+400=2400 toman, debts 150+200=350, equity 2050.
    $lines = DB::table('journal_lines')->where('operation_id', $opening)->get();
    $id = fn (string $name): int => (int) DB::table('accounts')->where('name', $name)->value('id');
    expect((string) $lines->where('side', 'debit')->sum('amount_rial'))->toBe('24000')
        ->and((string) $lines->where('side', 'credit')->sum('amount_rial'))->toBe('24000')
        ->and(baseSystemBalance('opening-equity'))->toBe('20500')
        ->and(basePosition($id('بانک پایه')))->toBe(['10000', '6000', '10000'])
        ->and(basePosition($id('نقد پایه')))->toBe(['2000', '0', '2000'])
        ->and(basePosition($id('طلب پایه')))->toBe(['3000', '0', '3000'])
        ->and(basePosition($id('بدهی پایه')))->toBe(['1500', '0', '0'])
        ->and(baseSystemBalance('system-income'))->toBe('0');

    $lot = DB::table('lots')->where('account_id', $id('طلای خانه پایه'))->first();
    $origin = DB::table('asset_origin_events')->where('lot_id', $lot->id)->first();
    expect((string) $lot->remaining_atoms)->toBe('10000')
        ->and((string) $lot->remaining_cost_rial)->toBe('5000')
        ->and((string) $origin->protected_min_atoms)->toBe('6000')
        ->and((string) $origin->protected_max_atoms)->toBe('10000')
        ->and((string) $origin->protected_basis_rial)->toBe('2700')
        ->and(DB::table('user_decisions')->where('kind', 'previous_khums_settled')->count())->toBe(1)
        ->and(DB::table('user_decisions')->where('kind', 'opening_protected_declaration')->count())->toBe(1)
        ->and(DB::table('user_decisions')->where('kind', 'opening_asset_protected')->count())->toBe(1);
    baseAssertInvariants('6000');
});

it('rejects opening declarations that invent protection', function (): void {
    $setup = app(OpeningSetup::class);

    expect(fn () => $setup->addItem('bank', 'الف', '100', '101', 'user_declared'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $setup->addItem('bank', 'ب', '100', '50', 'unknown'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $setup->addItem('payable', 'پ', '100', '50', 'user_declared'))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('opening_items')->count())->toBe(0);
    baseOpening(['بانک' => ['bank', '100']]);
    expect(fn () => $setup->confirm((int) $setup->session()->revision, 1, baseKey()))->toThrow(FinanceInputException::class);
});

it('keeps income, spending, transfers and loan principal apart through a whole ledger run', function (): void {
    $ids = baseOpening(['بانک یک' => ['bank', '1000', '600'], 'بانک دو' => ['bank', '500']]);
    [$one, $two] = [$ids['بانک یک'], $ids['بانک دو']];
    $receivable = app(CashBook::class)->createAccount('طلب ساختگی', 'receivable');
    $payable = app(CashBook::class)->createAccount('وام ساختگی', 'payable');

    // Income never raises the protected ceiling: 1200 balance, min 600, max stays 1000.
    basePost('income', '2026-08-12', '200', target: $one);
    expect(basePosition($one))->toBe(['12000', '6000', '10000']);
    // Spending 300 may come from protected money: min 300, max 900 caps at the balance.
    basePost('expense', '2026-08-13', '300', source: $one);
    expect(basePosition($one))->toBe(['9000', '3000', '9000']);
    // Transfer 400 of 900 with 600 unprotected moves no certain protection and up to 400.
    basePost('transfer', '2026-08-14', '400', $one, $two);
    expect(basePosition($one))->toBe(['5000', '0', '5000'])
        ->and(basePosition($two))->toBe(['9000', '0', '9000']);
    basePost('lend', '2026-08-15', '100', $two, $receivable);
    expect(basePosition($two))->toBe(['8000', '0', '8000'])
        ->and(basePosition($receivable))->toBe(['1000', '0', '1000']);
    basePost('collect', '2026-08-16', '40', $receivable, $two);
    expect(basePosition($receivable))->toBe(['600', '0', '600'])
        ->and(basePosition($two))->toBe(['8400', '0', '8400']);
    // Borrowed money is not protected: balance 1090, max stays 840.
    basePost('borrow', '2026-08-17', '250', $payable, $two);
    expect(basePosition($two))->toBe(['10900', '0', '8400'])
        ->and(basePosition($payable))->toBe(['2500', '0', '0']);
    basePost('repay', '2026-08-18', '100', $two, $payable);
    expect(basePosition($two))->toBe(['9900', '0', '8400'])
        ->and(basePosition($payable))->toBe(['1500', '0', '0']);

    // Net worth: 500+990+60-150=1400 = opening 1500 + income 200 - expense 300.
    expect(baseSystemBalance('system-income'))->toBe('2000')
        ->and(baseSystemBalance('system-expense'))->toBe('-3000');
    baseAssertInvariants('6000');
});

it('rejects overspending, over-collection and over-repayment without partial effects', function (): void {
    $ids = baseOpening(['بانک' => ['bank', '100'], 'طلب' => ['receivable', '10'], 'بدهی' => ['payable', '10']]);
    $before = DB::table('operations')->count();

    expect(fn () => basePost('expense', '2026-08-12', '101', source: $ids['بانک']))->toThrow(FinanceInputException::class)
        ->and(fn () => basePost('collect', '2026-08-12', '11', $ids['طلب'], $ids['بانک']))->toThrow(FinanceInputException::class)
        ->and(fn () => basePost('repay', '2026-08-12', '11', $ids['بانک'], $ids['بدهی']))->toThrow(FinanceInputException::class)
        ->and(DB::table('operations')->count())->toBe($before)
        ->and(basePosition($ids['بانک']))->toBe(['1000', '0', '1000']);
});

it('moves the exact certain protection when a transfer must take protected money', function (): void {
    $ids = baseOpening(['بانک مبدأ' => ['bank', '100', '90'], 'بانک مقصد' => ['bank', '0']]);

    // Only 10 is unprotected, so a transfer of 50 carries at least 40 protected.
    basePost('transfer', '2026-08-12', '50', $ids['بانک مبدأ'], $ids['بانک مقصد']);

    expect(basePosition($ids['بانک مبدأ']))->toBe(['500', '400', '500'])
        ->and(basePosition($ids['بانک مقصد']))->toBe(['500', '400', '500'])
        ->and(baseSystemBalance('system-income'))->toBe('0');
    baseAssertInvariants('900');
});

it('books a purchase fee once and a profitable sale exactly', function (): void {
    $ids = baseOpening(['بانک' => ['bank', '200000000', '150000000']]);
    $holding = baseHolding('BASE-PROFIT');

    baseBuy($holding, $ids['بانک'], '2026-08-12', '1', '100000000', '1000000');
    // Cost 101m enters the lot; bank 99m, certain protection 150-101=49m.
    expect(basePosition($holding)[0])->toBe('1010000000')
        ->and(basePosition($ids['بانک']))->toBe(['990000000', '490000000', '990000000'])
        ->and((string) DB::table('lots')->where('account_id', $holding)->value('cost_rial'))->toBe('1010000000');

    baseSell($holding, $ids['بانک'], '2026-08-13', '1', '120000000', '2000000');
    // Proceeds 118m, gain 120-101=19m, net of the 2m fee 17m.
    expect(basePosition($ids['بانک']))->toBe(['2170000000', '490000000', '2170000000'])
        ->and(baseSystemBalance('system-realized-gain'))->toBe('190000000')
        ->and(baseSystemBalance('system-sale-fee'))->toBe('-20000000')
        ->and(baseSystemBalance('system-expense'))->toBe('0')
        ->and(app(AssetOverview::class)->realizedResult('2026-08-13'))->toBe([
            'gain_toman' => '19000000', 'loss_toman' => '0',
            'sale_fees_toman' => '2000000', 'net_toman' => '17000000',
        ]);
    baseAssertInvariants('1500000000');
});

it('books a losing sale without leaving a phantom basis', function (): void {
    $ids = baseOpening(['بانک' => ['bank', '200000000']]);
    $holding = baseHolding('BASE-LOSS');
    baseBuy($holding, $ids['بانک'], '2026-08-12', '1', '100000000', '1000000');

    baseSell($holding, $ids['بانک'], '2026-08-13', '1', '90000000', '1000000');

    // Proceeds 89m; loss 101-90=11m plus 1m fee is a 12m net loss; nothing remains in the holding.
    expect(basePosition($ids['بانک'])[0])->toBe('1880000000')
        ->and(basePosition($holding)[0])->toBe('0')
        ->and(app(AssetOverview::class)->realizedResult('2026-08-13'))->toBe([
            'gain_toman' => '0', 'loss_toman' => '11000000',
            'sale_fees_toman' => '1000000', 'net_toman' => '-12000000',
        ]);
    baseAssertInvariants();
});

it('exits FIFO across lots to exactly zero', function (): void {
    $ids = baseOpening(['بانک' => ['bank', '10000']]);
    $holding = baseHolding('BASE-FIFO');
    baseBuy($holding, $ids['بانک'], '2026-08-12', '10', '1000');
    $dear = baseBuy($holding, $ids['بانک'], '2026-08-13', '10', '2000');

    baseSell($holding, $ids['بانک'], '2026-08-14', '15', '3000');
    // All of the 1000 lot plus half of the 2000 lot: cost 2000, gain 1000, 5 units at 1000 left.
    $lot = DB::table('lots')->where('acquisition_operation_id', $dear)->first();
    expect((string) $lot->remaining_atoms)->toBe('5')
        ->and((string) $lot->remaining_cost_rial)->toBe('10000')
        ->and(baseSystemBalance('system-realized-gain'))->toBe('10000');

    baseSell($holding, $ids['بانک'], '2026-08-15', '5', '400');
    // The last exit carries the remaining 1000 for a 600 loss; bank 10000-3000+3000+400=10400.
    expect(DB::table('lots')->where('account_id', $holding)->sum('remaining_atoms'))->toEqual(0)
        ->and(DB::table('lots')->where('account_id', $holding)->sum('remaining_cost_rial'))->toEqual(0)
        ->and(basePosition($ids['بانک'])[0])->toBe('104000')
        ->and(app(AssetOverview::class)->realizedResult('2026-08-15')['net_toman'])->toBe('400');
    baseAssertInvariants();
});

it('allocates indivisible rials across partial exits without losing one', function (): void {
    $ids = baseOpening(['بانک' => ['bank', '100']]);
    $holding = baseHolding('BASE-ROUND');
    baseBuy($holding, $ids['بانک'], '2026-08-12', '3', '10');
    $sale = fn (string $date): int => baseSell($holding, $ids['بانک'], $date, '1', '5');
    $cost = fn (int $operation): string => (string) DB::table('lot_allocations')->where('exit_operation_id', $operation)->value('allocated_cost_rial');

    // 100 rial over three units: 100/3 rounds to 33, then 67/2 rounds to 34, then the last 33.
    expect($cost($sale('2026-08-13')))->toBe('33')
        ->and($cost($sale('2026-08-14')))->toBe('34')
        ->and($cost($sale('2026-08-15')))->toBe('33')
        ->and(baseSystemBalance('system-realized-gain'))->toBe('50');
    baseAssertInvariants();
});

it('rejects selling more than is held without touching anything', function (): void {
    $ids = baseOpening(['بانک' => ['bank', '100']]);
    $holding = baseHolding('BASE-SHORT');
    baseBuy($holding, $ids['بانک'], '2026-08-12', '2', '50');
    $before = [DB::table('operations')->count(), DB::table('lots')->get()->toArray(), basePosition($ids['بانک'])];

    expect(fn () => baseSell($holding, $ids['بانک'], '2026-08-13', '3', '90'))->toThrow(FinanceInputException::class)
        ->and([DB::table('operations')->count(), DB::table('lots')->get()->toArray(), basePosition($ids['بانک'])])->toEqual($before);
});

it('carries a chained conversion without new income or a duplicated gain', function (): void {
    $ids = baseOpening(['بانک' => ['bank', '5000']]);
    $stock = baseHolding('BASE-STOCK');
    $coin = baseHolding('BASE-COIN');
    baseBuy($stock, $ids['بانک'], '2026-08-12', '10', '1000');

    app(BackdatedPosting::class)->convert([
        'source_id' => $stock, 'target_id' => $coin, 'cash_id' => $ids['بانک'], 'date' => '2026-08-13',
        'quantity' => '10', 'target_quantity' => '2', 'gross_toman' => '1300', 'fee_toman' => '20',
        'idempotency_key' => baseKey(),
    ]);
    // Gain 1300-1000=300; target cost 1300+20=1320; bank 5000-1000-20=3980.
    expect(basePosition($stock)[0])->toBe('0')
        ->and(basePosition($coin)[0])->toBe('13200')
        ->and(basePosition($ids['بانک'])[0])->toBe('39800')
        ->and(baseSystemBalance('system-realized-gain'))->toBe('3000')
        ->and(baseSystemBalance('system-income'))->toBe('0');

    baseSell($coin, $ids['بانک'], '2026-08-14', '2', '1500');
    // Second leg gains only 1500-1320=180; lifetime gain 480.
    expect(baseSystemBalance('system-realized-gain'))->toBe('4800')
        ->and(basePosition($ids['بانک'])[0])->toBe('54800');
    baseAssertInvariants();
});

it('settles FX principal apart from the exchange result', function (): void {
    $catalog = app(AssetCatalog::class);
    $setup = app(OpeningSetup::class);
    $usd = $catalog->createInstrument('BASE-FX', 'دلار پایه', 'currency', 2);
    $setup->addItem('bank', 'بانک ارز', '1000', '0', 'unknown');
    $catalog->addForeignObligationOpening($usd, 'receivable', 'طلب ارز', '100', '400');
    $catalog->addForeignObligationOpening($usd, 'payable', 'بدهی ارز', '50', '200');
    $setup->confirm((int) $setup->session()->revision, 1, baseKey());
    $id = fn (string $name): int => (int) DB::table('accounts')->where('name', $name)->value('id');
    $settle = app(ForeignObligationSettlement::class);

    // 40 of 100 units carries 160 of book value; receiving 200 is a 40 gain, leaving 240.
    $settle->post(['obligation_id' => $id('طلب ارز'), 'cash_id' => $id('بانک ارز'), 'date' => '2026-08-12',
        'quantity' => '40', 'cash_toman' => '200', 'idempotency_key' => baseKey()]);
    // Paying 230 for a 200 debt is a 30 loss.
    $settle->post(['obligation_id' => $id('بدهی ارز'), 'cash_id' => $id('بانک ارز'), 'date' => '2026-08-13',
        'quantity' => '50', 'cash_toman' => '230', 'idempotency_key' => baseKey()]);

    expect(basePosition($id('طلب ارز'))[0])->toBe('2400')
        ->and(basePosition($id('بدهی ارز'))[0])->toBe('0')
        ->and(basePosition($id('بانک ارز'))[0])->toBe('9700')
        ->and(baseSystemBalance('system-fx-gain'))->toBe('400')
        ->and(baseSystemBalance('system-fx-loss'))->toBe('-300');
    baseAssertInvariants();
});

it('gives a backdated income the same result as posting in order', function (): void {
    $ids = baseOpening(['بانک' => ['bank', '1000', '600']]);
    $bank = $ids['بانک'];
    basePost('expense', '2026-08-12', '100', source: $bank);
    basePost('expense', '2026-08-15', '50', source: $bank);

    basePost('income', '2026-08-13', '300', target: $bank);

    // In order: 900/500/900, income leaves max 900, then 1150/450/900.
    expect(basePosition($bank))->toBe(['11500', '4500', '9000']);
    baseAssertInvariants('6000');
});

it('does not treat backdated borrowed or accrued money as protected', function (): void {
    $ids = baseOpening(['بانک' => ['bank', '100'], 'طلب' => ['receivable', '0'], 'وام' => ['payable', '0']]);
    basePost('expense', '2026-08-15', '10', source: $ids['بانک']);

    basePost('borrow', '2026-08-12', '50', $ids['وام'], $ids['بانک']);
    basePost('accrue_income', '2026-08-13', '30', target: $ids['طلب']);

    // Neither inflow raises a ceiling: bank 100+50-10=140 keeps max 100, the receivable keeps 0.
    expect(basePosition($ids['بانک']))->toBe(['1400', '0', '1000'])
        ->and(basePosition($ids['طلب']))->toBe(['300', '0', '0']);
    baseAssertInvariants();
});

it('refuses a backdated repayment of a debt that did not exist yet', function (): void {
    $ids = baseOpening(['بانک' => ['bank', '100'], 'وام' => ['payable', '0']]);
    basePost('borrow', '2026-08-15', '50', $ids['وام'], $ids['بانک']);
    $before = [DB::table('operations')->count(), basePosition($ids['بانک']), basePosition($ids['وام'])];

    // Posted in order this would be rejected, so a date-shifted entry must be too.
    expect(fn () => basePost('repay', '2026-08-13', '40', $ids['بانک'], $ids['وام']))->toThrow(FinanceInputException::class)
        ->and([DB::table('operations')->count(), basePosition($ids['بانک']), basePosition($ids['وام'])])->toEqual($before);
});

it('reverses a later operation correctly after a backdated posting moved the balance', function (): void {
    $ids = baseOpening(['بانک' => ['bank', '1000']]);
    $bank = $ids['بانک'];
    basePost('expense', '2026-08-15', '100', source: $bank);
    basePost('income', '2026-08-13', '50', target: $bank);
    $later = basePost('expense', '2026-08-16', '200', source: $bank);
    expect(basePosition($bank)[0])->toBe('7500');

    app(CashBook::class)->reverseLatest($later, 'ثبت اشتباه', baseKey());

    // 1000+50-100=950 must return, not the 900 known before the backdated income.
    expect(basePosition($bank)[0])->toBe('9500');
    baseAssertInvariants();
});

it('reverses a backdated posting itself', function (): void {
    $ids = baseOpening(['بانک' => ['bank', '1000']]);
    $bank = $ids['بانک'];
    basePost('expense', '2026-08-15', '100', source: $bank);
    $backdated = basePost('income', '2026-08-13', '50', target: $bank);
    expect(basePosition($bank)[0])->toBe('9500');

    app(CashBook::class)->reverseLatest($backdated, 'ثبت اشتباه', baseKey());

    expect(basePosition($bank))->toBe(['9000', '0', '9000']);
    baseAssertInvariants();
});

it('keeps the bounds of a reversed transfer through a later replay', function (): void {
    $ids = baseOpening(['بانک مبدأ' => ['bank', '100', '90'], 'بانک مقصد' => ['bank', '0']]);
    [$source, $target] = [$ids['بانک مبدأ'], $ids['بانک مقصد']];
    $transfer = basePost('transfer', '2026-08-12', '50', $source, $target);
    app(CashBook::class)->reverseLatest($transfer, 'انتقال اشتباه', baseKey());
    expect(basePosition($source))->toBe(['1000', '900', '1000'])
        ->and(basePosition($target))->toBe(['0', '0', '0']);
    basePost('expense', '2026-08-15', '5', source: $source);

    // The cancelled transfer leaves no trace: 100+20-5=115, certain 90-5=85, ceiling stays 100.
    basePost('income', '2026-08-13', '20', target: $source);

    expect(basePosition($source))->toBe(['1150', '850', '1000'])
        ->and(basePosition($target))->toBe(['0', '0', '0']);
    baseAssertInvariants('900');
});

it('restores the balance and undoes a reversed operation only once', function (): void {
    $ids = baseOpening(['بانک' => ['bank', '100']]);
    $key = baseKey();
    $operation = basePost('income', '2026-08-12', '30', target: $ids['بانک']);

    $reversal = app(CashBook::class)->reverseLatest($operation, 'ثبت اشتباه', $key);

    expect(app(CashBook::class)->reverseLatest($operation, 'ثبت اشتباه', $key))->toBe($reversal)
        ->and(basePosition($ids['بانک']))->toBe(['1000', '0', '1000'])
        ->and(baseSystemBalance('system-income'))->toBe('0')
        ->and(DB::table('operations')->where('id', $operation)->exists())->toBeTrue();
    baseAssertInvariants();
});

it('keeps declared protection when an opening cash amount is corrected down', function (): void {
    $ids = baseOpening(['بانک' => ['bank', '1000', '800']]);
    $item = (int) DB::table('opening_items')->value('id');
    $amendment = app(OpeningAmendment::class);

    $case = $amendment->proposeCashAmount($item, '900', 'مانده اشتباه ثبت شده بود');
    $amendment->approve($case, (int) DB::table('financial_state')->value('revision'), baseKey());

    // The 800 declaration still fits inside the corrected 900, so it stays whole.
    expect(basePosition($ids['بانک']))->toBe(['9000', '8000', '9000']);
    baseAssertInvariants('8000');
});

it('reprices earlier sales after an opening asset value or quantity correction', function (): void {
    $catalog = app(AssetCatalog::class);
    $setup = app(OpeningSetup::class);
    $stock = $catalog->createInstrument('BASE-AMEND', 'سهم اصلاحی', 'stock', 0);
    $setup->addItem('bank', 'بانک اصلاح', '100', '0', 'unknown');
    $item = $catalog->addOpeningItem($stock, 'سبد اصلاحی', '10', '1000', '0', null, 'unknown');
    $setup->confirm((int) $setup->session()->revision, 1, baseKey());
    $bank = (int) DB::table('accounts')->where('name', 'بانک اصلاح')->value('id');
    $holding = (int) DB::table('accounts')->where('name', 'سبد اصلاحی')->value('id');
    baseSell($holding, $bank, '2026-08-12', '4', '600');
    // Cost 400, gain 200, 6 units carrying 600.
    expect(baseSystemBalance('system-realized-gain'))->toBe('2000');
    $amendment = app(OpeningAmendment::class);
    $revision = fn (): int => (int) DB::table('financial_state')->value('revision');

    $case = $amendment->proposeAssetValue($item, '500', 'ارزش اشتباه ثبت شده بود');
    $amendment->approveAssetValue($case, $revision(), baseKey());
    // Value 500: the 4 sold cost 200, so the gain becomes 400 and 6 units carry 300.
    expect(baseSystemBalance('system-realized-gain'))->toBe('4000')
        ->and(basePosition($holding)[0])->toBe('3000')
        ->and(basePosition($bank)[0])->toBe('7000');

    $case = $amendment->proposeAssetQuantityOrigin($item, '20', '0', null, 'unknown', 'تعداد اشتباه ثبت شده بود');
    $amendment->approveAssetQuantityOrigin($case, $revision(), baseKey());
    // 20 units on 500: the 4 sold cost 100, the gain becomes 500 and 16 units carry 400.
    expect(baseSystemBalance('system-realized-gain'))->toBe('5000')
        ->and(basePosition($holding)[0])->toBe('4000')
        ->and(app(AssetOverview::class)->holdings('2026-08-12')[0]['quantity'])->toBe('16');
    baseAssertInvariants();
});

it('rejects an opening correction that later spending can no longer support', function (): void {
    $ids = baseOpening(['بانک' => ['bank', '100']]);
    basePost('expense', '2026-08-12', '90', source: $ids['بانک']);
    $amendment = app(OpeningAmendment::class);
    $case = $amendment->proposeCashAmount((int) DB::table('opening_items')->value('id'), '50', 'مانده اشتباه ثبت شده بود');
    $before = DB::table('operations')->count();

    expect(fn () => $amendment->approve($case, (int) DB::table('financial_state')->value('revision'), baseKey()))->toThrow(FinanceInputException::class)
        ->and(DB::table('operations')->count())->toBe($before)
        ->and(basePosition($ids['بانک'])[0])->toBe('100')
        ->and(DB::table('amendment_cases')->find($case)->status)->toBe('draft');
});

it('values holdings with the last known price and never a later one', function (): void {
    $ids = baseOpening(['بانک' => ['bank', '1000']]);
    $catalog = app(AssetCatalog::class);
    $instrument = $catalog->createInstrument('BASE-PRICE', 'سهم قیمت', 'stock', 0);
    $holding = $catalog->createHolding($instrument, 'سبد قیمت');
    baseBuy($holding, $ids['بانک'], '2026-08-12', '3', '300');
    $market = fn (string $date): array => [
        app(AssetOverview::class)->holdings($date)[0]['market_toman'],
        app(AssetOverview::class)->holdings($date)[0]['price_date'],
    ];

    expect($market('2026-08-20'))->toBe([null, null]);

    $catalog->recordPrice($instrument, '2026-08-13', '1500', 'منبع ساختگی');
    $catalog->recordPrice($instrument, '2026-08-13', '1600', 'اصلاح قیمت ساختگی');
    $catalog->recordPrice($instrument, '2026-08-25', '9000', 'منبع آینده ساختگی');

    // Before any price it stays unknown; afterwards the newest revision at or before the date.
    expect($market('2026-08-12'))->toBe([null, null])
        ->and($market('2026-08-20'))->toBe(['480', '2026-08-13'])
        ->and($market('2026-08-25'))->toBe(['2700', '2026-08-25']);

    $catalog->recordPrice($instrument, '2026-08-26', '0', 'قیمت صفر ساختگی');
    expect($market('2026-08-26'))->toBe(['0', '2026-08-26']);
});

it('places operations and prices on the 20 Mordad boundary in the right year', function (): void {
    $period = KhumsYearSchedule::confirmedPeriod(1405);
    // 00:00 Tehran (UTC+3:30, no DST) is 20:30 UTC the evening before.
    expect(OpeningSetup::DATE)->toBe($period['start_date'])
        ->and(PersianDate::fromGregorian(OpeningSetup::DATE))->toBe('1405-05-20')
        ->and($period['end_exclusive_date'])->toBe('2027-08-11')
        ->and($period['start_at_utc'])->toBe('2026-08-10T20:30:00.000000Z')
        ->and($period['end_exclusive_at_utc'])->toBe('2027-08-10T20:30:00.000000Z')
        ->and(KhumsYearSchedule::dueStatus($period, '2027-08-10T20:29:59.999999Z'))->toBe('not_due')
        ->and(KhumsYearSchedule::dueStatus($period, '2027-08-10T20:30:00.000000Z'))->toBe('due');

    $ids = baseOpening(['بانک' => ['bank', '1000']]);
    $catalog = app(AssetCatalog::class);
    $instrument = $catalog->createInstrument('BASE-EDGE', 'سهم مرز', 'stock', 0);
    $holding = $catalog->createHolding($instrument, 'سبد مرز');
    expect(fn () => baseBuy($holding, $ids['بانک'], OpeningSetup::DATE, '1', '100'))->toThrow(InvalidArgumentException::class);
    app(BackdatedPosting::class)->buy([
        'account_id' => $holding, 'cash_account_id' => $ids['بانک'], 'date' => OpeningSetup::DATE,
        'quantity' => '1', 'price_toman' => '100', 'fee_toman' => '0', 'idempotency_key' => baseKey(), 'after_opening' => true,
    ]);
    baseBuy($holding, $ids['بانک'], '2027-08-10', '2', '100');
    baseBuy($holding, $ids['بانک'], '2027-08-11', '4', '100');
    $catalog->recordPrice($instrument, '2027-08-10', '1000', 'منبع ساختگی');
    $catalog->recordPrice($instrument, '2027-08-11', '2000', 'منبع روز مرز ساختگی');

    // The last day of 1405 sees 3 units at 1000 rial; boundary-day trade and price belong to 1406.
    $lastDay = app(AssetOverview::class)->holdings('2027-08-10')[0];
    expect($lastDay['quantity'])->toBe('3')
        ->and($lastDay['market_toman'])->toBe('300')
        ->and(app(AssetOverview::class)->holdings('2027-08-11')[0]['market_toman'])->toBe('1400');
    baseAssertInvariants();
});
