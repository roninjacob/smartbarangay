@props(['tone' => 'default'])
@php
    // Verified, active Admins and Residents save to their existing account preference (settings.update).
    // Everyone else keeps the choice in this browser only.
    $themeUser = auth()->user();
    $saveUrl = $themeUser && $themeUser->role instanceof \App\Enums\UserRole && $themeUser->is_active && $themeUser->hasVerifiedEmail()
        ? route($themeUser->role->value.'.settings.update') : null;
@endphp
<button type="button" {{ $attributes->class(['theme-toggle', 'theme-toggle-'.$tone]) }} data-theme-toggle
    @if($saveUrl) data-theme-save-url="{{ $saveUrl }}" @endif
    aria-pressed="{{ ($themeUser?->preferredTheme() ?? 'light') === 'dark' ? 'true' : 'false' }}" aria-label="Dark mode" title="Toggle dark mode">
    <svg class="theme-toggle-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/></svg>
    <svg class="theme-toggle-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
</button>
