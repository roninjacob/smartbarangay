@if($documentTemplate)
    <div class="rv-review-head"><h3>Resident and document information</h3><a class="panel-link" href="{{ route('resident.reservations.requirements') }}">Edit document information</a></div>
    <dl class="rv-facts">
        <div><dt>Name</dt><dd>{{ $residentInformation['resident_name'] }}</dd></div>
        <div><dt>Address</dt><dd>{{ $residentInformation['resident_address'] ?? 'Not provided' }}</dd></div>
        <div><dt>Contact</dt><dd>{{ $residentInformation['resident_contact'] ?? 'Not provided' }}</dd></div>
        @foreach($documentTemplate->fields as $field)
            <div><dt>{{ $field->label }}</dt><dd>{{ ($field->source === 'system' ? ($residentInformation[$field->field_name] ?? null) : ($draft['document_answers'][$field->id] ?? null)) ?? 'Not provided' }}</dd></div>
        @endforeach
    </dl>
@endif
