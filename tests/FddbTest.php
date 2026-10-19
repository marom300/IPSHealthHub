<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FddbTest extends TestCase
{
    /** @var string */
    private $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hh_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->tmp);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    private static function clock(): HHDayClock
    {
        $now = (new DateTimeImmutable('2026-10-19 10:00', new DateTimeZone('Europe/Vienna')))->getTimestamp();
        return new HHDayClock('Europe/Vienna', function () use ($now) {
            return $now;
        });
    }

    // ---------------------------------------------------------------- parser

    public function testParsesDiaryPage(): void
    {
        $r = HHFddbParser::parse(self::fixture('fddb_day.html'));
        $this->assertSame(HHFddbParser::STATUS_OK, $r['status']);
        $this->assertCount(4, $r['entries']);

        $first = $r['entries'][0];
        $this->assertSame('18:30', $first['time']);
        $this->assertSame('Dinner', $first['meal']);
        $this->assertSame('Hähnchenbrustfilet', $first['product']);
        $this->assertSame(200.0, $first['amount']);
        $this->assertSame('g', $first['unit']);
        $this->assertSame(220.0, $first['kcal']);
        $this->assertSame(46.0, $first['protein']);
        $this->assertStringContainsString('/food/hofer_haehnchenbrustfilet/', $first['link']);

        $rice = $r['entries'][1];
        $this->assertSame(1.5, $rice['amount'], 'decimal comma in amount');
        $this->assertSame('Portion', $rice['unit']);
        $this->assertSame('Reis, gekocht', $rice['product']);
        $this->assertSame(60.0, $rice['carbs'], 'bread units ignored');

        $oats = $r['entries'][2];
        $this->assertSame('07:15', $oats['time'], 'time zero padded');
        $this->assertSame('Breakfast', $oats['meal']);

        $t = $r['totals'];
        $this->assertSame(1115.0, $t['kcal'], 'thousands separator');
        $this->assertSame(31.6, $t['fat']);
        $this->assertSame(122.5, $t['carbs']);
        $this->assertSame(81.2, $t['protein']);
        $this->assertSame(14.3, $t['sugar']);
        $this->assertSame(9.8, $t['fiber']);
        $this->assertSame(0.0, $t['alcohol']);
        $this->assertSame(0.4, $t['water_l']);
        $this->assertSame(120.0, $t['cholesterol_mg']);
        $this->assertSame(2.15, $t['salt']);
    }

    public function testEmptyLoggedOutAndUnknownPages(): void
    {
        $this->assertSame(HHFddbParser::STATUS_EMPTY, HHFddbParser::parse(self::fixture('fddb_empty.html'))['status']);
        $this->assertSame(HHFddbParser::STATUS_LOGGED_OUT, HHFddbParser::parse(self::fixture('fddb_loggedout.html'))['status']);
        $unknown = HHFddbParser::parse('<html><body><h1>503 Service Unavailable</h1></body></html>');
        $this->assertSame(HHFddbParser::STATUS_UNKNOWN, $unknown['status']);
        $this->assertNotNull($unknown['error']);
        $this->assertSame(HHFddbParser::STATUS_UNKNOWN, HHFddbParser::parse('')['status']);
    }

    public function testSplitAmountAndNumber(): void
    {
        $this->assertSame([125.0, 'g', 'Tourinos, Meersalz & Pfeffer'], HHFddbParser::splitAmount('125 g Tourinos, Meersalz & Pfeffer'));
        $this->assertSame([1.0, 'Stück', 'Apfel'], HHFddbParser::splitAmount('1 Stück Apfel'));
        $this->assertSame([null, null, 'Selbstgemacht'], HHFddbParser::splitAmount('Selbstgemacht'));
        $this->assertSame(2691.0, HHFddbParser::number('2691 kcal'));
        $this->assertSame(1234.5, HHFddbParser::number('1,234.5 g'));
        $this->assertSame(0.1, HHFddbParser::number('0.1 litres'));
        $this->assertNull(HHFddbParser::number('-'));
    }

    // ---------------------------------------------------------------- client

    /** Fake fddb.info: login sets a cookie, the diary needs that cookie. */
    private function fakeTransport(array &$log, string $diaryHtml, bool $loginOk = true, int $expireAfter = PHP_INT_MAX): callable
    {
        $calls = 0;
        return function (string $method, string $url, array $headers, ?string $body) use (&$log, $diaryHtml, $loginOk, $expireAfter, &$calls) {
            $log[] = $method . ' ' . $url;
            if ($method === 'POST') {
                parse_str((string) $body, $form);
                $ok = $loginOk && ($form['loginemailorusername'] ?? '') === 'roman' && ($form['loginpassword'] ?? '') === 'secret';
                return ['status' => 302, 'headers' => $ok ? ['HTTP/1.1 302 Found', 'Set-Cookie: fddb=SESSION' . count($log) . '; path=/'] : ['HTTP/1.1 200 OK'], 'body' => ''];
            }
            $calls++;
            $cookieOk = (bool) preg_grep('/^Cookie: fddb=SESSION\d+$/', $headers);
            return ['status' => 200, 'headers' => [], 'body' => $cookieOk && $calls <= $expireAfter ? $diaryHtml : self::fixture('fddb_loggedout.html')];
        };
    }

    public function testClientLogsInAndFetchesDayWindow(): void
    {
        $log = [];
        $client = new HHFddbClient(self::clock(), 'roman', 'secret', '', $this->fakeTransport($log, self::fixture('fddb_day.html')));
        $r = $client->fetchDay('2026-10-18');
        $this->assertSame('ok', $r['status']);
        $this->assertStringStartsWith('POST https://fddb.info/db/i18n/account/', $log[0]);

        // window 02:00 local to 01:59:59 next day (CEST = UTC+2)
        $from = gmmktime(0, 0, 0, 10, 18, 2026);
        $this->assertSame('GET https://fddb.info/db/i18n/myday20/?lang=en&p=' . $from . '&q=' . ($from + 86400 - 1), $log[1]);
        $this->assertNotSame('', $client->cookie());
    }

    public function testDayWindowOnDstDay(): void
    {
        $client = new HHFddbClient(self::clock(), 'u', 'p');
        [$from, $to] = $client->dayWindow('2026-10-25'); // 25 h day
        $this->assertSame(25 * 3600 - 1, $to - $from);
    }

    public function testExpiredSessionReloginOnce(): void
    {
        $log = [];
        $client = new HHFddbClient(self::clock(), 'roman', 'secret', 'OLDSESSION', $this->fakeTransport($log, self::fixture('fddb_day.html')));
        $r = $client->fetchDay('2026-10-18');
        $this->assertSame('ok', $r['status']);
        $this->assertSame(['GET', 'POST', 'GET'], array_map(function ($l) {
            return strtok($l, ' ');
        }, $log));
    }

    public function testWrongPasswordThrows(): void
    {
        $log = [];
        $client = new HHFddbClient(self::clock(), 'roman', 'wrong', '', $this->fakeTransport($log, self::fixture('fddb_day.html')));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('login failed');
        $client->fetchDay('2026-10-18');
    }

    public function testMissingCredentialsThrow(): void
    {
        $this->expectException(RuntimeException::class);
        (new HHFddbClient(self::clock(), '', ''))->login();
    }

    // ---------------------------------------------------------------- store

    public static function storeProvider(): array
    {
        return ['sqlite' => [false], 'json' => [true]];
    }

    /** @dataProvider storeProvider */
    public function testStoreReplaceDeleteAndQuery(bool $json): void
    {
        $store = HHFoodStore::open($this->tmp, false, $json);
        $this->assertSame($json ? 'json' : 'sqlite', $store->backend());
        $page = HHFddbParser::parse(self::fixture('fddb_day.html'));

        $store->replaceDay('2026-10-18', $page['entries'], $page['totals'], '2026-10-19T03:30:00+02:00');
        $store->replaceDay('2026-10-18', $page['entries'], $page['totals'], '2026-10-19T03:31:00+02:00'); // idempotent
        $store->replaceDay('2026-10-17', array_slice($page['entries'], 2), ['kcal' => 494.0], '2026-10-19T03:30:00+02:00');

        $this->assertCount(4, $store->entries('2026-10-18', '2026-10-18'));
        $this->assertCount(6, $store->entries('2026-10-01', '2026-10-31'));
        $this->assertCount(2, $store->entries('2026-10-01', '2026-10-31', 'skyr'), 'case-insensitive search');
        $this->assertCount(2, $store->entries('2026-10-18', '2026-10-18', '', 'Dinner'));

        $rows = $store->entries('2026-10-18', '2026-10-18');
        $this->assertSame('07:15', $rows[0]['time'], 'ordered by time');
        $this->assertNull($rows[0]['sugar'], 'no per-entry sugar on the diary page');

        $t = $store->dayTotals('2026-10-18');
        $this->assertSame(4, (int) $t['entries']);
        $this->assertEquals(1115.0, $t['kcal']);
        $this->assertEquals(14.3, $t['sugar']);
        $this->assertSame(['2026-10-17', '2026-10-18'], array_column($store->days('2026-10-01', '2026-10-31'), 'day'));

        $store->deleteDay('2026-10-18');
        $this->assertNull($store->dayTotals('2026-10-18'));
        $this->assertCount(0, $store->entries('2026-10-18', '2026-10-18'));
        $this->assertCount(2, $store->entries('2026-10-17', '2026-10-17'));
    }

    public function testSqliteReadOnlyIsEnforcedBySqlite(): void
    {
        $page = HHFddbParser::parse(self::fixture('fddb_day.html'));
        HHFoodStore::open($this->tmp)->replaceDay('2026-10-18', $page['entries'], $page['totals'], 'x');

        $ro = HHFoodStore::open($this->tmp, true);
        $this->assertCount(4, $ro->entries('2026-10-18', '2026-10-18'));
        $this->expectException(PDOException::class);
        $ro->pdo()->exec("DELETE FROM food_entries"); // even raw SQL cannot write
    }

    // ---------------------------------------------------------------- import

    public function testImportDayWritesStoreAndReturnsValues(): void
    {
        $log = [];
        $client = new HHFddbClient(self::clock(), 'roman', 'secret', '', $this->fakeTransport($log, self::fixture('fddb_day.html')));
        $store = HHFoodStore::open($this->tmp);
        $r = HHFddbImport::importDay($client, $store, '2026-10-18', 'now');
        $this->assertSame('ok', $r['status']);
        $this->assertSame(4, $r['entries']);
        $this->assertSame(['KcalIn' => 1115.0, 'Protein' => 81.2, 'Fat' => 31.6, 'Carbs' => 122.5, 'Sugar' => 14.3, 'Fiber' => 9.8], $r['values']);
    }

    public function testEmptyDayClearsStoreAndYieldsNull(): void
    {
        $store = HHFoodStore::open($this->tmp);
        $page = HHFddbParser::parse(self::fixture('fddb_day.html'));
        $store->replaceDay('2026-10-18', $page['entries'], $page['totals'], 'x');

        $log = [];
        $client = new HHFddbClient(self::clock(), 'roman', 'secret', '', $this->fakeTransport($log, self::fixture('fddb_empty.html')));
        $r = HHFddbImport::importDay($client, $store, '2026-10-18', 'now');
        $this->assertSame('empty', $r['status']);
        $this->assertSame(array_fill_keys(array_keys(HHFddbImport::IDENTS), null), $r['values'], 'no 0 kcal day');
        $this->assertNull($store->dayTotals('2026-10-18'));
    }

    public function testUnknownPageChangesNothing(): void
    {
        $store = HHFoodStore::open($this->tmp);
        $page = HHFddbParser::parse(self::fixture('fddb_day.html'));
        $store->replaceDay('2026-10-18', $page['entries'], $page['totals'], 'x');

        $log = [];
        $client = new HHFddbClient(self::clock(), 'roman', 'secret', '', $this->fakeTransport($log, '<html><body>Wartungsarbeiten</body></html>'));
        try {
            HHFddbImport::importDay($client, $store, '2026-10-18', 'now');
            $this->fail('exception expected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not recognised', $e->getMessage());
        }
        $this->assertCount(4, $store->entries('2026-10-18', '2026-10-18'), 'stored day untouched');
    }
}
