<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** In-memory data source for the MCP tools. */
final class FakeHealthData implements HHHealthDataSource
{
    /** @var array ident => [day => value] */
    public $daily = [];
    /** @var array entries rows */
    public $entries = [];
    /** @var array day rows */
    public $days = [];

    public function daily(array $idents, string $from, string $to): array
    {
        $out = [];
        foreach ($idents as $i) {
            foreach ($this->daily[$i] ?? [] as $d => $v) {
                if ($d >= $from && $d <= $to) {
                    $out[$i][$d] = $v;
                }
            }
        }
        return $out;
    }

    public function foodEntries(string $from, string $to, string $search = '', string $meal = '', int $limit = 5000): array
    {
        return array_values(array_filter($this->entries, static function ($e) use ($from, $to, $search, $meal) {
            return $e['day'] >= $from && $e['day'] <= $to
                && ($search === '' || mb_stripos($e['product'], $search) !== false)
                && ($meal === '' || $e['meal'] === $meal);
        }));
    }

    public function foodDays(string $from, string $to): array
    {
        return array_values(array_filter($this->days, static function ($r) use ($from, $to) {
            return $r['day'] >= $from && $r['day'] <= $to;
        }));
    }

    public function profile(): array
    {
        return ['kcal_target' => 1800, 'protein_target_g' => 130, 'fat_target_g' => 60];
    }
}

final class McpTest extends TestCase
{
    /** @var FakeHealthData */
    private $data;
    /** @var HHMcpServer */
    private $server;

    protected function setUp(): void
    {
        $now = (new DateTimeImmutable('2026-10-19 12:00', new DateTimeZone('Europe/Vienna')))->getTimestamp();
        $clock = new HHDayClock('Europe/Vienna', function () use ($now) {
            return $now;
        });
        $this->data = new FakeHealthData();
        $this->server = new HHMcpServer(new HHMcpTools($this->data, $clock, 366), '1.0', 'test instructions');
    }

    private static function entry(string $day, string $time, string $meal, string $product, float $kcal, float $protein, float $amount = 100, string $unit = 'g'): array
    {
        return ['day' => $day, 'time' => $time, 'meal' => $meal, 'product' => $product, 'amount' => $amount, 'unit' => $unit,
            'kcal' => $kcal, 'protein' => $protein, 'fat' => 1.0, 'carbs' => 2.0];
    }

