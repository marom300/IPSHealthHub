<?php

declare(strict_types=1);

require_once __DIR__ . '/DayClock.php';

/**
 * Thin abstraction over the IPS archive and variable functions, so that the
 * write logic can be tested without IPS. Production implementation:
 * HHIpsArchiveBackend (below), test implementation: tests/FakeArchiveBackend.
 */
interface HHArchiveBackend
{
    /** Raw logged values in [start, end], ascending: [['TimeStamp' => int, 'Value' => mixed], ...] */
    public function getLoggedValues(int $varId, int $start, int $end): array;

    public function deleteVariableData(int $varId, int $start, int $end): void;

    /** @param array $values [['TimeStamp' => int, 'Value' => mixed], ...] */
    public function addLoggedValues(int $varId, array $values): void;

    public function reAggregateVariable(int $varId): void;

    public function getVariableValue(int $varId);

    /** Sets the variable value. IPS logs it asynchronously with the current time if the value changed. */
    public function setVariableValue(int $varId, $value): void;
}

/**
 * Idempotent writer for "one value per day" variables.
 *
 * Rules:
 *  - Past days:  delete the whole day, add one value at 12:00 local, re-aggregate once per batch.
 *  - Today:      the visible variable must show the value, but SetValue() itself writes a
 *                log entry with the current time - asynchronously. Writing a 12:00 value via
 *                AC_AddLoggedValues in addition would create a duplicate (and the async
 *                SetValue entry may even land after our delete). Therefore today's value is
 *                written through SetValue() ONLY:
 *                  value unchanged and exactly one entry today -> nothing to do
 *                  value unchanged, entry missing/duplicated   -> delete day, add one entry
 *                  value changed                               -> delete day, SetValue()
 *                The entry thus carries the write time instead of 12:00; consolidateDay()
 *                (nightly timer) normalises it to 12:00 after the day is over.
 *  - Future days are rejected.
 *  - null deletes the day (e.g. FDDB day without entries is NOT a 0 kcal day).
 *
 * Readers must treat the LAST entry of a day as the day's value (robust against a
 * rare leftover duplicate before consolidation).
 *
 * No IPS dependency - unit tested.
 */
class HHArchiveWriter
{
    private const EPS = 1e-9;

    /** @var HHArchiveBackend */
    private $backend;

    /** @var HHDayClock */
    private $clock;

    public function __construct(HHArchiveBackend $backend, HHDayClock $clock)
    {
        $this->backend = $backend;
        $this->clock = $clock;
    }

    /**
     * Writes daily values of one variable.
     *
     * @param array $values ['YYYY-MM-DD' => value|null, ...]
     * @return array stats: written, deleted, unchanged, skipped (list of "day: reason")
     */
    public function writeDays(int $varId, array $values): array
    {
        $stats = ['written' => 0, 'deleted' => 0, 'unchanged' => 0, 'skipped' => []];
        $today = $this->clock->today();
        $reaggregate = false;

        ksort($values);
        foreach ($values as $day => $value) {
            $day = (string) $day;
            if (!HHDayClock::isValidDay($day)) {
                $stats['skipped'][] = $day . ': invalid day';
                continue;
            }
            if ($day > $today) {
                $stats['skipped'][] = $day . ': future day';
                continue;
            }
            if ($value !== null && !is_int($value) && !is_float($value)) {
                $stats['skipped'][] = $day . ': value not numeric';
                continue;
            }
            if (is_float($value) && !is_finite($value)) {
                $stats['skipped'][] = $day . ': value not finite';
                continue;
            }

            [$start, $end] = $this->clock->dayRange($day);

            if ($value === null) {
                if (count($this->backend->getLoggedValues($varId, $start, $end)) > 0) {
                    $this->backend->deleteVariableData($varId, $start, $end);
                    $stats['deleted']++;
                    $reaggregate = true;
                } else {
                    $stats['unchanged']++;
                }
                continue;
            }

            if ($day === $today) {
                $result = $this->writeToday($varId, $value, $start, $end);
                $stats[$result]++;
                if ($result === 'written') {
                    $reaggregate = true;
                }
                continue;
            }

            // past day
            $logged = $this->backend->getLoggedValues($varId, $start, $end);
            $noon = $this->clock->noon($day);
            if (count($logged) === 1 && (int) $logged[0]['TimeStamp'] === $noon && self::same($logged[0]['Value'], $value)) {
                $stats['unchanged']++;
                continue;
            }
            if (count($logged) > 0) {
                $this->backend->deleteVariableData($varId, $start, $end);
            }
            $this->backend->addLoggedValues($varId, [['TimeStamp' => $noon, 'Value' => $value]]);
            $stats['written']++;
            $reaggregate = true;
        }

        if ($reaggregate) {
            $this->backend->reAggregateVariable($varId);
        }
        return $stats;
    }

