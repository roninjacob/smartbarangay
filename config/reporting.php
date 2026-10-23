<?php

return [
    // Cache the location only. The reviewed private data is validated on every report request.
    'manifest' => env('REPORTING_MANIFEST_PATH', storage_path('app/private/reporting/exclusions.json')),
];
