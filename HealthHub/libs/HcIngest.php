<?php

declare(strict_types=1);

require_once __DIR__ . '/DayClock.php';

/**
 * Parses "HC Webhook" payloads (github.com/mcnaveen/health-connect-webhook, JSON delivery)
 * and turns them into one value per day and variable.
 *
 * Payload facts (verified against SyncManager.kt / HealthConnectManager.kt):
 *  - Root: {timestamp, app_version, <type>: [records...]}; arrays only for types with data.
 *  - Times: ISO-8601, UTC ("2026-05-09T12:34:56.789Z") or - app setting - local with
 *    offset ("2026-05-09T14:34:56.789+02:00"); both parse to the same instant.
 *  - The app's "Test webhook" button sends mock data with "test": true -> ignored.
 *  - Records carry metadata.data_origin (package name of the source app) - but NO record id
 *    in JSON. Deduplication key is therefore (type, time/interval, origin).
 *  - Background sync re-sends a rolling 48 h window incrementally, so records arrive
 *    repeatedly and partially. Never add up on arrival - keep the records per day and
 *    recompute the day from the stored set.
 *  - steps / active_calories come, depending on the in-app resolution setting, as
 *      daily:    one Health-Connect aggregate per local day (already de-duplicated across
 *                sources by HC, no metadata), start = local midnight (or window start),
 *                end = now for today -> grows with every sync
 *      raw:      the original interval records with metadata
 *      bucketed: N-minute sums without metadata
 *    Handled uniformly: per origin, intervals contained in a larger one are dropped
 *    (older/partial daily aggregates), the rest is summed.
 *  - sleep: session_end_time, duration_seconds, stages[] (stage = HC int constant as string).
 *
 * The state (records of the last N days) is a plain array the caller persists.
 * No IPS dependency - unit tested.
 */
class HHHcIngest
{
    public const RULE_SESSION = 'session'; // median of the first weighing session of the day
    public const RULE_FIRST = 'first';
    public const RULE_LAST = 'last';
    public const RULE_AVG = 'avg';

    /** Measurements up to this many seconds after the first one form the first session. */
    public const SESSION_WINDOW = 900;

    /**
     * Supported payload types.
     * kind: instant (time + value), interval (start/end + value, summed), sleep
     * group: which preferred-source list applies
     * range: plausible values, everything outside is ignored
     */
    public const TYPES = [
        'weight'             => ['ident' => 'Weight', 'kind' => 'instant', 'field' => 'kilograms', 'group' => 'body', 'range' => [20, 300], 'digits' => 2],
        'body_fat'           => ['ident' => 'BodyFat', 'kind' => 'instant', 'field' => 'percentage', 'group' => 'body', 'range' => [1, 75], 'digits' => 1],
        'lean_body_mass'     => ['ident' => 'LeanMass', 'kind' => 'instant', 'field' => 'kilograms', 'group' => 'body', 'range' => [10, 200], 'digits' => 2],
        'body_water_mass'    => ['ident' => 'BodyWater', 'kind' => 'instant', 'field' => 'kilograms', 'group' => 'body', 'range' => [5, 150], 'digits' => 2],
        'bone_mass'          => ['ident' => 'BoneMass', 'kind' => 'instant', 'field' => 'kilograms', 'group' => 'body', 'range' => [0.3, 10], 'digits' => 2],
        'resting_heart_rate' => ['ident' => 'RestingHR', 'kind' => 'instant', 'field' => 'bpm', 'group' => 'activity', 'range' => [25, 150], 'digits' => 0, 'rule' => self::RULE_LAST],
        'steps'              => ['ident' => 'Steps', 'kind' => 'interval', 'field' => 'count', 'group' => 'activity', 'range' => [0, 200000], 'digits' => 0],
        'active_calories'    => ['ident' => 'ActiveKcal', 'kind' => 'interval', 'field' => 'calories', 'group' => 'activity', 'range' => [0, 10000], 'digits' => 0],
        'sleep'              => ['ident' => 'SleepMinutes', 'kind' => 'sleep', 'field' => 'duration_seconds', 'group' => 'activity', 'range' => [0, 1440], 'digits' => 0],
    ];

    /** Heart rate points are only buffered this long (needed to derive the resting heart rate). */
    private const HR_KEEP_SECONDS = 3 * 86400;
    /** Resting heart rate = lowest mean over this window during sleep. */
    private const RHR_WINDOW = 300;
    private const RHR_MIN_POINTS = 3;

    /** Health Connect sleep stages that are not sleep: AWAKE (1), OUT_OF_BED (3), AWAKE_IN_BED (7). */
    private const SLEEP_AWAKE_STAGES = ['1', '3', '7'];

