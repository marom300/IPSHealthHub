<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DayClockTest extends TestCase
{
    private function clockAt(string $localDateTime): HHDayClock
    {
        $ts = (new DateTimeImmutable($localDateTime, new DateTimeZone('Europe/Vienna')))->getTimestamp();
        return new HHDayClock('Europe/Vienna', static function () use ($ts) {
            return $ts;
        });
    }

    public function testNoonInWinterAndSummer(): void
    {
        $c = new HHDayClock('Europe/Vienna');
        $this->assertSame(gmmktime(11, 0, 0, 1, 15, 2026), $c->noon('2026-01-15')); // CET  = UTC+1
        $this->assertSame(gmmktime(10, 0, 0, 7, 15, 2026), $c->noon('2026-07-15')); // CEST = UTC+2
    }

    public function testDayRangeOnDstDays(): void
    {
        $c = new HHDayClock('Europe/Vienna');
        [$s, $e] = $c->dayRange('2026-03-29'); // spring forward: 23 h
        $this->assertSame(23 * 3600 - 1, $e - $s);
        [$s, $e] = $c->dayRange('2026-10-25'); // fall back: 25 h
        $this->assertSame(25 * 3600 - 1, $e - $s);
        [$s, $e] = $c->dayRange('2026-10-17');
        $this->assertSame(24 * 3600 - 1, $e - $s);
        $this->assertSame('2026-10-17', $c->dayOf($s));
        $this->assertSame('2026-10-17', $c->dayOf($e));
        $this->assertSame('2026-10-18', $c->dayOf($e + 1));
    }

    public function testNoonIsInsideItsDay(): void
    {
        $c = new HHDayClock('Europe/Vienna');
        foreach (['2026-03-29', '2026-10-25', '2026-12-31', '2028-02-29'] as $day) {
            [$s, $e] = $c->dayRange($day);
            $noon = $c->noon($day);
            $this->assertTrue($noon > $s && $noon < $e, $day);
            $this->assertSame($day, $c->dayOf($noon));
        }
    }

    public function testTodayUsesLocalTime(): void
    {
        // 00:30 local is still the previous day in UTC
        $this->assertSame('2026-10-17', $this->clockAt('2026-10-17 00:30:00')->today());
        $this->assertSame('2026-10-17', $this->clockAt('2026-10-17 23:59:59')->today());
    }

    public function testAddDaysAndDaysBetweenAcrossDst(): void
    {
        $c = new HHDayClock('Europe/Vienna');
        $this->assertSame('2026-10-26', $c->addDays('2026-10-24', 2));
        $this->assertSame('2026-02-28', $c->addDays('2026-03-01', -1));
        $this->assertSame(['2026-10-24', '2026-10-25', '2026-10-26'], $c->daysBetween('2026-10-24', '2026-10-26'));
        $this->assertSame([], $c->daysBetween('2026-10-26', '2026-10-24'));
    }

    public function testIsValidDay(): void
    {
        $this->assertTrue(HHDayClock::isValidDay('2026-10-17'));
        $this->assertTrue(HHDayClock::isValidDay('2028-02-29'));
        $this->assertFalse(HHDayClock::isValidDay('2026-02-29'));
        $this->assertFalse(HHDayClock::isValidDay('2026-1-7'));
        $this->assertFalse(HHDayClock::isValidDay('17.10.2026'));
        $this->assertFalse(HHDayClock::isValidDay(''));
    }

    public function testInvalidDayThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new HHDayClock())->noon('2026-13-01');
    }
}
