@extends('layouts.authenticated')
@section('dashboard')
<a href="{{ route('admin.services.index') }}" class="service-back-link">&larr; Back to service catalog</a>
<div class="row g-4">
    <div class="col-xl-8">
        <section class="dashboard-panel service-form-panel" aria-labelledby="service-form-heading">
            <div class="dashboard-panel-heading"><div><h2 id="service-form-heading">{{ $service->exists ? 'Service details' : 'Add a barangay service' }}</h2><p class="panel-description">{{ $service->exists ? 'Keep service information clear and up to date.' : 'Prepare a document or service for the Barangay Calayo catalog.' }}</p></div></div>
            <form method="POST" action="{{ $service->exists ? route('admin.services.update', $service) : route('admin.services.store') }}" class="service-form" data-service-submit>
                @csrf
                @if($service->exists) @method('PUT') @endif
                @if($errors->any())<div class="alert alert-danger" role="alert">Please correct the highlighted fields and try again.</div>@endif
                <div class="mb-4">
                    <label for="service-name" class="form-label">Service / Document Name <span class="service-required">(required)</span></label>
                    <input id="service-name" name="name" type="text" @class(['form-control', 'is-invalid' => $errors->has('name')]) value="{{ is_string(old('name', $service->name)) ? old('name', $service->name) : '' }}" required maxlength="255" aria-describedby="service-name-help{{ $errors->has('name') ? ' service-name-error' : '' }}" @if($errors->has('name')) aria-invalid="true" @endif>
                    <div id="service-name-help" class="form-text">Use a clear, unique name, such as Barangay Clearance.</div>
                    @error('name')<div id="service-name-error" class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-4">
                    <label for="service-description" class="form-label">Description <span class="service-required">(optional)</span></label>
                    <textarea id="service-description" name="description" rows="6" maxlength="5000" @class(['form-control', 'is-invalid' => $errors->has('description')]) aria-describedby="service-description-help{{ $errors->has('description') ? ' service-description-error' : '' }}" @if($errors->has('description')) aria-invalid="true" @endif>{{ is_string(old('description', $service->description)) ? old('description', $service->description) : '' }}</textarea>
                    <div id="service-description-help" class="form-text">Describe the service in plain text. Maximum 5,000 characters.</div>
                    @error('description')<div id="service-description-error" class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="service-form-actions"><button class="btn btn-primary" type="submit" data-saving-label="Saving…">{{ $service->exists ? 'Save Changes' : 'Create Service' }}</button><a class="btn btn-outline-secondary" href="{{ route('admin.services.index') }}">Cancel</a></div>
            </form>
        </section>
    </div>
    <div class="col-xl-4">
        <aside class="dashboard-panel service-form-note" aria-labelledby="availability-heading"><span class="service-empty-icon"><x-app-icon name="services"/></span><h2 id="availability-heading">Service availability</h2>
            <p>{{ $service->exists ? 'Change availability using Activate or Deactivate in the service catalog.' : 'New services start active. You can deactivate them from the service catalog when needed.' }}</p>
            @if($service->exists)<span @class(['service-status', 'is-active' => $service->is_active])>{{ $service->is_active ? 'Active' : 'Inactive' }}</span>@endif
            <p class="mb-0 mt-3">Deactivation keeps existing reservations and history intact.</p>
        </aside>
    </div>
</div>
@endsection
