<?php

declare(strict_types=1);

/**
 * Minimal MCP server over Streamable HTTP (JSON responses only, no SSE).
 *
 * Dual-era (see modelcontextprotocol.io/specification/2026-07-28/basic/versioning):
 *  - modern 2026-07-28: stateless, every request carries
 *      params._meta["io.modelcontextprotocol/protocolVersion"] (+ clientCapabilities),
 *    mirrored in the headers MCP-Protocol-Version, Mcp-Method and (tools/call) Mcp-Name.
 *    Header/body mismatch -> 400 / -32020, unsupported version -> 400 / -32022,
 *    missing required _meta -> 400 / -32602, unknown method -> 404 / -32601.
 *    server/discover is mandatory. Results carry resultType + _meta serverInfo.
 *  - legacy 2025-11-25 / 2025-06-18 / 2025-03-26: "initialize" handshake. No session id is
 *    issued (optional in those revisions), so every POST is still independent.
 *
 * Transport rules: POST only (GET/DELETE -> 405), notifications -> 202 without body,
 * batches are not supported, an Origin header must match the Host (DNS rebinding).
 * Authentication is done by the caller before handle() (bearer token).
 *
 * Tools come from an HHMcpTools instance. No IPS dependency - unit tested.
 */
class HHMcpServer
{
    public const MODERN_VERSIONS = ['2026-07-28'];
    public const LEGACY_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    private const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    private const META_CAPS = 'io.modelcontextprotocol/clientCapabilities';
    private const META_SERVER = 'io.modelcontextprotocol/serverInfo';

    /** @var HHMcpTools */
    private $tools;
    /** @var array */
    private $serverInfo;
    /** @var string */
    private $instructions;

    public function __construct(HHMcpTools $tools, string $version, string $instructions = '')
    {
        $this->tools = $tools;
        $this->serverInfo = ['name' => 'healthhub', 'title' => 'HealthHub (IP-Symcon)', 'version' => $version];
        $this->instructions = $instructions;
    }