    /** @var HHDayClock */
    private $clock;
    /** @var array group => [origin, ...] (lower case, priority order) */
    private $preferred;
    /** @var string */
    private $measureRule;
    /** @var int */
    private $keepDays;

    public function __construct(HHDayClock $clock, array $bodySources = [], array $activitySources = [], string $measureRule = self::RULE_SESSION, int $keepDays = 14)
    {
        $this->clock = $clock;
        $this->preferred = [
            'body'     => self::normaliseList($bodySources),
            'activity' => self::normaliseList($activitySources),
        ];
        $rules = [self::RULE_SESSION, self::RULE_FIRST, self::RULE_LAST, self::RULE_AVG];
        $this->measureRule = in_array($measureRule, $rules, true) ? $measureRule : self::RULE_SESSION;
        $this->keepDays = max(1, $keepDays);
    }

    /** Splits "a, b;c" into ['a','b','c'] (lower case). */
    public static function parseSourceList(string $list): array
    {
        return self::normaliseList(preg_split('/[\s,;]+/', $list) ?: []);
    }

    /**
     * Merges a payload into the state.
     *
     * @return array [
     *   'state'   => new state,
     *   'touched' => [ident => [day, ...]]  days whose value must be recomputed,
     *   'stats'   => [accepted, duplicates, ignored, too_old, unused_types[], origins[type][origin] => count, errors[]]
     * ]
     */
    public function ingest(array $payload, array $state, ?int $receivedAt = null): array
    {
        $receivedAt = $receivedAt ?? $this->clock->now();
        $today = $this->clock->today();
        $oldest = $this->clock->addDays($today, -($this->keepDays - 1));
        $days = isset($state['days']) && is_array($state['days']) ? $state['days'] : [];
        $hr = isset($state['hr']) && is_array($state['hr']) ? $state['hr'] : [];
        $hrDays = [];

        $stats = ['accepted' => 0, 'duplicates' => 0, 'ignored' => 0, 'too_old' => 0, 'partial' => 0, 'hr_points' => 0, 'unused_types' => [], 'origins' => [], 'errors' => [], 'test' => false];
        $touched = [];

        // The app's "Test webhook" button sends realistic MOCK data flagged with "test": true.
        // It must never end up in the archive.
        if (($payload['test'] ?? false) === true) {
            $stats['test'] = true;
            return ['state' => ['days' => $days, 'hr' => $hr], 'touched' => [], 'stats' => $stats];
        }

        foreach ($payload as $type => $records) {
            if (!is_array($records) || $type === 'metadata') {
                continue;
            }
            if ($type === 'heart_rate') {
                foreach ($records as $rec) {
                    $point = is_array($rec) ? $this->parseHeartRate($rec) : null;
                    if ($point === null) {
                        continue;
                    }
                    [$minute, $bpm] = $point;
                    $hr[(string) $minute] = $bpm;
                    $hrDays[$this->clock->dayOf($minute)] = true;
                    $stats['hr_points']++;
                }
                continue;
            }
            if (!isset(self::TYPES[$type])) {
                $stats['unused_types'][] = (string) $type;
                continue;
            }
            $def = self::TYPES[$type];
            foreach ($records as $i => $rec) {
                $parsed = is_array($rec) ? $this->parseRecord($def, $rec) : null;
                if ($parsed === null) {
                    $stats['ignored']++;
                    if (count($stats['errors']) < 10) {
                        $stats['errors'][] = $type . '[' . $i . ']: unreadable or implausible';
                    }
                    continue;
                }
                [$day, $key, $row] = $parsed;
                if ($day > $today) {
                    $stats['ignored']++;
                    continue;
                }
                if ($day < $oldest) {
                    $stats['too_old']++;
                    continue;
                }
                if ($def['kind'] === 'interval' && $this->isPartialDailyAggregate($row, $day)) {
                    $stats['partial']++; // first day of a sync window, counted only from mid-day
                    continue;
                }
                $row['r'] = $receivedAt;
                $origin = $row['o'] === '' ? '(health connect aggregate)' : $row['o'];
                $stats['origins'][$type][$origin] = ($stats['origins'][$type][$origin] ?? 0) + 1;

                $existing = $days[$day][$type][$key] ?? null;
                if ($existing !== null && $existing['v'] == $row['v']) {
                    $stats['duplicates']++;
                    continue;
                }
                $days[$day][$type][$key] = $row;
                $touched[$def['ident']][$day] = true;
                if ($type === 'weight' || $type === 'body_fat') {
                    $touched['LeanMass'][$day] = true; // may be derived from both
                }
                $stats['accepted']++;
            }
        }

        // daily aggregates grow with every sync (new end time = new key):
        // keep only intervals that are not contained in a larger one of the same origin
        foreach ($touched as $ident => $set) {
            $type = self::typeOf($ident);
            if (self::TYPES[$type]['kind'] !== 'interval') {
                continue;
            }
            foreach (array_keys($set) as $day) {
                $byOrigin = [];
                foreach ($days[$day][$type] as $key => $row) {
                    $row['k'] = $key;
                    $byOrigin[$row['o']][] = $row;
                }
                $pruned = [];
                foreach ($byOrigin as $rows) {
                    foreach (self::dropContained($rows) as $row) {
                        $key = $row['k'];
                        unset($row['k']);
                        $pruned[$key] = $row;
                    }
                }
                $days[$day][$type] = $pruned;
            }
        }

        // resting heart rate from the heart rate during sleep (no source writes it to Health Connect).
        // Night heart rate of day D belongs to the wake-up day D+1.
        $rhrDays = array_keys($touched['SleepMinutes'] ?? []);
        foreach (array_keys($hrDays) as $d) {
            $rhrDays[] = $d;
            $rhrDays[] = $this->clock->addDays($d, 1);
        }
        foreach (array_unique($rhrDays) as $day) {
            if ($day < $oldest || $day > $today || !isset($days[$day]['sleep'])) {
                continue;
            }
            $value = $this->deriveRestingHr($days[$day]['sleep'], $hr);
            if ($value !== null && ($days[$day]['rhr_derived'] ?? null) !== $value) {
                $days[$day]['rhr_derived'] = $value;
                $touched['RestingHR'][$day] = true;
            }
        }
        $cut = $this->clock->now() - self::HR_KEEP_SECONDS;
        foreach (array_keys($hr) as $minute) {
            if ((int) $minute < $cut) {
                unset($hr[$minute]);
            }
        }
        ksort($hr);

        // drop days outside the retention window
        foreach (array_keys($days) as $day) {
            if ($day < $oldest) {
                unset($days[$day]);
            }
        }
        ksort($days);

        $stats['unused_types'] = array_values(array_unique($stats['unused_types']));
        $touchedDays = [];
        foreach ($touched as $ident => $set) {
            $list = array_keys($set);
            sort($list);
            $touchedDays[$ident] = $list;
        }
        return ['state' => ['days' => $days, 'hr' => $hr], 'touched' => $touchedDays, 'stats' => $stats];
    }

