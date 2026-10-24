<?php

return [
    // Cache the location only. The reviewed private data is validated on every report request.
    'exports' => ['batch_size' => 500, 'max_bytes' => 25 * 1024 * 1024, 'max_seconds' => 60],
    'manifest' => env('REPORTING_MANIFEST_PATH', storage_path('app/private/reporting/exclusions.json')),
];
