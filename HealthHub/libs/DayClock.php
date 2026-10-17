<?php

declare(strict_types=1);

/**
 * Calendar-day helper bound to a fixed time zone.
 *
 * All daily values are stored with a timestamp of 12:00 local time of their
 * day. Noon is never affected by DST switches (which happen at night), so the
 * day a value belongs to is always unambiguous.
 *
 * No IPS dependency - unit tested.
 */
class HHDayClock
{
    /** @var DateTimeZone */
    private $tz;

    /** @var callable(): int */
    private $now;

    public function __construct(string $timeZone = 'Europe/Vienna', ?callable $now = null)
    {
        $this->tz = new DateTimeZone($timeZone);
        $this->now = $now ?? static function (): int {
            return time();
        };
    }

    public function timeZone(): DateTimeZone
    {
        return $this->tz;
    }

    public function now(): int
    {
        return (int) ($this->now)();
    }

    /** Current local day as YYYY-MM-DD. */
    public function today(): string
    {
        return $this->dayOf($this->now());
    }

    /** Local day (YYYY-MM-DD) of a unix timestamp. */
    public function dayOf(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->tz)->format('Y-m-d');
    }

    /** True if $day is a valid calendar date in YYYY-MM-DD format. */
    public static function isValidDay(string $day): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Unix timestamp of 12:00:00 local time on $day. */
    public function noon(string $day): int
    {
        return $this->at($day, '12:00:00');
    }

    /**
     * First and last second of $day in local time: [start, end].
     * Handles 23h/25h DST days correctly.
     */
    public function dayRange(string $day): array
    {
        $start = $this->at($day, '00:00:00');
        $next = $this->at($this->addDays($day, 1), '00:00:00');
        return [$start, $next - 1];
    }

    /** $day shifted by $days calendar days. */
    public function addDays(string $day, int $days): string
    {
        $this->assertDay($day);
        $d = new DateTimeImmutable($day . ' 12:00:00', $this->tz);
        return $d->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** Inclusive list of days from $from to $to (empty if $from > $to). */
    public function daysBetween(string $from, string $to): array
    {
        $this->assertDay($from);
        $this->assertDay($to);
        $out = [];
        for ($d = $from; $d <= $to; $d = $this->addDays($d, 1)) {
            $out[] = $d;
        }
        return $out;
    }

    private function at(string $day, string $time): int
    {
        $this->assertDay($day);
        return (new DateTimeImmutable($day . ' ' . $time, $this->tz))->getTimestamp();
    }

    private function assertDay(string $day): void
    {
        if (!self::isValidDay($day)) {
            throw new InvalidArgumentException('Invalid day (expected YYYY-MM-DD): ' . $day);
        }
    }
}
