<?php

namespace App\Http\Middleware;

use App\Services\IotDeviceAuthentication;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIotDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('iot.enabled'), 503);
        $peer = $request->server('REMOTE_ADDR');
        $localTest = app()->environment(['local', 'testing']) && in_array($peer, ['127.0.0.1', '::1'], true);
        // Device TLS requires direct server evidence, never client-forwarded headers.
        $directTls = in_array(strtolower((string) $request->server('HTTPS')), ['on', '1'], true);
        abort_unless($directTls || $localTest, 403);
        abort_unless($request->method() === 'POST' && in_array($request->getRequestUri(), IotDeviceAuthentication::PATHS, true), 400);
        abort_unless(preg_match('/\Aapplication\/json(?:\s*;\s*charset=utf-8)?\z/i', $request->header('Content-Type', '')), 415);
        abort_unless($request->header('Content-Encoding', 'identity') === 'identity', 415);
        $length = $request->header('Content-Length');
        abort_if($length !== null && (! ctype_digit($length) || (int) $length > IotDeviceAuthentication::bodyLimit($request)), 413);
        abort_if(strlen($request->getContent()) > IotDeviceAuthentication::bodyLimit($request), 413);
        try {
            $body = json_decode($request->getContent(), false, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(400);
        }
        abort_unless($body instanceof \stdClass, 422);
        if ($request->getRequestUri() === IotDeviceAuthentication::PATH) {
            abort_unless((array) $body === ['message' => 'probe'], 422);
        }

        $device = app(IotDeviceAuthentication::class)->authenticate($request);
        abort_unless($device, 401);
        $request->attributes->set('iot_device_id', $device);
        $request->attributes->set('iot_body', (array) $body);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
