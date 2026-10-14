<?php

namespace App\Support\Numbers;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final readonly class MoneyRial
{
    private function __construct(private BigInteger $rial) {}

    public static function fromRial(string $value): self
    {
        $number = DecimalInput::normalize($value);
        if (! preg_match('/^[+-]?[0-9]+$/D', $number)) {
            throw new InvalidArgumentException('Rial amount must be whole.');
        }

        return self::checked(BigInteger::of($number));
    }

    public static function fromToman(string $value): self
    {
        $number = BigDecimal::of(DecimalInput::normalize($value))->multipliedBy(10);

        return self::checked($number->toBigInteger());
    }

    public static function fromRatio(string $numerator, string $denominator): self
    {
        $result = BigInteger::of($numerator)->dividedBy($denominator, RoundingMode::HalfUp);

        return self::checked($result);
    }

    public function add(self $other): self
    {
        return self::checked($this->rial->plus($other->rial));
    }

    public function subtract(self $other): self
    {
        return self::checked($this->rial->minus($other->rial));
    }

    public function rial(): string
    {
        return (string) $this->rial;
    }

    public function toman(): string
    {
        return (string) BigDecimal::ofUnscaledValue($this->rial, 1)->strippedOfTrailingZeros();
    }

    private static function checked(BigInteger $value): self
    {
        if ($value->compareTo('9223372036854775807') > 0 || $value->compareTo('-9223372036854775808') < 0) {
            throw new InvalidArgumentException('Rial amount exceeds signed BIGINT range.');
        }

        return new self($value);
    }
}
