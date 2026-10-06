@props(['reservations', 'admin' => false])
<section class="card dashboard-panel recent-panel" aria-labelledby="recent-reservations-heading">
    <div class="dashboard-panel-heading"><div><h2 id="recent-reservations-heading">Recent reservations</h2><p class="panel-description">{{ $admin ? 'Latest requests submitted to Barangay Calayo.' : 'Your latest document requests, at a glance.' }}</p></div><span class="panel-caption">Latest 6</span></div>
    @if($reservations->isEmpty())
        <div class="dashboard-empty">
            <span class="empty-icon"><x-app-icon name="document"/></span>
            <h3>No reservations yet</h3>
            <p>{{ $admin ? 'When residents submit document requests, their latest reservations will appear here.' : 'Your document requests will appear here. Choose New Reservation to submit your first request.' }}</p>
            <span class="empty-footnote">{{ $admin ? 'There are no requests to review right now.' : 'Your account is ready. Thank you for joining SmartBarangay.' }}</span>
        </div>
    @else
        <div class="table-responsive" tabindex="0" role="region" aria-label="Recent reservations table">
            <table class="table dashboard-table align-middle mb-0">
                <caption class="visually-hidden">{{ $admin ? 'Latest six barangay reservations' : 'Your latest six reservations' }}</caption>
                <thead><tr><th scope="col">Document / Service</th>@if($admin)<th scope="col">Resident</th>@endif<th scope="col">Appointment</th><th scope="col">Status</th></tr></thead>
                <tbody>
                    @foreach($reservations as $reservation)
                        <tr>
                            <th scope="row"><span class="reservation-service">{{ $reservation->service->name }}</span><span class="reservation-reference">Request #{{ $reservation->id }} · {{ $reservation->created_at->timezone('Asia/Manila')->format('M j, Y') }}</span></th>
                            @if($admin)<td><span class="reservation-resident" title="{{ $reservation->user->name }}">{{ $reservation->user->name }}</span></td>@endif
                            <td><time datetime="{{ $reservation->schedule->date->toDateString() }}">{{ $reservation->schedule->date->format('M j, Y') }}</time><span class="reservation-reference">{{ substr($reservation->schedule->start_time, 0, 5) }}–{{ substr($reservation->schedule->end_time, 0, 5) }}</span></td>
                            <td><x-reservation-status :status="$reservation->status"/></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
