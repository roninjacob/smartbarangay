@props(['reservations', 'admin' => false])
<section class="card dashboard-panel recent-panel" aria-labelledby="recent-reservations-heading">
    <div class="dashboard-panel-heading">
        <h2 id="recent-reservations-heading">Recent reservations</h2>
        <a class="panel-link" href="{{ route($admin ? 'admin.reservations.index' : 'resident.reservations.index') }}">View all <x-app-icon name="arrow"/></a>
    </div>
    @if($reservations->isEmpty())
        <div class="dashboard-empty">
            <span class="empty-icon"><x-app-icon name="document"/></span>
            <h3>No reservations yet</h3>
            <p>{{ $admin ? 'New document requests from residents will show up here.' : 'Your document requests will show up here.' }}</p>
            @if(! $admin && auth()->user()->canSubmitReservations())
                <a class="btn btn-primary btn-sm" href="{{ route('resident.reservations.create') }}"><x-app-icon name="plus"/> New reservation</a>
            @endif
        </div>
    @elseif(! $admin)
        <div class="resident-recent-cards">
            @foreach($reservations as $reservation)
                <article class="resident-recent-card" aria-labelledby="recent-request-{{ $reservation->id }}">
                    <div class="resident-recent-top">
                        <span class="reservation-reference">Request #{{ $reservation->id }} · {{ $reservation->created_at->timezone('Asia/Manila')->format('M j, Y') }}</span>
                        <x-reservation-status :status="$reservation->status"/>
                    </div>
                    <h3 id="recent-request-{{ $reservation->id }}">{{ $reservation->service->name }}</h3>
                    <p>@switch($reservation->status)
                        @case(\App\Enums\ReservationStatus::Pending) Submitted and waiting for review. @break
                        @case(\App\Enums\ReservationStatus::UnderReview) The barangay office is reviewing your request. @break
                        @case(\App\Enums\ReservationStatus::Approved) Approved. Wait for Ready for Pickup before collecting your document. @break
                        @case(\App\Enums\ReservationStatus::ReadyForPickup) Ready. Bring your QR ticket when you visit. @break
                        @case(\App\Enums\ReservationStatus::Completed) Completed. The details stay available for your records. @break
                        @default Open the request to see its status and details.
                    @endswitch</p>
                    <div class="resident-recent-foot">
                        <span class="resident-recent-appointment"><x-app-icon name="calendar"/><time datetime="{{ $reservation->appointment->date->toDateString() }}">{{ $reservation->appointment->date->format('M j, Y') }}</time> · {{ \Illuminate\Support\Carbon::parse($reservation->appointment->start_time)->format('g:i A') }}&ndash;{{ \Illuminate\Support\Carbon::parse($reservation->appointment->end_time)->format('g:i A') }}</span>
                        <a href="{{ route('resident.reservations.show', $reservation) }}" class="panel-link" aria-label="View request #{{ $reservation->id }}">View <x-app-icon name="arrow"/></a>
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <div class="table-responsive" tabindex="0" role="region" aria-label="Recent reservations table">
            <table class="table dashboard-table recent-reservations-table align-middle mb-0">
                <caption class="visually-hidden">Latest six barangay reservations</caption>
                <thead><tr><th scope="col">Resident</th><th scope="col">Service</th><th scope="col">Appointment</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Action</span></th></tr></thead>
                <tbody>
                    @foreach($reservations as $reservation)
                        <tr>
                            <th scope="row"><div class="recent-person"><x-resident-avatar :user="$reservation->user" :initials="true"/><div><span class="recent-resident-name">{{ $reservation->user->name }}</span><span class="reservation-reference">Request #{{ $reservation->id }}</span></div></div></th>
                            <td data-label="Service"><span class="reservation-service">{{ $reservation->service->name }}</span></td>
                            <td class="recent-appointment" data-label="Appointment"><time datetime="{{ $reservation->appointment->date->toDateString() }}">{{ $reservation->appointment->date->format('M j, Y') }}</time><span class="reservation-reference">{{ \Illuminate\Support\Carbon::parse($reservation->appointment->start_time)->format('g:i A') }} &ndash; {{ \Illuminate\Support\Carbon::parse($reservation->appointment->end_time)->format('g:i A') }}</span></td>
                            <td data-label="Status"><x-reservation-status :status="$reservation->status"/></td>
                            <td class="recent-action"><a class="recent-open" href="{{ route('admin.reservations.show', $reservation) }}" aria-label="View request #{{ $reservation->id }}"><span class="recent-action-label visually-hidden">View</span><x-app-icon name="arrow"/></a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
