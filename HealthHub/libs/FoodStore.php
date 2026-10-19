<?php

declare(strict_types=1);

/**
 * Storage of the individual FDDB diary entries and the FDDB day totals.
 *
 *  - food_entries: one row per diary entry (time, meal, product, amount, unit, kJ, kcal, fat,
 *                  carbs, protein, sugar/fiber = NULL: the diary page has no per-entry values)
 *  - food_days:    FDDB's own day totals incl. sugar, fibre, alcohol, water, cholesterol, salt
 *
 * A day is always replaced as a whole (no stable entry id in FDDB) inside one transaction.
 * Only the import writes; readers (MCP, phase 4) open the store read-only.
 *
 * Backends: PDO SQLite (preferred) or, if the embedded PHP lacks pdo_sqlite, JSON files per month.
 * No IPS dependency - unit tested.
 */
abstract class HHFoodStore
{
    public const ENTRY_COLUMNS = ['day', 'time', 'meal', 'product', 'brand', 'amount', 'unit', 'kj', 'kcal', 'protein', 'fat', 'carbs', 'sugar', 'fiber', 'link', 'imported_at'];
    public const DAY_COLUMNS = ['day', 'entries', 'kcal', 'kj', 'fat', 'carbs', 'sugar', 'protein', 'fiber', 'alcohol', 'water_l', 'cholesterol_mg', 'salt', 'imported_at'];

    /**
     * Opens the store in $dir: SQLite file healthhub.sqlite if pdo_sqlite is available,
     * else JSON files in $dir/food. $forceJson for tests.
     */
    public static function open(string $dir, bool $readOnly = false, bool $forceJson = false): self
    {
        if (!$forceJson && self::sqliteAvailable()) {
            return new HHSqliteFoodStore($dir . DIRECTORY_SEPARATOR . 'healthhub.sqlite', $readOnly);
        }
        return new HHJsonFoodStore($dir . DIRECTORY_SEPARATOR . 'food', $readOnly);
    }

    public static function sqliteAvailable(): bool
    {
        return extension_loaded('pdo_sqlite') && in_array('sqlite', PDO::getAvailableDrivers(), true);
    }

    abstract public function backend(): string;

    /** Replaces a day: entries + totals. */
    abstract public function replaceDay(string $day, array $entries, array $totals, string $importedAt): void;

    /** Removes a day completely (day without entries). */
    abstract public function deleteDay(string $day): void;

    /** Day totals ['day' => …, 'kcal' => …] or null. */
    abstract public function dayTotals(string $day): ?array;

    /** Day totals of all imported days in [from, to], ascending. */
    abstract public function days(string $from, string $to): array;

    /**
     * Entries in [from, to], ordered by day and time.
     * $search: case-insensitive substring of the product name; $meal: exact meal name.
     */
    abstract public function entries(string $from, string $to, string $search = '', string $meal = '', int $limit = 5000): array;

    /** Normalises an entry to ENTRY_COLUMNS (missing = null). */
    protected static function entryRow(string $day, array $e, string $importedAt): array
    {
        $row = array_fill_keys(self::ENTRY_COLUMNS, null);
        foreach ($e as $k => $v) {
            if (array_key_exists($k, $row)) {
                $row[$k] = $v;
            }
        }
        $row['day'] = $day;
        $row['imported_at'] = $importedAt;
        return $row;
    }

    protected static function dayRow(string $day, int $count, array $totals, string $importedAt): array
    {
        $row = array_fill_keys(self::DAY_COLUMNS, null);
        foreach ($totals as $k => $v) {
            if (array_key_exists($k, $row)) {
                $row[$k] = $v;
            }
        }
        $row['day'] = $day;
        $row['entries'] = $count;
        $row['imported_at'] = $importedAt;
        return $row;
    }
}

class HHSqliteFoodStore extends HHFoodStore
{
    /** @var PDO */
    private $pdo;

