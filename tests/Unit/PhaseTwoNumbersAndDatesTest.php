<?php

use App\Support\Dates\EffectiveTime;
use App\Support\Dates\PersianDate;
use App\Support\Numbers\AssetQuantity;
use App\Support\Numbers\MoneyRial;
use App\Support\Numbers\RialAllocation;
use Brick\Math\Exception\RoundingNecessaryException;

it('converts Persian and Arabic digits to exact rial and rejects sub-rial or ambiguous input', function () {
    expect(MoneyRial::fromToman('۱۰٬۰۰۰٬۰۰۰')->rial())->toBe('100000000')
        ->and(MoneyRial::fromRial('11')->toman())->toBe('1.1')
        ->and(MoneyRial::fromToman('-۱٫۱')->rial())->toBe('-11');

    expect(fn () => MoneyRial::fromToman('1.01'))->toThrow(RoundingNecessaryException::class);
    foreach (['1e3', '1,23', 'NaN', '۱.۲.۳'] as $bad) {
        expect(fn () => MoneyRial::fromToman($bad))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => MoneyRial::fromRial('9223372036854775808'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MoneyRial::fromRial('-9223372036854775809'))->toThrow(InvalidArgumentException::class);
});

it('rounds only final rational-to-rial conversion with symmetric half up', function () {
    expect(MoneyRial::fromRatio('1', '2')->rial())->toBe('1')
        ->and(MoneyRial::fromRatio('-1', '2')->rial())->toBe('-1')
        ->and(MoneyRial::fromRatio('1', '3')->rial())->toBe('0');
});

it('allocates exact rial without losing or inventing remainder', function () {
    expect(RialAllocation::distribute(MoneyRial::fromRial('100'), ['a' => '1', 'b' => '1', 'c' => '1']))
        ->toBe(['a' => '34', 'b' => '33', 'c' => '33'])
        ->and(RialAllocation::distribute(MoneyRial::fromRial('-100'), ['a' => '1', 'b' => '1', 'c' => '1']))
        ->toBe(['a' => '-34', 'b' => '-33', 'c' => '-33']);
});

it('keeps high precision quantities as strings', function () {
    $value = AssetQuantity::fromDecimal('123456789012345678901234567890.123456789012345678', 18);
    expect($value->atoms())->toBe('123456789012345678901234567890123456789012345678')
        ->and($value->decimal())->toBe('123456789012345678901234567890.123456789012345678');
    expect(fn () => AssetQuantity::fromDecimal('0.0000000000000000001', 18))->toThrow(RoundingNecessaryException::class);
});

it('uses the Persian calendar for leap boundaries and keeps unknown time unknown', function () {
    expect(PersianDate::toGregorian(1405, 5, 20))->toBe('2026-08-11')
        ->and(PersianDate::parseInput('۱۴۰۵/۰۵/۲۰'))->toBe('2026-08-11')
        ->and(PersianDate::fromGregorian('2026-08-11'))->toBe('1405-05-20')
        ->and(PersianDate::toGregorian(1406, 5, 20))->toBe('2027-08-11')
        ->and(EffectiveTime::dateOnly('2026-08-11')->utcInstant)->toBeNull()
        ->and(EffectiveTime::atLocalTime('2026-08-11', '12:30')->utcInstant)->toBe('2026-08-11T09:00:00.000000Z');
    expect(fn () => PersianDate::toGregorian(1403, 12, 31))->toThrow(InvalidArgumentException::class);
});