    /**
     * Value of one variable (ident) for one day from the state, null if no data.
     */
    public function computeDay(array $state, string $day, string $ident)
    {
        $type = self::typeOf($ident);
        if ($type === null) {
            return null;
        }
        $def = self::TYPES[$type];
        $records = $state['days'][$day][$type] ?? [];
        if (count($records) === 0) {
            if ($ident === 'LeanMass') {
                return $this->deriveLeanMass($state, $day);
            }
            if ($ident === 'RestingHR') {
                return $state['days'][$day]['rhr_derived'] ?? null;
            }
            return null;
        }

        $groups = [];
        foreach ($records as $r) {
            $groups[$r['o']][] = $r;
        }
        if ($def['kind'] === 'interval') {
            foreach ($groups as $o => $rows) {
                $groups[$o] = self::dropContained($rows);
            }
        }
        $origin = $this->pickOrigin($groups, $def);
        $rows = $groups[$origin];

        switch ($def['kind']) {
            case 'instant':
                $value = self::applyRule($rows, $def['rule'] ?? $this->measureRule);
                break;
            default: // interval, sleep
                $value = array_sum(array_column($rows, 'v'));
                break;
        }

        $value = round((float) $value, $def['digits']);
        return $def['digits'] === 0 ? (int) $value : $value;
    }

