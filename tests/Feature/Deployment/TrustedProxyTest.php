<?php

namespace Tests\Feature\Deployment;

use Illuminate\Http\Request;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['session.secure' => null, 'session.domain' => null]);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);

        parent::tearDown();
    }

    public function test_loopback_proxy_preserves_public_https_urls_and_secure_cookies(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'scholarship.example',
                'X-Forwarded-For' => '198.51.100.10',
            ])->get('http://internal.example/login');

        $response->assertOk()
            ->assertSee('action="https://scholarship.example/login"', false)
            ->assertSee('https://scholarship.example/images/favicon.webp', false)
            ->assertSee('https://scholarship.example/images/cronasia-logo-white.png', false)
            ->assertSee('https://scholarship.example/images/cronasia-school.webp', false);

        $this->assertSame('198.51.100.10', request()->ip());
        $this->assertSessionCookieSecurity($response, true);
    }

    public function test_ipv6_loopback_proxy_is_trusted(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '::1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://scholarship.example/login');

        $response->assertOk()->assertSee('action="https://scholarship.example/login"', false);
        $this->assertSessionCookieSecurity($response, true);
    }

    public function test_direct_local_http_remains_usable_with_non_secure_session_cookies(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('http://127.0.0.1:8000/login');

        $response->assertOk()
            ->assertSee('action="http://127.0.0.1:8000/login"', false)
            ->assertSee('http://127.0.0.1:8000/images/favicon.webp', false);

        $this->assertSessionCookieSecurity($response, false);
    }

    public function test_non_loopback_clients_cannot_spoof_forwarded_protocol_or_host(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'spoofed.example',
                'X-Forwarded-For' => '198.51.100.10',
            ])->get('http://internal.example/login');

        $response->assertOk()->assertSee('action="http://internal.example/login"', false);
        $this->assertSame('198.51.100.20', request()->ip());
        $this->assertSessionCookieSecurity($response, false);
    }

    private function assertSessionCookieSecurity($response, bool $secure): void
    {
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($cookie);
        $this->assertSame($secure, $cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertNull($cookie->getDomain());
    }
}
