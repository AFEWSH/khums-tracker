<?php

use App\Support\Numbers\FifoLots;

it('allocates FIFO economic cost exactly and assigns all residual cost on the final exit', function (): void {
    $lots = [(object) ['id' => 10, 'remaining_atoms' => '3', 'remaining_cost_rial' => '100'],
        (object) ['id' => 11, 'remaining_atoms' => '2', 'remaining_cost_rial' => '80']];
    expect(FifoLots::allocate($lots, '4'))->toBe([
        'allocations' => [
            ['lot_id' => 10, 'atoms' => '3', 'cost_rial' => '100'],
            ['lot_id' => 11, 'atoms' => '1', 'cost_rial' => '40'],
        ], 'cost_rial' => '140',
    ]);
    expect(FifoLots::allocate([(object) ['id' => 1, 'remaining_atoms' => '3', 'remaining_cost_rial' => '100']], '1')['cost_rial'])->toBe('33');
    expect(FifoLots::allocate([(object) ['id' => 1, 'remaining_atoms' => '2', 'remaining_cost_rial' => '67']], '2')['cost_rial'])->toBe('67');
    expect(fn () => FifoLots::allocate($lots, '6'))->toThrow(InvalidArgumentException::class);
});
