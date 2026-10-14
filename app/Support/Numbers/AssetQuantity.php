<?php

namespace App\Support\Numbers;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use InvalidArgumentException;

final readonly class AssetQuantity
{
    private function __construct(private BigInteger $atoms, public int $scale) {}

    public static function fromDecimal(string $value, int $scale): self
    {
        if ($scale < 0 || $scale > 18) {
            throw new InvalidArgumentException('Unsupported quantity scale.');
        }

        $atoms = BigDecimal::of(DecimalInput::normalize($value))->toScale($scale)->getUnscaledValue();
        if (strlen(ltrim((string) $atoms, '-')) > 78) {
            throw new InvalidArgumentException('Quantity exceeds 78 atoms digits.');
        }

        return new self($atoms, $scale);
    }

    public function atoms(): string
    {
        return (string) $this->atoms;
    }

    public function decimal(): string
    {
        return (string) BigDecimal::ofUnscaledValue($this->atoms, $this->scale)->strippedOfTrailingZeros();
    }

    public function add(self $other): self
    {
        if ($this->scale !== $other->scale) {
            throw new InvalidArgumentException('Quantity scales must match.');
        }

        return self::fromDecimal((string) BigDecimal::ofUnscaledValue($this->atoms->plus($other->atoms), $this->scale), $this->scale);
    }
}
