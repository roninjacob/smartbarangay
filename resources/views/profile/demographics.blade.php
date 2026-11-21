<section class="card dashboard-panel" id="resident-demographics" aria-labelledby="demographic-heading">
    <div class="dashboard-panel-heading"><div><h2 id="demographic-heading"><span class="panel-icon is-gold" aria-hidden="true"><x-app-icon name="document"/></span>Demographic information</h2><p class="panel-description">Optional here; a document may require specific information.</p></div></div>
    <div class="ac-card-body">
        <p class="app-note mt-0">Updates apply to new requests and do not rewrite existing requests. Emergency contacts stay private and are not mapped to certificates.</p>
        @if($errors->any())
            <div class="alert alert-danger" role="alert"><strong>Your profile was not saved.</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <form method="POST" action="{{ route('resident.profile.demographics.update') }}" data-service-submit>
            @csrf @method('PUT')
            <div class="row g-3">
                @foreach(['civil_status' => 'Civil status', 'occupation' => 'Occupation', 'emergency_contact_name' => 'Emergency contact name', 'emergency_contact_number' => 'Emergency contact number'] as $field => $label)
                    <div class="col-md-6">
                        <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                        @php($fieldValue = old($field, $residentProfile?->{$field}))
                        <input id="{{ $field }}" type="{{ $field === 'emergency_contact_number' ? 'tel' : 'text' }}" name="{{ $field }}" class="form-control @error($field) is-invalid @enderror" value="{{ is_string($fieldValue) ? $fieldValue : '' }}" maxlength="{{ \App\Models\ResidentProfile::TEXT_LENGTHS[$field] }}" @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>
                        @error($field)<div class="invalid-feedback" id="{{ $field }}-error">{{ $message }}</div>@enderror
                    </div>
                @endforeach
            </div>
            <div class="ac-form-actions">
                @if($user->canSubmitReservations() && session('reservation_wizard.resident_id') === auth()->id() && session('reservation_wizard.service_id'))
                    <a class="btn btn-outline-primary" href="{{ route('resident.reservations.requirements') }}">Return to document form</a>
                @elseif($user->canSubmitReservations())
                    <a class="btn btn-outline-primary" href="{{ route('resident.reservations.create') }}">Start a document request</a>
                @endif
                <button type="submit" class="btn btn-primary" data-saving-label="Saving…">Save resident profile</button>
            </div>
        </form>
    </div>
</section>
