<?php

use App\Support\Numbers\EconomicReturn;

it('matches independent no-flow, contribution and withdrawal purchasing-power examples', function (): void {
    expect(EconomicReturn::calculate('100000000', '150000000', '100', '125', []))
        ->toMatchArray(['nominal_rial' => '50000000', 'real_rial' => '25000000']);
    expect(EconomicReturn::calculate('100000000', '210000000', '100', '150', [
        ['amount_rial' => '50000000', 'cpi' => '125'],
    ]))->toMatchArray(['nominal_rial' => '60000000', 'real_rial' => '0']);
    expect(EconomicReturn::calculate('100000000', '120000000', '100', '150', [
        ['amount_rial' => '-20000000', 'cpi' => '125'],
    ]))->toMatchArray(['nominal_rial' => '40000000', 'real_rial' => '-6000000']);
});

it('keeps fixed-scale CPI rebasing invariant and rounds only the final result', function (): void {
    $first = EconomicReturn::calculate('1001', '1511', '100.5', '125.625', [
        ['amount_rial' => '101', 'cpi' => '110.55'],
    ]);
    $rebased = EconomicReturn::calculate('1001', '1511', '201', '251.25', [
        ['amount_rial' => '101', 'cpi' => '221.1'],
    ]);
    expect($first['nominal_rial'])->toBe('409')
        ->and($first['real_rial'])->toBe($rebased['real_rial'])
        ->and($first['inflation_difference_rial'])->toBe($rebased['inflation_difference_rial']);
});

it('rejects zero or negative CPI levels rather than producing a false return', function (): void {
    expect(fn () => EconomicReturn::calculate('100', '110', '0', '120', []))->toThrow(InvalidArgumentException::class);
    expect(fn () => EconomicReturn::calculate('100', '110', '100', '120', [
        ['amount_rial' => '10', 'cpi' => '-1'],
    ]))->toThrow(InvalidArgumentException::class);
});
