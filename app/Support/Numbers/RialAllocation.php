<?php

namespace App\Support\Numbers;

use Brick\Math\BigInteger;
use InvalidArgumentException;

final class RialAllocation
{
    /** @param array<string, string> $weights
     * @return array<string, string>
     */
    public static function distribute(MoneyRial $amount, array $weights): array
    {
        if ($weights === []) {
            throw new InvalidArgumentException('At least one weight is required.');
        }

        $total = BigInteger::zero();
        foreach ($weights as $weight) {
            if (! preg_match('/^[0-9]+$/D', $weight)) {
                throw new InvalidArgumentException('Weights must be nonnegative whole strings.');
            }
            $total = $total->plus($weight);
        }
        if ($total->isZero()) {
            throw new InvalidArgumentException('Total weight must be positive.');
        }

        $negative = str_starts_with($amount->rial(), '-');
        $absolute = BigInteger::of($amount->rial())->abs();
        $shares = [];
        $used = BigInteger::zero();
        foreach ($weights as $id => $weight) {
            [$base, $remainder] = $absolute->multipliedBy($weight)->quotientAndRemainder($total);
            $shares[$id] = ['base' => $base, 'remainder' => $remainder];
            $used = $used->plus($base);
        }

        $remaining = $absolute->minus($used)->toInt();
        $order = array_keys($shares);
        usort($order, function ($a, $b) use ($shares): int {
            $fractionOrder = $shares[$b]['remainder']->compareTo($shares[$a]['remainder']);

            return $fractionOrder !== 0 ? $fractionOrder : strcmp((string) $a, (string) $b);
        });
        for ($i = 0; $i < $remaining; $i++) {
            $shares[$order[$i]]['base'] = $shares[$order[$i]]['base']->plus(1);
        }

        $result = [];
        foreach ($shares as $id => $share) {
            $value = $negative ? $share['base']->negated() : $share['base'];
            $result[$id] = (string) $value;
        }

        return $result;
    }
}
