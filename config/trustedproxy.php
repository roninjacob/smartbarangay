<?php

return [
    // Only provider-verified ingress addresses; direct connections trust no proxy.
    'proxies' => array_values(array_filter(array_map('trim', explode(',', env('TRUSTED_PROXIES', ''))),
        static fn (string $address): bool => filter_var(explode('/', $address)[0], FILTER_VALIDATE_IP) !== false)),
];
