<?php

return [
    'resident' => [
        'Overview' => [
            ['label' => 'Dashboard', 'icon' => 'dashboard', 'route' => 'resident.home'],
        ],
        'My services' => [
            ['label' => 'New Reservation', 'icon' => 'plus'],
            ['label' => 'My Reservations', 'icon' => 'document'],
            ['label' => 'My QR Tickets', 'icon' => 'qr'],
            ['label' => 'Request Status', 'icon' => 'status'],
        ],
    ],
    'admin' => [
        'Overview' => [
            ['label' => 'Dashboard', 'icon' => 'dashboard', 'route' => 'admin.home'],
        ],
        'Barangay operations' => [
            ['label' => 'Reservation Management', 'icon' => 'document'],
            ['label' => 'Schedule & Slot Management', 'icon' => 'calendar'],
            ['label' => 'QR Verification / Check-in', 'icon' => 'qr'],
            ['label' => 'Request Status Management', 'icon' => 'status'],
            ['label' => 'Services & Document Management', 'icon' => 'services', 'route' => 'admin.services.index', 'active' => 'admin.services.*'],
            ['label' => 'User Management', 'icon' => 'users'],
            ['label' => 'Check-in / Reservation Logs', 'icon' => 'history'],
        ],
    ],
];
