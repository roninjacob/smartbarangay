@if($documentTemplate)
    <input type="hidden" name="document_signature" value="{{ $documentSignature }}">
    <fieldset class="mt-4">
        <legend class="h5">Resident information</legend>
        <p class="app-note">System information comes from your profile. <a href="{{ route('resident.profile.edit') }}">Update your profile</a> if anything needs correcting, then return to this form.</p>
        @if(!empty($missingProfileFields))
            <div class="alert alert-warning" role="alert">
                <strong>Complete the profile information required for this document.</strong>
                <p class="mb-2 mt-2">Missing: {{ implode(', ', $missingProfileFields) }}.</p>
                <a class="btn btn-outline-primary" href="{{ route('resident.profile.edit') }}">Complete required profile information</a>
            </div>
        @endif
        <dl class="reservation-summary document-information-summary">
            @foreach($documentTemplate->fields->where('source', 'system') as $field)
                <div><dt>{{ $field->label }}</dt><dd>{{ $residentInformation[$field->field_name] ?? 'Not provided' }}</dd></div>
                @error('document_answers.'.$field->id)<div><dt>Needs attention</dt><dd class="text-danger">{{ $message }}</dd></div>@enderror
            @endforeach
            @if($documentTemplate->fields->where('source', 'system')->isEmpty())
                <div><dt>Name</dt><dd>{{ $residentInformation['resident_name'] }}</dd></div>
                <div><dt>Address</dt><dd>{{ $residentInformation['resident_address'] ?? 'Not provided' }}</dd></div>
                <div><dt>Contact</dt><dd>{{ $residentInformation['resident_contact'] ?? 'Not provided' }}</dd></div>
            @endif
        </dl>
    </fieldset>
    @if($documentTemplate->fields->where('source', 'custom')->isNotEmpty())
        <fieldset class="mt-4">
            <legend class="h5">Document information</legend>
            <p class="app-note">Complete the fields configured for this service. Required fields are marked below.</p>
            @foreach($documentTemplate->fields->where('source', 'custom') as $field)
                @php($value = old('document_answers.'.$field->id, $draft['document_answers'][$field->id] ?? ''))
                @php($value = is_scalar($value) ? $value : '')
                <div class="mb-4">
                    <label class="form-label" for="document-field-{{ $field->id }}">{{ $field->label }} <span class="requirement-label">{{ $field->is_required ? 'Required' : 'Optional' }}</span></label>
                    @if($field->field_type === \App\Enums\DocumentFieldType::Dropdown)
                        <select id="document-field-{{ $field->id }}" name="document_answers[{{ $field->id }}]" class="form-select @error('document_answers.'.$field->id) is-invalid @enderror" @required($field->is_required) aria-describedby="document-error-{{ $field->id }}">
                            <option value="">Choose an option</option>
                            @foreach($field->options ?? [] as $option)<option value="{{ $option }}" @selected((string)$value === $option)>{{ $option }}</option>@endforeach
                        </select>
                    @else
                        <input id="document-field-{{ $field->id }}" name="document_answers[{{ $field->id }}]" value="{{ $value }}" type="{{ $field->field_type->value }}" class="form-control @error('document_answers.'.$field->id) is-invalid @enderror" @required($field->is_required) aria-describedby="document-error-{{ $field->id }}"
                            @if($field->field_type === \App\Enums\DocumentFieldType::Text) maxlength="{{ $field->validation_rules['max_length'] ?? 1000 }}" @endif
                            @if($field->field_type === \App\Enums\DocumentFieldType::Number) step="any" min="{{ $field->validation_rules['min'] ?? -1000000000 }}" max="{{ $field->validation_rules['max'] ?? 1000000000 }}" @endif>
                    @endif
                    @error('document_answers.'.$field->id)<p class="invalid-feedback" id="document-error-{{ $field->id }}" role="alert">{{ $message }}</p>@else<span id="document-error-{{ $field->id }}"></span>@enderror
                </div>
            @endforeach
        </fieldset>
    @endif
@endif
