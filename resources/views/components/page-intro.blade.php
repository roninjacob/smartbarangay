@props(['subtitle' => null])
{{-- A one-line page subtitle with optional actions on the right; the page H1 lives in the topbar. --}}
<div {{ $attributes->class(['rv-intro']) }}>
    @if($subtitle)<p class="rv-intro-text">{{ $subtitle }}</p>@endif
    @isset($actions)<div class="rv-intro-actions">{{ $actions }}</div>@endisset
</div>
