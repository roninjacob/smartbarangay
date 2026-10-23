<?php

return [
    'resident' => [
        'Overview' => [
            ['label' => 'Dashboard', 'icon' => 'dashboard', 'route' => 'resident.home'],
            ['label' => 'Notifications', 'icon' => 'bell', 'route' => 'resident.notifications.index', 'active' => 'resident.notifications.*'],
        ],
        'My services' => [
            ['label' => 'New Reservation', 'icon' => 'plus', 'route' => 'resident.reservations.create', 'active' => [
                'resident.reservations.create', 'resident.reservations.service', 'resident.reservations.requirements*',
                'resident.reservations.schedule*', 'resident.reservations.confirm', 'resident.reservations.store',
            ]],
            ['label' => 'My Reservations', 'icon' => 'document', 'route' => 'resident.reservations.index', 'active' => [
                'resident.reservations.index', 'resident.reservations.show',
            ]],
            ['label' => 'My QR Tickets', 'icon' => 'qr', 'route' => 'resident.qr-tickets.index', 'active' => 'resident.qr-tickets.*'],
            ['label' => 'Request Status', 'icon' => 'status', 'route' => 'resident.request-status.index', 'active' => 'resident.request-status.*'],
        ],
    ],
    'admin' => [
        'Overview' => [
            ['label' => 'Dashboard', 'icon' => 'dashboard', 'route' => 'admin.home'],
            ['label' => 'Notifications', 'icon' => 'bell', 'route' => 'admin.notifications.index', 'active' => 'admin.notifications.*'],
        ],
        'Barangay operations' => [
            ['label' => 'Reservation Management', 'icon' => 'document', 'route' => 'admin.reservations.index', 'active' => 'admin.reservations.*'],
            ['label' => 'Schedule & Slot Management', 'icon' => 'calendar', 'route' => 'admin.schedules.index', 'active' => 'admin.schedules.*'],
            ['label' => 'QR Verification', 'icon' => 'qr', 'route' => 'admin.qr-verification.index', 'active' => 'admin.qr-verification.*'],
            ['label' => 'Request Status Management', 'icon' => 'status'],
            ['label' => 'Services & Document Management', 'icon' => 'services', 'route' => 'admin.services.index', 'active' => 'admin.services.*'],
            ['label' => 'User Management', 'icon' => 'users', 'route' => 'admin.users.index', 'active' => 'admin.users.*'],
            ['label' => 'Reservation Logs', 'icon' => 'history', 'route' => 'admin.reservation-logs.index', 'active' => 'admin.reservation-logs.*'],
            ['label' => 'Reservation Reports', 'icon' => 'document', 'route' => 'admin.reports.reservations', 'active' => 'admin.reports.*'],
        ],
    ],
];
