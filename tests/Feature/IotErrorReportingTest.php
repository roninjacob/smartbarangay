<?php

namespace Tests\Feature;

use App\Services\IotErrorReporting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IotErrorReportingTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cachePath = sys_get_temp_dir().'/sb-iot-error-tests-'.Str::uuid();
        config(['iot.enabled' => true, 'app.debug' => true,
            'cache.stores.file.path' => $this->cachePath,
            'cache.stores.file.lock_path' => $this->cachePath]);
        Cache::forgetDriver('file');
        Log::spy();
    }

    protected function tearDown(): void
    {
        if (str_starts_with($this->cachePath, sys_get_temp_dir().'/sb-iot-error-tests-')) {
            File::deleteDirectory($this->cachePath);
        }
        parent::tearDown();
    }

    public static function endpoints(): array
    {
        return [
            ['api/iot/v1/probe'], ['api/iot/v1/status'], ['api/iot/v1/scans'],
            ['api/iot/v1/preview'], ['api/iot/v1/pair'],
            ['admin/iot-scanner/poll'], ['admin/iot-scanner/frame'], ['admin/iot-dashboard/data'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_unexpected_failures_have_one_allowlisted_record_and_unchanged_response(string $endpoint): void
    {
        Route::get('/'.$endpoint, fn () => throw new RuntimeException('fake-secret-qr-image-password'));

        $this->getJson('/'.$endpoint.'?token=fake-query-secret', [
            'Authorization' => 'Bearer fake-auth-secret', 'X-SB-Signature' => 'fake-signature',
            'X-Request-ID' => 'fake-client-id', 'Cookie' => 'fake-session',
        ])->assertStatus(503)->assertExactJson(['ok' => false, 'error' => 'service_unavailable'])
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');

        Log::shouldHaveReceived('error')->once()->with('IoT backend failure.', \Mockery::on(function ($context) use ($endpoint) {
            $this->assertSame(['category', 'endpoint', 'correlation_id'], array_keys($context));
            $this->assertSame('unexpected_failure', $context['category']);
            $this->assertSame($endpoint, $context['endpoint']);
            $this->assertTrue(Str::isUuid($context['correlation_id']));
            $this->assertStringNotContainsString('fake-', json_encode($context));

            return true;
        }));
    }

    public static function expectedErrors(): array
    {
        return [[400, 'invalid_request'], [401, 'authentication_failed'], [403, 'access_denied'],
            [404, 'not_found'], [405, 'method_not_allowed'], [409, 'scanner_conflict'],
            [413, 'request_too_large'], [415, 'unsupported_media_type'], [419, 'session_expired'],
            [422, 'invalid_request'], [429, 'rate_limited']];
    }

    #[DataProvider('expectedErrors')]
    public function test_expected_http_failures_remain_quiet(int $status, string $code): void
    {
        Route::get('/admin/iot-scanner/poll', fn () => throw new HttpException($status, 'fake-private-message', null, ['Retry-After' => '7']));
        $this->getJson('/admin/iot-scanner/poll')->assertStatus($status)
            ->assertExactJson(['ok' => false, 'error' => $code])->assertHeader('Retry-After', '7');
        Log::shouldNotHaveReceived('error');
    }

    public function test_disabled_api_is_quiet(): void
    {
        config(['iot.enabled' => false]);
        $this->postJson('/api/iot/v1/probe', ['message' => 'probe'])
            ->assertStatus(503)->assertExactJson(['ok' => false, 'error' => 'service_unavailable']);
        Log::shouldNotHaveReceived('error');
    }

    public function test_real_authentication_failure_is_quiet(): void
    {
        $this->postJson('/api/iot/v1/probe', ['message' => 'probe'])
            ->assertUnauthorized()->assertExactJson(['ok' => false, 'error' => 'authentication_failed']);
        Log::shouldNotHaveReceived('error');
    }

    public function test_database_failure_omits_sql_bindings_trace_and_request_data(): void
    {
        $exception = new QueryException('fake-private-connection', 'SELECT fake-private-sql',
            ['fake-hmac-secret', 'fake-qr-ticket', 'fake-resident-name'], new RuntimeException('fake-password'));
        Route::post('/api/iot/v1/preview', fn () => throw $exception);
        $this->postJson('/api/iot/v1/preview?nonce=fake-nonce', ['image' => 'fake-image', 'payload' => 'fake-qr'])
            ->assertStatus(503)->assertExactJson(['ok' => false, 'error' => 'service_unavailable']);
        Log::shouldHaveReceived('error')->once()->with('IoT backend failure.', \Mockery::on(fn ($context) => $context['category'] === 'database_failure' && count($context) === 3
            && ! str_contains(json_encode($context), 'fake-')));
    }

    public function test_http_server_failure_is_reported_even_when_framework_ignores_it(): void
    {
        Route::get('/admin/iot-scanner/frame', fn () => abort(503));
        $this->getJson('/admin/iot-scanner/frame')->assertStatus(503);
        Log::shouldHaveReceived('error')->once()->with('IoT backend failure.', \Mockery::on(fn ($context) => $context['category'] === 'server_unavailable'));
    }

    public function test_repeated_outage_is_bounded_and_reporting_resumes_after_sixty_seconds(): void
    {
        Route::get('/admin/iot-scanner/poll', fn () => throw new RuntimeException('private'));
        $this->getJson('/admin/iot-scanner/poll')->assertStatus(503);
        $this->getJson('/admin/iot-scanner/poll')->assertStatus(503);
        Log::shouldHaveReceived('error')->once();
        $this->travel(61)->seconds();
        $this->getJson('/admin/iot-scanner/poll')->assertStatus(503);
        Log::shouldHaveReceived('error')->twice();
    }

    public function test_unknown_paths_and_exception_types_cannot_inject_log_values(): void
    {
        app(IotErrorReporting::class)->report(new \TypeError('fake-secret'), Request::create('/api/iot/fake-ticket?secret=fake'));
        Log::shouldHaveReceived('error')->once()->with('IoT backend failure.', \Mockery::on(fn ($context) => $context['endpoint'] === 'iot.other' && $context['category'] === 'application_error'
            && ! str_contains(json_encode($context), 'fake')));
    }

    public function test_cache_failure_preserves_http_response_without_recursive_reporting(): void
    {
        Cache::shouldReceive('store')->with('file')->andThrow(new RuntimeException('fake-cache-secret'));
        Route::get('/admin/iot-scanner/poll', fn () => throw new RuntimeException('fake-original'));
        $this->getJson('/admin/iot-scanner/poll')->assertStatus(503)
            ->assertExactJson(['ok' => false, 'error' => 'service_unavailable']);
        Log::shouldNotHaveReceived('error');
    }

    public function test_logger_failure_does_not_replace_the_original_response_or_retry(): void
    {
        Log::shouldReceive('error')->once()->andThrow(new RuntimeException('fake-log-secret'));
        Route::get('/admin/iot-scanner/poll', fn () => throw new RuntimeException('fake-original'));
        $this->getJson('/admin/iot-scanner/poll')->assertStatus(503)
            ->assertExactJson(['ok' => false, 'error' => 'service_unavailable']);
    }

    public function test_reporting_and_rendering_the_same_exception_do_not_duplicate_records(): void
    {
        $request = Request::create('/admin/iot-scanner/poll');
        $this->app->instance('request', $request);
        $handler = app(ExceptionHandler::class);
        $exception = new RuntimeException('fake-secret');
        $handler->report($exception);
        $handler->report($exception);
        $this->assertSame(503, $handler->render($request, $exception)->getStatusCode());
        Log::shouldHaveReceived('error')->once();
    }
}
