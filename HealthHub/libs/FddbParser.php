<?php

declare(strict_types=1);

/**
 * Parses one fddb.info diary page (".../db/i18n/myday20/?lang=en&p=<from>&q=<to>").
 *
 * Why the diary page and not the CSV export: the page carries time, meal, product, amount
 * and kcal/fat/carbs/protein per entry plus the day totals including sugar, fibre, alcohol
 * and salt; the CSV export is known to be incomplete (see github.com/itobey/fddb-exporter).
 *
 * Page structure (lang=en, layout as of 2026):
 *  - header quick links contain "Logout"/"Abmelden" when logged in, "Login"/"Anmelden" when not
 *  - table.myday-table-std: header row, then per meal a grey category row
 *    ("Dinner: 1 product", style color:#AAAAAA) followed by its entries:
 *      td0: <span class="mydayshowtime">17:00&nbsp;</span><a href="…/food/<slug>/index.html">125 g Name</a>
 *      td1 kJ, td2 kcal, td3 fat, td4 carbs, td5 protein
 *    last row (class *-footer) = day totals
 *  - below: a label/value table with "Calorific value", "thereof Sugar", "Dietary fibre",
 *    "Alcohol", "Water", "Cholesterol", … and a minerals table with "Salt"
 *  - logged in but nothing logged: no myday-table-std at all
 *
 * No IPS dependency - unit tested.
 */
class HHFddbParser
{
    public const STATUS_OK = 'ok';
    public const STATUS_EMPTY = 'empty';            // logged in, no entries for the day
    public const STATUS_LOGGED_OUT = 'logged_out';
    public const STATUS_UNKNOWN = 'unknown';        // neither diary nor known page -> layout change?

    /** Summary labels (lower case, whitespace collapsed) -> total keys. */
    private const SUMMARY_LABELS = [
        'fat'              => 'fat',
        'carbohydrates'    => 'carbs',
        'thereof sugar'    => 'sugar',
        'protein'          => 'protein',
        'alcohol'          => 'alcohol',
        'water'            => 'water_l',
        'dietary fibre'    => 'fiber',
        'dietary fiber'    => 'fiber',
        'cholesterol'      => 'cholesterol_mg',
        'salt'             => 'salt',
    ];

    /**
     * @return array [
     *   'status'  => ok|empty|logged_out|unknown,
     *   'entries' => [[time, meal, product, amount, unit, kj, kcal, fat, carbs, protein, link], ...],
     *   'totals'  => [kcal, kj, fat, carbs, sugar, protein, fiber, alcohol, water_l, cholesterol_mg, salt] (null if absent),
     *   'error'   => string|null
     * ]
     */
    public static function parse(string $html): array
    {
        $result = ['status' => self::STATUS_UNKNOWN, 'entries' => [], 'totals' => [], 'error' => null];
        if (trim($html) === '') {
            $result['error'] = 'empty response';
            return $result;
        }

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);

        $login = self::loginState($xp);
        if ($login === 'out') {
            $result['status'] = self::STATUS_LOGGED_OUT;
            return $result;
        }

        $rows = $xp->query("//table[contains(concat(' ', normalize-space(@class), ' '), ' myday-table-std ')]//tr");
        if ($rows === false || $rows->length === 0) {
            if ($login === 'in') {
                $result['status'] = self::STATUS_EMPTY;
            } else {
                $result['error'] = 'neither diary table nor login state found - page layout changed?';
            }
            return $result;
        }

        $meal = null;
        $footer = null;
        foreach ($rows as $row) {
            $cells = $xp->query('./td', $row);
            if ($cells->length < 6) {
                continue; // header row (th) or malformed
            }
            $first = $cells->item(0);
            if (strpos((string) $first->getAttribute('class'), 'footer') !== false) {
                $footer = $cells;
                continue;
            }
            if (self::isCategoryRow($xp, $row)) {
                // "Dinner: 1 product" -> "Dinner"
                $meal = trim(preg_replace('/\s*:.*$/u', '', self::text($first)));
                continue;
            }
            $entry = self::parseEntry($xp, $cells, $meal);
            if ($entry !== null) {
                $result['entries'][] = $entry;
            }
        }

        $totals = array_fill_keys(['kcal', 'kj', 'fat', 'carbs', 'sugar', 'protein', 'fiber', 'alcohol', 'water_l', 'cholesterol_mg', 'salt'], null);
        if ($footer !== null) {
            $totals['kj'] = self::number(self::text($footer->item(1)));
            $totals['kcal'] = self::number(self::text($footer->item(2)));
            $totals['fat'] = self::number(self::text($footer->item(3)));
            $totals['carbs'] = self::number(self::text($footer->item(4)));
            $totals['protein'] = self::number(self::text($footer->item(5)));
        }
        foreach (self::summaryRows($xp) as $label => $value) {
            $key = self::SUMMARY_LABELS[$label] ?? null;
            if ($key !== null && ($totals[$key] === null || in_array($key, ['sugar', 'fiber', 'alcohol', 'water_l', 'cholesterol_mg', 'salt'], true))) {
                $totals[$key] = self::number($value);
            }
            if ($label === 'calorific value' && $totals['kcal'] === null && preg_match('/\(([\d.,]+)\s*kcal\)/i', $value, $m)) {
                $totals['kcal'] = self::number($m[1]);
            }
        }
        if ($totals['kcal'] === null && count($result['entries']) > 0) {
            // no footer (layout change) -> sum of the entries
            foreach (['kj', 'kcal', 'fat', 'carbs', 'protein'] as $k) {
                $totals[$k] = round(array_sum(array_map(static function ($e) use ($k) {
                    return (float) ($e[$k] ?? 0);
                }, $result['entries'])), 1);
            }
        }
        $result['totals'] = $totals;

