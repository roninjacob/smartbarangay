@extends('layouts.authenticated')
@section('dashboard')
<div class="reservation-reports">
    <section class="dashboard-panel p-3 p-md-4" aria-labelledby="report-scope">
        <h2 id="report-scope" class="h5">Recorded submissions excluding reviewed acceptance fixtures.</h2>
        <p class="text-secondary">Submission-period report · Philippine time (Asia/Manila). Statuses reflect the current state of these requests, not transitions during the period.</p>
        @if($errors->any())<div class="alert alert-danger" role="alert">Please correct the filters. No report totals are shown.<ul class="mb-0">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
        <form method="GET" action="{{ route('admin.reports.reservations') }}" class="row g-3 align-items-start">
            <div class="col-12 col-md-6 col-xl-3"><label for="report-period" class="form-label">Reporting period</label><select class="form-select" id="report-period" name="period">@foreach($modes as $value => $label)<option value="{{ $value }}" @selected(old('period', $filters['period']) === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-12 col-md-6 col-xl-3"><label for="report-date" class="form-label">Specific date</label><input class="form-control" type="date" id="report-date" name="date" value="{{ old('date', $filters['date'] ?? '') }}"><small class="text-secondary">Used with Specific Date.</small></div>
            <div class="col-12 col-sm-6 col-xl-3"><label for="report-start" class="form-label">Range start</label><input class="form-control" type="date" id="report-start" name="start" value="{{ old('start', $filters['start'] ?? '') }}"></div>
            <div class="col-12 col-sm-6 col-xl-3"><label for="report-end" class="form-label">Range end (inclusive)</label><input class="form-control" type="date" id="report-end" name="end" value="{{ old('end', $filters['end'] ?? '') }}"></div>
            <div class="col-12 report-filter-actions"><button class="btn btn-primary" type="submit">Apply filters</button><a class="btn btn-outline-secondary" href="{{ route('admin.reports.reservations') }}">Reset to This Month</a><span class="text-secondary">Custom dates apply only to Custom Date Range. Weeks run Monday–Sunday.</span></div>
        </form>
    </section>
    @if($reportError)<div class="alert alert-warning mt-3" role="alert">{{ $reportError }}</div>@endif
    @if($report)
        <p class="report-period mt-4"><strong>{{ $period['start']->format('M j, Y') }} – {{ $period['end']->format('M j, Y') }}</strong> · Philippine time<br><span class="text-secondary">{{ $report['excluded'] }} reviewed fixture {{ $report['excluded'] === 1 ? 'exclusion' : 'exclusions' }} within this period.</span></p>
        <section class="row g-3" aria-label="Submitted request summary">
            <div class="col-12"><x-dashboard-stat label="Total Requests Submitted" :value="$report['total']" icon="document" note="Submitted within the selected period"/></div>
            @foreach($statuses as $status)<div class="col-6 col-md-4 col-xl-3"><x-dashboard-stat :label="$status->label()" :value="$report['counts'][$status->value]" icon="status" note="Current status of selected submissions"/></div>@endforeach
        </section>
        <div class="row g-3 mt-1">
            <section class="col-12 col-xl-7"><div class="dashboard-panel p-3 p-md-4 h-100">
                <h2 class="h5">Requests Submitted Over Time</h2><p class="text-secondary">{{ ucfirst($report['bucket']) }} buckets · Philippine time · Missing buckets count as zero.</p>
                @if($report['total'] === 0)<p class="report-empty">No submissions in this period.</p>@else
                    @php
                        $series = $report['series']; $peak = max(1, max(array_column($series, 'count'))); $last = max(1, count($series) - 1);
                        $points = implode(' ', array_map(fn ($row, $i) => (55 + $i * 590 / $last).','. (215 - $row['count'] * 170 / $peak), $series, array_keys($series)));
                    @endphp
                    <svg class="report-line" viewBox="0 0 680 290" role="img" aria-labelledby="submission-chart-title submission-chart-desc">
                        <title id="submission-chart-title">Requests submitted over time</title><desc id="submission-chart-desc">{{ $report['total'] }} submissions. Highest bucket: {{ $peak }}. Exact dates and counts follow in the chart data table.</desc>
                        <path d="M55 35 V215 H650" fill="none" stroke="#6B7280"/>
                        @foreach([0, 1, 2] as $tick)<text x="45" y="{{ 219 - $tick * 85 }}" text-anchor="end">{{ round($peak * $tick / 2, 1) }}</text>@endforeach
                        <polyline points="{{ $points }}" fill="none" stroke="#1F5EA8" stroke-width="3"/>
                        <text x="55" y="243">{{ $series[0]['label'] }}</text><text x="645" y="264" text-anchor="end">{{ $series[count($series)-1]['label'] }}</text>
                        <text x="350" y="286" text-anchor="middle">Philippine time</text>
                    </svg>
                    <details><summary>View exact chart dates and counts</summary><div class="report-chart-data"><table class="table table-sm"><caption>Submissions per {{ $report['bucket'] }} in Philippine time</caption><thead><tr><th scope="col">Date / time</th><th scope="col">Requests</th></tr></thead><tbody>@foreach($series as $row)<tr><th scope="row">{{ $row['label'] }}</th><td>{{ $row['count'] }}</td></tr>@endforeach</tbody></table></div></details>
                @endif
            </div></section>
            <section class="col-12 col-xl-5"><div class="dashboard-panel p-3 p-md-4 h-100"><h2 class="h5">Most Requested Services</h2><p class="text-secondary">Top 5 by submissions. Names reflect the current linked Service records.</p>
                @if($report['services']->isEmpty())<p class="report-empty">No services to rank in this period.</p>@else
                    <ol class="report-services">@foreach($report['services'] as $service)<li><div class="report-bar-label"><span>{{ $service->name }}</span><strong>{{ $service->total }} {{ (int) $service->total === 1 ? 'request' : 'requests' }}</strong></div><div class="report-bar-track" aria-hidden="true"><div style="width: {{ 100 * $service->total / $report['services']->first()->total }}%"></div></div></li>@endforeach</ol>
                    <p class="small text-secondary mb-0">Ties ordered by service record. Top 5 may represent only part of the total.</p>
                @endif
            </div></section>
        </div>
        <section class="dashboard-panel p-3 p-md-4 mt-3" aria-labelledby="report-table-title"><h2 class="h5" id="report-table-title">Submitted Requests</h2>
            @if($report['reservations']->isEmpty())<p class="report-empty">No requests on this page. Choose another period or return to the first page.</p>@endif
            <div class="table-responsive report-table" tabindex="0" role="region" aria-label="Submitted requests; scroll horizontally on small screens">
                <table class="table align-middle"><caption>Newest submissions first. Current status, not historical period-end status.</caption><thead><tr><th scope="col">Request</th><th scope="col">Resident</th><th scope="col">Service</th><th scope="col">Submitted (Philippine time)</th><th scope="col">Current status</th><th scope="col">Action</th></tr></thead><tbody>
                @foreach($report['reservations'] as $reservation)<tr><th scope="row">Request #{{ $reservation->id }}</th><td>{{ $reservation->user?->name ?? 'Former account' }}</td><td>{{ $reservation->service?->name ?? 'Unavailable service' }}</td><td>{{ $reservation->created_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</td><td>{{ $reservation->status->label() }}</td><td><a class="btn btn-outline-primary btn-sm" href="{{ route('admin.reservations.show', $reservation) }}" aria-label="View Request #{{ $reservation->id }}">View details</a></td></tr>@endforeach
                </tbody></table>
            </div>
            <div class="report-pagination"><span>{{ $report['reservations']->total() }} matching requests</span>{{ $report['reservations']->links('pagination::bootstrap-5') }}</div>
        </section>
    @endif
</div>
@endsection
