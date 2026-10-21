<?php

declare(strict_types=1);

require_once __DIR__ . '/McpTools.php';

/**
 * Builds the JSON for the HealthHub dashboard (?data=1) from the same read-only
 * data source as the MCP server. Today is shown as "in progress" and never enters
 * averages or the TDEE estimate.
 *
 * No IPS dependency - unit tested.
 */
class HHDashboardData
{
    public const SERIES_DAYS = 60;
    private const AVG_DAYS = 7;
    private const TREND_DAYS = 28;

    private const IDENTS = ['KcalIn', 'Protein', 'Fat', 'Carbs', 'Weight', 'BodyFat', 'LeanMass', 'Waist',
        'Steps', 'ActiveKcal', 'SleepMinutes', 'RestingHR'];

    /** @var HHHealthDataSource */
    private $data;
    /** @var HHDayClock */
    private $clock;

    public function __construct(HHHealthDataSource $data, HHDayClock $clock)
    {
        $this->data = $data;
        $this->clock = $clock;
    }

    public function build(): array
    {
        $today = $this->clock->today();
        $from = $this->clock->addDays($today, -(self::SERIES_DAYS - 1));
        $days = $this->clock->daysBetween($from, $today);
        $v = $this->data->daily(self::IDENTS, $from, $today);
        $profile = $this->data->profile();
        $get = static function (string $ident, string $day) use ($v) {
            $x = $v[$ident][$day] ?? null;
            return $x === null ? null : (float) $x;
        };

        // today (in progress)
        $todayEntries = count($this->data->foodEntries($today, $today));
        $out = [
            'today' => $today,
            'targets' => [
                'kcal' => $profile['kcal_target'] ?? null,
                'protein_g' => $profile['protein_target_g'] ?? null,
                'fat_g' => $profile['fat_target_g'] ?? null,
                'pace_kg_week' => $profile['target_pace_kg_per_week'] ?? null,
            ],
            'goal' => $profile['goal'] ?? '',
            'day' => [
                'kcal' => $get('KcalIn', $today), 'protein_g' => $get('Protein', $today),
                'fat_g' => $get('Fat', $today), 'carbs_g' => $get('Carbs', $today),
                'entries' => $todayEntries,
                'steps' => $get('Steps', $today), 'active_kcal' => $get('ActiveKcal', $today),
                'sleep_min' => $get('SleepMinutes', $today), 'resting_hr' => $get('RestingHR', $today),
            ],
        ];

        // averages over the last complete days with FDDB entries
        $avgFrom = $this->clock->addDays($today, -self::AVG_DAYS);
        $avgDays = $this->clock->daysBetween($avgFrom, $this->clock->addDays($today, -1));
        $avg = ['kcal' => [], 'protein_g' => [], 'fat_g' => [], 'carbs_g' => [], 'steps' => [], 'sleep_min' => [], 'resting_hr' => []];
        $map = ['kcal' => 'KcalIn', 'protein_g' => 'Protein', 'fat_g' => 'Fat', 'carbs_g' => 'Carbs', 'steps' => 'Steps', 'sleep_min' => 'SleepMinutes', 'resting_hr' => 'RestingHR'];
        foreach ($avgDays as $d) {
            foreach ($map as $k => $ident) {
                $x = $get($ident, $d);
                if ($x !== null) {
                    $avg[$k][] = $x;
                }
            }
        }
        $out['avg7'] = ['days' => count($avg['kcal'])];
        foreach ($avg as $k => $list) {
            $out['avg7'][$k] = count($list) ? round(array_sum($list) / count($list), $k === 'kcal' || $k === 'steps' ? 0 : 1) : null;
        }

        // body: latest values and change over ~4 weeks
        $out['body'] = [];
        foreach (['weight' => 'Weight', 'body_fat' => 'BodyFat', 'lean_mass' => 'LeanMass', 'waist' => 'Waist'] as $k => $ident) {
            $latest = self::latest($v[$ident] ?? [], $days);
            $ref = self::latest($v[$ident] ?? [], array_filter($days, function ($d) use ($today) {
                return $d <= $this->clock->addDays($today, -self::TREND_DAYS);
            }));
            $first = self::first($v[$ident] ?? [], $days);
            $base = $ref ?? $first;
            $out['body'][$k] = [
                'value' => $latest['value'] ?? null,
                'date' => $latest['date'] ?? null,
                'change' => $latest !== null && $base !== null && $base['date'] !== $latest['date'] ? round($latest['value'] - $base['value'], 2) : null,
                'since' => $base !== null && $latest !== null && $base['date'] !== $latest['date'] ? $base['date'] : null,
            ];
        }
        $weighIns = 0;
        foreach ($this->clock->daysBetween($this->clock->addDays($today, -6), $today) as $d) {
            if ($get('Weight', $d) !== null) {
                $weighIns++;
            }
        }
        $out['body']['weigh_ins_7d'] = $weighIns;

        // TDEE over the last 8 weeks (same method as the MCP tool)
        $tools = new HHMcpTools($this->data, $this->clock, 366);
        $summary = $tools->call('get_weekly_summary', ['from' => $this->clock->addDays($today, -55), 'to' => $today]);
        $out['tdee'] = $summary['overall'];

        // series for the charts (today flagged: intake/steps incomplete)
        $series = ['days' => $days, 'weight' => [], 'waist' => [], 'body_fat' => [], 'lean_mass' => [], 'kcal' => [], 'protein_g' => [], 'steps' => [], 'sleep_min' => []];
        foreach ($days as $d) {
            $series['weight'][] = $get('Weight', $d);
            $series['waist'][] = $get('Waist', $d);
            $series['body_fat'][] = $get('BodyFat', $d);
            $series['lean_mass'][] = $get('LeanMass', $d);
            $series['kcal'][] = $get('KcalIn', $d);
            $series['protein_g'][] = $get('Protein', $d);
            $series['steps'][] = $get('Steps', $d);
            $series['sleep_min'][] = $get('SleepMinutes', $d);
        }
        $out['series'] = $series;
        return $out;
    }

    /** Most recent non-null value among $days: ['date', 'value'] or null. */
    private static function latest(array $values, array $days): ?array
    {
        $days = array_values($days);
        for ($i = count($days) - 1; $i >= 0; $i--) {
            if (($values[$days[$i]] ?? null) !== null) {
                return ['date' => $days[$i], 'value' => (float) $values[$days[$i]]];
            }
        }
        return null;
    }

    private static function first(array $values, array $days): ?array
    {
        foreach ($days as $d) {
            if (($values[$d] ?? null) !== null) {
                return ['date' => $d, 'value' => (float) $values[$d]];
            }
        }
        return null;
    }
}
