@php
    $identityUser = $identityUser ?? null;
    $identityProfile = $identityProfile ?? null;
    $identityFields = [
        'first_name' => ['First name', true, 80, 'given-name'],
        'middle_name' => ['Middle name', false, 80, 'additional-name'],
        'last_name' => ['Last name', true, 80, 'family-name'],
        'suffix' => ['Suffix', false, 20, 'honorific-suffix'],
        'birthdate' => ['Date of birth', true, null, 'bday'],
        'contact_number' => ['Contact number', true, 30, 'tel'],
        'house_unit_number' => ['House / Unit number', false, 80, 'address-line1'],
        'address_street' => ['Street / Sitio / Purok', true, 255, 'address-line2'],
        'address_zone_purok' => ['Additional zone / purok', false, 120, null],
    ];
@endphp
<fieldset class="mb-4">
    <legend class="h5 mb-3">Resident information</legend>
    <p class="form-text mb-3">Use your name and residential address as recorded on your supporting documents. Optional fields may be left blank.</p>
    <div class="row g-3">
        @foreach($identityFields as $field => [$label, $required, $length, $autocomplete])
            @php
                $stored = in_array($field, [...\App\Services\ResidentIdentity::NAME_FIELDS, 'contact_number']) ? $identityUser?->$field : $identityProfile?->$field;
                $stored = $stored instanceof \Carbon\CarbonInterface ? $stored->toDateString() : $stored;
                $inputValue = old($field, $stored);
            @endphp
            <div class="col-md-6">
                <label for="{{ $field }}" class="form-label">{{ $label }}@unless($required)<span class="text-secondary fw-normal"> (optional)</span>@endunless</label>
                <input id="{{ $field }}" name="{{ $field }}" type="{{ $field === 'birthdate' ? 'date' : ($field === 'contact_number' ? 'tel' : 'text') }}"
                    value="{{ is_string($inputValue) ? $inputValue : '' }}" @class(['form-control', 'is-invalid' => $errors->has($field)])
                    @required($required) @if($length) maxlength="{{ $length }}" @endif
                    @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif
                    @if($field === 'birthdate') max="{{ now('Asia/Manila')->toDateString() }}" @endif
                    @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>
                @error($field)<div class="invalid-feedback" id="{{ $field }}-error">{{ $message }}</div>@enderror
            </div>
        @endforeach
        <div class="col-12"><p class="form-text mb-0"><strong>Barangay Calayo, Nasugbu, Batangas</strong><br>Your barangay, municipality and province are fixed for SmartBarangay.</p></div>
    </div>
</fieldset>
