<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HookAuthTest extends TestCase
{
    private const TOKEN = 'abc123secret';

    public function testBearer(): void
    {
        $this->assertTrue(HHHookAuth::isAuthorized(['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN], self::TOKEN));
        $this->assertTrue(HHHookAuth::isAuthorized(['HTTP_AUTHORIZATION' => 'bearer  ' . self::TOKEN . ' '], self::TOKEN));
        $this->assertFalse(HHHookAuth::isAuthorized(['HTTP_AUTHORIZATION' => 'Bearer wrong'], self::TOKEN));
    }

    public function testBasicWithTokenAsPassword(): void
    {
        $this->assertTrue(HHHookAuth::isAuthorized(['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('any:' . self::TOKEN)], self::TOKEN));
        $this->assertTrue(HHHookAuth::isAuthorized(['PHP_AUTH_USER' => 'x', 'PHP_AUTH_PW' => self::TOKEN], self::TOKEN));
        $this->assertFalse(HHHookAuth::isAuthorized(['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode(self::TOKEN . ':x')], self::TOKEN));
    }

    public function testApiKeyHeader(): void
    {
        $this->assertTrue(HHHookAuth::isAuthorized(['HTTP_X_API_KEY' => self::TOKEN], self::TOKEN));
    }

    public function testMissingOrEmpty(): void
    {
        $this->assertFalse(HHHookAuth::isAuthorized([], self::TOKEN));
        $this->assertFalse(HHHookAuth::isAuthorized(['HTTP_AUTHORIZATION' => 'Bearer '], ''), 'empty token never authorizes');
        $this->assertFalse(HHHookAuth::isAuthorized(['HTTP_X_API_KEY' => ''], ''));
    }

    public function testNewTokenFormat(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', HHHookAuth::newToken());
        $this->assertNotSame(HHHookAuth::newToken(), HHHookAuth::newToken());
    }
}