    /** Modern request helper: correct headers and _meta. */
    private function modern(string $method, array $params = [], array $headerOverride = [], $id = 1): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1'],
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ];
        $headers = ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];
        if ($method === 'tools/call') {
            $headers['Mcp-Name'] = $params['name'];
        }
        $headers = array_merge($headers, $headerOverride);
        return $this->server->handle('POST', array_filter($headers, 'is_string'), json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]));
    }

    private static function body(array $res): array
    {
        return json_decode((string) $res['body'], true);
    }

    private function callTool(string $name, array $args): array
    {
        $res = $this->modern('tools/call', ['name' => $name, 'arguments' => $args]);
        $this->assertSame(200, $res['status']);
        return self::body($res)['result'];
    }

    // ---------------------------------------------------------------- protocol: modern

    public function testModernDiscoverAndToolsList(): void
    {
        $r = self::body($this->modern('server/discover'));
        $this->assertSame('complete', $r['result']['resultType']);
        $this->assertContains('2026-07-28', $r['result']['supportedVersions']);
        $this->assertContains('2025-11-25', $r['result']['supportedVersions']);
        $this->assertArrayHasKey('tools', $r['result']['capabilities']);
        $this->assertSame('healthhub', $r['result']['_meta']['io.modelcontextprotocol/serverInfo']['name']);

        $tools = self::body($this->modern('tools/list'))['result']['tools'];
        $this->assertSame(
            ['get_daily_overview', 'get_nutrition', 'get_food_entries', 'get_food_stats', 'get_meal_pattern', 'get_body', 'get_activity', 'get_weekly_summary', 'get_profile'],
            array_column($tools, 'name')
        );
        foreach ($tools as $t) {
            $this->assertTrue($t['annotations']['readOnlyHint'], $t['name'] . ' is read-only');
            $this->assertSame('object', $t['inputSchema']['type']);
        }
    }

    public function testModernHeaderValidation(): void
    {
        $r = $this->modern('tools/list', [], ['Mcp-Method' => 'tools/call']);
        $this->assertSame(400, $r['status']);
        $this->assertSame(-32020, self::body($r)['error']['code']);

        $r = $this->modern('tools/list', [], ['MCP-Protocol-Version' => null]);
        $this->assertSame(400, $r['status'], 'missing version header');

        $r = $this->modern('tools/call', ['name' => 'get_profile', 'arguments' => []], ['Mcp-Name' => 'get_body']);
        $this->assertSame(-32020, self::body($r)['error']['code']);

        // base64 sentinel in Mcp-Name is decoded
        $r = $this->modern('tools/call', ['name' => 'get_profile', 'arguments' => []], ['Mcp-Name' => '=?base64?' . base64_encode('get_profile') . '?=']);
        $this->assertSame(200, $r['status']);
    }

    public function testModernUnsupportedVersionAndMissingCapabilities(): void
    {
        $body = ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list', 'params' => ['_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '2099-01-01', 'io.modelcontextprotocol/clientCapabilities' => (object) []]]];
        $r = $this->server->handle('POST', ['MCP-Protocol-Version' => '2099-01-01', 'Mcp-Method' => 'tools/list'], json_encode($body));
        $this->assertSame(400, $r['status']);
        $err = self::body($r)['error'];
        $this->assertSame(-32022, $err['code']);
        $this->assertSame('2099-01-01', $err['data']['requested']);

        $body['params']['_meta'] = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28'];
        $r = $this->server->handle('POST', ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list'], json_encode($body));
        $this->assertSame(400, $r['status']);
        $this->assertSame(-32602, self::body($r)['error']['code']);
    }

    public function testModernUnknownMethodIs404(): void
    {
        $r = $this->modern('resources/list');
        $this->assertSame(404, $r['status']);
        $this->assertSame(-32601, self::body($r)['error']['code']);
    }

    // ---------------------------------------------------------------- protocol: legacy + transport

    public function testLegacyHandshakeAndToolCall(): void
    {
        $init = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'mcp-remote', 'version' => '0.1']]];
        $r = $this->server->handle('POST', ['Accept' => 'application/json, text/event-stream'], json_encode($init));
        $this->assertSame(200, $r['status']);
        $res = self::body($r)['result'];
        $this->assertSame('2025-06-18', $res['protocolVersion']);
        $this->assertSame('healthhub', $res['serverInfo']['name']);
        $this->assertArrayNotHasKey('Mcp-Session-Id', $r['headers'], 'no session is minted');

        $init['params']['protocolVersion'] = '2024-11-05';
        $this->assertSame('2025-11-25', self::body($this->server->handle('POST', [], json_encode($init)))['result']['protocolVersion']);

        $r = $this->server->handle('POST', ['MCP-Protocol-Version' => '2025-06-18'], json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
        $this->assertSame(202, $r['status']);
        $this->assertNull($r['body']);

        $r = $this->server->handle('POST', ['MCP-Protocol-Version' => '2025-06-18'], json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'get_profile', 'arguments' => []]]));
        $res = self::body($r)['result'];
        $this->assertFalse($res['isError']);
        $this->assertSame(1800, $res['structuredContent']['kcal_target']);
        $this->assertArrayNotHasKey('resultType', $res, 'legacy results unchanged');
    }

    public function testTransportRules(): void
    {
        $this->assertSame(405, $this->server->handle('GET', [], '')['status']);
        $this->assertSame(405, $this->server->handle('DELETE', [], '')['status']);
        $this->assertSame(400, $this->server->handle('POST', [], '{not json')['status']);
        $this->assertSame(-32600, self::body($this->server->handle('POST', [], '[{"jsonrpc":"2.0","id":1,"method":"ping"}]'))['error']['code'], 'no batches');
        $r = $this->server->handle('POST', ['Origin' => 'http://evil.example', 'Host' => '192.168.1.10:3777'], '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        $this->assertSame(403, $r['status']);
        $r = $this->server->handle('POST', ['Origin' => 'http://192.168.1.10:3777', 'Host' => '192.168.1.10:3777'], '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        $this->assertSame(200, $r['status']);
    }

    public function testToolErrors(): void
    {
        $r = self::body($this->modern('tools/call', ['name' => 'drop_database', 'arguments' => []]));
        $this->assertSame(-32602, $r['error']['code'], 'unknown tool = protocol error');

        $res = $this->callTool('get_daily_overview', ['from' => '19.10.2026', 'to' => '2026-10-19']);
        $this->assertTrue($res['isError']);
        $this->assertStringContainsString('YYYY-MM-DD', $res['content'][0]['text']);

        $res = $this->callTool('get_food_entries', ['from' => '2026-08-01', 'to' => '2026-10-01']);
        $this->assertTrue($res['isError']);
        $this->assertStringContainsString('get_food_stats', $res['content'][0]['text']);

        $res = $this->callTool('get_daily_overview', ['from' => '2025-01-01', 'to' => '2026-10-19']);
        $this->assertTrue($res['isError'], 'more than 366 days');

        $res = $this->callTool('get_daily_overview', ['from' => '2026-11-01', 'to' => '2026-11-02']);
        $this->assertTrue($res['isError'], 'future');
    }

    // ---------------------------------------------------------------- tools

    public function testDailyOverviewOneRowPerDayWithNulls(): void
    {
        $this->data->daily = ['KcalIn' => ['2026-10-17' => 1750.0], 'Weight' => ['2026-10-18' => 70.9], 'Steps' => ['2026-10-17' => 8333]];
        $res = $this->callTool('get_daily_overview', ['from' => '2026-10-17', 'to' => '2026-10-25']); // to clipped at today
        $days = $res['structuredContent']['days'];
        $this->assertCount(3, $days);
        $this->assertSame(['date' => '2026-10-17', 'weekday' => 'Sat'], array_slice($days[0], 0, 2));
        $this->assertSame(1750, $days[0]['kcal_in']);
        $this->assertNull($days[0]['weight_kg']);
        $this->assertSame(70.9, $days[1]['weight_kg']);
        $this->assertNull($days[2]['kcal_in']);
        // text content: one line per day
        $text = $res['content'][0]['text'];
        $this->assertSame(3, substr_count($text, '{"date":'));
        $this->assertSame($res['structuredContent'], json_decode($text, true));
    }

    public function testNutritionAveragesOverTrackedDays(): void
    {
        $this->data->days = [
            ['day' => '2026-10-17', 'entries' => 5, 'kcal' => 1700, 'protein' => 120, 'fat' => 60, 'carbs' => 150, 'sugar' => 30, 'fiber' => 20, 'alcohol' => 0, 'salt' => 5],
            ['day' => '2026-10-18', 'entries' => 6, 'kcal' => 1900, 'protein' => 140, 'fat' => 70, 'carbs' => 170, 'sugar' => 40, 'fiber' => 25, 'alcohol' => 10, 'salt' => 6],
        ];
        $s = $this->callTool('get_nutrition', ['from' => '2026-10-16', 'to' => '2026-10-18'])['structuredContent'];
        $this->assertSame(2, $s['tracked_days']);
        $this->assertSame(1800.0, $s['average_per_tracked_day']['kcal']);
        $this->assertSame(130.0, $s['average_per_tracked_day']['protein_g']);
        $this->assertFalse($s['days'][0]['tracked']);
        $this->assertNull($s['days'][0]['kcal']);
        $this->assertSame(1800, $s['targets']['kcal']);
    }

    public function testFoodEntriesAndStats(): void
    {
        $this->data->entries = [
            self::entry('2026-10-17', '07:15', 'Breakfast', 'Skyr natur', 150, 25, 250),
            self::entry('2026-10-17', '18:30', 'Dinner', 'Pizza', 900, 40, 400),
            self::entry('2026-10-18', '07:10', 'Breakfast', 'Skyr Natur', 150, 25, 250),
            self::entry('2026-10-18', '12:00', 'Lunch', 'Apfel', 80, 0.5, 1, 'Stück'),
        ];
        $e = $this->callTool('get_food_entries', ['from' => '2026-10-17', 'to' => '2026-10-18', 'meal' => 'Breakfast'])['structuredContent'];
        $this->assertSame(2, $e['count']);
        $this->assertSame('07:15', $e['entries'][0]['time']);

        $s = $this->callTool('get_food_stats', ['from' => '2026-10-01', 'to' => '2026-10-19'])['structuredContent'];
        $this->assertSame(3, $s['distinct_products'], 'case-insensitive grouping');
        $this->assertSame('Pizza', $s['products'][0]['product'], 'sorted by kcal');
        $skyr = $s['products'][1];
        $this->assertSame(2, $skyr['count']);
        $this->assertSame(500, $skyr['total_amount']);
        $this->assertSame(23.4, $skyr['kcal_share_pct']); // 300 / 1280

        $byProtein = $this->callTool('get_food_stats', ['from' => '2026-10-01', 'to' => '2026-10-19', 'sort_by' => 'protein', 'limit' => 1])['structuredContent'];
        $this->assertCount(1, $byProtein['products']);
        $this->assertSame('Skyr natur', $byProtein['products'][0]['product']);

        $this->assertTrue($this->callTool('get_food_stats', ['from' => '2026-10-01', 'to' => '2026-10-19', 'sort_by' => 'fat'])['isError']);
    }

    public function testMealPattern(): void
    {
        $this->data->entries = [
            self::entry('2026-10-16', '07:00', 'Breakfast', 'A', 400, 30), // Fri
            self::entry('2026-10-16', '19:00', 'Dinner', 'B', 800, 40),
            self::entry('2026-10-17', '08:00', 'Breakfast', 'A', 400, 30), // Sat
            self::entry('2026-10-17', '23:00', 'Snack 3', 'C', 600, 5),
        ];
        $s = $this->callTool('get_meal_pattern', ['from' => '2026-10-16', 'to' => '2026-10-17'])['structuredContent'];
        $this->assertSame(2, $s['tracked_days']);
        $byMeal = array_column($s['by_meal'], null, 'name');
        $this->assertSame(400, $byMeal['Breakfast']['avg_kcal_per_tracked_day']);
        $this->assertSame(2, $byMeal['Breakfast']['days_present']);
        $windows = array_column($s['by_time_window'], 'name');
        $this->assertSame(['morning 05-11', 'evening 18-22', 'late 22-24'], $windows, 'fixed window order');
        $this->assertSame(1200, $s['weekday_vs_weekend']['weekday_mon_fri']['avg_kcal']);
        $this->assertSame(1000, $s['weekday_vs_weekend']['weekend_sat_sun']['avg_kcal']);
    }

    public function testBodyOmitsDaysWithoutMeasurement(): void
    {
        $this->data->daily = ['Weight' => ['2026-10-11' => 70.9], 'BodyFat' => ['2026-10-11' => 18.7], 'Waist' => ['2026-10-15' => 86.5]];
        $s = $this->callTool('get_body', ['from' => '2026-10-10', 'to' => '2026-10-19'])['structuredContent'];
        $this->assertSame(['2026-10-11', '2026-10-15'], array_column($s['days'], 'date'));
        $this->assertNull($s['days'][1]['weight_kg']);
    }

    public function testWeeklySummaryAndTdee(): void
    {
        // W40 (Sep 28 - Oct 4): weight 72.0, W41: 71.5, W42: 71.0 -> -0.5 kg/week
        // intake 1800 kcal on 6 days per week -> TDEE = 1800 + 0.5 * 7700 / 7 = 2350
        $kcal = [];
        $weight = [];
        foreach ([['2026-09-28', 72.0], ['2026-10-05', 71.5], ['2026-10-12', 71.0]] as [$monday, $w]) {
            for ($i = 0; $i < 7; $i++) {
                $d = (new DateTimeImmutable($monday))->modify("+$i day")->format('Y-m-d');
                if ($i < 6) {
                    $kcal[$d] = 1800.0;
                }
                if ($i % 3 === 0) {
                    $weight[$d] = $w;
                }
            }
        }
        $kcal['2026-10-14'] = null; // W42 only 5 tracked days, still enough
        unset($kcal['2026-10-14']);
        $this->data->daily = ['KcalIn' => $kcal, 'Weight' => $weight];

        $s = $this->callTool('get_weekly_summary', ['from' => '2026-10-05', 'to' => '2026-10-18'])['structuredContent'];
        $weeks = array_column($s['weeks'], null, 'week');
        $this->assertSame(['2026-W41', '2026-W42'], array_keys($weeks));
        $this->assertSame(-0.5, $weeks['2026-W41']['weight_change_kg'], 'vs W40 before the range');
        $this->assertSame(2350, $weeks['2026-W41']['tdee_estimate_kcal']);
        $this->assertSame(5, $weeks['2026-W42']['nutrition_days']);
        $this->assertSame(2350, $weeks['2026-W42']['tdee_estimate_kcal']);

        $o = $s['overall'];
        $this->assertSame(2, $o['weeks_used']);
        $this->assertSame(2350, $o['tdee_estimate_kcal']);
        $this->assertSame(-0.5, $o['rate_kg_per_week']);
        $this->assertGreaterThan(0, $o['uncertainty_kcal']);

        // too few tracked days -> no TDEE with explanation
        $this->data->daily['KcalIn'] = ['2026-10-05' => 1800.0];
        $w = $this->callTool('get_weekly_summary', ['from' => '2026-10-05', 'to' => '2026-10-11'])['structuredContent']['weeks'][0];
        $this->assertNull($w['tdee_estimate_kcal']);
        $this->assertStringContainsString('tracked nutrition day', $w['tdee_note']);
    }

    public function testCompactJsonOneLinePerRow(): void
    {
        $text = HHMcpServer::compactJson(['a' => 1, 'rows' => [['x' => 1], ['x' => 2]], 'empty' => []]);
        $this->assertSame("{\n\"a\": 1,\n\"rows\": [\n  {\"x\":1},\n  {\"x\":2}\n],\n\"empty\": []\n}", $text);
        $this->assertSame(['a' => 1, 'rows' => [['x' => 1], ['x' => 2]], 'empty' => []], json_decode($text, true));
    }
}
