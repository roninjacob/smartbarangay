@props(['label', 'value', 'icon' => 'document', 'note' => '', 'tone' => 'blue', 'formatted' => null])
<article {{ $attributes->class(['card dashboard-stat', 'stat-'.$tone]) }} aria-label="{{ $label }}: {{ $formatted ?? number_format($value) }}">
    <div class="card-body">
        <div class="stat-heading"><h2>{{ $label }}</h2><span class="stat-icon"><x-app-icon :name="$icon"/></span></div>
        <p class="stat-value text-break">{{ $formatted ?? number_format($value) }}</p>
        <p class="stat-note mb-0">{{ $note }}</p>
    </div>
</article>
