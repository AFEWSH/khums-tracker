<?php

namespace App\Support\Khums;

use App\Support\Dates\EffectiveTime;
use App\Support\Dates\PersianDate;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Pure date and instant boundaries; a missing clock remains missing. */
final class KhumsYearSchedule
{
    /** User-confirmed local boundary for the first schedule version. */
    public const CONFIRMED_LOCAL_CLOCK = '00:00';

    public static function confirmedPeriod(int $startYear): array
    {
        return self::period($startYear, self::CONFIRMED_LOCAL_CLOCK);
    }

    /** List overdue candidates in order. A candidate never implies an approved closure. */
    public static function pendingPeriods(string $nowUtc, ?string $confirmedLocalClock = self::CONFIRMED_LOCAL_CLOCK): array
    {
        $first = self::period(1405, $confirmedLocalClock);
        self::dueStatus($first, $nowUtc);
        $localYear = (int) explode('-', PersianDate::fromGregorian(
            (new DateTimeImmutable($nowUtc))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d')
        ))[0];
        $periods = [];
        for ($year = 1405; $year < $localYear && $year <= 9998; $year++) {
            $period = $year === 1405 ? $first : self::period($year, $confirmedLocalClock);
            $status = self::dueStatus($period, $nowUtc);
            if ($status !== 'not_due') {
                $periods[] = ['period' => $period, 'due_status' => $status];
            }
        }

        return $periods;
    }

    /** A planning clock is never a confirmed religious boundary. */
    public static function scenarioPeriod(int $startYear, string $planningLocalClock): array
    {
        $period = self::period($startYear, $planningLocalClock);
        $period['boundary_status'] = 'scenario';

        return $period;
    }

    public static function period(int $startYear, ?string $confirmedLocalClock = null): array
    {
        if ($startYear < 1405 || $startYear > 9998) {
            throw new InvalidArgumentException('Khums period year is outside the supported range.');
        }

        $startDate = PersianDate::toGregorian($startYear, 5, 20);
        $endDate = PersianDate::toGregorian($startYear + 1, 5, 20);
        $startAt = $confirmedLocalClock === null ? null : EffectiveTime::atLocalTime($startDate, $confirmedLocalClock)->utcInstant;
        $endAt = $confirmedLocalClock === null ? null : EffectiveTime::atLocalTime($endDate, $confirmedLocalClock)->utcInstant;

        return [
            'start_persian' => sprintf('%04d-05-20', $startYear),
            'end_exclusive_persian' => sprintf('%04d-05-20', $startYear + 1),
            'start_date' => $startDate, 'end_exclusive_date' => $endDate,
            'start_at_utc' => $startAt, 'end_exclusive_at_utc' => $endAt,
            'local_clock' => $confirmedLocalClock, 'timezone' => 'Asia/Tehran',
            'boundary_status' => $confirmedLocalClock === null ? 'unconfirmed' : 'confirmed',
            'schedule_version' => 1,
        ];
    }

    public static function dueStatus(array $period, string $nowUtc): string
    {
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $nowUtc, new DateTimeZone('UTC'));
        if ($instant === false || $instant->format('Y-m-d\TH:i:s.u\Z') !== $nowUtc) {
            throw new InvalidArgumentException('Expected a canonical UTC instant.');
        }

        if ($period['boundary_status'] === 'confirmed' && $period['end_exclusive_at_utc'] !== null) {
            return $nowUtc >= $period['end_exclusive_at_utc'] ? 'due' : 'not_due';
        }

        $localDate = $instant->setTimezone(new DateTimeZone($period['timezone']))->format('Y-m-d');
        if ($localDate < $period['end_exclusive_date']) {
            return 'not_due';
        }
        if ($localDate === $period['end_exclusive_date']) {
            return 'boundary_day_unconfirmed';
        }

        return 'past_due_boundary_unconfirmed';
    }
}
