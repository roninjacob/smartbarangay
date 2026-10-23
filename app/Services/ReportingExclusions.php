<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReportingExclusions
{
    public const UNAVAILABLE = 'Reservation reports are unavailable until the reviewed reporting configuration is verified. Contact the system administrator.';

    public function binding(): array
    {
        $db = DB::connection();
        $config = $db->getConfig();

        return ['environment' => app()->environment(), 'driver' => $db->getDriverName(),
            'host' => (string) ($config['host'] ?? ''), 'port' => (string) ($config['port'] ?? ''),
            'database' => (string) $config['database']];
    }

    /** Validate the entire manifest, including fixtures outside the requested period. */
    public function validatedIds(): array
    {
        $path = config('reporting.manifest');
        if (! is_string($path) || ! is_file($path) || ! is_readable($path) || filesize($path) > 1048576) {
            throw new RuntimeException(self::UNAVAILABLE);
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(self::UNAVAILABLE);
        }
        try {
            // Preserve JSON types: {} must never become an approved empty [] list.
            $data = json_decode($contents, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException(self::UNAVAILABLE);
        }
        if (! ($data instanceof \stdClass) || ($data->version ?? null) !== 1 || ($data->reviewed ?? null) !== true
            || ! (($data->binding ?? null) instanceof \stdClass) || get_object_vars($data->binding) !== $this->binding()
            || ! is_array($data->excluded_reservations ?? null)) {
            throw new RuntimeException(self::UNAVAILABLE);
        }
        $entries = [];
        $ids = [];
        foreach ($data->excluded_reservations as $object) {
            if (! ($object instanceof \stdClass)) {
                throw new RuntimeException(self::UNAVAILABLE);
            }
            $entry = get_object_vars($object);
            if (count($entry) !== 5) {
                throw new RuntimeException(self::UNAVAILABLE);
            }
            foreach (['reservation_id', 'user_id', 'service_id', 'schedule_id'] as $key) {
                if (! is_int($entry[$key] ?? null) || $entry[$key] < 1) {
                    throw new RuntimeException(self::UNAVAILABLE);
                }
            }
            if (! is_string($entry['created_at'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $entry['created_at'])
                || in_array($entry['reservation_id'], $ids, true)) {
                throw new RuntimeException(self::UNAVAILABLE);
            }
            $ids[] = $entry['reservation_id'];
            $entries[] = $entry;
        }
        $rows = DB::table('reservations')->whereIn('id', $ids)->get(['id', 'user_id', 'service_id', 'schedule_id', 'created_at'])->keyBy('id');
        foreach ($entries as $entry) {
            $row = $rows->get($entry['reservation_id']);
            if (! $row || (int) $row->user_id !== $entry['user_id'] || (int) $row->service_id !== $entry['service_id']
                || (int) $row->schedule_id !== $entry['schedule_id'] || $row->created_at !== $entry['created_at']) {
                throw new RuntimeException(self::UNAVAILABLE);
            }
        }

        return $ids;
    }
}
