<?php

declare(strict_types=1);

require_once __DIR__ . '/DayClock.php';

/**
 * Read-only data access for the MCP tools (implemented by the module on top of
 * the IPS archive and the food store; faked in the tests).
 */
interface HHHealthDataSource
{
    /** Daily values: [ident => ['YYYY-MM-DD' => value|null, ...]] for every day in [from, to]. */
    public function daily(array $idents, string $from, string $to): array;

    /** FDDB entries (rows as in HHFoodStore::entries). */
    public function foodEntries(string $from, string $to, string $search = '', string $meal = '', int $limit = 5000): array;

    /** FDDB day totals (rows as in HHFoodStore::days). */
    public function foodDays(string $from, string $to): array;

    /** Goals and context (module properties). */
    public function profile(): array;
}

/**
 * The HealthHub MCP tools. Strictly read-only: no tool writes anything or runs IPS functions.
 * Dates are YYYY-MM-DD; one row per day; missing values are null.
 * Invalid arguments throw InvalidArgumentException (-> tool error the model can fix).
 *
 * No IPS dependency - unit tested.
 */
class HHMcpTools
{
    public const KCAL_PER_KG = 7700;
    private const FOOD_ENTRIES_MAX_DAYS = 31;
    private const TDEE_MIN_NUTRITION_DAYS = 5;

    private const TODAY_NOTE = 'Today is still in progress: its intake and steps are incomplete and are excluded from averages and TDEE (row flag day_complete=false).';

    private const FOOD_NOTE = 'Source: FDDB diary pages. Entries made via FDDB AI food scan or FDDB recipes may be missing in FDDB exports; compare with the FDDB app if totals look low.';

    /** variable ident => output key */
    private const OVERVIEW = [
        'KcalIn' => 'kcal_in', 'Protein' => 'protein_g', 'Fat' => 'fat_g', 'Carbs' => 'carbs_g',
        'Sugar' => 'sugar_g', 'Fiber' => 'fiber_g',
        'Weight' => 'weight_kg', 'BodyFat' => 'body_fat_pct', 'LeanMass' => 'lean_mass_kg',
        'BodyWater' => 'body_water_kg', 'BoneMass' => 'bone_mass_kg', 'Waist' => 'waist_cm',
        'Steps' => 'steps', 'ActiveKcal' => 'active_kcal_watch_estimate', 'SleepMinutes' => 'sleep_min',
        'RestingHR' => 'resting_hr_bpm',
    ];
    private const BODY = ['Weight', 'BodyFat', 'LeanMass', 'BodyWater', 'BoneMass', 'Waist'];
    private const ACTIVITY = ['Steps', 'ActiveKcal', 'SleepMinutes', 'RestingHR'];

    /** @var HHHealthDataSource */
    private $data;
    /** @var HHDayClock */
    private $clock;
    /** @var int */
    private $maxDays;

    public function __construct(HHHealthDataSource $data, HHDayClock $clock, int $maxDays = 366)
    {
        $this->data = $data;
        $this->clock = $clock;
        $this->maxDays = max(1, min(366, $maxDays));
    }

    public function has(string $name): bool
    {
        return method_exists($this, 'tool_' . $name) && in_array($name, array_column($this->definitions(), 'name'), true);
    }

    public function call(string $name, array $args)
    {
        return $this->{'tool_' . $name}($args);
    }