    /** Convenience wrapper for a single day. */
    public function writeDay(int $varId, string $day, $value): array
    {
        return $this->writeDays($varId, [$day => $value]);
    }

    /**
     * Normalises a finished day to exactly one entry at 12:00 (last entry wins).
     * Returns true if something was changed.
     */
    public function consolidateDay(int $varId, string $day, bool $reaggregate = true): bool
    {
        if ($day >= $this->clock->today()) {
            return false; // only finished days
        }
        [$start, $end] = $this->clock->dayRange($day);
        $logged = $this->backend->getLoggedValues($varId, $start, $end);
        if (count($logged) === 0) {
            return false;
        }
        $noon = $this->clock->noon($day);
        $last = $logged[count($logged) - 1];
        if (count($logged) === 1 && (int) $last['TimeStamp'] === $noon) {
            return false;
        }
        $this->backend->deleteVariableData($varId, $start, $end);
        $this->backend->addLoggedValues($varId, [['TimeStamp' => $noon, 'Value' => $last['Value']]]);
        if ($reaggregate) {
            $this->backend->reAggregateVariable($varId);
        }
        return true;
    }

    /**
     * Reads daily values: ['YYYY-MM-DD' => value|null] for every day in [from, to].
     * The last entry of a day wins.
     */
    public function readDays(int $varId, string $from, string $to): array
    {
        $days = $this->clock->daysBetween($from, $to);
        $out = array_fill_keys($days, null);
        if (count($days) === 0) {
            return $out;
        }
        [$start] = $this->clock->dayRange($from);
        [, $end] = $this->clock->dayRange($to);
        foreach ($this->backend->getLoggedValues($varId, $start, $end) as $row) {
            $day = $this->clock->dayOf((int) $row['TimeStamp']);
            if (array_key_exists($day, $out)) {
                $out[$day] = $row['Value']; // ascending order -> last wins
            }
        }
        return $out;
    }

    private function writeToday(int $varId, $value, int $start, int $end): string
    {
        $logged = $this->backend->getLoggedValues($varId, $start, $end);
        $current = $this->backend->getVariableValue($varId);

        if (self::same($current, $value)) {
            if (count($logged) === 1 && self::same($logged[0]['Value'], $value)) {
                return 'unchanged';
            }
            // value already set (no SetValue log will come), but archive entry missing or duplicated
            if (count($logged) > 0) {
                $this->backend->deleteVariableData($varId, $start, $end);
            }
            $ts = min($this->clock->noon($this->clock->today()), $this->clock->now());
            $this->backend->addLoggedValues($varId, [['TimeStamp' => $ts, 'Value' => $value]]);
            return 'written';
        }

        if (count($logged) > 0) {
            $this->backend->deleteVariableData($varId, $start, $end);
        }
        $this->backend->setVariableValue($varId, $value); // IPS logs this entry itself
        return 'written';
    }

    private static function same($a, $b): bool
    {
        if (!is_numeric($a) || !is_numeric($b)) {
            return false;
        }
        return abs((float) $a - (float) $b) < self::EPS;
    }
}

/**
 * Production backend: IPS archive functions (AC_*) and variable functions.
 */
class HHIpsArchiveBackend implements HHArchiveBackend
{
    /** @var int */
    private $archiveId;

    public function __construct(int $archiveId)
    {
        $this->archiveId = $archiveId;
    }

    public function getLoggedValues(int $varId, int $start, int $end): array
    {
        // AC_GetLoggedValues returns newest first and at most 10000 rows per call.
        $rows = [];
        $to = $end;
        while (true) {
            $chunk = AC_GetLoggedValues($this->archiveId, $varId, $start, $to, 10000);
            if (!is_array($chunk) || count($chunk) === 0) {
                break;
            }
            foreach ($chunk as $r) {
                $rows[] = ['TimeStamp' => (int) $r['TimeStamp'], 'Value' => $r['Value']];
            }
            if (count($chunk) < 10000) {
                break;
            }
            $to = (int) $chunk[count($chunk) - 1]['TimeStamp'] - 1;
            if ($to < $start) {
                break;
            }
        }
        usort($rows, static function ($a, $b) {
            return $a['TimeStamp'] <=> $b['TimeStamp'];
        });
        return $rows;
    }

    public function deleteVariableData(int $varId, int $start, int $end): void
    {
        AC_DeleteVariableData($this->archiveId, $varId, $start, $end);
    }

    public function addLoggedValues(int $varId, array $values): void
    {
        if (!AC_AddLoggedValues($this->archiveId, $varId, $values)) {
            throw new RuntimeException('AC_AddLoggedValues failed for variable ' . $varId);
        }
    }

    public function reAggregateVariable(int $varId): void
    {
        AC_ReAggregateVariable($this->archiveId, $varId);
    }

    public function getVariableValue(int $varId)
    {
        return GetValue($varId);
    }

    public function setVariableValue(int $varId, $value): void
    {
        SetValue($varId, $value);
    }
}
