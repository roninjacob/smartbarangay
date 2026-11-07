<?php

return [
    // Enable only after controlled migration and infrastructure acceptance.
    'enabled' => env('IOT_API_ENABLED', false),
    'ip_requests_per_minute' => 60,
    'device_requests_per_minute' => 30,
];
