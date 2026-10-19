<?php

declare(strict_types=1);

/**
 * Token check for the WebHooks (ingest now, MCP in phase 4).
 *
 * Accepted:
 *  - "Authorization: Bearer <token>"
 *  - HTTP Basic auth with any user name and <token> as password
 *  - "X-Api-Key: <token>" (the header HC Webhook's own docs use as example)
 *
 * No IPS dependency - unit tested.
 */
class HHHookAuth
{
    /**
     * @param array $server $_SERVER of the hook request
     */
    public static function isAuthorized(array $server, string $token): bool
    {
        if ($token === '') {
            return false; // never accept anything without a configured token
        }
        foreach (self::candidates($server) as $candidate) {
            if (hash_equals($token, $candidate)) {
                return true;
            }
        }
        return false;
    }

    /** Generates a random token (32 hex chars). */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function candidates(array $server): array
    {
        $out = [];
        $auth = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m)) {
            $out[] = $m[1];
        } elseif (preg_match('/^\s*Basic\s+(\S+)\s*$/i', $auth, $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded !== false && strpos($decoded, ':') !== false) {
                $out[] = substr($decoded, strpos($decoded, ':') + 1);
            }
        }
        if (isset($server['PHP_AUTH_PW'])) {
            $out[] = (string) $server['PHP_AUTH_PW'];
        }
        if (isset($server['HTTP_X_API_KEY'])) {
            $out[] = trim((string) $server['HTTP_X_API_KEY']);
        }
        return $out;
    }
}
