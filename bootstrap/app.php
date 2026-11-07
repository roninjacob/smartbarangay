<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\LimitIotRequestBody;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Session\Middleware\AuthenticateSession;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(LimitIotRequestBody::class);
        $middleware->trustProxies(at: '*');

        $middleware->appendToGroup('web', EnsureAccountIsActive::class);
        $middleware->appendToGroup('web', AuthenticateSession::class);
        $middleware->alias(['role' => EnsureUserHasRole::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => route($request->user()->entryRouteName()));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Device errors never expose debug traces, request headers or SQL bindings.
        $exceptions->dontReportWhen(fn (Throwable $exception) => request()->is('api/iot/*', 'admin/iot-scanner/*'));
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->is('api/iot/*', 'admin/iot-scanner/*')) {
                $status = $exception instanceof AuthenticationException ? 401
                    : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503);
                $code = match ($status) {
                    401 => 'authentication_failed', 403 => $request->is('admin/*') ? 'access_denied' : 'secure_transport_required',
                    409 => 'scanner_conflict', 419 => 'session_expired',
                    404 => 'not_found', 405 => 'method_not_allowed', 413 => 'request_too_large',
                    415 => 'unsupported_media_type', 422 => $request->is('api/iot/v1/probe') ? 'invalid_probe' : 'invalid_request', 429 => 'rate_limited',
                    400 => 'invalid_request', default => 'service_unavailable',
                };
                $headers = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
                if ($exception instanceof HttpExceptionInterface) {
                    $headers += array_intersect_key($exception->getHeaders(), array_flip(['Retry-After', 'Allow']));
                }

                return response()->json(['ok' => false, 'error' => $code], $status, $headers);
            }
        });
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'token']);

        $exceptions->render(function (InvalidSignatureException $exception, Request $request) {
            if ($request->routeIs('verification.verify')) {
                return response()->view('auth.verification-invalid', [], 403);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request) {
            if ($request->routeIs('verification.verify')) {
                return response()->view('auth.verification-invalid', [], 403);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if ($request->routeIs(
                'verification.send',
                'verification.verify',
                'verification.email.update',
                'password.email',
                'password.update'
            )) {
                $verification = $request->routeIs('verification.*');

                return response()->view('auth.action-throttled', [
                    'verification' => $verification,
                ], 429, $exception->getHeaders());
            }
        });
    })
    ->create();