        if (count($result['entries']) === 0) {
            $result['status'] = $totals['kcal'] !== null && $totals['kcal'] > 0 ? self::STATUS_UNKNOWN : self::STATUS_EMPTY;
            if ($result['status'] === self::STATUS_UNKNOWN) {
                $result['error'] = 'totals found but no entries - page layout changed?';
            }
            return $result;
        }
        $result['status'] = self::STATUS_OK;
        return $result;
    }

    /**
     * Splits "125 g Tourinos, Meersalz & Pfeffer" into [125.0, 'g', 'Tourinos, …'].
     * Amount may use "," or "." as decimal separator; unit is the second token.
     */
    public static function splitAmount(string $label): array
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label));
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s+(\S+)\s+(.+)$/u', $label, $m)) {
            return [(float) str_replace(',', '.', $m[1]), $m[2], $m[3]];
        }
        return [null, null, $label];
    }

    /** "1,234.5 kcal" / "12.3 g" / "0.1 litres" -> float; null if no number. */
    public static function number(?string $text): ?float
    {
        if ($text === null) {
            return null;
        }
        $text = str_replace(["\xc2\xa0", ' '], '', $text);
        if (!preg_match('/-?\d[\d,]*(?:\.\d+)?/', $text, $m)) {
            return null;
        }
        return (float) str_replace(',', '', $m[0]);
    }

    // ------------------------------------------------------------------

    /** 'in', 'out' or '' (unknown) from the header quick links. */
    private static function loginState(DOMXPath $xp): string
    {
        foreach ($xp->query("//div[contains(@class, 'quicklinks')]//a") as $a) {
            $t = strtolower(trim($a->textContent));
            if ($t === 'logout' || $t === 'abmelden') {
                return 'in';
            }
            if ($t === 'login' || $t === 'anmelden') {
                return 'out';
            }
        }
        return '';
    }

    private static function isCategoryRow(DOMXPath $xp, DOMNode $row): bool
    {
        foreach ($xp->query('.//span[@style]', $row) as $span) {
            if (stripos(str_replace(' ', '', $span->getAttribute('style')), 'color:#aaaaaa') !== false) {
                return true;
            }
        }
        return false;
    }

    private static function parseEntry(DOMXPath $xp, DOMNodeList $cells, ?string $meal): ?array
    {
        $first = $cells->item(0);
        $link = $xp->query('.//a', $first)->item(0);
        if ($link === null) {
            return null;
        }
        $timeNode = $xp->query(".//span[contains(@class, 'mydayshowtime')]", $first)->item(0);
        $time = null;
        if ($timeNode !== null && preg_match('/(\d{1,2}):(\d{2})/', $timeNode->textContent, $m)) {
            $time = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }
        [$amount, $unit, $product] = self::splitAmount(self::text($link));
        return [
            'time'    => $time,
            'meal'    => $meal,
            'product' => $product,
            'amount'  => $amount,
            'unit'    => $unit,
            'kj'      => self::number(self::text($cells->item(1))),
            'kcal'    => self::number(self::text($cells->item(2))),
            'fat'     => self::number(self::text($cells->item(3))),
            'carbs'   => self::number(self::cellValue($xp, $cells->item(4))),
            'protein' => self::number(self::text($cells->item(5))),
            'link'    => $link->getAttribute('href') ?: null,
        ];
    }

    /** Label => value text of all two-column rows outside the diary table. */
    private static function summaryRows(DOMXPath $xp): array
    {
        $out = [];
        $rows = $xp->query("//table[not(contains(concat(' ', normalize-space(@class), ' '), ' myday-table-std '))]//tr");
        foreach ($rows as $row) {
            $cells = $xp->query('./td', $row);
            if ($cells->length < 2) {
                continue;
            }
            $label = strtolower(trim(preg_replace('/\s+/u', ' ', self::text($cells->item(0)))));
            if ($label !== '' && !isset($out[$label])) {
                $out[$label] = self::text($cells->item(1));
            }
        }
        return $out;
    }

    /** Carbs cells may carry bread units in a second element: use the first span/b only. */
    private static function cellValue(DOMXPath $xp, DOMNode $cell): string
    {
        $first = $xp->query('.//span|.//b', $cell)->item(0);
        return $first !== null ? self::text($first) : self::text($cell);
    }

    private static function text(?DOMNode $node): string
    {
        return $node === null ? '' : trim(str_replace("\xc2\xa0", ' ', $node->textContent));
    }
}
