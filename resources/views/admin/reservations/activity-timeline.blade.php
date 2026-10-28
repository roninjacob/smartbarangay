<section class="card dashboard-panel reservation-panel mt-4" aria-labelledby="activity-heading">
    <div class="activity-section-heading"><h2 id="activity-heading">Reservation Activity</h2><a class="workspace-action" href="{{ route('admin.reservation-logs.index', ['search' => '#'.$reservation->id]) }}">View in Reservation Logs</a></div>
    <p class="app-note">Oldest first · Philippine time. Only recorded activities are shown.</p>
    @if($activities->isEmpty())<p class="text-secondary mb-0">No recorded activities are available.</p>@else
        <ol class="reservation-activity" aria-label="Reservation activity timeline">
            @foreach($activities as $activity)<li>@include('admin.reservations.activity-item')</li>@endforeach
        </ol>
    @endif
</section>
