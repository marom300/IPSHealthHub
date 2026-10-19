<?php

declare(strict_types=1);

require_once __DIR__ . '/DayClock.php';
require_once __DIR__ . '/FddbParser.php';

/**
 * fddb.info access: login (session cookie "fddb") and diary page per day.
 * No official API - flow as in github.com/itobey/fddb-exporter:
 *  - POST https://fddb.info/db/i18n/account/?lang=de&action=login
 *        loginemailorusername=<user>&loginpassword=<password>  -> Set-Cookie: fddb=<session>
 *  - GET  https://fddb.info/db/i18n/myday20/?lang=en&p=<from>&q=<to>   (Cookie: fddb=<session>)
 *    <from>/<to> = unix timestamps; an FDDB diary day runs from 02:00 local to 01:59:59 next day.
 *  - An expired session returns the logged-out page with HTTP 200 -> log in again once.
 *
 * HTTP goes through an injectable transport so the logic is testable without network.
 * transport(method, url, headers[], body|null): ['status' => int, 'headers' => string[], 'body' => string]
 */
class HHFddbClient
{
    public const BASE_URL = 'https://fddb.info';
    private const DAY_START_HOUR = 2;
    private const USER_AGENT = 'Mozilla/5.0 (compatible; IPSymcon-HealthHub)';

    /** @var HHDayClock */
    private $clock;
    /** @var string */
    private $username;
    /** @var string */
    private $password;
    /** @var callable */
    private $transport;
    /** @var string */
    private $cookie;

    public function __construct(HHDayClock $clock, string $username, string $password, string $cookie = '', ?callable $transport = null)
    {
        $this->clock = $clock;
        $this->username = $username;
        $this->password = $password;
        $this->cookie = $cookie;
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    /** Session cookie to persist between runs ('' = none). */
    public function cookie(): string
    {
        return $this->cookie;
    }

    /** FDDB diary window of a calendar day: [from, to] (02:00 local to 01:59:59 next day). */
    public function dayWindow(string $day): array
    {
        [$start] = $this->clock->dayRange($day);
        [$nextStart] = $this->clock->dayRange($this->clock->addDays($day, 1));
        return [$start + self::DAY_START_HOUR * 3600, $nextStart + self::DAY_START_HOUR * 3600 - 1];
    }

    /**
     * Fetches and parses one day.
     * @return array parser result (status ok|empty|logged_out|unknown, entries, totals, error) + 'html'
     */
    public function fetchDay(string $day): array
    {
        if ($this->cookie === '') {
            $this->login();
        }
        $html = $this->getDiary($day);
        $parsed = HHFddbParser::parse($html);
        if ($parsed['status'] === HHFddbParser::STATUS_LOGGED_OUT) {
            // session expired -> fresh login, one retry
            $this->cookie = '';
            $this->login();
            $html = $this->getDiary($day);
            $parsed = HHFddbParser::parse($html);
            if ($parsed['status'] === HHFddbParser::STATUS_LOGGED_OUT) {
                throw new RuntimeException('FDDB login not accepted - check user name and password');
            }
        }
        $parsed['html'] = $html;
        return $parsed;
    }

    /** Logs in and stores the session cookie. Throws on failure. */
    public function login(): void
    {
        if ($this->username === '' || $this->password === '') {
            throw new RuntimeException('FDDB user name or password not configured');
        }
        $body = http_build_query(['loginemailorusername' => $this->username, 'loginpassword' => $this->password]);
        $res = ($this->transport)('POST', self::BASE_URL . '/db/i18n/account/?lang=de&action=login', [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ' . self::USER_AGENT,
        ], $body);
        if ($res['status'] >= 500 || $res['status'] === 0) {
            throw new RuntimeException('FDDB not reachable (HTTP ' . $res['status'] . ')');
        }
        foreach ($res['headers'] as $line) {
            if (preg_match('/^Set-Cookie:\s*fddb=([^;]+)/i', $line, $m) && $m[1] !== '' && strtolower($m[1]) !== 'deleted') {
                $this->cookie = $m[1];
                return;
            }
        }
        throw new RuntimeException('FDDB login failed - check user name and password');
    }

    private function getDiary(string $day): string
    {
        [$from, $to] = $this->dayWindow($day);
        $res = ($this->transport)('GET', self::BASE_URL . '/db/i18n/myday20/?lang=en&p=' . $from . '&q=' . $to, [
            'Cookie: fddb=' . $this->cookie,
            'User-Agent: ' . self::USER_AGENT,
        ], null);
        if ($res['status'] !== 200) {
            throw new RuntimeException('FDDB diary request failed (HTTP ' . $res['status'] . ')');
        }
        return (string) $res['body'];
    }

    /** Default transport (cURL). */
    public static function curlTransport(string $method, string $url, array $headers, ?string $body): array
    {
        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // the login answer itself carries the cookie
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => array_merge($headers, ['Expect:']),
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$responseHeaders) {
                $responseHeaders[] = trim($line);
                return strlen($line);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body);
        }
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($out === false) {
            throw new RuntimeException('FDDB request failed: ' . $error);
        }
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $out];
    }
}
