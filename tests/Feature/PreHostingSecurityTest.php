<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PreHostingSecurityTest extends TestCase
{
    public function test_untrusted_forwarded_headers_cannot_change_request_identity(): void
    {
        config(['trustedproxy.proxies' => []]);
        Route::get('/security-test/identity', fn (Request $request) => [
            'ip' => $request->ip(), 'host' => $request->getHost(), 'secure' => $request->isSecure(),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.1', 'X-Forwarded-Host' => 'attacker.invalid',
                'X-Forwarded-Proto' => 'https'])
            ->getJson('http://localhost/security-test/identity')
            ->assertOk()->assertJson(['ip' => '203.0.113.10', 'host' => 'localhost', 'secure' => false]);
    }

    public function test_explicitly_configured_proxy_is_supported(): void
    {
        config(['trustedproxy.proxies' => ['203.0.113.10']]);
        Route::get('/security-test/proxy', fn (Request $request) => ['ip' => $request->ip(), 'secure' => $request->isSecure()]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.1', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/security-test/proxy')->assertOk()
            ->assertJson(['ip' => '198.51.100.1', 'secure' => true]);
    }

    public function test_security_headers_preserve_pdf_sandbox_and_same_origin_camera(): void
    {
        $response = (new SecurityHeaders)->handle(Request::create('/certificate'), fn () => response('PDF', 200, [
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'; sandbox",
            'Cache-Control' => 'no-store',
        ]));

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertSame('camera=(self), microphone=(), geolocation=()', $response->headers->get('Permissions-Policy'));
        $this->assertSame("default-src 'none'; frame-ancestors 'self'; sandbox", $response->headers->get('Content-Security-Policy'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function test_private_local_disk_has_no_generic_serving_route(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertFalse(Route::has('storage.local'));
    }

    public function test_production_rejects_unrecognized_host_and_accepts_configured_host(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://barangay.example.test']);
        Route::get('/security-test/host', fn (Request $request) => response()->json(['host' => $request->getHost()]));

        $this->getJson('https://attacker.invalid/security-test/host')->assertStatus(400);
        $this->getJson('https://barangay.example.test/security-test/host')->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_local_http_does_not_receive_hsts(): void
    {
        $response = (new SecurityHeaders)->handle(Request::create('http://localhost/'), fn () => response('OK'));
        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }
}
