<?php

use App\Support\Khums\ProtectedLotBasis;

it('uses the previously settled value instead of economic purchase cost and conserves fractional basis', function (): void {
    // Economic cost is deliberately absent: prior settled value is 100, sale is 130.
    $whole = ProtectedLotBasis::exit('3', '100', '3', '130');
    expect($whole['allocated_basis_exact_rial'])->toBe('100')
        ->and($whole['increase_candidate_exact_rial'])->toBe('30')
        ->and($whole['eligible_rial'])->toBeNull();
    $first = ProtectedLotBasis::exit('3', '100', '1', '50');
    $second = ProtectedLotBasis::exit($first['remaining_atoms'], $first['remaining_basis_exact_rial'], '1', '50');
    $last = ProtectedLotBasis::exit($second['remaining_atoms'], $second['remaining_basis_exact_rial'], '1', '50');
    expect($first['allocated_basis_exact_rial'])->toBe('100/3')
        ->and($second['allocated_basis_exact_rial'])->toBe('100/3')
        ->and($last['allocated_basis_exact_rial'])->toBe('100/3')
        ->and($last['remaining_basis_exact_rial'])->toBe('0')
        ->and($last['remaining_atoms'])->toBe('0');
});

it('does not manufacture protected cash beyond proceeds or allow excess quantity', function (): void {
    $loss = ProtectedLotBasis::exit('2', '100', '1', '30');
    expect($loss['protected_proceeds_exact_rial'])->toBe('30')
        ->and($loss['increase_candidate_exact_rial'])->toBe('0')
        ->and($loss['remaining_basis_exact_rial'])->toBe('50')
        ->and(fn () => ProtectedLotBasis::exit('2', '100', '3', '100'))->toThrow(InvalidArgumentException::class);
});