    /**
     * @param string $httpMethod GET/POST/...
     * @param array  $headers    request headers, any case
     * @param string $body       raw request body
     * @return array ['status' => int, 'headers' => [name => value], 'body' => string|null]
     */
    public function handle(string $httpMethod, array $headers, string $body): array
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower((string) $k)] = trim((string) $v);
        }

        $origin = $h['origin'] ?? '';
        if ($origin !== '' && !self::originMatchesHost($origin, $h['host'] ?? '')) {
            return self::http(403, self::error(null, -32600, 'Origin not allowed'));
        }
        if (strtoupper($httpMethod) !== 'POST') {
            return ['status' => 405, 'headers' => ['Allow' => 'POST'], 'body' => null];
        }

        $msg = json_decode($body, true);
        if (!is_array($msg)) {
            return self::http(400, self::error(null, -32700, 'Parse error'));
        }
        if (array_keys($msg) === range(0, count($msg) - 1)) {
            return self::http(400, self::error(null, -32600, 'Batch requests are not supported'));
        }
        if (($msg['jsonrpc'] ?? '') !== '2.0' || !is_string($msg['method'] ?? null)) {
            return self::http(400, self::error($msg['id'] ?? null, -32600, 'Invalid Request'));
        }
        $method = $msg['method'];
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

        // notification (no id): accepted, never answered
        if (!array_key_exists('id', $msg)) {
            return ['status' => 202, 'headers' => [], 'body' => null];
        }
        $id = $msg['id'];
        if (!is_int($id) && !is_string($id)) {
            return self::http(400, self::error(null, -32600, 'Invalid Request: id must be string or integer'));
        }

        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $modern = $method !== 'initialize' && array_key_exists(self::META_VERSION, $meta);
        return $modern ? $this->handleModern($id, $method, $params, $meta, $h) : $this->handleLegacy($id, $method, $params);
    }

    // ------------------------------------------------------------------ modern

    private function handleModern($id, string $method, array $params, array $meta, array $h): array
    {
        $version = $meta[self::META_VERSION];
        if (!is_string($version) || !in_array($version, self::MODERN_VERSIONS, true)) {
            return self::http(400, self::error($id, -32022, 'Unsupported protocol version', [
                'supported' => array_merge(self::MODERN_VERSIONS, self::LEGACY_VERSIONS),
                'requested' => $version,
            ]));
        }
        // headers mirror the body
        $mismatch = null;
        if (($h['mcp-protocol-version'] ?? null) !== $version) {
            $mismatch = 'MCP-Protocol-Version header missing or not matching _meta';
        } elseif (($h['mcp-method'] ?? null) !== $method) {
            $mismatch = 'Mcp-Method header missing or not matching the method';
        } elseif ($method === 'tools/call' && self::decodeHeader($h['mcp-name'] ?? null) !== ($params['name'] ?? null)) {
            $mismatch = 'Mcp-Name header missing or not matching params.name';
        }
        if ($mismatch !== null) {
            return self::http(400, self::error($id, -32020, 'Header mismatch: ' . $mismatch));
        }
        if (!is_array($meta[self::META_CAPS] ?? null)) {
            return self::http(400, self::error($id, -32602, 'Invalid params: _meta ' . self::META_CAPS . ' is required'));
        }

        switch ($method) {
            case 'server/discover':
                $result = [
                    'supportedVersions' => array_merge(self::MODERN_VERSIONS, self::LEGACY_VERSIONS),
                    'capabilities'      => ['tools' => (object) []],
                    'instructions'      => $this->instructions,
                ];
                break;
            case 'ping':
                $result = [];
                break;
            case 'tools/list':
                $result = ['tools' => $this->tools->definitions()];
                break;
            case 'tools/call':
                $call = $this->callTool($id, $params);
                if (isset($call['error'])) {
                    return self::http(200, $call['error']);
                }
                $result = $call['result'];
                break;
            default:
                return self::http(404, self::error($id, -32601, 'Method not found: ' . $method));
        }
        $result = ['resultType' => 'complete'] + $result;
        $result['_meta'] = [self::META_SERVER => $this->serverInfo];
        return self::http(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    // ------------------------------------------------------------------ legacy

    private function handleLegacy($id, string $method, array $params): array
    {
        switch ($method) {
            case 'initialize':
                $requested = $params['protocolVersion'] ?? '';
                $version = in_array($requested, self::LEGACY_VERSIONS, true) ? $requested : self::LEGACY_VERSIONS[0];
                $result = [
                    'protocolVersion' => $version,
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => $this->serverInfo,
                    'instructions'    => $this->instructions,
                ];
                break;
            case 'ping':
                $result = (object) [];
                break;
            case 'tools/list':
                $result = ['tools' => $this->tools->definitions()];
                break;
            case 'tools/call':
                $call = $this->callTool($id, $params);
                if (isset($call['error'])) {
                    return self::http(200, $call['error']);
                }
                $result = $call['result'];
                break;
            default:
                return self::http(200, self::error($id, -32601, 'Method not found: ' . $method));
        }
        return self::http(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    // ------------------------------------------------------------------

    /** @return array ['result' => CallToolResult] or ['error' => JSON-RPC error response] */
    private function callTool($id, array $params): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || !$this->tools->has($name)) {
            return ['error' => self::error($id, -32602, 'Unknown tool: ' . (is_string($name) ? $name : '?'))];
        }
        $args = $params['arguments'] ?? [];
        if (!is_array($args)) {
            return ['error' => self::error($id, -32602, 'Invalid params: arguments must be an object')];
        }
        try {
            $data = $this->tools->call($name, $args);
            return ['result' => [
                'content'           => [['type' => 'text', 'text' => self::compactJson($data)]],
                'structuredContent' => $data,
                'isError'           => false,
            ]];
        } catch (InvalidArgumentException $e) {
            // tool execution error: actionable for the model
            return ['result' => ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]];
        } catch (Throwable $e) {
            return ['result' => ['content' => [['type' => 'text', 'text' => 'Internal error: ' . $e->getMessage()]], 'isError' => true]];
        }
    }

    /**
     * JSON with one line per row: top-level keys on their own lines, list items
     * (days, weeks, entries, products) one per line - compact but readable.
     */
    public static function compactJson(array $data): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
        $lines = [];
        foreach ($data as $k => $v) {
            if (is_array($v) && $v !== [] && array_keys($v) === range(0, count($v) - 1)) {
                $items = array_map(static function ($item) use ($flags) {
                    return '  ' . json_encode($item, $flags);
                }, $v);
                $lines[] = json_encode((string) $k) . ': [' . "\n" . implode(",\n", $items) . "\n]";
            } else {
                $lines[] = json_encode((string) $k) . ': ' . json_encode($v, $flags);
            }
        }
        return "{\n" . implode(",\n", $lines) . "\n}";
    }

    /** Decodes the "=?base64?…?=" header sentinel. */
    private static function decodeHeader(?string $value): ?string
    {
        if ($value !== null && preg_match('/^=\?base64\?(.*)\?=$/', $value, $m)) {
            $decoded = base64_decode($m[1], true);
            return $decoded === false ? null : $decoded;
        }
        return $value;
    }

    private static function originMatchesHost(string $origin, string $host): bool
    {
        $o = parse_url($origin);
        if (!is_array($o) || !isset($o['host'])) {
            return false;
        }
        $originHost = strtolower($o['host']) . (isset($o['port']) ? ':' . $o['port'] : '');
        $host = strtolower($host);
        return $host !== '' && ($originHost === $host || strtolower($o['host']) === preg_replace('/:\d+$/', '', $host) && !isset($o['port']));
    }

    private static function error($id, int $code, string $message, $data = null): array
    {
        $err = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $err['data'] = $data;
        }
        $out = ['jsonrpc' => '2.0'];
        if ($id !== null) {
            $out['id'] = $id;
        }
        $out['error'] = $err;
        return $out;
    }

    private static function http(int $status, array $payload): array
    {
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'    => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        ];
    }
}
