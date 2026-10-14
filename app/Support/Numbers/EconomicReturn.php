<?php

namespace App\Support\Numbers;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Pure economic purchasing-power arithmetic; it does not decide CPI months or religious treatment. */
final class EconomicReturn
{
    /** @param array<int,array{amount_rial:string,cpi:string}> $externalFlows */
    public static function calculate(string $openingRial, string $closingRial, string $openingCpi, string $closingCpi, array $externalFlows): array
    {
        $opening = MoneyRial::fromRial($openingRial);
        $closing = MoneyRial::fromRial($closingRial);
        $startIndex = self::index($openingCpi);
        $endIndex = self::index($closingCpi);
        $nominal = $closing->subtract($opening);
        $real = BigRational::of($closing->rial())
            ->minus(BigRational::of($opening->rial())->multipliedBy($endIndex)->dividedBy($startIndex));
        $components = [];
        foreach ($externalFlows as $flow) {
            $amount = MoneyRial::fromRial($flow['amount_rial']);
            $index = self::index($flow['cpi']);
            $nominal = $nominal->subtract($amount);
            $adjusted = BigRational::of($amount->rial())->multipliedBy($endIndex)->dividedBy($index);
            $real = $real->minus($adjusted);
            $components[] = ['amount_rial' => $amount->rial(), 'cpi' => $flow['cpi'],
                'adjusted_rial' => MoneyRial::fromRial((string) $adjusted->toScale(0, RoundingMode::HalfUp)->toBigInteger())->rial()];
        }
        $realMoney = MoneyRial::fromRial((string) $real->toScale(0, RoundingMode::HalfUp)->toBigInteger());

        return [
            'nominal_rial' => $nominal->rial(), 'real_rial' => $realMoney->rial(),
            'inflation_difference_rial' => $realMoney->subtract($nominal)->rial(),
            'flow_components' => $components,
        ];
    }

    private static function index(string $value): BigRational
    {
        $normal = DecimalInput::normalize($value);
        $index = BigRational::of($normal);
        if ($index->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException('CPI level must be positive.');
        }

        return $index;
    }
}
