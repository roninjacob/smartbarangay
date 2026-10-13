@if($documentTemplate)
    <h3 class="h6 mt-4">Resident information</h3>
    <dl class="reservation-summary document-information-summary">
        <div><dt>Name</dt><dd>{{ $residentInformation['resident_name'] }}</dd></div>
        <div><dt>Address</dt><dd>{{ $residentInformation['resident_address'] ?? 'Not provided' }}</dd></div>
        <div><dt>Contact</dt><dd>{{ $residentInformation['resident_contact'] ?? 'Not provided' }}</dd></div>
    </dl>
    <h3 class="h6 mt-4">Document information</h3>
    <dl class="reservation-summary document-information-summary">
        @foreach($documentTemplate->fields as $field)
            <div><dt>{{ $field->label }}</dt><dd>{{ ($field->source === 'system' ? ($residentInformation[$field->field_name] ?? null) : ($draft['document_answers'][$field->id] ?? null)) ?? 'Not provided' }}</dd></div>
        @endforeach
    </dl>
    <a class="btn btn-outline-secondary" href="{{ route('resident.reservations.requirements') }}">Edit document information</a>
@endif
