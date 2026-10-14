<?php

use App\Actions\Finance\CashBook;
use App\Actions\Finance\CashOverview;
use App\Actions\Finance\OpeningSetup;
use App\Livewire\CashSetup;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function cashTestOpening(OpeningSetup $setup): array
{
    $setup->saveSettings(true);
    $setup->addItem('bank', 'بانک ساختگی', '100', '60', 'user_declared');
    $setup->addItem('cash', 'نقد ساختگی', '20', '0', 'unknown');
    $setup->addItem('payable', 'بدهی ساختگی', '30', '0', 'unknown');
    $revision = (int) $setup->session()->revision;
    $openingId = $setup->confirm($revision, 1, 'synthetic-opening-001');
    $bank = DB::table('accounts')->where('name', 'بانک ساختگی')->first();
    $cash = DB::table('accounts')->where('name', 'نقد ساختگی')->first();
    $debt = DB::table('accounts')->where('name', 'بدهی ساختگی')->first();

    return [$openingId, $bank, $cash, $debt];
}

it('posts a balanced opening with per-item declaration and no synthetic income', function (): void {
    $this->actingAs(User::factory()->create());
    $setup = app(OpeningSetup::class);
    [$openingId, $bank, $cash, $debt] = cashTestOpening($setup);

    expect(DB::table('journal_lines')->where('operation_id', $openingId)->where('side', 'debit')->orderBy('line_no')->pluck('amount_rial')->map(strval(...))->all())->toBe(['1000', '200'])
        ->and(DB::table('journal_lines')->where('operation_id', $openingId)->where('side', 'credit')->orderBy('line_no')->pluck('amount_rial')->map(strval(...))->all())->toBe(['300', '900'])
        ->and(DB::table('operations')->where('type', 'income')->count())->toBe(0)
        ->and((string) $bank->protected_min_rial)->toBe('600')
        ->and((string) $bank->protected_max_rial)->toBe('1000')
        ->and(DB::table('user_decisions')->where('kind', 'opening_protected_declaration')->count())->toBe(1)
        ->and(DB::table('user_decisions')->where('kind', 'previous_khums_settled')->count())->toBe(1)
        ->and($setup->confirm(3, 1, 'synthetic-opening-001'))->toBe($openingId);
    expect(fn () => $setup->addItem('cash', 'late', '1', '0', 'unknown'))->toThrow(RuntimeException::class);
});

it('keeps journal, balances and provenance together and avoids protected overclaim for mixed funds', function (): void {
    $this->actingAs(User::factory()->create());
    [, $bank, $cash, $debt] = cashTestOpening(app(OpeningSetup::class));
    $book = app(CashBook::class);
    $expense = $book->post([
        'type' => 'expense', 'date' => '2026-08-12', 'amount_toman' => '10',
        'source_id' => $bank->id, 'idempotency_key' => 'synthetic-expense-001',
    ]);
    $transfer = $book->post([
        'type' => 'transfer', 'date' => '2026-08-12', 'amount_toman' => '20',
        'source_id' => $bank->id, 'target_id' => $cash->id, 'idempotency_key' => 'synthetic-transfer-001',
    ]);
    $book->post([
        'type' => 'income', 'date' => '2026-08-13', 'amount_toman' => '5',
        'target_id' => $bank->id, 'idempotency_key' => 'synthetic-income-001',
    ]);
    $book->post([
        'type' => 'repay', 'date' => '2026-08-13', 'amount_toman' => '5',
        'source_id' => $bank->id, 'target_id' => $debt->id, 'idempotency_key' => 'synthetic-repay-001',
    ]);

    $updatedBank = DB::table('accounts')->find($bank->id);
    $updatedCash = DB::table('accounts')->find($cash->id);
    expect((string) $updatedBank->balance_rial)->toBe('700')
        ->and((string) $updatedCash->balance_rial)->toBe('400')
        ->and((string) DB::table('accounts')->find($debt->id)->balance_rial)->toBe('250')
        ->and((string) $updatedBank->protected_min_rial)->toBe('250')
        ->and((string) $updatedCash->protected_min_rial)->toBe('0')
        ->and(DB::table('operations')->where('type', 'transfer')->count())->toBe(1)
        ->and($book->post([
            'type' => 'expense', 'date' => '2026-08-12', 'amount_toman' => '10',
            'source_id' => $bank->id, 'idempotency_key' => 'synthetic-expense-001',
        ]))->toBe($expense);

    foreach ([$expense, $transfer] as $id) {
        $debits = DB::table('journal_lines')->where('operation_id', $id)->where('side', 'debit')->pluck('amount_rial');
        $credits = DB::table('journal_lines')->where('operation_id', $id)->where('side', 'credit')->pluck('amount_rial');
        expect($debits->map(fn ($value) => (string) $value)->all())->toBe($credits->map(fn ($value) => (string) $value)->all());
    }
});

