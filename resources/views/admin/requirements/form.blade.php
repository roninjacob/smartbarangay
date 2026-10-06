@extends('layouts.authenticated')
@section('dashboard')
<a href="{{ route('admin.services.requirements.index', $service) }}" class="service-back-link">&larr; Back to service requirements</a>
<div class="row g-4">
    <div class="col-xl-8">
        <section class="dashboard-panel service-form-panel" aria-labelledby="requirement-form-heading">
            <div class="dashboard-panel-heading"><div class="min-w-0"><h2 id="requirement-form-heading">{{ $requirement->exists ? 'Requirement details' : 'Add a service requirement' }}</h2><p class="panel-description requirement-service-name">For {{ $service->name }}</p></div></div>
            <form method="POST" action="{{ $requirement->exists ? route('admin.services.requirements.update', [$service, $requirement]) : route('admin.services.requirements.store', $service) }}" class="service-form" data-service-submit>
                @csrf
                @if($requirement->exists) @method('PUT') @endif
                @if($errors->any())<div class="alert alert-danger" role="alert">Please correct the highlighted fields and try again.</div>@endif
                <div class="mb-4">
                    <label for="requirement-name" class="form-label">Requirement Name <span class="service-required">(required)</span></label>
                    <input id="requirement-name" name="name" type="text" @class(['form-control', 'is-invalid' => $errors->has('name')]) value="{{ is_string(old('name', $requirement->name)) ? old('name', $requirement->name) : '' }}" required maxlength="255" aria-describedby="requirement-name-help{{ $errors->has('name') ? ' requirement-name-error' : '' }}" @if($errors->has('name')) aria-invalid="true" @endif>
                    <div id="requirement-name-help" class="form-text">Use a clear name, such as Valid ID, Cedula, or Application Form.</div>
                    @error('name')<div id="requirement-name-error" class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-4">
                    <label for="requirement-description" class="form-label">Description <span class="service-required">(optional)</span></label>
                    <textarea id="requirement-description" name="description" rows="4" maxlength="5000" @class(['form-control', 'is-invalid' => $errors->has('description')]) aria-describedby="requirement-description-help{{ $errors->has('description') ? ' requirement-description-error' : '' }}" @if($errors->has('description')) aria-invalid="true" @endif>{{ is_string(old('description', $requirement->description)) ? old('description', $requirement->description) : '' }}</textarea>
                    <div id="requirement-description-help" class="form-text">Add plain-text instructions. Maximum 5,000 characters.</div>
                    @error('description')<div id="requirement-description-error" class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-4">
                    <label for="requirement-needed" class="form-label">Requirement Setting</label>
                    <select id="requirement-needed" name="is_required" @class(['form-select', 'is-invalid' => $errors->has('is_required')]) required aria-describedby="requirement-needed-help{{ $errors->has('is_required') ? ' requirement-needed-error' : '' }}" @if($errors->has('is_required')) aria-invalid="true" @endif>
                        <option value="1" @selected(in_array(old('is_required', $requirement->is_required), [true, 1, '1'], true))>Required</option>
                        <option value="0" @selected(in_array(old('is_required', $requirement->is_required), [false, 0, '0'], true))>Optional</option>
                    </select>
                    <div id="requirement-needed-help" class="form-text">Specify whether this requirement is mandatory for the service.</div>
                    @error('is_required')<div id="requirement-needed-error" class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="service-form-actions"><button class="btn btn-primary" type="submit" data-saving-label="Saving…">{{ $requirement->exists ? 'Save Changes' : 'Add Requirement' }}</button><a class="btn btn-outline-secondary" href="{{ route('admin.services.requirements.index', $service) }}">Cancel</a></div>
            </form>
        </section>
    </div>
    <div class="col-xl-4"><aside class="dashboard-panel service-form-note" aria-labelledby="requirement-note-heading"><span class="service-empty-icon"><x-app-icon name="document"/></span><h2 id="requirement-note-heading">Service requirements</h2><p>Keep requirement names and instructions clear so residents know which documents they will need.</p><p class="mb-0">This requirement belongs to <strong class="requirement-service-name">{{ $service->name }}</strong>.</p></aside></div>
</div>
@endsection
