<?php

use App\Support\Khums\ProtectedOriginReplay;
use Brick\Math\BigRational;

function originOpeningLot(): array
{
    return ['id' => 1, 'type' => 'opening', 'date' => '2026-08-11', 'cash_lines' => [], 'opening_cash' => [],
        'new_lots' => [['lot_id' => 1, 'account_id' => 10, 'atoms' => '3', 'opening_basis_rial' => '100']], 'exit' => null];
}

it('preserves exact thirds through successive physical sales', function (): void {
    $operations = [originOpeningLot()];
    $plan = [];
    foreach ([2, 3] as $id) {
        $operations[] = ['id' => $id, 'type' => 'asset_sell', 'date' => '2027-01-0'.$id,
            'cash_lines' => [['account_id' => 20, 'side' => 'debit', 'amount_rial' => '50']],
            'opening_cash' => [], 'new_lots' => [], 'exit' => ['account_id' => 10, 'atoms' => '1',
                'cash_account_id' => 20, 'net_proceeds_rial' => '50', 'economic_cost_rial' => '20']];
        $plan[$id] = ['lots' => [1 => ['atoms' => '1', 'net_proceeds_rial' => '50']]];
    }
    $trace = ProtectedOriginReplay::evaluate($operations, $plan);
    expect($trace['holdings'][0]['protected_basis_exact_rial'])->toBe('100/3')
        ->and($trace['cash_positions'][0]['protected_basis_exact_rial'])->toBe('200/3')
        ->and((string) BigRational::of($trace['holdings'][0]['protected_basis_exact_rial'])
            ->plus($trace['cash_positions'][0]['protected_basis_exact_rial']))->toBe('100')
        ->and($trace['sales'][0]['eligible_rial'])->toBeNull();
});

it('never restores basis lost during a conversion when the acquired asset later rises', function (): void {
    $conversion = ['id' => 2, 'type' => 'asset_convert', 'date' => '2027-01-02', 'cash_lines' => [], 'opening_cash' => [],
        'new_lots' => [['lot_id' => 2, 'account_id' => 11, 'atoms' => '5', 'opening_basis_rial' => null]],
        'exit' => ['account_id' => 10, 'atoms' => '3', 'cash_account_id' => null, 'net_proceeds_rial' => '60', 'economic_cost_rial' => '80']];
    $sale = ['id' => 3, 'type' => 'asset_sell', 'date' => '2027-01-03', 'opening_cash' => [], 'new_lots' => [],
        'cash_lines' => [['account_id' => 20, 'side' => 'debit', 'amount_rial' => '90']],
        'exit' => ['account_id' => 11, 'atoms' => '5', 'cash_account_id' => 20, 'net_proceeds_rial' => '90', 'economic_cost_rial' => '60']];
    $trace = ProtectedOriginReplay::evaluate([originOpeningLot(), $conversion, $sale], [
        2 => ['lots' => [1 => ['atoms' => '3', 'net_proceeds_rial' => '60']]],
        3 => ['lots' => [2 => ['atoms' => '5', 'net_proceeds_rial' => '90']]],
    ]);
    expect($trace['cash_positions'][0]['protected_basis_exact_rial'])->toBe('60')
        ->and($trace['sales'][1]['increase_candidate_exact_rial'])->toBe('30');
});

it('requires an explicit feasible choice for protected cash spending', function (array $choice): void {
    $opening = ['id' => 1, 'type' => 'opening', 'date' => '2026-08-11',
        'cash_lines' => [['account_id' => 20, 'side' => 'debit', 'amount_rial' => '100']],
        'opening_cash' => [20 => '100'], 'new_lots' => [], 'exit' => null];
    $transfer = ['id' => 2, 'type' => 'transfer', 'date' => '2027-01-02', 'opening_cash' => [], 'new_lots' => [], 'exit' => null,
        'cash_lines' => [['account_id' => 20, 'side' => 'credit', 'amount_rial' => '40'],
            ['account_id' => 21, 'side' => 'debit', 'amount_rial' => '40']]];
    expect(fn () => ProtectedOriginReplay::evaluate([$opening, $transfer], [2 => $choice]))->toThrow(InvalidArgumentException::class);
})->with([
    'missing choice' => [[]],
    'invented unprotected funds' => [['cash_spends' => [20 => '0']]],
    'excess protected spending' => [['cash_spends' => [20 => '41']]],
    'unknown source' => [['cash_spends' => [21 => '40']]],
]);