it('rejects insufficient funds, conflicting retries and older transactions without partial writes', function (): void {
    $this->actingAs(User::factory()->create());
    [, $bank, $cash] = cashTestOpening(app(OpeningSetup::class));
    $book = app(CashBook::class);
    $book->post([
        'type' => 'transfer', 'date' => '2026-08-12', 'amount_toman' => '1',
        'source_id' => $bank->id, 'target_id' => $cash->id, 'idempotency_key' => 'synthetic-transfer-002',
    ]);
    $before = DB::table('operations')->count();
    expect(fn () => $book->post([
        'type' => 'transfer', 'date' => '2026-08-12', 'amount_toman' => '2',
        'source_id' => $bank->id, 'target_id' => $cash->id, 'idempotency_key' => 'synthetic-transfer-002',
    ]))->toThrow(RuntimeException::class);
    expect(fn () => $book->post([
        'type' => 'expense', 'date' => '2026-08-13', 'amount_toman' => '1000',
        'source_id' => $bank->id, 'idempotency_key' => 'synthetic-expense-002',
    ]))->toThrow(RuntimeException::class);
    expect(fn () => $book->post([
        'type' => 'income', 'date' => '2026-08-11', 'amount_toman' => '2',
        'target_id' => $bank->id, 'after_opening' => true, 'idempotency_key' => 'synthetic-income-002',
    ]))->toThrow(RuntimeException::class);
    expect(DB::table('operations')->count())->toBe($before)
        ->and((string) DB::table('accounts')->find($bank->id)->balance_rial)->toBe('990');
});

it('requires an active owner to use financial actions', function (): void {
    expect(fn () => app(OpeningSetup::class)->saveSettings(true))->toThrow(AuthorizationException::class);
    expect(fn () => app(CashBook::class)->createAccount('ساختگی', 'bank'))->toThrow(AuthorizationException::class);
});

it('keeps the cash setup and workspace private and renders Persian forms', function (): void {
    $this->get('/setup/cash')->assertRedirect(route('login'));
    $this->get('/cash')->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create());
    $this->get('/setup/cash')->assertOk()->assertSee('موجودی ۲۰ مرداد ۱۴۰۵');
    $this->get('/cash')->assertOk()->assertSee('ابتدا افتتاحیه نقدی را ثبت کنید');
    Livewire::test(CashSetup::class)
        ->set('kind', 'bank')->set('name', 'بانک ساختگی')->set('amountToman', '۱۰')
        ->set('protectedToman', '۵')->set('evidence', 'user_declared')
        ->call('addItem')->assertHasNoErrors()->assertSee('بانک ساختگی');
    expect(DB::table('operations')->count())->toBe(0);
});

