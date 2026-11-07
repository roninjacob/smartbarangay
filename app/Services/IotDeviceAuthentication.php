<?php

namespace App\Services;

use App\Models\IotDevice;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IotDeviceAuthentication
{
    public const PATH = '/api/iot/v1/probe';

    public const CLOCK_SKEW = 90;

    public const MAX_BODY_BYTES = 1024;

    public function authenticate(Request $request): ?string
    {
        $headers = [];
        $patterns = [
            'X-SB-Device' => '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            'X-SB-Key-Version' => '/\A[1-9][0-9]{0,8}\z/',
            'X-SB-Timestamp' => '/\A[1-9][0-9]{9}\z/',
            'X-SB-Nonce' => '/\A[0-9a-f]{32}\z/',
            'X-SB-Signature' => '/\A[0-9a-f]{64}\z/',
        ];
        foreach ($patterns as $name => $pattern) {
            $values = $request->headers->all($name);
            if (count($values) !== 1 || strlen($values[0]) > 64 || ! preg_match($pattern, $values[0])) {
                return null;
            }
            $headers[$name] = $values[0];
        }
        $timestamp = (int) $headers['X-SB-Timestamp'];
        if (abs(now()->timestamp - $timestamp) > self::CLOCK_SKEW) {
            return null;
        }
        $canonical = implode("\n", [
            'SB-IOT-1', $request->method(), $request->getRequestUri(),
            $headers['X-SB-Device'], $headers['X-SB-Key-Version'],
            $headers['X-SB-Timestamp'], $headers['X-SB-Nonce'], hash('sha256', $request->getContent()),
        ]);

        try {
            return DB::transaction(function () use ($headers, $timestamp, $canonical) {
                // Also serializes revocation/rotation with successful authentication.
                $device = IotDevice::lockForUpdate()->find($headers['X-SB-Device']);
                if (! $device || $device->revoked_at || $device->credential_version !== (int) $headers['X-SB-Key-Version']
                    || abs(now()->timestamp - $timestamp) > self::CLOCK_SKEW) {
                    return null;
                }
                try {
                    $key = $device->credential;
                } catch (DecryptException) {
                    return null;
                }
                if (! is_string($key) || ! preg_match('/\A[0-9a-f]{64}\z/', $key)
                    || ! hash_equals(hash_hmac('sha256', $canonical, hex2bin($key)), $headers['X-SB-Signature'])) {
                    return null;
                }
                DB::table('iot_request_nonces')->insert([
                    'device_id' => $device->id,
                    'nonce_hash' => hash('sha256', $headers['X-SB-Nonce']),
                    // A future-dated request must remain blocked throughout its acceptance window.
                    'expires_at' => Carbon::createFromTimestampUTC($timestamp + self::CLOCK_SKEW + 1),
                ]);

                return $device->id;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
