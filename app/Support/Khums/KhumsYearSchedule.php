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
