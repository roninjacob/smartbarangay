@props(['type' => 'mail'])
<span @class(['au-symbol', 'au-symbol-'.$type]) aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false">
        @switch($type)
            @case('lock')
                <rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>
                @break
            @case('clock')
                <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
                @break
            @case('warning')
                <path d="M10.3 3.9 2.4 18a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>
                @break
            @default
                <rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>
        @endswitch
    </svg>
</span>
