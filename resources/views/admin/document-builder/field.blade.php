@extends('layouts.authenticated')
@section('dashboard')
<a href="{{ route('admin.services.document-configuration.index', $service) }}" class="service-back-link">&larr; Back to document configuration</a>
<section class="dashboard-panel builder-panel" aria-labelledby="field-heading">
    <div class="dashboard-panel-heading"><div class="min-w-0"><h2 id="field-heading">{{ $field->exists ? 'Edit document field' : 'Add document field' }}</h2><p class="panel-description">{{ $service->name }}</p></div></div>
    <form method="POST" action="{{ $field->exists ? route('admin.services.document-fields.update', [$service, $field]) : route('admin.services.document-fields.store', $service) }}" class="builder-body" data-builder-field data-service-submit>
        @csrf @if($field->exists) @method('PUT') @endif
        <input type="hidden" name="version" value="{{ $template->version }}">
        @if($errors->any())<div class="alert alert-danger" role="alert"><strong>Please correct these settings.</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="row g-3">
            <div class="col-sm-6"><label for="field-source" class="form-label">Field source</label><select id="field-source" name="source" class="form-select" data-builder-source>@foreach(['custom' => 'Custom input', 'system' => 'System supplied'] as $value => $label)<option value="{{ $value }}" @selected(old('source', $field->source) === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-sm-6"><label for="field-type" class="form-label">Field type</label><select id="field-type" name="field_type" class="form-select" data-builder-type>@foreach($types as $type)<option value="{{ $type->value }}" @selected(old('field_type', $field->field_type->value) === $type->value)>{{ $type->label() }}</option>@endforeach</select></div>
            <div class="col-sm-6"><label for="field-label" class="form-label">Label</label><input id="field-label" class="form-control" name="label" value="{{ is_string(old('label', $field->label)) ? old('label', $field->label) : '' }}" maxlength="150" required></div>
            <div class="col-sm-6"><label for="field-name" class="form-label">Internal name</label><input id="field-name" class="form-control" name="field_name" value="{{ is_string(old('field_name', $field->field_name)) ? old('field_name', $field->field_name) : '' }}" maxlength="64" pattern="[a-z][a-z0-9_]*" required aria-describedby="field-name-help"><p class="form-text" id="field-name-help">Lowercase letters, digits and underscores. For system fields: resident_name, resident_address, resident_contact, date_issued (Date), barangay_name, resident_birthdate (Date), resident_civil_status, resident_occupation, resident_address_street, or resident_address_zone_purok. Demographic system fields come from the Resident profile; emergency contacts are not document fields.</p></div>
            <div class="col-sm-6"><label for="field-required" class="form-label">Required</label><select id="field-required" class="form-select" name="is_required"><option value="0" @selected(!old('is_required', $field->is_required))>Optional</option><option value="1" @selected(old('is_required', $field->is_required))>Required</option></select></div>
            <div class="col-sm-6"><label for="field-order" class="form-label">Display order</label><input id="field-order" type="number" name="sort_order" class="form-control" value="{{ is_scalar(old('sort_order', $field->sort_order)) ? old('sort_order', $field->sort_order) : 0 }}" min="0" max="10000" required></div>
            <div class="col-12" data-builder-for="text"><label for="field-max-length" class="form-label">Maximum length (optional)</label><input id="field-max-length" type="number" class="form-control" name="max_length" min="1" max="1000" value="{{ is_scalar(old('max_length', $field->validation_rules['max_length'] ?? '')) ? old('max_length', $field->validation_rules['max_length'] ?? '') : '' }}"></div>
            <div class="col-sm-6" data-builder-for="number"><label for="field-min" class="form-label">Minimum value (optional)</label><input id="field-min" type="number" step="any" class="form-control" name="min_value" min="-1000000000" max="1000000000" value="{{ is_scalar(old('min_value', $field->validation_rules['min'] ?? '')) ? old('min_value', $field->validation_rules['min'] ?? '') : '' }}"></div>
            <div class="col-sm-6" data-builder-for="number"><label for="field-max" class="form-label">Maximum value (optional)</label><input id="field-max" type="number" step="any" class="form-control" name="max_value" min="-1000000000" max="1000000000" value="{{ is_scalar(old('max_value', $field->validation_rules['max'] ?? '')) ? old('max_value', $field->validation_rules['max'] ?? '') : '' }}"></div>
            <div class="col-12" data-builder-for="dropdown"><label for="field-options" class="form-label">Dropdown options</label><textarea id="field-options" name="options_text" class="form-control" rows="4" maxlength="6000">{{ is_string(old('options_text', implode("\n", $field->options ?? []))) ? old('options_text', implode("\n", $field->options ?? [])) : '' }}</textarea><p class="form-text">One option per line. Up to 50 unique options, 100 characters each.</p></div>
        </div>
        <input type="hidden" name="has_position" value="{{ $position ? 1 : 0 }}">
        @if($position)
            @foreach(['page_number','x_position','y_position','width','height','font_size','alignment','text_behavior'] as $attribute)
                <input type="hidden" name="position[{{ $attribute }}]" value="{{ $position->$attribute }}">
            @endforeach
        @endif
        <p class="app-note mt-4">Save this field, then use Design Document Layout to place it on the document.</p>
        <div class="builder-actions mt-4"><button class="btn btn-primary" type="submit">Save Field</button><a class="btn btn-outline-secondary" href="{{ route('admin.services.document-configuration.index', $service) }}">Cancel</a></div>
    </form>
</section>
@endsection