    /** Payload type of a variable ident (null if not fed by Health Connect). */
    public static function typeOf(string $ident): ?string
    {
        foreach (self::TYPES as $type => $def) {
            if ($def['ident'] === $ident) {
                return $type;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------

    /**
     * Fitdays writes weight and body fat but no lean body mass to Health Connect.
     * Lean (fat-free) mass = weight x (1 - body fat / 100), taken from the SAME
     * measurement (same time and source) that the body-fat rule selects.
     */
    private function deriveLeanMass(array $state, string $day): ?float
    {
        $fat = $state['days'][$day]['body_fat'] ?? [];
        $weight = $state['days'][$day]['weight'] ?? [];
        if (count($fat) === 0 || count($weight) === 0) {
            return null;
        }
        $groups = [];
        foreach ($fat as $r) {
            $groups[$r['o']][] = $r;
        }
        $origin = $this->pickOrigin($groups, self::TYPES['body_fat']);
        $rows = $groups[$origin];
        usort($rows, static function ($a, $b) {
            return $a['t'] <=> $b['t'];
        });
        $pairs = [];
        foreach ($rows as $f) {
            foreach ($weight as $w) {
                if ($w['o'] === $f['o'] && abs($w['t'] - $f['t']) <= 60) {
                    $pairs[] = ['t' => $f['t'], 'v' => $w['v'] * (1 - $f['v'] / 100)];
                    break;
                }
            }
        }
        if (count($pairs) === 0) {
            return null;
        }
        return round(self::applyRule($pairs, $this->measureRule), self::TYPES['lean_body_mass']['digits']);
    }

    /**
     * Picks the day value from instant rows ['t' => ts, 'v' => value].
     *  session: median of the first weighing session (all measurements within
     *           SESSION_WINDOW after the first one) - robust against a single outlier
     *  first / last / avg: as named
     */
    private static function applyRule(array $rows, string $rule): float
    {
        usort($rows, static function ($a, $b) {
            return $a['t'] <=> $b['t'];
        });
        switch ($rule) {
            case self::RULE_LAST:
                return (float) $rows[count($rows) - 1]['v'];
            case self::RULE_AVG:
                return array_sum(array_column($rows, 'v')) / count($rows);
            case self::RULE_FIRST:
                return (float) $rows[0]['v'];
            default: // session
                $start = $rows[0]['t'];
                $values = [];
                foreach ($rows as $r) {
                    if ($r['t'] - $start <= self::SESSION_WINDOW) {
                        $values[] = (float) $r['v'];
                    }
                }
                return self::median($values);
        }
    }

    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * Heart rate record -> [minute timestamp, bpm]. Aggregates (N-minute buckets) carry
     * avg/min/max - the bucket average is used; raw samples carry bpm only.
     * Only the preferred activity sources are kept (all, if none configured).
     */
    private function parseHeartRate(array $rec): ?array
    {
        $origin = strtolower(trim((string) ($rec['metadata']['data_origin'] ?? '')));
        $preferred = $this->preferred['activity'];
        if ($origin !== '' && count($preferred) > 0 && !in_array($origin, $preferred, true)) {
            return null;
        }
        $t = self::parseTime($rec['time'] ?? null);
        $bpm = $rec['avg'] ?? $rec['bpm'] ?? null;
        if ($t === null || !is_numeric($bpm) || $bpm < 25 || $bpm > 220) {
            return null;
        }
        return [intdiv($t, 60) * 60, round((float) $bpm, 1)];
    }

    /**
     * Resting heart rate of a wake-up day: lowest mean heart rate over RHR_WINDOW
     * during the sleep sessions of that day (source as for sleep). A single minute
     * would be too sensitive to watch artefacts.
     */
    private function deriveRestingHr(array $sleepRows, array $hr): ?int
    {
        $groups = [];
        foreach ($sleepRows as $r) {
            $groups[$r['o']][] = $r;
        }
        $origin = $this->pickOrigin($groups, self::TYPES['sleep']);
        $points = [];
        foreach ($groups[$origin] as $session) {
            $start = $session['s'] ?? (int) ($session['e'] - $session['v'] * 60);
            foreach ($hr as $minute => $bpm) {
                if ((int) $minute >= $start && (int) $minute <= $session['e']) {
                    $points[(int) $minute] = $bpm;
                }
            }
        }
        if (count($points) < self::RHR_MIN_POINTS) {
            return null;
        }
        ksort($points);
        $times = array_keys($points);
        $values = array_values($points);
        $best = null;
        $n = count($times);
        for ($i = 0; $i < $n; $i++) {
            $sum = 0.0;
            $cnt = 0;
            for ($j = $i; $j < $n && $times[$j] < $times[$i] + self::RHR_WINDOW; $j++) {
                $sum += $values[$j];
                $cnt++;
            }
            if ($cnt >= self::RHR_MIN_POINTS) {
                $mean = $sum / $cnt;
                $best = $best === null ? $mean : min($best, $mean);
            }
        }
        if ($best === null || $best < 30 || $best > 120) {
            return null;
        }
        return (int) round($best);
    }

    /**
     * A Health Connect daily aggregate (no source) that starts after local midnight and
     * runs to the end of the day only covers part of it: the first day of a sync window.
     */
    private function isPartialDailyAggregate(array $row, string $day): bool
    {
        if ($row['o'] !== '' || !isset($row['s'])) {
            return false;
        }
        [$dayStart, $dayEnd] = $this->clock->dayRange($day);
        return $row['s'] > $dayStart && $row['e'] >= $dayEnd && ($row['e'] - $row['s']) > 2 * 3600;
    }

    /**
     * @return array|null [day, key, row] - row: o (origin), v (value), t | s+e
     */
    private function parseRecord(array $def, array $rec): ?array
    {
        $origin = strtolower(trim((string) ($rec['metadata']['data_origin'] ?? '')));

        switch ($def['kind']) {
            case 'instant':
                $t = self::parseTime($rec['time'] ?? null);
                $v = $rec[$def['field']] ?? null;
                if ($t === null || !is_numeric($v) || !self::inRange((float) $v, $def['range'])) {
                    return null;
                }
                return [$this->clock->dayOf($t), $t . '|' . $origin, ['o' => $origin, 'v' => (float) $v, 't' => $t]];

            case 'interval':
                $s = self::parseTime($rec['start_time'] ?? null);
                $e = self::parseTime($rec['end_time'] ?? null);
                $v = $rec[$def['field']] ?? null;
                if ($s === null || $e === null || $e < $s || !is_numeric($v) || !self::inRange((float) $v, $def['range'])) {
                    return null;
                }
                return [$this->clock->dayOf($s), $s . '-' . $e . '|' . $origin, ['o' => $origin, 'v' => (float) $v, 's' => $s, 'e' => $e]];

            case 'sleep':
                $e = self::parseTime($rec['session_end_time'] ?? null);
                $duration = $rec['duration_seconds'] ?? null;
                if ($e === null || !is_numeric($duration) || $duration < 0) {
                    return null;
                }
                $minutes = self::asleepSeconds($rec, (int) $duration) / 60;
                if (!self::inRange($minutes, $def['range'])) {
                    return null;
                }
                // wake-up day; key without duration: an updated session replaces the old one
                return [$this->clock->dayOf($e), $e . '|' . $origin, ['o' => $origin, 'v' => round($minutes, 1), 's' => $e - (int) $duration, 'e' => $e]];
        }
        return null;
    }

    /** Sleep time without awake stages; whole session if no stages are delivered. */
    private static function asleepSeconds(array $rec, int $duration): int
    {
        $stages = $rec['stages'] ?? [];
        if (!is_array($stages) || count($stages) === 0) {
            return $duration;
        }
        $sum = 0;
        foreach ($stages as $st) {
            $name = strtolower((string) ($st['stage'] ?? ''));
            if (in_array($name, self::SLEEP_AWAKE_STAGES, true) || strpos($name, 'awake') !== false || strpos($name, 'out_of_bed') !== false) {
                continue;
            }
            $sum += max(0, (int) ($st['duration_seconds'] ?? 0));
        }
        return $sum;
    }

    /**
     * Picks one source per day: first configured preferred source that has data,
     * else the Health Connect aggregate (no origin, already de-duplicated by HC),
     * else the source with the most records (tie: alphabetical).
     */
    private function pickOrigin(array $groups, array $def): string
    {
        foreach ($this->preferred[$def['group']] as $p) {
            if (isset($groups[$p])) {
                return $p;
            }
        }
        if ($def['kind'] === 'interval' && isset($groups[''])) {
            return '';
        }
        $origins = array_keys($groups);
        usort($origins, static function ($a, $b) use ($groups) {
            return [count($groups[$b]), $a] <=> [count($groups[$a]), $b];
        });
        return (string) $origins[0];
    }

    /** Removes intervals fully contained in another interval (older / partial daily aggregates). */
    private static function dropContained(array $rows): array
    {
        usort($rows, static function ($a, $b) {
            return [$b['e'] - $b['s'], $b['r'] ?? 0] <=> [$a['e'] - $a['s'], $a['r'] ?? 0];
        });
        $kept = [];
        foreach ($rows as $r) {
            foreach ($kept as $k) {
                if ($k['s'] <= $r['s'] && $k['e'] >= $r['e']) {
                    continue 2;
                }
            }
            $kept[] = $r;
        }
        return $kept;
    }

    /** ISO-8601 -> unix timestamp; null if unparsable. */
    public static function parseTime($value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        // PHP handles at most 6 fractional digits
        $value = preg_replace('/(\.\d{6})\d+/', '$1', $value);
        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (Exception $e) {
            return null;
        }
    }

    private static function inRange(float $v, array $range): bool
    {
        return is_finite($v) && $v >= $range[0] && $v <= $range[1];
    }

    private static function normaliseList(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            $item = strtolower(trim((string) $item));
            if ($item !== '' && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }
        return $out;
    }
}
