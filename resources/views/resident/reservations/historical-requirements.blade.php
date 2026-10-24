@if($reservation->requirements_snapshotted_at)
    <h3 class="h6 mt-4">Requirements at submission</h3>
    @if($reservation->requirementSnapshots->isEmpty())
        <p class="app-note">No requirements were listed when this request was submitted.</p>
    @else
        <ul class="reservation-requirements">
            @foreach($reservation->requirementSnapshots as $requirement)
                <li><strong>{{ $requirement->name }}</strong> <span class="requirement-label">{{ $requirement->is_required ? 'Required' : 'Optional' }}</span>
                    @if($requirement->description)<p>{{ $requirement->description }}</p>@endif
                </li>
            @endforeach
        </ul>
    @endif
@else
    <h3 class="h6 mt-4">Current service requirements</h3>
    <p class="app-note">This legacy request has no preserved requirement checklist. The following is current service information, not its original submission requirements.</p>
    @include('resident.reservations.requirements-list', ['service' => $reservation->service])
@endif
