<?php

return [
    // Enable only after controlled migration and infrastructure acceptance.
    'enabled' => env('IOT_API_ENABLED', false),
    'ip_requests_per_minute' => 60,
    'device_requests_per_minute' => 30,

    // Shared verification result lifecycle (website modal, scanner LEDs and OLED).
    // Display timing only: it never extends ticket validity or check-in evidence.
    'result' => [
        // An open Admin viewing session gets this long to pick up a fresh scan.
        'pickup_seconds' => 5,
        // Each Admin poll while the result modal is open renews the review lease by this much.
        'review_lease_seconds' => 10,
        // Absolute cap for holding one result on the hardware, reviewed or not.
        'max_hold_seconds' => 120,
        // Result shown by the scanner when no Admin is reviewing it (firmware uses the same value).
        'standalone_seconds' => 15,
        // How long the scanner shows a recorded check-in before returning to Ready.
        'recorded_seconds' => 4,
    ],
];
