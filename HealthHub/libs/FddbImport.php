<?php

declare(strict_types=1);

require_once __DIR__ . '/FddbClient.php';
require_once __DIR__ . '/FoodStore.php';

/**
 * Imports one FDDB day: fetch -> replace the day in the food store -> day values
 * (read back from the store, so store and archive are always consistent).
 *
 * A day without entries is NOT a 0 kcal day: its values are null (= delete in the archive).
 * An unrecognised page (layout change, error page) throws and changes nothing.
 *
 * No IPS dependency - unit tested.
 */
class HHFddbImport
{
    /** variable ident => food_days column */
    public const IDENTS = [
        'KcalIn'  => 'kcal',
        'Protein' => 'protein',
        'Fat'     => 'fat',
        'Carbs'   => 'carbs',
        'Sugar'   => 'sugar',
        'Fiber'   => 'fiber',
    ];

    /**
     * @return array ['day', 'status' => ok|empty, 'entries' => n, 'values' => [ident => float|null], 'html']
     */
    public static function importDay(HHFddbClient $client, HHFoodStore $store, string $day, string $importedAt): array
    {
        $page = $client->fetchDay($day);
        if ($page['status'] === HHFddbParser::STATUS_OK) {
            $store->replaceDay($day, $page['entries'], $page['totals'], $importedAt);
        } elseif ($page['status'] === HHFddbParser::STATUS_EMPTY) {
            $store->deleteDay($day);
        } else {
            throw new RuntimeException('FDDB page for ' . $day . ' not recognised: ' . ($page['error'] ?? $page['status']));
        }
        return [
            'day'     => $day,
            'status'  => $page['status'],
            'entries' => count($page['entries']),
            'values'  => self::dayValues($store, $day),
            'html'    => $page['html'] ?? '',
        ];
    }

    /** Day values from the store: [ident => value|null]; all null if the day is not stored. */
    public static function dayValues(HHFoodStore $store, string $day): array
    {
        $totals = $store->dayTotals($day);
        $out = [];
        foreach (self::IDENTS as $ident => $col) {
            $v = $totals[$col] ?? null;
            $out[$ident] = $v === null ? null : round((float) $v, 1);
        }
        return $out;
    }
}
