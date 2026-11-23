@extends('layouts.authenticated')
@section('dashboard')
<x-page-intro class="admin-catalog-intro" :subtitle="$service->name">
    <x-slot:actions><a class="btn btn-outline-secondary" href="{{ route('admin.services.requirements.index', $service) }}">Back to requirements</a></x-slot:actions>
</x-page-intro>
<form method="POST" action="{{ $requirement->exists ? route('admin.services.requirements.update', [$service, $requirement]) : route('admin.services.requirements.store', $service) }}" class="admin-editor" data-service-submit>
    @csrf
    @if($requirement->exists) @method('PUT') @endif
    @if($errors->any())<div class="alert alert-danger" role="alert">Please correct the highlighted fields.</div>@endif
    <div class="admin-editor-grid">
        <section class="dashboard-panel rv-section">
            <div class="dashboard-panel-heading"><h2><span class="panel-icon" aria-hidden="true"><x-app-icon name="document"/></span>Document details</h2></div>
            <div class="rv-section-body">
                <div class="admin-form-field">
                    <label for="requirement-name" class="form-label">Requirement Name <span class="service-required">(required)</span></label>
                    <input id="requirement-name" name="name" type="text" @class(['form-control', 'is-invalid' => $errors->has('name')]) value="{{ is_string(old('name', $requirement->name)) ? old('name', $requirement->name) : '' }}" required maxlength="255" aria-describedby="requirement-name-help{{ $errors->has('name') ? ' requirement-name-error' : '' }}" @if($errors->has('name')) aria-invalid="true" @endif>
                    <div id="requirement-name-help" class="form-text">Maximum 255 characters.</div>
                    @error('name')<div id="requirement-name-error" class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="admin-form-field">
                    <label for="requirement-description" class="form-label">Description <span class="service-required">(optional)</span></label>
                    <textarea id="requirement-description" name="description" rows="4" maxlength="5000" @class(['form-control', 'is-invalid' => $errors->has('description')]) aria-describedby="requirement-description-help{{ $errors->has('description') ? ' requirement-description-error' : '' }}" @if($errors->has('description')) aria-invalid="true" @endif>{{ is_string(old('description', $requirement->description)) ? old('description', $requirement->description) : '' }}</textarea>
                    <div id="requirement-description-help" class="form-text">Maximum 5,000 characters.</div>
                    @error('description')<div id="requirement-description-error" class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </section>
        <section class="dashboard-panel rv-section catalog-rule-panel">
            <div class="dashboard-panel-heading"><h2><span class="panel-icon is-gold" aria-hidden="true"><x-app-icon name="check"/></span>Submission rule</h2></div>
            <div class="rv-section-body">
                <div class="admin-form-field">
                    <label for="requirement-needed" class="form-label">Requirement Setting</label>
                    <select id="requirement-needed" name="is_required" @class(['form-select', 'is-invalid' => $errors->has('is_required')]) required aria-describedby="requirement-needed-help{{ $errors->has('is_required') ? ' requirement-needed-error' : '' }}" @if($errors->has('is_required')) aria-invalid="true" @endif>
                        <option value="1" @selected(in_array(old('is_required', $requirement->is_required), [true, 1, '1'], true))>Required</option>
                        <option value="0" @selected(in_array(old('is_required', $requirement->is_required), [false, 0, '0'], true))>Optional</option>
                    </select>
                    <div id="requirement-needed-help" class="form-text">Residents must upload required files before submitting.</div>
                    @error('is_required')<div id="requirement-needed-error" class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </section>
    </div>
    <x-action-bar>
        <a class="btn btn-outline-secondary" href="{{ route('admin.services.requirements.index', $service) }}">Cancel</a>
        <button class="btn btn-primary" type="submit" data-saving-label="Saving…">{{ $requirement->exists ? 'Save Changes' : 'Add Requirement' }}</button>
    </x-action-bar>
</form>

@endsection
