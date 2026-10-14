<?php

namespace App\Support\Dates;

use DateTimeImmutable;
use DateTimeZone;
use IntlCalendar;
use InvalidArgumentException;
use RuntimeException;

final class PersianDate
{
    public static function parseInput(string $input): string
    {
        $value = strtr(trim($input), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        if (! preg_match('/^([0-9]{4})[\/-]([0-9]{1,2})[\/-]([0-9]{1,2})$/D', $value, $matches)) {
            throw new InvalidArgumentException('Invalid Persian date format.');
        }

        return self::toGregorian((int) $matches[1], (int) $matches[2], (int) $matches[3]);
    }

    public static function toGregorian(int $year, int $month, int $day): string
    {
        if ($year < 1 || $year > 9999 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            throw new InvalidArgumentException('Invalid Persian date.');
        }

        $calendar = self::calendar();
        $calendar->clear();
        $calendar->setLenient(false);
        $calendar->setDateTime($year, $month - 1, $day, 0, 0, 0);
        $millis = $calendar->getTime();
        if ($millis === false) {
            throw new InvalidArgumentException('Invalid Persian date.');
        }

        return gmdate('Y-m-d', intdiv((int) $millis, 1000));
    }

    public static function fromGregorian(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invalid Gregorian date.');
        }

        $calendar = self::calendar();
        $calendar->setTime($parsed->getTimestamp() * 1000);

        return sprintf('%04d-%02d-%02d', $calendar->get(IntlCalendar::FIELD_YEAR), $calendar->get(IntlCalendar::FIELD_MONTH) + 1, $calendar->get(IntlCalendar::FIELD_DAY_OF_MONTH));
    }

    private static function calendar(): IntlCalendar
    {
        $calendar = IntlCalendar::createInstance('UTC', 'en_US@calendar=persian');
        if ($calendar === null || $calendar->getType() !== 'persian') {
            throw new RuntimeException('ICU Persian calendar is unavailable.');
        }

        return $calendar;
    }
}
