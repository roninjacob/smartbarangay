<?php

namespace App\Services;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class IotErrorReporting
{
    private const ENDPOINTS = [
        'api/iot/v1/probe', 'api/iot/v1/pair', 'api/iot/v1/scans',
        'api/iot/v1/status', 'api/iot/v1/preview',
        'admin/iot-scanner/devices', 'admin/iot-scanner/challenge',
        'admin/iot-scanner/poll', 'admin/iot-scanner/disconnect',
        'admin/iot-scanner/connect', 'admin/iot-scanner/preview',
        'admin/iot-scanner/frame', 'admin/iot-dashboard/data',
    ];

    public function report(Throwable $exception, Request $request): void
    {
        // This boundary must not throw or report its own failures recursively.
        try {
            if ($exception instanceof AuthenticationException || $exception instanceof ValidationException) {
                return;
            }
            if ($exception instanceof HttpExceptionInterface
                && ($exception->getStatusCode() < 500
                    || ($exception->getStatusCode() === 503 && ! config('iot.enabled')))) {
                return;
            }
            if ($request->attributes->get('_iot_failure_reported', false)) {
                return;
            }
            $request->attributes->set('_iot_failure_reported', true);

            $endpoint = in_array($request->path(), self::ENDPOINTS, true) ? $request->path() : 'iot.other';
            $category = match (true) {
                $exception instanceof QueryException => 'database_failure',
                $exception instanceof \TypeError, $exception instanceof \Error => 'application_error',
                $exception instanceof HttpExceptionInterface => 'server_unavailable',
                default => 'unexpected_failure',
            };

            // FileStore::add uses an exclusive file lock. No DB dependency during an outage.
            // Fixed keys only: at most one record per endpoint/category per 60 seconds.
            if (! Cache::store('file')->add('iot-error-report:'.$endpoint.':'.$category, true, 60)) {
                return;
            }

            Log::error('IoT backend failure.', [
                'category' => $category,
                'endpoint' => $endpoint,
                'correlation_id' => (string) Str::uuid(),
            ]);
        } catch (Throwable) {
            // A cache/log outage must preserve the original generic HTTP response.
        }
    }
}
