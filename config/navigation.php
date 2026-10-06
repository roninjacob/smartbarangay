<?php

return [
    'resident' => [
        'Overview' => [
            ['label' => 'Dashboard', 'icon' => 'dashboard', 'route' => 'resident.home'],
        ],
        'My services' => [
            ['label' => 'New Reservation', 'icon' => 'plus', 'route' => 'resident.reservations.create', 'active' => [
                'resident.reservations.create', 'resident.reservations.service', 'resident.reservations.requirements*',
                'resident.reservations.schedule*', 'resident.reservations.confirm', 'resident.reservations.store',
            ]],
            ['label' => 'My Reservations', 'icon' => 'document', 'route' => 'resident.reservations.index', 'active' => [
                'resident.reservations.index', 'resident.reservations.show',
            ]],
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
            ['label' => 'Schedule & Slot Management', 'icon' => 'calendar', 'route' => 'admin.schedules.index', 'active' => 'admin.schedules.*'],
            ['label' => 'QR Verification / Check-in', 'icon' => 'qr'],
            ['label' => 'Request Status Management', 'icon' => 'status'],
            ['label' => 'Services & Document Management', 'icon' => 'services', 'route' => 'admin.services.index', 'active' => 'admin.services.*'],
            ['label' => 'User Management', 'icon' => 'users'],
            ['label' => 'Check-in / Reservation Logs', 'icon' => 'history'],
        ],
    ],
];
