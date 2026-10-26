@props(['statuses', 'counts', 'total'])
<section class="card dashboard-panel" aria-labelledby="status-overview-heading">
    <div class="dashboard-panel-heading"><h2 id="status-overview-heading">Request overview</h2><span class="panel-caption">{{ number_format($total) }} total</span></div>
    <dl class="dashboard-status-list mb-0">
        @foreach($statuses as $status)
            <div style="--status-share: {{ $total > 0 ? min(100, 100 * $counts[$status->value] / $total) : 0 }}%"><dt><x-reservation-status :status="$status"/></dt><dd>{{ number_format($counts[$status->value]) }}</dd></div>
        @endforeach
    </dl>
</section>