it('reverses the latest posted expense with a linked new document and restores provenance', function (): void {
    $this->actingAs(User::factory()->create());
    [, $bank] = cashTestOpening(app(OpeningSetup::class));
    $book = app(CashBook::class);
    $original = $book->post([
        'type' => 'expense', 'date' => '2026-08-12', 'amount_toman' => '10',
        'source_id' => $bank->id, 'idempotency_key' => 'synthetic-expense-003',
    ]);
    $beforeLines = DB::table('journal_lines')->where('operation_id', $original)->orderBy('line_no')->get()->toArray();
    $reversal = $book->reverseLatest($original, 'اشتباه در ثبت ساختگی', 'synthetic-reversal-001');
    expect((int) DB::table('operations')->find($reversal)->reversal_of_id)->toBe($original)
        ->and(DB::table('journal_lines')->where('operation_id', $original)->orderBy('line_no')->get()->toArray())->toEqual($beforeLines)
        ->and((string) DB::table('accounts')->find($bank->id)->balance_rial)->toBe('1000')
        ->and((string) DB::table('accounts')->find($bank->id)->protected_min_rial)->toBe('600')
        ->and(app(CashOverview::class)->incomeExpense()['expense_toman'])->toBe('0')
        ->and($book->reverseLatest($original, 'اشتباه در ثبت ساختگی', 'synthetic-reversal-001'))->toBe($reversal);
    expect(fn () => $book->reverseLatest($original, 'دوباره', 'synthetic-reversal-002'))->toThrow(RuntimeException::class);
});

it('rolls back journal, projection and audit when provenance persistence fails', function (): void {
    $this->actingAs(User::factory()->create());
    [, $bank] = cashTestOpening(app(OpeningSetup::class));
    $beforeOperations = DB::table('operations')->count();
    $beforeLines = DB::table('journal_lines')->count();
    $beforeEvents = DB::table('provenance_events')->count();
    DB::statement("CREATE TRIGGER synthetic_fail_provenance BEFORE INSERT ON provenance_events WHEN NEW.origin_kind = 'expense' BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
    expect(fn () => app(CashBook::class)->post([
        'type' => 'expense', 'date' => '2026-08-12', 'amount_toman' => '10',
        'source_id' => $bank->id, 'idempotency_key' => 'synthetic-expense-fail',
    ]))->toThrow(QueryException::class);
    expect(DB::table('operations')->count())->toBe($beforeOperations)
        ->and(DB::table('journal_lines')->count())->toBe($beforeLines)
        ->and(DB::table('provenance_events')->count())->toBe($beforeEvents)
        ->and((string) DB::table('accounts')->find($bank->id)->balance_rial)->toBe('1000');
});

it('requires explicit no-items confirmation and a boundary relation for opening-day operations', function (): void {
    $this->actingAs(User::factory()->create());
    $setup = app(OpeningSetup::class);
    expect(fn () => $setup->confirm(0, 1, 'synthetic-empty-open'))->toThrow(RuntimeException::class);
    $setup->addItem('bank', 'بانک صفر ساختگی', '0', '0', 'unknown');
    $setup->confirm((int) $setup->session()->revision, 1, 'synthetic-zero-open');
    expect(DB::table('operations')->count())->toBe(0)
        ->and(DB::table('accounts')->where('name', 'بانک صفر ساختگی')->count())->toBe(1);
    $id = (int) DB::table('accounts')->where('name', 'بانک صفر ساختگی')->value('id');
    expect(fn () => app(CashBook::class)->post([
        'type' => 'income', 'date' => '2026-08-11', 'amount_toman' => '1',
        'target_id' => $id, 'idempotency_key' => 'synthetic-boundary-001',
    ]))->toThrow(InvalidArgumentException::class);
    app(CashBook::class)->post([
        'type' => 'income', 'date' => '2026-08-11', 'amount_toman' => '1',
        'target_id' => $id, 'after_opening' => true, 'idempotency_key' => 'synthetic-boundary-002',
    ]);
    expect((string) DB::table('accounts')->find($id)->balance_rial)->toBe('10');
});

it('confirms an explicitly empty opening once without fabricating a zero journal', function (): void {
    $this->actingAs(User::factory()->create());
    $setup = app(OpeningSetup::class);
    expect($setup->confirm(0, 1, 'synthetic-empty-confirm', true))->toBeNull()
        ->and($setup->confirm(0, 1, 'synthetic-empty-confirm', true))->toBeNull()
        ->and(DB::table('operations')->count())->toBe(0)
        ->and(DB::table('setup_sessions')->where('status', 'confirmed')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'opening_empty_confirmed')->count())->toBe(1);
});

