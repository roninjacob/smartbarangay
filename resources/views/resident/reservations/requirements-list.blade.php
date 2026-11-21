@if($service->serviceRequirements->isEmpty())
    <p class="app-note">No requirements have been listed for this service. Contact the barangay office if you need guidance on what to prepare.</p>
@else
    <ul class="rv-requirements">
        @foreach($service->serviceRequirements as $requirement)
            <li><strong>{{ $requirement->name }}</strong> <span @class(['rv-tag', 'is-required' => $requirement->is_required])>{{ $requirement->is_required ? 'Required' : 'Optional' }}</span>@if($requirement->description)<p>{{ $requirement->description }}</p>@endif</li>
        @endforeach
    </ul>
@endif
