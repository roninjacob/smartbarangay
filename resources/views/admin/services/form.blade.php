@extends('layouts.authenticated')
@section('dashboard')
<a href="{{ route('admin.services.index') }}" class="service-back-link">&larr; Back to service catalog</a>
<div class="row g-4">
    <div class="col-xl-8">
        <section class="dashboard-panel service-form-panel" aria-labelledby="service-form-heading">
            <div class="dashboard-panel-heading"><div><h2 id="service-form-heading">{{ $service->exists ? 'Service details' : 'Add a barangay service' }}</h2><p class="panel-description">{{ $service->exists ? 'Keep service information clear and up to date.' : 'Prepare a document or service for the Barangay Calayo catalog.' }}</p></div></div>
            <form method="POST" action="{{ $service->exists ? route('admin.services.update', $service) : route('admin.services.store') }}" enctype="multipart/form-data" class="service-form" data-service-submit>
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
                <fieldset class="mb-4 border-top pt-4">
                    <legend class="h5 float-none w-auto">Official Document Template</legend>
                    <p class="app-note">This is the Barangay Calayo master document used to prepare the issued document. Service Requirements are the files a Resident submits with a reservation.</p>
                    @if($service->exists && $service->documentTemplate)
                        @php($template = $service->documentTemplate)
                        <dl class="mb-3">
                            <dt>Current file</dt><dd class="text-break">{{ $template->original_filename }}</dd>
                            <dt>Format / Size</dt><dd>{{ $template->format() }} · {{ number_format($template->file_size / 1024, 1) }} KB</dd>
                            <dt>Uploaded</dt><dd>{{ $template->uploaded_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }} · Philippine time</dd>
                        </dl>
                        <a href="{{ route('admin.services.template.download', $service) }}" class="btn btn-outline-primary mb-3">Download Template</a>
                    @else
                        <p class="app-note">No template uploaded. Services can be saved without a template.</p>
                    @endif
                    <label class="form-label d-block" for="official-template">{{ $service->exists && $service->documentTemplate ? 'Replace Template' : 'Official template (optional)' }}</label>
                    <input id="official-template" type="file" name="official_template" accept=".docx,.pdf" @class(['form-control', 'is-invalid' => $errors->has('official_template')]) aria-describedby="template-help{{ $errors->has('official_template') ? ' template-error' : '' }}">
                    <div id="template-help" class="form-text">DOCX or PDF · Maximum 10 MB. Upload the official Barangay Calayo master document used for this service. DOCX supports ready-to-print document preparation. PDF is suitable as a static official template/reference. Select the file again if validation fails.</div>
                    @error('official_template')<div id="template-error" class="invalid-feedback">{{ $message }}</div>@enderror
                    @include('admin.services.merge-fields')
                </fieldset>
                <div class="service-form-actions"><button class="btn btn-primary" type="submit" data-saving-label="Saving…">{{ $service->exists ? 'Save Changes' : 'Create Service' }}</button><a class="btn btn-outline-secondary" href="{{ route('admin.services.index') }}">Cancel</a></div>
            </form>
            @if($service->exists && $service->documentTemplate)
                <section class="border-top mt-4 p-4" aria-labelledby="remove-template-heading">
                    <h3 id="remove-template-heading" class="h6">Remove current master template</h3>
                    <p class="app-note">Only the current official template will be removed. The service, requirements, reservations, submitted files and QR tickets are preserved.</p>
                    <form method="POST" action="{{ route('admin.services.template.destroy', $service) }}" data-service-submit>
                        @csrf @method('DELETE')
                        <input type="hidden" name="template_id" value="{{ $service->documentTemplate->id }}">
                        <input type="hidden" name="template_revision" value="{{ $service->documentTemplate->revision() }}">
                        <div class="form-check mb-3">
                            <input id="confirm-template-removal" type="checkbox" name="confirm_removal" value="1" class="form-check-input" required>
                            <label class="form-check-label" for="confirm-template-removal">I confirm removal of the current master template.</label>
                        </div>
                        @error('confirm_removal')<p class="text-danger" role="alert">{{ $message }}</p>@enderror
                        @error('template_id')<p class="text-danger" role="alert">{{ $message }}</p>@enderror
                        <button type="submit" class="btn btn-outline-danger" data-saving-label="Removing…">Remove Template</button>
                    </form>
                </section>
            @endif
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
