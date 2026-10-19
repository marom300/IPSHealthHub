<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HcIngestTest extends TestCase
{
    /** @var int */
    private $now;
    /** @var HHDayClock */
    private $clock;

    protected function setUp(): void
    {
        $this->setNow('2026-10-17 21:00:00');
        $this->clock = new HHDayClock('Europe/Vienna', function () {
            return $this->now;
        });
    }

    private function setNow(string $local): void
    {
        $this->now = (new DateTimeImmutable($local, new DateTimeZone('Europe/Vienna')))->getTimestamp();
    }

    /** Local Vienna time -> ISO UTC string as HC Webhook sends it. */
    private static function iso(string $local): string
    {
        return (new DateTimeImmutable($local, new DateTimeZone('Europe/Vienna')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function meta(string $origin): array
    {
        return ['data_origin' => $origin, 'recording_method' => 'automatically_recorded'];
    }

    private function parser(array $body = [], array $activity = [], string $rule = HHHcIngest::RULE_FIRST): HHHcIngest
    {
        return new HHHcIngest($this->clock, $body, $activity, $rule, 14);
    }

    private function payload(array $types): array
    {
        return ['timestamp' => '2026-10-17T19:00:00.000Z', 'app_version' => '2.0.0'] + $types;
    }

    public function testBodyValuesFirstMeasurementOfDay(): void
    {
        $p = $this->parser();
        $r = $p->ingest($this->payload([
            'weight' => [
                ['kilograms' => 73.95, 'time' => self::iso('2026-10-17 19:30'), 'metadata' => self::meta('com.fitdays.fitdays')],
                ['kilograms' => 73.40, 'time' => self::iso('2026-10-17 06:45'), 'metadata' => self::meta('com.fitdays.fitdays')],
            ],
            'body_fat' => [['percentage' => 21.2, 'time' => self::iso('2026-10-17 06:45'), 'metadata' => self::meta('com.fitdays.fitdays')]],
            'lean_body_mass' => [['kilograms' => 57.9, 'time' => self::iso('2026-10-17 06:45'), 'metadata' => self::meta('com.fitdays.fitdays')]],
            'body_water_mass' => [['kilograms' => 42.1, 'time' => self::iso('2026-10-17 06:45'), 'metadata' => self::meta('com.fitdays.fitdays')]],
            'bone_mass' => [['kilograms' => 2.9, 'time' => self::iso('2026-10-17 06:45'), 'metadata' => self::meta('com.fitdays.fitdays')]],
        ]), []);

        $this->assertSame(6, $r['stats']['accepted']);
        $this->assertSame(['2026-10-17'], $r['touched']['Weight']);
        $this->assertSame(73.4, $p->computeDay($r['state'], '2026-10-17', 'Weight'));
        $this->assertSame(21.2, $p->computeDay($r['state'], '2026-10-17', 'BodyFat'));
        $this->assertSame(57.9, $p->computeDay($r['state'], '2026-10-17', 'LeanMass'));
        $this->assertSame(42.1, $p->computeDay($r['state'], '2026-10-17', 'BodyWater'));
        $this->assertSame(2.9, $p->computeDay($r['state'], '2026-10-17', 'BoneMass'));

        $this->assertSame(73.95, $this->parser([], [], 'last')->computeDay($r['state'], '2026-10-17', 'Weight'));
        $this->assertSame(73.68, $this->parser([], [], 'avg')->computeDay($r['state'], '2026-10-17', 'Weight'));
    }

    public function testDayAssignmentUsesLocalTime(): void
    {
        // 00:30 local on the 17th = 22:30 UTC on the 16th
        $p = $this->parser();
        $r = $p->ingest($this->payload([
            'weight' => [['kilograms' => 74.0, 'time' => self::iso('2026-10-17 00:30'), 'metadata' => self::meta('a')]],
        ]), []);
        $this->assertSame(['2026-10-17'], $r['touched']['Weight']);
    }

    public function testRepeatedPayloadIsIdempotent(): void
    {
        $p = $this->parser();
        $payload = $this->payload([
            'weight' => [['kilograms' => 73.4, 'time' => self::iso('2026-10-17 06:45'), 'metadata' => self::meta('a')]],
        ]);
        $r1 = $p->ingest($payload, []);
        $r2 = $p->ingest($payload, $r1['state']);
        $this->assertSame(0, $r2['stats']['accepted']);
        $this->assertSame(1, $r2['stats']['duplicates']);
        $this->assertSame([], $r2['touched']);
        $this->assertEquals($r1['state'], $r2['state']);
    }

    public function testPreferredSourceWinsOtherwiseMostRecords(): void
    {
        $payload = $this->payload([
            'weight' => [
                ['kilograms' => 75.0, 'time' => self::iso('2026-10-17 06:00'), 'metadata' => self::meta('com.google.android.apps.fitness')],
                ['kilograms' => 73.4, 'time' => self::iso('2026-10-17 06:45'), 'metadata' => self::meta('com.fitdays.fitdays')],
                ['kilograms' => 73.6, 'time' => self::iso('2026-10-17 07:00'), 'metadata' => self::meta('com.fitdays.fitdays')],
            ],
        ]);
        $auto = $this->parser();
        $r = $auto->ingest($payload, []);
        $this->assertSame(73.4, $auto->computeDay($r['state'], '2026-10-17', 'Weight'), 'most records');

        $pref = $this->parser(['com.google.android.apps.fitness']);
        $this->assertSame(75.0, $pref->computeDay($r['state'], '2026-10-17', 'Weight'), 'preferred source');

        $missing = $this->parser(['com.not.present', 'COM.FITDAYS.FITDAYS']);
        $this->assertSame(73.4, $missing->computeDay($r['state'], '2026-10-17', 'Weight'), 'second preference, case-insensitive');
    }

    public function testDailyStepAggregatesGrowAndPartialDaysAreDropped(): void
    {
        $p = $this->parser();
        // sync 1 (48 h window starting mid-day on the 15th): partial 15th, full 16th, today so far
        $r = $p->ingest($this->payload([
            'steps' => [
                ['count' => 2000, 'start_time' => self::iso('2026-10-15 21:00'), 'end_time' => self::iso('2026-10-16 00:00')],
                ['count' => 9000, 'start_time' => self::iso('2026-10-16 00:00'), 'end_time' => self::iso('2026-10-17 00:00')],
                ['count' => 3000, 'start_time' => self::iso('2026-10-17 00:00'), 'end_time' => self::iso('2026-10-17 12:00')],
            ],
        ]), []);
        $this->assertSame(9000, $p->computeDay($r['state'], '2026-10-16', 'Steps'));
        $this->assertSame(3000, $p->computeDay($r['state'], '2026-10-17', 'Steps'));

        // sync 2: today has grown -> new interval contains the old one
        $r = $p->ingest($this->payload([
            'steps' => [
                ['count' => 7500, 'start_time' => self::iso('2026-10-17 00:00'), 'end_time' => self::iso('2026-10-17 20:00')],
            ],
        ]), $r['state']);
        $this->assertSame(['2026-10-17'], $r['touched']['Steps']);
        $this->assertSame(7500, $p->computeDay($r['state'], '2026-10-17', 'Steps'));
        $this->assertCount(1, $r['state']['days']['2026-10-17']['steps'], 'contained aggregate pruned from state');

        // a later window starting mid-day on the 16th must not lower the full-day value
        $r = $p->ingest($this->payload([
            'steps' => [['count' => 4000, 'start_time' => self::iso('2026-10-16 10:00'), 'end_time' => self::iso('2026-10-17 00:00')]],
        ]), $r['state']);
        $this->assertSame(9000, $p->computeDay($r['state'], '2026-10-16', 'Steps'));
    }

    public function testRawIntervalRecordsAreSummedPerSource(): void
    {
        $p = $this->parser([], ['com.google.android.apps.fitness']);
        $r = $p->ingest($this->payload([
            'active_calories' => [
                ['calories' => 120.5, 'start_time' => self::iso('2026-10-17 08:00'), 'end_time' => self::iso('2026-10-17 09:00'), 'metadata' => self::meta('com.google.android.apps.fitness')],
                ['calories' => 80.0, 'start_time' => self::iso('2026-10-17 17:00'), 'end_time' => self::iso('2026-10-17 18:00'), 'metadata' => self::meta('com.google.android.apps.fitness')],
                // phone duplicates the same activity -> must not be added
                ['calories' => 150.0, 'start_time' => self::iso('2026-10-17 08:00'), 'end_time' => self::iso('2026-10-17 09:00'), 'metadata' => self::meta('com.sec.android.app.shealth')],
            ],
        ]), []);
        $this->assertSame(201, $p->computeDay($r['state'], '2026-10-17', 'ActiveKcal'));
        // same records again (overlapping sync) -> unchanged
        $r2 = $p->ingest($this->payload(['active_calories' => [
            ['calories' => 80.0, 'start_time' => self::iso('2026-10-17 17:00'), 'end_time' => self::iso('2026-10-17 18:00'), 'metadata' => self::meta('com.google.android.apps.fitness')],
        ]]), $r['state']);
        $this->assertSame(201, $p->computeDay($r2['state'], '2026-10-17', 'ActiveKcal'));
    }

    public function testHealthConnectAggregatePreferredWithoutConfiguredSource(): void
    {
        $p = $this->parser();
        $r = $p->ingest($this->payload([
            'steps' => [
                ['count' => 8000, 'start_time' => self::iso('2026-10-16 00:00'), 'end_time' => self::iso('2026-10-17 00:00')],
                ['count' => 100, 'start_time' => self::iso('2026-10-16 08:00'), 'end_time' => self::iso('2026-10-16 08:05'), 'metadata' => self::meta('x')],
                ['count' => 100, 'start_time' => self::iso('2026-10-16 09:00'), 'end_time' => self::iso('2026-10-16 09:05'), 'metadata' => self::meta('x')],
            ],
        ]), []);
        $this->assertSame(8000, $p->computeDay($r['state'], '2026-10-16', 'Steps'));
    }

    public function testSleepGoesToWakeUpDayWithoutAwakeStages(): void
    {
        $p = $this->parser();
        $r = $p->ingest($this->payload([
            'sleep' => [[
                'session_end_time' => self::iso('2026-10-17 06:30'),
                'duration_seconds' => 8 * 3600,
                'stages' => [
                    ['stage' => '4', 'start_time' => self::iso('2026-10-16 22:30'), 'end_time' => self::iso('2026-10-17 02:30'), 'duration_seconds' => 4 * 3600],
                    ['stage' => '1', 'start_time' => self::iso('2026-10-17 02:30'), 'end_time' => self::iso('2026-10-17 03:00'), 'duration_seconds' => 1800],
                    ['stage' => '6', 'start_time' => self::iso('2026-10-17 03:00'), 'end_time' => self::iso('2026-10-17 06:30'), 'duration_seconds' => 3 * 3600 + 1800],
                ],
                'metadata' => self::meta('com.google.android.apps.fitness'),
            ]],
        ]), []);
        $this->assertSame(['2026-10-17'], $r['touched']['SleepMinutes']);
        $this->assertSame(450, $p->computeDay($r['state'], '2026-10-17', 'SleepMinutes')); // 8 h - 30 min awake
        $this->assertNull($p->computeDay($r['state'], '2026-10-16', 'SleepMinutes'));

        // no stages (summary resolution) -> whole session; nap is added on the same day
        $r = $p->ingest($this->payload([
            'sleep' => [['session_end_time' => self::iso('2026-10-17 15:00'), 'duration_seconds' => 1800, 'stages' => [], 'metadata' => self::meta('com.google.android.apps.fitness')]],
        ]), $r['state']);
        $this->assertSame(480, $p->computeDay($r['state'], '2026-10-17', 'SleepMinutes'));
    }

    public function testRestingHeartRateUsesLastValue(): void
    {
        $p = $this->parser();
        $r = $p->ingest($this->payload([
            'resting_heart_rate' => [
                ['bpm' => 58, 'time' => self::iso('2026-10-17 07:00'), 'metadata' => self::meta('w')],
                ['bpm' => 55, 'time' => self::iso('2026-10-17 20:00'), 'metadata' => self::meta('w')],
            ],
        ]), []);
        $this->assertSame(55, $p->computeDay($r['state'], '2026-10-17', 'RestingHR'));
    }

    public function testInvalidImplausibleFutureAndOldRecords(): void
    {
        $p = $this->parser();
        $r = $p->ingest($this->payload([
            'weight' => [
                ['kilograms' => 0, 'time' => self::iso('2026-10-17 06:00')],          // implausible
                ['kilograms' => 'x', 'time' => self::iso('2026-10-17 06:00')],        // not numeric
                ['kilograms' => 74, 'time' => 'yesterday-ish'],                       // bad time
                ['kilograms' => 74, 'time' => self::iso('2026-10-18 06:00')],          // future day
                ['kilograms' => 74, 'time' => self::iso('2026-09-01 06:00')],          // older than retention
                'garbage',
            ],
            'heart_rate' => [['bpm' => 70, 'time' => self::iso('2026-10-17 06:00')]],
        ]), []);
        $this->assertSame(0, $r['stats']['accepted']);
        $this->assertSame(5, $r['stats']['ignored']);
        $this->assertSame(1, $r['stats']['too_old']);
        $this->assertSame(['heart_rate'], $r['stats']['unused_types']);
        $this->assertSame([], $r['touched']);
    }

    public function testOldDaysArePrunedFromState(): void
    {
        $p = $this->parser();
        $r = $p->ingest($this->payload([
            'weight' => [['kilograms' => 74, 'time' => self::iso('2026-10-10 06:00'), 'metadata' => self::meta('a')]],
        ]), []);
        $this->assertArrayHasKey('2026-10-10', $r['state']['days']);
        $this->setNow('2026-10-30 08:00:00');
        $r = $p->ingest($this->payload([]), $r['state']);
        $this->assertSame([], $r['state']['days']);
    }

    public function testLeanMassDerivedFromSameMeasurement(): void
    {
        $fd = self::meta('cn.fitdays.fitdays');
        $p = $this->parser(['cn.fitdays.fitdays']);
        $r = $p->ingest($this->payload([
            'weight' => [
                ['kilograms' => 74.4, 'time' => self::iso('2026-10-17 05:16:44'), 'metadata' => $fd],
                ['kilograms' => 72.5, 'time' => self::iso('2026-10-17 05:18:44'), 'metadata' => $fd],
                ['kilograms' => 72.0, 'time' => self::iso('2026-10-17 04:00:00'), 'metadata' => self::meta('com.fitbit.FitbitMobile')],
            ],
            'body_fat' => [
                ['percentage' => 21.6, 'time' => self::iso('2026-10-17 05:16:44'), 'metadata' => $fd],
                ['percentage' => 20.0, 'time' => self::iso('2026-10-17 05:18:44'), 'metadata' => $fd],
            ],
        ]), []);
        $this->assertSame(['2026-10-17'], $r['touched']['LeanMass']);
        $this->assertSame(58.33, $p->computeDay($r['state'], '2026-10-17', 'LeanMass')); // 74.4 x 0.784
        $this->assertSame(58.0, $this->parser(['cn.fitdays.fitdays'], [], 'last')->computeDay($r['state'], '2026-10-17', 'LeanMass'));

        // delivered lean mass wins over the derived value
        $r = $p->ingest($this->payload([
            'lean_body_mass' => [['kilograms' => 58.1, 'time' => self::iso('2026-10-17 05:16:44'), 'metadata' => $fd]],
        ]), $r['state']);
        $this->assertSame(58.1, $p->computeDay($r['state'], '2026-10-17', 'LeanMass'));
    }

    public function testLeanMassNotDerivedWithoutMatchingWeight(): void
    {
        $p = $this->parser();
        $r = $p->ingest($this->payload([
            'weight' => [['kilograms' => 72.0, 'time' => self::iso('2026-10-17 04:00'), 'metadata' => self::meta('a')]],
            'body_fat' => [['percentage' => 20.0, 'time' => self::iso('2026-10-17 06:00'), 'metadata' => self::meta('a')]],
        ]), []);
        $this->assertNull($p->computeDay($r['state'], '2026-10-17', 'LeanMass'));
    }

    public function testPartialFirstDayOfSyncWindowIsSkipped(): void
    {
        $p = $this->parser();
        $r = $p->ingest($this->payload([
            'steps' => [
                ['count' => 7640, 'start_time' => self::iso('2026-10-10 12:10:00'), 'end_time' => self::iso('2026-10-11 00:00')],
                ['count' => 4832, 'start_time' => self::iso('2026-10-11 00:00'), 'end_time' => self::iso('2026-10-12 00:00')],
                // today so far: starts at midnight -> kept
                ['count' => 2270, 'start_time' => self::iso('2026-10-17 00:00'), 'end_time' => self::iso('2026-10-17 12:10')],
                // raw record with source mid-day -> kept
                ['count' => 100, 'start_time' => self::iso('2026-10-12 12:00'), 'end_time' => self::iso('2026-10-13 00:00'), 'metadata' => self::meta('x')],
            ],
        ]), []);
        $this->assertSame(1, $r['stats']['partial']);
        $this->assertSame(['2026-10-11', '2026-10-12', '2026-10-17'], $r['touched']['Steps']);
    }

    public function testAppTestPayloadIsIgnored(): void
    {
        $p = $this->parser();
        $r = $p->ingest($this->payload([
            'test' => true,
            'weight' => [['kilograms' => 75.4, 'time' => self::iso('2026-10-16 07:00'), 'metadata' => self::meta('com.google.android.apps.fitness')]],
        ]), ['days' => ['2026-10-15' => ['weight' => []]]]);
        $this->assertTrue($r['stats']['test']);
        $this->assertSame(0, $r['stats']['accepted']);
        $this->assertSame([], $r['touched']);
        $this->assertSame(['days' => ['2026-10-15' => ['weight' => []]]], $r['state'], 'state unchanged');
    }

    public function testLocalTimestampsWithOffset(): void
    {
        $p = $this->parser();
        $r = $p->ingest($this->payload([
            'weight' => [['kilograms' => 74.0, 'time' => '2026-10-17T00:30:00.000+02:00', 'metadata' => self::meta('a')]],
        ]), []);
        $this->assertSame(['2026-10-17'], $r['touched']['Weight']);
    }

    public function testParseTimeVariants(): void
    {
        $this->assertSame(gmmktime(12, 34, 56, 5, 9, 2026), HHHcIngest::parseTime('2026-05-09T12:34:56Z'));
        $this->assertSame(gmmktime(12, 34, 56, 5, 9, 2026), HHHcIngest::parseTime('2026-05-09T12:34:56.789Z'));
        $this->assertSame(gmmktime(12, 34, 56, 5, 9, 2026), HHHcIngest::parseTime('2026-05-09T12:34:56.123456789Z'));
        $this->assertNull(HHHcIngest::parseTime(''));
        $this->assertNull(HHHcIngest::parseTime(null));
        $this->assertNull(HHHcIngest::parseTime('not a date'));
    }

    public function testParseSourceList(): void
    {
        $this->assertSame(['a.b', 'c.d'], HHHcIngest::parseSourceList(' A.b, c.d;a.b '));
        $this->assertSame([], HHHcIngest::parseSourceList(''));
    }
}