    /** tools/list definitions (deterministic order). */
    public function definitions(): array
    {
        $range = function (string $extra = '') {
            return [
                'from' => ['type' => 'string', 'format' => 'date', 'description' => 'First day, YYYY-MM-DD (local time Europe/Vienna).'],
                'to'   => ['type' => 'string', 'format' => 'date', 'description' => 'Last day, YYYY-MM-DD, inclusive. Max. ' . $this->maxDays . ' days' . $extra . '.'],
            ];
        };
        $ro = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $obj = function (array $props, array $required) {
            return ['type' => 'object', 'properties' => $props, 'required' => $required, 'additionalProperties' => false];
        };
        return [
            [
                'name' => 'get_daily_overview',
                'title' => 'Daily overview',
                'description' => 'Most important tool. One row per day with all values merged: nutrition from FDDB (kcal_in, protein_g, fat_g, carbs_g, sugar_g, fiber_g), body from the Fitdays scale (weight_kg, body_fat_pct, lean_mass_kg = weight x (1 - body fat), body_water_kg, bone_mass_kg) and manual waist_cm, activity from the Pixel Watch (steps, active_kcal_watch_estimate, sleep_min on the wake-up day, resting_hr_bpm derived from the lowest 5-min heart rate during sleep). Missing values are null; a day without FDDB entries has kcal_in null (not 0). active_kcal_watch_estimate is the watch estimate (likely 30-50 % too high) and is NOT part of the calorie target.',
                'inputSchema' => $obj($range(), ['from', 'to']),
                'annotations' => $ro,
            ],
            [
                'name' => 'get_nutrition',
                'title' => 'Nutrition per day',
                'description' => 'FDDB day totals per day: kcal, protein_g, fat_g, carbs_g, sugar_g, fiber_g, alcohol_g, salt_g, entries (number of diary entries). Days without entries have tracked=false and null values. Includes averages over tracked days and the daily targets. ' . self::FOOD_NOTE,
                'inputSchema' => $obj($range(), ['from', 'to']),
                'annotations' => $ro,
            ],
            [
                'name' => 'get_food_entries',
                'title' => 'Food diary entries',
                'description' => 'All individual FDDB diary entries with date, time (HH:MM), meal (Breakfast, Lunch, Dinner, Snack 1/2/3 …), product, amount + unit, kcal, protein_g, fat_g, carbs_g. Sugar and fibre exist only as day totals. Range max. ' . self::FOOD_ENTRIES_MAX_DAYS . ' days - for longer periods use get_food_stats. Optional filters: search (case-insensitive part of the product name), meal (exact meal name). ' . self::FOOD_NOTE,
                'inputSchema' => $obj($range(' (here max. ' . self::FOOD_ENTRIES_MAX_DAYS . ')') + [
                    'search' => ['type' => 'string', 'description' => 'Optional: part of the product name, case-insensitive.'],
                    'meal'   => ['type' => 'string', 'description' => 'Optional: exact meal name, e.g. "Breakfast".'],
                ], ['from', 'to']),
                'annotations' => $ro,
            ],
            [
                'name' => 'get_food_stats',
                'title' => 'Food statistics per product',
                'description' => 'Aggregates FDDB entries per product over a period: count, days, total amount (if units agree), kcal, protein_g, fat_g, carbs_g and the share of total kcal/protein in %. For questions like "What are my biggest calorie sources?" or "Where does my protein come from?". sort_by: kcal (default), protein or count; limit default 25 (max. 200).',
                'inputSchema' => $obj($range() + [
                    'sort_by' => ['type' => 'string', 'enum' => ['kcal', 'protein', 'count'], 'description' => 'Sort order, default kcal.'],
                    'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Number of products, default 25.'],
                ], ['from', 'to']),
                'annotations' => $ro,
            ],
            [
                'name' => 'get_meal_pattern',
                'title' => 'Meal pattern',
                'description' => 'Eating pattern over tracked days (days with FDDB entries): average kcal and protein per day by meal and by time window (night 00-05, morning 05-11, midday 11-14, afternoon 14-18, evening 18-22, late 22-24), and weekday (Mon-Fri) vs weekend (Sat-Sun) daily averages.',
                'inputSchema' => $obj($range(), ['from', 'to']),
                'annotations' => $ro,
            ],
            [
                'name' => 'get_body',
                'title' => 'Body composition',
                'description' => 'Body values per measurement day (days without any measurement are omitted): weight_kg, body_fat_pct, lean_mass_kg (derived: weight x (1 - body fat), Fitdays does not export it), body_water_kg (usually null, Fitdays does not export it), bone_mass_kg, waist_cm (manual). Scale values = median of the first weighing session of the day (morning).',
                'inputSchema' => $obj($range(), ['from', 'to']),
                'annotations' => $ro,
            ],
            [
                'name' => 'get_activity',
                'title' => 'Activity and sleep',
                'description' => 'Per day: steps, active_kcal_watch_estimate (Pixel Watch estimate, likely 30-50 % too high, not part of the calorie target), sleep_min (asleep time without awake phases, assigned to the wake-up day), resting_hr_bpm (lowest 5-minute mean heart rate during sleep; null if the night was not measured enough).',
                'inputSchema' => $obj($range(), ['from', 'to']),
                'annotations' => $ro,
            ],
            [
                'name' => 'get_weekly_summary',
                'title' => 'Weekly summary with TDEE estimate',
                'description' => 'ISO weeks: averages of kcal_in, protein, fat, carbs (over tracked days), steps, sleep, resting HR, weight; number of tracked nutrition days and weigh-ins; weight change vs previous week; and an estimated total daily energy expenditure TDEE = avg kcal_in - (weekly change of average weight x 7700) / 7, only for weeks with >= 5 tracked days and weight in both weeks. Also an overall TDEE over the whole period. Method and uncertainty are included.',
                'inputSchema' => $obj($range(), ['from', 'to']),
                'annotations' => $ro,
            ],
            [
                'name' => 'get_profile',
                'title' => 'Goals and context',
                'description' => 'Goals and context for evaluating the data: the personal goal in own words, calorie target (flat, sport included, watch calories not counted), protein and fat target, target pace (kg/week), start values, data sources and known limitations. Call this first when judging progress.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false],
                'annotations' => $ro,
            ],
        ];
    }

    // ------------------------------------------------------------------ tools

    private function tool_get_daily_overview(array $a): array
    {
        [$from, $to, $days] = $this->range($a);
        $vals = $this->data->daily(array_keys(self::OVERVIEW), $from, $to);
        $rows = [];
        foreach ($days as $d) {
            $row = ['date' => $d, 'weekday' => $this->weekday($d), 'day_complete' => $d < $this->clock->today()];
            foreach (self::OVERVIEW as $ident => $key) {
                $row[$key] = self::num($vals[$ident][$d] ?? null);
            }
            $rows[] = $row;
        }
        return [
            'from' => $from, 'to' => $to,
            'units' => 'kcal, g, kg, %, cm, min, bpm',
            'notes' => 'null = no data. kcal_in null means no FDDB entries that day (not 0 kcal). active_kcal_watch_estimate is a watch estimate, likely 30-50 % too high, not counted against the calorie target. Today is still in progress: its intake and steps are incomplete and are excluded from averages and TDEE (row flag day_complete=false).',
            'days' => $rows,
        ];
    }

    private function tool_get_nutrition(array $a): array
    {
        [$from, $to, $days] = $this->range($a);
        $byDay = [];
        foreach ($this->data->foodDays($from, $to) as $r) {
            $byDay[$r['day']] = $r;
        }
        $rows = [];
        $sum = ['kcal' => 0.0, 'protein_g' => 0.0, 'fat_g' => 0.0, 'carbs_g' => 0.0, 'sugar_g' => 0.0, 'fiber_g' => 0.0];
        $n = 0;
        foreach ($days as $d) {
            $r = $byDay[$d] ?? null;
            $row = [
                'date' => $d, 'weekday' => $this->weekday($d), 'tracked' => $r !== null, 'day_complete' => $d < $this->clock->today(),
                'kcal' => self::num($r['kcal'] ?? null), 'protein_g' => self::num($r['protein'] ?? null),
                'fat_g' => self::num($r['fat'] ?? null), 'carbs_g' => self::num($r['carbs'] ?? null),
                'sugar_g' => self::num($r['sugar'] ?? null), 'fiber_g' => self::num($r['fiber'] ?? null),
                'alcohol_g' => self::num($r['alcohol'] ?? null), 'salt_g' => self::num($r['salt'] ?? null),
                'entries' => $r !== null ? (int) $r['entries'] : 0,
            ];
            if ($r !== null && $row['day_complete']) {
                $n++;
                foreach ($sum as $k => $_) {
                    $sum[$k] += (float) ($row[$k] ?? 0);
                }
            }
            $rows[] = $row;
        }
        $p = $this->data->profile();
        $avg = null;
        if ($n > 0) {
            $avg = [];
            foreach ($sum as $k => $v) {
                $avg[$k] = round($v / $n, 1);
            }
        }
        return [
            'from' => $from, 'to' => $to,
            'tracked_days' => $n, 'days_in_range' => count($days), 'averages_cover' => 'complete tracked days only (today excluded)',
            'average_per_tracked_day' => $avg,
            'targets' => ['kcal' => $p['kcal_target'] ?? null, 'protein_g' => $p['protein_target_g'] ?? null, 'fat_g' => $p['fat_target_g'] ?? null],
            'notes' => self::FOOD_NOTE . ' ' . self::TODAY_NOTE,
            'days' => $rows,
        ];
    }

    private function tool_get_food_entries(array $a): array
    {
        [$from, $to, $days] = $this->range($a);
        if (count($days) > self::FOOD_ENTRIES_MAX_DAYS) {
            throw new InvalidArgumentException('get_food_entries covers at most ' . self::FOOD_ENTRIES_MAX_DAYS . ' days (requested ' . count($days) . '). Use get_food_stats for longer periods, or split the range.');
        }
        $search = self::optString($a, 'search');
        $meal = self::optString($a, 'meal');
        $rows = [];
        foreach ($this->data->foodEntries($from, $to, $search, $meal, 5000) as $e) {
            $rows[] = [
                'date' => $e['day'], 'time' => $e['time'], 'meal' => $e['meal'], 'product' => $e['product'],
                'amount' => self::num($e['amount']), 'unit' => $e['unit'],
                'kcal' => self::num($e['kcal']), 'protein_g' => self::num($e['protein']),
                'fat_g' => self::num($e['fat']), 'carbs_g' => self::num($e['carbs']),
            ];
        }
        return [
            'from' => $from, 'to' => $to,
            'filter' => ['search' => $search !== '' ? $search : null, 'meal' => $meal !== '' ? $meal : null],
            'count' => count($rows),
            'notes' => self::FOOD_NOTE,
            'entries' => $rows,
        ];
    }

    private function tool_get_food_stats(array $a): array
    {
        [$from, $to] = $this->range($a);
        $sortBy = $a['sort_by'] ?? 'kcal';
        if (!in_array($sortBy, ['kcal', 'protein', 'count'], true)) {
            throw new InvalidArgumentException('sort_by must be kcal, protein or count.');
        }
        $limit = (int) ($a['limit'] ?? 25);
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('limit must be between 1 and 200.');
        }
        $products = [];
        $total = ['kcal' => 0.0, 'protein' => 0.0];
        foreach ($this->data->foodEntries($from, $to, '', '', 100000) as $e) {
            $key = mb_strtolower(trim((string) $e['product']));
            if (!isset($products[$key])) {
                $products[$key] = ['product' => $e['product'], 'count' => 0, 'days' => [], 'amount' => 0.0, 'units' => [],
                    'kcal' => 0.0, 'protein_g' => 0.0, 'fat_g' => 0.0, 'carbs_g' => 0.0];
            }
            $p = &$products[$key];
            $p['count']++;
            $p['days'][$e['day']] = true;
            $p['units'][(string) $e['unit']] = true;
            $p['amount'] += (float) $e['amount'];
            $p['kcal'] += (float) $e['kcal'];
            $p['protein_g'] += (float) $e['protein'];
            $p['fat_g'] += (float) $e['fat'];
            $p['carbs_g'] += (float) $e['carbs'];
            unset($p);
            $total['kcal'] += (float) $e['kcal'];
            $total['protein'] += (float) $e['protein'];
        }
        $rows = [];
        foreach ($products as $p) {
            $oneUnit = count($p['units']) === 1;
            $rows[] = [
                'product' => $p['product'], 'count' => $p['count'], 'days' => count($p['days']),
                'total_amount' => $oneUnit ? self::num(round($p['amount'], 1)) : null,
                'unit' => $oneUnit ? array_key_first($p['units']) : 'mixed',
                'kcal' => (int) round($p['kcal']), 'protein_g' => round($p['protein_g'], 1),
                'fat_g' => round($p['fat_g'], 1), 'carbs_g' => round($p['carbs_g'], 1),
                'kcal_share_pct' => $total['kcal'] > 0 ? round($p['kcal'] / $total['kcal'] * 100, 1) : null,
                'protein_share_pct' => $total['protein'] > 0 ? round($p['protein_g'] / $total['protein'] * 100, 1) : null,
            ];
        }
        $field = ['kcal' => 'kcal', 'protein' => 'protein_g', 'count' => 'count'][$sortBy];
        usort($rows, static function ($x, $y) use ($field) {
            return [$y[$field], $x['product']] <=> [$x[$field], $y['product']];
        });
        return [
            'from' => $from, 'to' => $to, 'sort_by' => $sortBy,
            'distinct_products' => count($rows),
            'total' => ['kcal' => (int) round($total['kcal']), 'protein_g' => round($total['protein'], 1)],
            'notes' => self::FOOD_NOTE,
            'products' => array_slice($rows, 0, $limit),
        ];
    }

    private function tool_get_meal_pattern(array $a): array
    {
        [$from, $to] = $this->range($a);
        $today = $this->clock->today();
        $entries = array_values(array_filter($this->data->foodEntries($from, $to, '', '', 100000), static function ($e) use ($today) {
            return $e['day'] < $today; // today is still in progress
        }));
        $trackedDays = [];
        $meals = [];
        $windows = [];
        $perDay = [];
        foreach ($entries as $e) {
            $d = $e['day'];
            $trackedDays[$d] = true;
            self::addTo($meals, $e['meal'] ?? 'unknown', $e);
            self::addTo($windows, self::timeWindow($e['time'] ?? null), $e);
            $perDay[$d]['kcal'] = ($perDay[$d]['kcal'] ?? 0) + (float) $e['kcal'];
            $perDay[$d]['protein'] = ($perDay[$d]['protein'] ?? 0) + (float) $e['protein'];
        }
        $n = count($trackedDays);
        $totalKcal = array_sum(array_column($perDay, 'kcal'));
        $fmt = function (array $buckets, array $order = []) use ($n, $totalKcal) {
            $out = [];
            $keys = array_keys($buckets);
            usort($keys, static function ($x, $y) use ($order, $buckets) {
                $ix = array_search($x, $order, true);
                $iy = array_search($y, $order, true);
                if ($ix !== false || $iy !== false) {
                    return ($ix === false ? 99 : $ix) <=> ($iy === false ? 99 : $iy);
                }
                return $buckets[$y]['kcal'] <=> $buckets[$x]['kcal'];
            });
            foreach ($keys as $k) {
                $b = $buckets[$k];
                $out[] = [
                    'name' => $k,
                    'avg_kcal_per_tracked_day' => $n ? (int) round($b['kcal'] / $n) : null,
                    'avg_protein_g_per_tracked_day' => $n ? round($b['protein'] / $n, 1) : null,
                    'kcal_share_pct' => $totalKcal > 0 ? round($b['kcal'] / $totalKcal * 100, 1) : null,
                    'days_present' => count($b['days']),
                ];
            }
            return $out;
        };
        $split = ['weekday' => [], 'weekend' => []];
        foreach ($perDay as $d => $v) {
            $split[(int) (new DateTimeImmutable($d))->format('N') >= 6 ? 'weekend' : 'weekday'][] = $v;
        }
        $avg = function (array $list) {
            if (count($list) === 0) {
                return ['tracked_days' => 0, 'avg_kcal' => null, 'avg_protein_g' => null];
            }
            return [
                'tracked_days' => count($list),
                'avg_kcal' => (int) round(array_sum(array_column($list, 'kcal')) / count($list)),
                'avg_protein_g' => round(array_sum(array_column($list, 'protein')) / count($list), 1),
            ];
        };
        return [
            'from' => $from, 'to' => $to, 'tracked_days' => $n,
            'by_meal' => $fmt($meals),
            'by_time_window' => $fmt($windows, ['night 00-05', 'morning 05-11', 'midday 11-14', 'afternoon 14-18', 'evening 18-22', 'late 22-24', 'no time']),
            'weekday_vs_weekend' => ['weekday_mon_fri' => $avg($split['weekday']), 'weekend_sat_sun' => $avg($split['weekend'])],
        ];
    }

    private function tool_get_body(array $a): array
    {
        [$from, $to, $days] = $this->range($a);
        $vals = $this->data->daily(self::BODY, $from, $to);
        $rows = [];
        foreach ($days as $d) {
            $row = ['date' => $d];
            $any = false;
            foreach (self::BODY as $ident) {
                $v = self::num($vals[$ident][$d] ?? null);
                $row[self::OVERVIEW[$ident]] = $v;
                $any = $any || $v !== null;
            }
            if ($any) {
                $rows[] = $row;
            }
        }
        return [
            'from' => $from, 'to' => $to, 'measurement_days' => count($rows),
            'notes' => 'Days without any measurement are omitted. lean_mass_kg derived from weight x (1 - body fat %). body_water_kg usually null (not exported by Fitdays). Scale values: median of the first weighing session of the day.',
            'days' => $rows,
        ];
    }

    private function tool_get_activity(array $a): array
    {
        [$from, $to, $days] = $this->range($a);
        $vals = $this->data->daily(self::ACTIVITY, $from, $to);
        $rows = [];
        foreach ($days as $d) {
            $row = ['date' => $d, 'weekday' => $this->weekday($d)];
            foreach (self::ACTIVITY as $ident) {
                $row[self::OVERVIEW[$ident]] = self::num($vals[$ident][$d] ?? null);
            }
            $rows[] = $row;
        }
        return [
            'from' => $from, 'to' => $to,
            'notes' => 'active_kcal_watch_estimate is the Pixel Watch estimate, presumably 30-50 % too high; it is NOT part of the calorie target (sport is included in the flat target). sleep_min is assigned to the wake-up day. resting_hr_bpm = lowest 5-minute mean heart rate during sleep.',
            'days' => $rows,
        ];
    }

    private function tool_get_weekly_summary(array $a): array
    {
        [$from, $to, $days] = $this->range($a);
        // one extra week before the range for the first weight change
        $pre = $this->clock->addDays($from, -7);
        $idents = ['KcalIn', 'Protein', 'Fat', 'Carbs', 'Steps', 'SleepMinutes', 'RestingHR', 'Weight', 'BodyFat', 'LeanMass'];
        $vals = $this->data->daily($idents, $pre, $to);

        $weeks = [];
        foreach ($this->clock->daysBetween($pre, $to) as $d) {
            $dt = new DateTimeImmutable($d);
            $key = $dt->format('o-\WW');
            $weeks[$key]['days'][] = $d;
            $weeks[$key]['in_range'] = ($weeks[$key]['in_range'] ?? false) || $d >= $from;
        }

        $rows = [];
        $prevWeight = null;
        $qualifying = [];
        foreach ($weeks as $key => $w) {
            // averages cover the whole ISO week (also its days before "from"), non-null values only
            $series = [];
            foreach ($idents as $ident) {
                $series[$ident] = [];
                foreach ($w['days'] as $d) {
                    if ($d === $this->clock->today() && in_array($ident, ['KcalIn', 'Protein', 'Fat', 'Carbs', 'Steps'], true)) {
                        continue; // today's intake and steps are still incomplete
                    }
                    if (($vals[$ident][$d] ?? null) !== null) {
                        $series[$ident][] = (float) $vals[$ident][$d];
                    }
                }
            }
            $avgWeight = self::avg($series['Weight'], 2);
            if (!$w['in_range']) {
                $prevWeight = $avgWeight;
                continue;
            }
            $inRangeDays = array_values(array_filter($w['days'], static function ($d) use ($from) {
                return $d >= $from;
            }));
            $nutritionDays = count($series['KcalIn']);
            $avgKcal = self::avg($series['KcalIn'], 0);
            $change = $avgWeight !== null && $prevWeight !== null ? round($avgWeight - $prevWeight, 2) : null;
            $tdee = null;
            $note = null;
            if ($nutritionDays < self::TDEE_MIN_NUTRITION_DAYS) {
                $note = 'no TDEE: only ' . $nutritionDays . ' tracked nutrition day(s), need >= ' . self::TDEE_MIN_NUTRITION_DAYS;
            } elseif ($change === null) {
                $note = 'no TDEE: weight missing in this or the previous week';
            } else {
                $tdee = (int) round($avgKcal - $change * self::KCAL_PER_KG / 7);
            }
            $row = [
                'week' => $key, 'from' => $inRangeDays[0], 'to' => end($inRangeDays), 'days_in_range' => count($inRangeDays),
                'nutrition_days' => $nutritionDays,
                'avg_kcal_in' => $avgKcal, 'avg_protein_g' => self::avg($series['Protein'], 1),
                'avg_fat_g' => self::avg($series['Fat'], 1), 'avg_carbs_g' => self::avg($series['Carbs'], 1),
                'avg_steps' => self::avg($series['Steps'], 0), 'avg_sleep_min' => self::avg($series['SleepMinutes'], 0),
                'avg_resting_hr_bpm' => self::avg($series['RestingHR'], 0),
                'weigh_ins' => count($series['Weight']), 'avg_weight_kg' => $avgWeight,
                'weight_change_kg' => $change,
                'avg_body_fat_pct' => self::avg($series['BodyFat'], 1), 'avg_lean_mass_kg' => self::avg($series['LeanMass'], 2),
                'tdee_estimate_kcal' => $tdee,
            ];
            if ($note !== null) {
                $row['tdee_note'] = $note;
            }
            $rows[] = $row;
            if ($avgWeight !== null) {
                $prevWeight = $avgWeight;
            }
            if ($nutritionDays >= self::TDEE_MIN_NUTRITION_DAYS && $avgWeight !== null) {
                $qualifying[] = ['week' => $key, 'days' => $w['days'], 'kcal' => $series['KcalIn'], 'weight' => $avgWeight, 'nutrition_days' => $nutritionDays];
            }
        }

        return [
            'from' => $from, 'to' => $to,
            'method' => 'TDEE = average kcal_in of tracked days - (change of weekly average weight x ' . self::KCAL_PER_KG . ' kcal/kg) / days. Only weeks with >= ' . self::TDEE_MIN_NUTRITION_DAYS . ' tracked nutrition days and weight in the week and the week before. Weekly averages always cover the whole ISO week (Mon-Sun, up to "to"). Watch calories are not used.',
            'today' => self::TODAY_NOTE,
            'uncertainty' => 'Weekly average weight varies by roughly +/-0.3-0.5 kg (water, glycogen, salt, bowel content). Over one week that alone is +/-300-550 kcal/day, so single weeks are rough; the overall estimate over several weeks is much more reliable. Missing FDDB entries (forgotten items, AI food scan, recipes) bias the TDEE estimate low.',
            'overall' => $this->overallTdee($qualifying),
            'weeks' => $rows,
        ];
    }

    /** TDEE over the whole period: first vs last qualifying week. */
    private function overallTdee(array $q): array
    {
        if (count($q) < 2) {
            return ['tdee_estimate_kcal' => null, 'note' => 'needs at least 2 qualifying weeks (>= ' . self::TDEE_MIN_NUTRITION_DAYS . ' tracked days and a weight)'];
        }
        $first = $q[0];
        $last = $q[count($q) - 1];
        $mid = static function (array $days) {
            return (strtotime($days[0]) + strtotime(end($days))) / 2;
        };
        $span = ($mid($last['days']) - $mid($first['days'])) / 86400;
        $kcal = [];
        foreach ($q as $w) {
            $kcal = array_merge($kcal, $w['kcal']);
        }
        $avgKcal = array_sum($kcal) / count($kcal);
        $change = $last['weight'] - $first['weight'];
        $tdee = $avgKcal - $change * self::KCAL_PER_KG / $span;
        // +/-0.4 kg noise on each weekly mean -> error of the difference ~0.57 kg
        $unc = 0.4 * sqrt(2) * self::KCAL_PER_KG / $span;
        return [
            'tdee_estimate_kcal' => (int) round($tdee),
            'uncertainty_kcal' => (int) round($unc),
            'avg_kcal_in' => (int) round($avgKcal),
            'weight_change_kg' => round($change, 2),
            'weeks_used' => count($q),
            'first_week' => $first['week'], 'last_week' => $last['week'],
            'span_days' => (int) round($span),
            'tracked_nutrition_days' => count($kcal),
            'rate_kg_per_week' => round($change / $span * 7, 2),
        ];
    }

    private function tool_get_profile(array $a): array
    {
        return $this->data->profile();
    }

    // ------------------------------------------------------------------ helpers

    /** Validates from/to, clips "to" at today. @return [from, to, days[]] */
    private function range(array $a): array
    {
        $from = $a['from'] ?? null;
        $to = $a['to'] ?? null;
        if (!is_string($from) || !HHDayClock::isValidDay($from) || !is_string($to) || !HHDayClock::isValidDay($to)) {
            throw new InvalidArgumentException('from and to are required as YYYY-MM-DD (e.g. "2026-10-01").');
        }
        if ($from > $to) {
            throw new InvalidArgumentException('from must not be after to.');
        }
        $today = $this->clock->today();
        if ($from > $today) {
            throw new InvalidArgumentException('from is in the future; today is ' . $today . '.');
        }
        $to = min($to, $today);
        $days = $this->clock->daysBetween($from, $to);
        if (count($days) > $this->maxDays) {
            throw new InvalidArgumentException('Range too long: ' . count($days) . ' days, maximum ' . $this->maxDays . '. Split the request.');
        }
        return [$from, $to, $days];
    }

    private function weekday(string $day): string
    {
        return (new DateTimeImmutable($day))->format('D');
    }

    private static function addTo(array &$buckets, string $key, array $entry): void
    {
        $buckets[$key]['kcal'] = ($buckets[$key]['kcal'] ?? 0) + (float) $entry['kcal'];
        $buckets[$key]['protein'] = ($buckets[$key]['protein'] ?? 0) + (float) $entry['protein'];
        $buckets[$key]['days'][$entry['day']] = true;
    }

    private static function timeWindow(?string $time): string
    {
        if ($time === null || !preg_match('/^(\d{2}):/', $time, $m)) {
            return 'no time';
        }
        $h = (int) $m[1];
        if ($h < 5) {
            return 'night 00-05';
        }
        if ($h < 11) {
            return 'morning 05-11';
        }
        if ($h < 14) {
            return 'midday 11-14';
        }
        if ($h < 18) {
            return 'afternoon 14-18';
        }
        if ($h < 22) {
            return 'evening 18-22';
        }
        return 'late 22-24';
    }

    private static function avg(array $values, int $digits)
    {
        if (count($values) === 0) {
            return null;
        }
        $v = round(array_sum($values) / count($values), $digits);
        return $digits === 0 ? (int) $v : $v;
    }

    private static function num($v)
    {
        if ($v === null || $v === '') {
            return null;
        }
        $f = (float) $v;
        return floor($f) == $f && abs($f) < 1e9 ? (int) $f : $f;
    }

    private static function optString(array $a, string $key): string
    {
        $v = $a[$key] ?? '';
        if (!is_string($v)) {
            throw new InvalidArgumentException($key . ' must be a string.');
        }
        return trim($v);
    }
}