    public function __construct(string $file, bool $readOnly = false)
    {
        if ($readOnly) {
            if (!is_file($file)) {
                throw new RuntimeException('food database does not exist yet: ' . $file);
            }
            // read-only is enforced by SQLite itself, not by string checks
            $this->pdo = new PDO('sqlite:' . $file, null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        } else {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            $this->pdo = new PDO('sqlite:' . $file);
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        if (!$readOnly) {
            $this->migrate();
        }
    }

    public function backend(): string
    {
        return 'sqlite';
    }

    /** The underlying connection (read-only SQL tool in phase 4). */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS food_entries (
            id INTEGER PRIMARY KEY,
            day TEXT NOT NULL, time TEXT, meal TEXT, product TEXT, brand TEXT,
            amount REAL, unit TEXT, kj REAL, kcal REAL, protein REAL, fat REAL, carbs REAL,
            sugar REAL, fiber REAL, link TEXT, imported_at TEXT)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_food_entries_day ON food_entries(day)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_food_entries_product ON food_entries(product)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS food_days (
            day TEXT PRIMARY KEY, entries INTEGER, kcal REAL, kj REAL, fat REAL, carbs REAL,
            sugar REAL, protein REAL, fiber REAL, alcohol REAL, water_l REAL, cholesterol_mg REAL,
            salt REAL, imported_at TEXT)');
    }

    public function replaceDay(string $day, array $entries, array $totals, string $importedAt): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM food_entries WHERE day = ?')->execute([$day]);
            $cols = self::ENTRY_COLUMNS;
            $ins = $this->pdo->prepare('INSERT INTO food_entries (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
            foreach ($entries as $e) {
                $ins->execute(array_values(self::entryRow($day, $e, $importedAt)));
            }
            $dcols = self::DAY_COLUMNS;
            $this->pdo->prepare('INSERT OR REPLACE INTO food_days (' . implode(',', $dcols) . ') VALUES (' . implode(',', array_fill(0, count($dcols), '?')) . ')')
                ->execute(array_values(self::dayRow($day, count($entries), $totals, $importedAt)));
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function deleteDay(string $day): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->prepare('DELETE FROM food_entries WHERE day = ?')->execute([$day]);
        $this->pdo->prepare('DELETE FROM food_days WHERE day = ?')->execute([$day]);
        $this->pdo->commit();
    }

    public function dayTotals(string $day): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM food_days WHERE day = ?');
        $st->execute([$day]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public function days(string $from, string $to): array
    {
        $st = $this->pdo->prepare('SELECT * FROM food_days WHERE day BETWEEN ? AND ? ORDER BY day');
        $st->execute([$from, $to]);
        return $st->fetchAll();
    }

    public function entries(string $from, string $to, string $search = '', string $meal = '', int $limit = 5000): array
    {
        $sql = 'SELECT ' . implode(',', self::ENTRY_COLUMNS) . ' FROM food_entries WHERE day BETWEEN ? AND ?';
        $args = [$from, $to];
        if ($search !== '') {
            $sql .= ' AND product LIKE ?';
            $args[] = '%' . $search . '%';
        }
        if ($meal !== '') {
            $sql .= ' AND meal = ?';
            $args[] = $meal;
        }
        $sql .= ' ORDER BY day, time, id LIMIT ' . max(1, $limit);
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }
}

/** Fallback without pdo_sqlite: one JSON file per month ({day: {totals, entries}}). */
class HHJsonFoodStore extends HHFoodStore
{
    /** @var string */
    private $dir;
    /** @var bool */
    private $readOnly;

    public function __construct(string $dir, bool $readOnly = false)
    {
        $this->dir = $dir;
        $this->readOnly = $readOnly;
        if (!$readOnly && !is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    public function backend(): string
    {
        return 'json';
    }

    public function replaceDay(string $day, array $entries, array $totals, string $importedAt): void
    {
        $this->assertWritable();
        $month = $this->load(substr($day, 0, 7));
        $month[$day] = [
            'totals'  => self::dayRow($day, count($entries), $totals, $importedAt),
            'entries' => array_map(static function ($e) use ($day, $importedAt) {
                return self::entryRow($day, $e, $importedAt);
            }, array_values($entries)),
        ];
        ksort($month);
        $this->save(substr($day, 0, 7), $month);
    }

    public function deleteDay(string $day): void
    {
        $this->assertWritable();
        $month = $this->load(substr($day, 0, 7));
        if (isset($month[$day])) {
            unset($month[$day]);
            $this->save(substr($day, 0, 7), $month);
        }
    }

    public function dayTotals(string $day): ?array
    {
        return $this->load(substr($day, 0, 7))[$day]['totals'] ?? null;
    }

    public function days(string $from, string $to): array
    {
        $out = [];
        foreach ($this->monthsBetween($from, $to) as $m) {
            foreach ($this->load($m) as $day => $d) {
                if ($day >= $from && $day <= $to) {
                    $out[] = $d['totals'];
                }
            }
        }
        return $out;
    }

    public function entries(string $from, string $to, string $search = '', string $meal = '', int $limit = 5000): array
    {
        $out = [];
        foreach ($this->monthsBetween($from, $to) as $m) {
            foreach ($this->load($m) as $day => $d) {
                if ($day < $from || $day > $to) {
                    continue;
                }
                $rows = $d['entries'];
                usort($rows, static function ($a, $b) {
                    return strcmp((string) $a['time'], (string) $b['time']);
                });
                foreach ($rows as $e) {
                    if ($search !== '' && mb_stripos((string) $e['product'], $search) === false) {
                        continue;
                    }
                    if ($meal !== '' && $e['meal'] !== $meal) {
                        continue;
                    }
                    $out[] = $e;
                    if (count($out) >= $limit) {
                        return $out;
                    }
                }
            }
        }
        return $out;
    }

    private function monthsBetween(string $from, string $to): array
    {
        $out = [];
        $d = new DateTimeImmutable(substr($from, 0, 7) . '-01');
        $end = substr($to, 0, 7);
        while ($d->format('Y-m') <= $end) {
            $out[] = $d->format('Y-m');
            $d = $d->modify('+1 month');
        }
        return $out;
    }

    private function load(string $month): array
    {
        $file = $this->dir . DIRECTORY_SEPARATOR . $month . '.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function save(string $month, array $data): void
    {
        $file = $this->dir . DIRECTORY_SEPARATOR . $month . '.json';
        $tmp = $file . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename($tmp, $file); // atomic replace
    }

    private function assertWritable(): void
    {
        if ($this->readOnly) {
            throw new RuntimeException('food store opened read-only');
        }
    }
}
