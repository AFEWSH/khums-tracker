<?php

namespace App\Support\Numbers;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class FifoLots
{
    /** @param iterable<object> $lots
     * @return array{allocations:array<int,array{lot_id:int,atoms:string,cost_rial:string}>,cost_rial:string}
     */
    public static function allocate(iterable $lots, string $atoms): array
    {
        $wanted = BigInteger::of($atoms);
        if ($wanted->isNegative() || $wanted->isZero()) {
            throw new InvalidArgumentException('Exit quantity must be positive.');
        }
        $remaining = $wanted;
        $cost = MoneyRial::fromRial('0');
        $allocations = [];
        foreach ($lots as $lot) {
            $available = BigInteger::of((string) $lot->remaining_atoms);
            if ($available->isZero()) {
                continue;
            }
            if ($available->isNegative()) {
                throw new InvalidArgumentException('Negative lot quantity.');
            }
            $take = $remaining->compareTo($available) < 0 ? $remaining : $available;
            $existingCost = MoneyRial::fromRial((string) $lot->remaining_cost_rial);
            $allocatedCost = $take->compareTo($available) === 0
                ? $existingCost
                : MoneyRial::fromRial((string) BigInteger::of($existingCost->rial())
                    ->multipliedBy($take)->dividedBy($available, RoundingMode::HalfUp));
            $allocations[] = ['lot_id' => (int) $lot->id, 'atoms' => (string) $take, 'cost_rial' => $allocatedCost->rial()];
            $cost = $cost->add($allocatedCost);
            $remaining = $remaining->minus($take);
            if ($remaining->isZero()) {
                break;
            }
        }
        if (! $remaining->isZero()) {
            throw new InvalidArgumentException('Insufficient asset quantity.');
        }

        return ['allocations' => $allocations, 'cost_rial' => $cost->rial()];
    }
}