it('rejects a future lot and duplicate or phantom operations', function (): void {
    $opening = originOpeningLot();
    expect(fn () => ProtectedOriginReplay::evaluate([$opening, $opening], []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ProtectedOriginReplay::evaluate([$opening], [99 => []]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ProtectedOriginReplay::evaluate([$opening], [1 => 'malformed']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ProtectedOriginReplay::evaluate([$opening], [1 => ['cash_spends' => 'malformed']]))->toThrow(InvalidArgumentException::class);
    $sale = ['id' => 2, 'type' => 'asset_sell', 'date' => '2027-01-02', 'opening_cash' => [], 'new_lots' => [],
        'cash_lines' => [['account_id' => 20, 'side' => 'debit', 'amount_rial' => '50']],
        'exit' => ['account_id' => 10, 'atoms' => '1', 'cash_account_id' => 20, 'net_proceeds_rial' => '50', 'economic_cost_rial' => '20']];
    expect(fn () => ProtectedOriginReplay::evaluate([$opening, $sale], [2 => ['lots' => [99 => ['atoms' => '1', 'net_proceeds_rial' => '50']]]]))
        ->toThrow(InvalidArgumentException::class);
});

it('verifies purchase fees against the original commitment and preserves only the converted capital', function (): void {
    $opening = ['id' => 1, 'type' => 'opening', 'date' => '2026-08-11', 'new_lots' => [], 'exit' => null,
        'cash_lines' => [['account_id' => 20, 'side' => 'debit', 'amount_rial' => '100']], 'opening_cash' => [20 => '100']];
    $purchase = ['id' => 2, 'type' => 'asset_buy', 'date' => '2027-01-02', 'opening_cash' => [], 'exit' => null,
        'cash_lines' => [['account_id' => 20, 'side' => 'credit', 'amount_rial' => '100']],
        'new_lots' => [['lot_id' => 1, 'account_id' => 10, 'atoms' => '2', 'opening_basis_rial' => null]],
        'payload_hash' => hash('sha256', json_encode([10, 20, '2027-01-02', '2', '80', '20', false], JSON_THROW_ON_ERROR))];
    $plan = [2 => ['cash_spends' => [20 => '100'], 'acquisition_basis_rial' => '80', 'purchase_fee_rial' => '20']];
    $result = ProtectedOriginReplay::evaluate([$opening, $purchase], $plan);
    expect($result['holdings'][0]['protected_basis_exact_rial'])->toBe('80')
        ->and($result['cash_positions'][0]['protected_basis_exact_rial'])->toBe('0');
    foreach (['79', '81'] as $badBasis) {
        $invalid = $plan;
        $invalid[2]['acquisition_basis_rial'] = $badBasis;
        expect(fn () => ProtectedOriginReplay::evaluate([$opening, $purchase], $invalid))->toThrow(InvalidArgumentException::class);
    }
    $plan[2]['purchase_fee_rial'] = '0';
    expect(fn () => ProtectedOriginReplay::evaluate([$opening, $purchase], $plan))->toThrow(InvalidArgumentException::class);
});

it('keeps a purchase from explicitly selected new income separate from protected cash', function (): void {
    $opening = ['id' => 1, 'type' => 'opening', 'date' => '2026-08-11', 'new_lots' => [], 'exit' => null,
        'cash_lines' => [['account_id' => 20, 'side' => 'debit', 'amount_rial' => '100']], 'opening_cash' => [20 => '100']];
    $income = ['id' => 2, 'type' => 'income', 'date' => '2027-01-01', 'new_lots' => [], 'exit' => null,
        'cash_lines' => [['account_id' => 20, 'side' => 'debit', 'amount_rial' => '100']], 'opening_cash' => []];
    $purchase = ['id' => 3, 'type' => 'asset_buy', 'date' => '2027-01-02', 'opening_cash' => [], 'exit' => null,
        'cash_lines' => [['account_id' => 20, 'side' => 'credit', 'amount_rial' => '40']],
        'new_lots' => [['lot_id' => 1, 'account_id' => 10, 'atoms' => '2', 'opening_basis_rial' => null]],
        'payload_hash' => hash('sha256', json_encode([10, 20, '2027-01-02', '2', '40', '0', false], JSON_THROW_ON_ERROR))];
    $plan = [3 => ['cash_spends' => [20 => '0'], 'acquisition_basis_rial' => '0', 'purchase_fee_rial' => '0']];
    $trace = ProtectedOriginReplay::evaluate([$opening, $income, $purchase], $plan);
    expect($trace['holdings'][0]['protected_basis_exact_rial'])->toBe('0')
        ->and($trace['cash_positions'][0]['protected_basis_exact_rial'])->toBe('100');
    $plan[3]['acquisition_basis_rial'] = '40';
    expect(fn () => ProtectedOriginReplay::evaluate([$opening, $income, $purchase], $plan))->toThrow(InvalidArgumentException::class);
});
