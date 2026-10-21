<?php

declare(strict_types=1);

/**
 * Physically possible ranges of the daily variables.
 *
 * Used twice:
 *  - when reading (MCP server, dashboard): values outside the range count as "no data"
 *  - by the archive clean-up: such entries are deleted
 *
 * Typical culprit: the 0 IPS logs when archiving is switched on for a variable
 * (a 0 cm waist or 0 kg weight must never reach an evaluation).
 *
 * No IPS dependency - unit tested.
 */
class HHPlausibility
{
    /** ident => [min, max, minExclusive] */
    public const RANGES = [
        'KcalIn'       => [0, 10000, true],   // a tracked day has > 0 kcal; untracked days are null
        'Protein'      => [0, 1000, false],
        'Fat'          => [0, 1000, false],
        'Carbs'        => [0, 2000, false],
        'Sugar'        => [0, 1000, false],
        'Fiber'        => [0, 300, false],
        'Weight'       => [20, 300, false],
        'BodyFat'      => [1, 75, false],
        'LeanMass'     => [10, 200, false],
        'BodyWater'    => [5, 150, false],
        'BoneMass'     => [0.3, 10, false],
        'Waist'        => [30, 250, false],
        'Steps'        => [0, 200000, true],  // Health Connect only reports days with steps
        'ActiveKcal'   => [0, 10000, true],
        'SleepMinutes' => [0, 1440, true],
        'RestingHR'    => [25, 150, false],
    ];

    public static function isValid(string $ident, $value): bool
    {
        if ($value === null || !is_numeric($value)) {
            return false;
        }
        $v = (float) $value;
        if (!is_finite($v)) {
            return false;
        }
        if (!isset(self::RANGES[$ident])) {
            return true;
        }
        [$min, $max, $exclusive] = self::RANGES[$ident];
        return ($exclusive ? $v > $min : $v >= $min) && $v <= $max;
    }

    /** Value or null if implausible. */
    public static function filter(string $ident, $value)
    {
        return self::isValid($ident, $value) ? $value : null;
    }

    /** Applies filter() to a [day => value] map. */
    public static function filterDays(string $ident, array $days): array
    {
        foreach ($days as $d => $v) {
            $days[$d] = $v === null ? null : self::filter($ident, $v);
        }
        return $days;
    }
}