it('keeps income and expense categories distinct', function (): void {
    $this->actingAs(User::factory()->create());
    [, $bank] = cashTestOpening(app(OpeningSetup::class));
    $book = app(CashBook::class);
    $category = $book->createCategory('دسته ساختگی', 'expense');
    expect(fn () => $book->post([
        'type' => 'income', 'date' => '2026-08-12', 'amount_toman' => '1',
        'target_id' => $bank->id, 'category_id' => $category, 'idempotency_key' => 'synthetic-category-001',
    ]))->toThrow(InvalidArgumentException::class);
    $posted = $book->post([
        'type' => 'expense', 'date' => '2026-08-12', 'amount_toman' => '1',
        'source_id' => $bank->id, 'category_id' => $category, 'idempotency_key' => 'synthetic-category-002',
    ]);
    expect((int) DB::table('operations')->find($posted)->category_id)->toBe($category)
        ->and(DB::table('categories')->count())->toBe(1);
});

it('records loan principal separately and recognizes accrued income only once', function (): void {
    $this->actingAs(User::factory()->create());
    [, $bank, , $debt] = cashTestOpening(app(OpeningSetup::class));
    $book = app(CashBook::class);
    $receivable = $book->createAccount('طلب ساختگی جدید', 'receivable');
    $book->post([
        'type' => 'borrow', 'date' => '2026-08-12', 'amount_toman' => '20',
        'source_id' => $debt->id, 'target_id' => $bank->id, 'idempotency_key' => 'synthetic-borrow-001',
    ]);
    $book->post([
        'type' => 'repay', 'date' => '2026-08-12', 'amount_toman' => '10',
        'source_id' => $bank->id, 'target_id' => $debt->id, 'idempotency_key' => 'synthetic-repay-002',
    ]);
    $book->post([
        'type' => 'lend', 'date' => '2026-08-12', 'amount_toman' => '15',
        'source_id' => $bank->id, 'target_id' => $receivable, 'idempotency_key' => 'synthetic-lend-001',
    ]);
    $book->post([
        'type' => 'collect', 'date' => '2026-08-12', 'amount_toman' => '5',
        'source_id' => $receivable, 'target_id' => $bank->id, 'idempotency_key' => 'synthetic-collect-001',
    ]);
    $book->post([
        'type' => 'accrue_income', 'date' => '2026-08-12', 'amount_toman' => '2',
        'target_id' => $receivable, 'idempotency_key' => 'synthetic-accrual-001',
    ]);
    $book->post([
        'type' => 'collect', 'date' => '2026-08-12', 'amount_toman' => '2',
        'source_id' => $receivable, 'target_id' => $bank->id, 'idempotency_key' => 'synthetic-collect-002',
    ]);
    expect((string) DB::table('accounts')->find($bank->id)->balance_rial)->toBe('1020')
        ->and((string) DB::table('accounts')->find($debt->id)->balance_rial)->toBe('400')
        ->and((string) DB::table('accounts')->find($receivable)->balance_rial)->toBe('100')
        ->and(app(CashOverview::class)->incomeExpense())->toBe(['income_toman' => '2', 'expense_toman' => '0']);
});

it('moves rial between bank, brokerage cash and wallet without recording income', function (): void {
    $this->actingAs(User::factory()->create());
    [, $bank] = cashTestOpening(app(OpeningSetup::class));
    $book = app(CashBook::class);
    $broker = $book->createAccount('نقد کارگزاری ساختگی', 'broker_cash');
    $wallet = $book->createAccount('کیف پول ساختگی', 'wallet_cash');
    $book->post([
        'type' => 'transfer', 'date' => '2026-08-12', 'amount_toman' => '10',
        'source_id' => $bank->id, 'target_id' => $broker, 'idempotency_key' => 'synthetic-broker-001',
    ]);
    $book->post([
        'type' => 'transfer', 'date' => '2026-08-12', 'amount_toman' => '5',
        'source_id' => $broker, 'target_id' => $wallet, 'idempotency_key' => 'synthetic-wallet-001',
    ]);
    expect((string) DB::table('accounts')->find($broker)->balance_rial)->toBe('50')
        ->and((string) DB::table('accounts')->find($wallet)->balance_rial)->toBe('50')
        ->and(app(CashOverview::class)->incomeExpense()['income_toman'])->toBe('0');
});
