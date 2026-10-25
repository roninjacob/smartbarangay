<section class="dashboard-panel p-3 p-md-4 mt-4" id="resident-demographics" aria-labelledby="demographic-heading">
    <span class="app-eyebrow">YOUR RESIDENT PROFILE</span>
    <h2 class="h4" id="demographic-heading">Demographic information</h2>
    <p class="text-body-secondary">Complete the information needed for your document requests. Fields are optional here; a document may require specific information. Updates apply to new requests and do not rewrite existing requests.</p>
    <p class="small text-body-secondary">Street and zone/purok do not replace your account's existing address. Emergency contacts stay in your private profile and are not mapped to certificates.</p>
    @if($errors->any())
        <div class="alert alert-danger" role="alert"><strong>Your profile was not saved.</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <form method="POST" action="{{ route('resident.profile.demographics.update') }}" data-service-submit>
        @csrf @method('PUT')
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="birthdate">Birthdate</label>
                @php($birthdateValue = old('birthdate', $residentProfile?->birthdate?->toDateString()))
                <input id="birthdate" type="date" name="birthdate" class="form-control @error('birthdate') is-invalid @enderror" value="{{ is_string($birthdateValue) ? $birthdateValue : '' }}" max="{{ now('Asia/Manila')->toDateString() }}" autocomplete="bday" @error('birthdate') aria-invalid="true" aria-describedby="birthdate-error" @enderror>
                @error('birthdate')<div class="invalid-feedback" id="birthdate-error">{{ $message }}</div>@enderror
            </div>
            @foreach(['civil_status' => 'Civil status', 'occupation' => 'Occupation', 'address_street' => 'Street / house address', 'address_zone_purok' => 'Zone / Purok', 'emergency_contact_name' => 'Emergency contact name', 'emergency_contact_number' => 'Emergency contact number'] as $field => $label)
                <div class="col-md-6">
                    <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                    @php($fieldValue = old($field, $residentProfile?->{$field}))
                    <input id="{{ $field }}" type="{{ $field === 'emergency_contact_number' ? 'tel' : 'text' }}" name="{{ $field }}" class="form-control @error($field) is-invalid @enderror" value="{{ is_string($fieldValue) ? $fieldValue : '' }}" maxlength="{{ \App\Models\ResidentProfile::TEXT_LENGTHS[$field] }}" @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>
                    @error($field)<div class="invalid-feedback" id="{{ $field }}-error">{{ $message }}</div>@enderror
                </div>
            @endforeach
        </div>
        <div class="d-flex flex-wrap gap-3 align-items-center mt-4">
            <button type="submit" class="btn btn-primary" data-saving-label="Saving…">Save resident profile</button>
            @if(session('reservation_wizard.resident_id') === auth()->id() && session('reservation_wizard.service_id'))
                <a class="btn btn-outline-primary" href="{{ route('resident.reservations.requirements') }}">Return to document form</a>
            @else
                <a class="btn btn-outline-primary" href="{{ route('resident.reservations.create') }}">Start a document request</a>
            @endif
        </div>
    </form>
</section>
