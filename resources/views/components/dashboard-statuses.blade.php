@props(['statuses', 'counts', 'total'])
@php($largest = max(1, ...array_values($counts)))
<section class="card dashboard-panel status-bars-panel" aria-labelledby="status-overview-heading">
    <div class="dashboard-panel-heading"><h2 id="status-overview-heading">Requests by status</h2><span class="panel-count">{{ number_format($total) }} total</span></div>
    <dl class="status-bars mb-0" data-status-bars>
        @foreach($statuses as $status)
            <div class="status-bar status-bar-{{ $status->value }}" style="--bar: {{ round(100 * $counts[$status->value] / $largest, 2) }}%">
                <dt>{{ $status->label() }}</dt>
                <dd>{{ number_format($counts[$status->value]) }}</dd>
                <span class="status-bar-track" aria-hidden="true"><span></span></span>
            </div>
        @endforeach
    </dl>
</section>
