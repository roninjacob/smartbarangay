@props(['label', 'value', 'icon' => 'document', 'note' => '', 'tone' => 'blue', 'formatted' => null])
<article {{ $attributes->class(['card dashboard-stat', 'stat-'.$tone]) }} aria-label="{{ $label }}: {{ $formatted ?? number_format($value) }}">
    <div class="card-body">
        <span class="stat-icon" aria-hidden="true"><x-app-icon :name="$icon"/></span>
        <div class="stat-text">
            <h2>{{ $label }}</h2>
            <p class="stat-value text-break" @if($formatted === null && is_numeric($value)) data-count-to="{{ (int) $value }}" @endif>{{ $formatted ?? number_format($value) }}</p>
            @if($note !== '')<p class="stat-note mb-0">{{ $note }}</p>@endif
        </div>
    </div>
</article>
