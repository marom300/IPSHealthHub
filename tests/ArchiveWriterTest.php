<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ArchiveWriterTest extends TestCase
{
    private const VAR = 4711;

    /** @var int */
    private $now;
    /** @var HHDayClock */
    private $clock;
    /** @var FakeArchiveBackend */
    private $backend;
    /** @var HHArchiveWriter */
    private $writer;

    protected function setUp(): void
    {
        $this->setNow('2026-10-17 08:15:00');
        $nowFn = function () {
            return $this->now;
        };
        $this->clock = new HHDayClock('Europe/Vienna', $nowFn);
        $this->backend = new FakeArchiveBackend($nowFn);
        $this->writer = new HHArchiveWriter($this->backend, $this->clock);
    }

    private function setNow(string $local): void
    {
        $this->now = (new DateTimeImmutable($local, new DateTimeZone('Europe/Vienna')))->getTimestamp();
    }

    private function rowsOf(string $day): array
    {
        [$s, $e] = $this->clock->dayRange($day);
        return $this->backend->getLoggedValues(self::VAR, $s, $e);
    }

    public function testPastDayIsWrittenAtNoon(): void
    {
        $stats = $this->writer->writeDay(self::VAR, '2026-10-15', 1830.0);
        $this->assertSame(1, $stats['written']);
        $rows = $this->rowsOf('2026-10-15');
        $this->assertCount(1, $rows);
        $this->assertSame($this->clock->noon('2026-10-15'), $rows[0]['TimeStamp']);
        $this->assertSame(1830.0, $rows[0]['Value']);
        $this->assertSame(0, $this->backend->getVariableValue(self::VAR), 'visible value untouched for past days');
    }

    public function testPastDayRewriteIsIdempotent(): void
    {
        $this->writer->writeDay(self::VAR, '2026-10-15', 1830.0);
        $this->backend->calls = [];
        $stats = $this->writer->writeDay(self::VAR, '2026-10-15', 1830.0);
        $this->assertSame(1, $stats['unchanged']);
        $this->assertSame([], $this->backend->calls, 'no archive operation for unchanged value');

        $stats = $this->writer->writeDay(self::VAR, '2026-10-15', 1900.0);
        $this->assertSame(1, $stats['written']);
        $rows = $this->rowsOf('2026-10-15');
        $this->assertCount(1, $rows);
        $this->assertSame(1900.0, $rows[0]['Value']);
    }

    public function testPastDayWithStrayEntriesIsCleanedUp(): void
    {
        [$s] = $this->clock->dayRange('2026-10-15');
        $this->backend->addLoggedValues(self::VAR, [
            ['TimeStamp' => $s + 3600, 'Value' => 1.0],
            ['TimeStamp' => $s + 7200, 'Value' => 2.0],
        ]);
        $this->writer->writeDay(self::VAR, '2026-10-15', 2.0);
        $rows = $this->rowsOf('2026-10-15');
        $this->assertCount(1, $rows);
        $this->assertSame($this->clock->noon('2026-10-15'), $rows[0]['TimeStamp']);
    }

    public function testNeighbourDaysAreNotTouched(): void
    {
        $this->writer->writeDays(self::VAR, ['2026-10-14' => 1.0, '2026-10-15' => 2.0, '2026-10-16' => 3.0]);
        $this->writer->writeDay(self::VAR, '2026-10-15', 5.0);
        $this->assertSame(
            ['2026-10-14' => 1.0, '2026-10-15' => 5.0, '2026-10-16' => 3.0],
            $this->writer->readDays(self::VAR, '2026-10-14', '2026-10-16')
        );
    }

    public function testReaggregateOncePerBatch(): void
    {
        $this->writer->writeDays(self::VAR, ['2026-10-14' => 1.0, '2026-10-15' => 2.0, '2026-10-16' => 3.0]);
        $this->assertSame(1, count(array_keys($this->backend->calls, 'reaggregate')));
    }

    public function testFutureAndInvalidDaysAreSkipped(): void
    {
        $stats = $this->writer->writeDays(self::VAR, ['2026-10-18' => 1.0, '2026-02-30' => 1.0, 'x' => 1.0, '2026-10-16' => 'abc']);
        $this->assertCount(4, $stats['skipped']);
        $this->assertSame(0, $stats['written']);
        $this->assertSame([], $this->backend->calls);
    }

    public function testNullDeletesDay(): void
    {
        $this->writer->writeDay(self::VAR, '2026-10-15', 1830.0);
        $stats = $this->writer->writeDay(self::VAR, '2026-10-15', null);
        $this->assertSame(1, $stats['deleted']);
        $this->assertCount(0, $this->rowsOf('2026-10-15'));

        $stats = $this->writer->writeDay(self::VAR, '2026-10-15', null);
        $this->assertSame(1, $stats['unchanged']);
    }

    public function testTodayIsWrittenViaSetValueOnly(): void
    {
        $this->writer->writeDay(self::VAR, '2026-10-17', 3500);
        $this->assertSame(3500, $this->backend->getVariableValue(self::VAR));
        $this->assertNotContains('add', $this->backend->calls, 'no AC_AddLoggedValues next to SetValue');
        $rows = $this->rowsOf('2026-10-17');
        $this->assertCount(1, $rows);
        $this->assertSame($this->now, $rows[0]['TimeStamp']);
    }

    public function testTodayRepeatedUpdatesKeepOneEntry(): void
    {
        $this->writer->writeDay(self::VAR, '2026-10-17', 3500);
        $this->setNow('2026-10-17 14:00:00');
        $this->backend->calls = [];
        $stats = $this->writer->writeDay(self::VAR, '2026-10-17', 3500);
        $this->assertSame(1, $stats['unchanged']);
        $this->assertSame([], $this->backend->calls);

        $this->setNow('2026-10-17 18:00:00');
        $this->writer->writeDay(self::VAR, '2026-10-17', 9000);
        $rows = $this->rowsOf('2026-10-17');
        $this->assertCount(1, $rows);
        $this->assertSame(9000, $rows[0]['Value']);
        $this->assertSame(9000, $this->backend->getVariableValue(self::VAR));
    }

    public function testTodaySameValueButMissingEntryIsRestored(): void
    {
        $this->backend->values[self::VAR] = 85.0; // value already shown, archive entry missing
        $this->writer->writeDay(self::VAR, '2026-10-17', 85.0);
        $rows = $this->rowsOf('2026-10-17');
        $this->assertCount(1, $rows);
        $this->assertSame($this->now, $rows[0]['TimeStamp'], 'before noon: clamp to now (no future entries)');

        $this->setNow('2026-10-17 15:00:00');
        $this->backend->deleteVariableData(self::VAR, 0, PHP_INT_MAX);
        $this->writer->writeDay(self::VAR, '2026-10-17', 85.0);
        $this->assertSame($this->clock->noon('2026-10-17'), $this->rowsOf('2026-10-17')[0]['TimeStamp']);
    }

    public function testTodayFallsBackToDayStartWhenIpsRejectsTimestamp(): void
    {
        $this->backend->values[self::VAR] = 85.0;
        $this->backend->rejectAddFrom = $this->now - 30; // unwritten SetValue entry 30 s ago
        $this->writer->writeDay(self::VAR, '2026-10-17', 85.0);
        $rows = $this->rowsOf('2026-10-17');
        $this->assertCount(1, $rows);
        $this->assertSame($this->clock->dayRange('2026-10-17')[0], $rows[0]['TimeStamp']);
    }

    public function testTodayDuplicateWithSameValueIsCollapsed(): void
    {
        $this->backend->values[self::VAR] = 85.0;
        [$s] = $this->clock->dayRange('2026-10-17');
        $this->backend->addLoggedValues(self::VAR, [
            ['TimeStamp' => $s + 60, 'Value' => 85.0],
            ['TimeStamp' => $s + 120, 'Value' => 85.0],
        ]);
        $this->writer->writeDay(self::VAR, '2026-10-17', 85.0);
        $this->assertCount(1, $this->rowsOf('2026-10-17'));
    }

    public function testConsolidateMovesLastValueToNoon(): void
    {
        $this->writer->writeDay(self::VAR, '2026-10-17', 3500);
        $this->setNow('2026-10-17 20:00:00');
        $this->writer->writeDay(self::VAR, '2026-10-17', 9000);

        // still today -> nothing to do
        $this->assertFalse($this->writer->consolidateDay(self::VAR, '2026-10-17'));

        $this->setNow('2026-10-18 00:10:00');
        $this->assertTrue($this->writer->consolidateDay(self::VAR, '2026-10-17'));
        $rows = $this->rowsOf('2026-10-17');
        $this->assertCount(1, $rows);
        $this->assertSame($this->clock->noon('2026-10-17'), $rows[0]['TimeStamp']);
        $this->assertSame(9000, $rows[0]['Value']);

        $this->assertFalse($this->writer->consolidateDay(self::VAR, '2026-10-17'), 'second run is a no-op');
        $this->assertFalse($this->writer->consolidateDay(self::VAR, '2026-10-10'), 'empty day stays empty');
    }

    public function testReadDaysLastEntryWinsAndMissingIsNull(): void
    {
        [$s] = $this->clock->dayRange('2026-10-15');
        $this->backend->addLoggedValues(self::VAR, [
            ['TimeStamp' => $s + 3600, 'Value' => 1.0],
            ['TimeStamp' => $s + 7200, 'Value' => 2.0],
        ]);
        $this->assertSame(
            ['2026-10-14' => null, '2026-10-15' => 2.0, '2026-10-16' => null],
            $this->writer->readDays(self::VAR, '2026-10-14', '2026-10-16')
        );
    }
}
