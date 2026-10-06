@if($service->serviceRequirements->isEmpty())
    <p class="reservation-notice">No requirements have been listed for this service. Contact the barangay office if you need guidance on what to prepare.</p>
@else
    <ul class="reservation-requirements">
        @foreach($service->serviceRequirements as $requirement)
            <li><div><strong>{{ $requirement->name }}</strong><span class="requirement-label">{{ $requirement->is_required ? 'Required' : 'Optional' }}</span></div>@if($requirement->description)<p>{{ $requirement->description }}</p>@endif</li>
        @endforeach
    </ul>
@endif
