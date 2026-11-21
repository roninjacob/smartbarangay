<section class="card dashboard-panel rv-section" aria-labelledby="activity-heading">
    <div class="dashboard-panel-heading">
        <h2 id="activity-heading"><span class="panel-icon is-indigo" aria-hidden="true"><x-app-icon name="history"/></span>Reservation Activity</h2>
        <a class="panel-link" href="{{ route('admin.reservation-logs.index', ['search' => '#'.$reservation->id]) }}">Activity Logs <x-app-icon name="arrow"/></a>
    </div>
    <div class="rv-section-body">
        <p class="app-note">Oldest first · Philippine time. Only recorded activities are shown.</p>
        @if($activities->isEmpty())<p class="app-note mb-0">No recorded activities are available.</p>@else
            <ol class="rv-timeline" aria-label="Reservation activity timeline">
                @foreach($activities as $activity)<li>@include('admin.reservations.activity-item')</li>@endforeach
            </ol>
        @endif
    </div>
</section>
