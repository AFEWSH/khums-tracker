<?php

namespace App\Support\Dates;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class EffectiveTime
{
    private function __construct(
        public string $localDate,
        public ?string $utcInstant,
        public string $precision,
        public string $timezone,
    ) {}

    public static function dateOnly(string $gregorianDate, string $timezone = 'Asia/Tehran'): self
    {
        self::validateDate($gregorianDate);
        new DateTimeZone($timezone);

        return new self($gregorianDate, null, 'date', $timezone);
    }

    public static function atLocalTime(string $gregorianDate, string $localClock, string $timezone = 'Asia/Tehran'): self
    {
        self::validateDate($gregorianDate);
        if (! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D', $localClock)) {
            throw new InvalidArgumentException('Invalid local time.');
        }

        $zone = new DateTimeZone($timezone);
        $clock = strlen($localClock) === 5 ? $localClock.':00' : $localClock;
        $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', "$gregorianDate $clock", $zone);
        if ($local === false || $local->format('Y-m-d H:i:s') !== "$gregorianDate $clock") {
            throw new InvalidArgumentException('Nonexistent local time.');
        }

        return new self($gregorianDate, $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'), 'instant', $timezone);
    }

    private static function validateDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invalid Gregorian date.');
        }
    }
}
