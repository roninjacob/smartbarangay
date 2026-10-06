@props(['name' => 'document'])
<svg {{ $attributes->class(['app-icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('dashboard')
            <rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>
            @break
        @case('calendar')
            <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 11h18M8 15h2M14 15h2M8 18h2"/>
            @break
        @case('qr')
            <path d="M3 3h6v6H3ZM15 3h6v6h-6ZM3 15h6v6H3ZM15 15h3v3h-3ZM21 14v4M14 21h4M21 21h-1"/>
            @break
        @case('plus')
            <rect x="3" y="3" width="18" height="18" rx="4"/><path d="M12 8v8M8 12h8"/>
            @break
        @case('status')
            <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
            @break
        @case('check')
            <circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>
            @break
        @case('users')
            <circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6M17 14a5 5 0 0 1 4 5v2"/>
            @break
        @case('account')
            <circle cx="12" cy="8" r="4"/><path d="M4 21v-1a8 8 0 0 1 16 0v1"/>
            @break
        @case('services')
            <path d="m3 9 9-6 9 6M5 10v10M10 10v10M14 10v10M19 10v10M3 21h18"/>
            @break
        @case('history')
            <path d="M3 11a9 9 0 1 1 2 7M3 5v6h6M12 7v5l3 2"/>
            @break
        @case('logout')
            <path d="M9 4H4v16h5M9 12h12m-4-4 4 4-4 4"/>
            @break
        @case('menu')
            <path d="M4 6h16M4 12h16M4 18h16"/>
            @break
        @case('arrow')
            <path d="M5 12h14m-5-5 5 5-5 5"/>
            @break
        @default
            <path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8l-5-5ZM14 3v5h5M8 12h8M8 16h6"/>
    @endswitch
</svg>
