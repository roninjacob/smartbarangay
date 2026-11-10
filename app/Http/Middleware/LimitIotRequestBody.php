<?php

namespace App\Http\Middleware;

use App\Services\IotDeviceAuthentication;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitIotRequestBody
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/iot/*')) {
            $maximum = IotDeviceAuthentication::bodyLimit($request);
            $length = $request->header('Content-Length');
            abort_if($length !== null && (! ctype_digit($length) || (int) $length > $maximum), 413);
            // Bound input before Laravel's global JSON input transformations run.
            $stream = $request->getContent(true);
            $body = stream_get_contents($stream, $maximum + 1);
            rewind($stream);
            abort_if($body === false || strlen($body) > $maximum, 413);
        }

        return $next($request);
    }
}
