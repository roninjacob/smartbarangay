@extends('layouts.authenticated')
@section('dashboard')
<a href="{{ route('admin.services.index') }}" class="service-back-link">&larr; Back to services</a>
@if($template)
<nav class="builder-flow" aria-label="Document configuration steps"><a href="#builder-heading"><span>1</span>Review source</a><a href="#builder-fields-heading"><span>2</span>Manage fields</a>@if($pdfAvailable && $template->matchesCurrentPdf())<a href="{{ route('admin.services.document-layout.edit', $service) }}"><span>3</span>Design layout</a>@endif</nav>
@endif
<section class="dashboard-panel builder-panel" aria-labelledby="builder-heading">
    <div class="dashboard-panel-heading"><div class="min-w-0"><h2 id="builder-heading">{{ $service->name }}</h2><p class="panel-description">Configure document information for this service.</p></div></div>
    <div class="builder-body">
        <p class="builder-guide">Configure fields and design their placement on the official document. Layout changes do not alter the original PDF or current printing workflow.</p>
        @if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if($template)<p class="alert alert-info" role="status">{{ $readiness }}</p>@endif
        <dl class="builder-metadata">
            <div><dt>Current official PDF</dt><dd>{{ $service->documentTemplate?->original_filename ?? 'Not uploaded' }}</dd></div>
            @if($template)
                <div><dt>Referenced PDF</dt><dd>{{ $template->source_filename }}</dd></div>
                <div><dt>Configuration version</dt><dd>{{ $template->version }}</dd></div>
                <div><dt>Created by</dt><dd>{{ $template->creator?->name ?? 'Former account' }}</dd></div>
            @endif
        </dl>
        @if($template && ! $template->matchesCurrentPdf())<p class="alert alert-warning" role="status">The official PDF has changed, was removed, or its original integrity evidence is unavailable. Needs reconfiguration. New document requests are blocked until this configuration is reviewed against the current master.</p>@endif
        @if($template && !$template->matchesCurrentPdf() && $pdfAvailable)
            <form method="POST" action="{{ route('admin.services.document-configuration.update', $service) }}" class="mb-3">
                @csrf @method('PATCH')
                <input type="hidden" name="version" value="{{ $template->version }}">
                <input type="hidden" name="is_active" value="0"><input type="hidden" name="reconfigure" value="1">
                <input type="hidden" name="pdf_id" value="{{ $service->documentTemplate->id }}">
                <input type="hidden" name="pdf_revision" value="{{ $service->documentTemplate->revision() }}">
                <p>Keep field definitions, clear old placements, and review the current master before activating. Historical requests are unchanged.</p>
                <button type="submit" class="btn btn-warning">Reconfigure for Current Master</button>
            </form>
        @endif
        @if(!$template && !$pdfAvailable)
            <div class="service-empty p-3"><h3>Upload an official PDF first</h3><p>Use Edit Service to upload the document that this configuration will reference.</p><a class="btn btn-outline-primary" href="{{ route('admin.services.edit', $service) }}">Edit Service</a></div>
        @else
            <form method="POST" action="{{ $template ? route('admin.services.document-configuration.update', $service) : route('admin.services.document-configuration.store', $service) }}" class="builder-status-form" data-service-submit>
                @csrf
                @if($template) @method('PATCH')<input type="hidden" name="version" value="{{ $template->version }}">
                @else<input type="hidden" name="pdf_id" value="{{ $service->documentTemplate->id }}"><input type="hidden" name="pdf_revision" value="{{ $service->documentTemplate->revision() }}">@endif
                <div><label class="form-label" for="configuration-active">Configuration status</label>
                <select id="configuration-active" name="is_active" class="form-select"><option value="1" @selected(old('is_active', $template?->is_active ?? true))>Active</option><option value="0" @selected(!old('is_active', $template?->is_active ?? true))>Inactive</option></select></div>
                <button class="btn btn-primary" type="submit">{{ $template ? 'Save Configuration Status' : 'Create Configuration' }}</button>
            </form>
        @endif
    </div>
</section>
@if($template)
<section class="dashboard-panel builder-panel mt-4" aria-labelledby="builder-fields-heading">
    <div class="dashboard-panel-heading builder-heading"><div><h2 id="builder-fields-heading">Document fields</h2><p class="panel-description">System-supplied information and configurable custom inputs.</p></div><a class="btn btn-primary" href="{{ route('admin.services.document-fields.create', $service) }}">Add Field</a></div>
    <div class="builder-body">
        @if($pdfAvailable && $template->matchesCurrentPdf())
            <a class="btn btn-primary mb-4" href="{{ route('admin.services.document-layout.edit', $service) }}">Design Document Layout</a>
        @endif
        <div class="builder-fields-grid">@forelse($template->fields->sortBy(fn($field) => sprintf('%05d-%010d', $field->sort_order, $field->id)) as $field)
            <article class="builder-field-card">
                <h3>{{ $field->label }}</h3><p class="app-note">{{ $field->field_name }} · {{ ucfirst($field->source) }} · {{ $field->field_type->label() }} · {{ $field->is_required ? 'Required' : 'Optional' }}</p>
                @if($field->validation_rules)<p class="app-note">Validation settings: @foreach($field->validation_rules as $key => $value){{ str_replace('_', ' ', $key) }}: {{ $value }}{{ !$loop->last ? ' · ' : '' }}@endforeach</p>@endif
                @if($field->options)<p class="app-note">Options: {{ implode(', ', $field->options) }}</p>@endif
                @forelse($field->positions as $position)<p class="app-note">Placed on page {{ $position->page_number }} &middot; {{ ucfirst($position->alignment) }} aligned &middot; {{ $position->text_behavior === 'wrap' ? 'Wrap' : 'Auto resize' }}</p>@empty<p class="app-note">Not placed yet. Use Design Document Layout.</p>@endforelse
                <div class="builder-actions"><a class="btn btn-outline-primary" href="{{ route('admin.services.document-fields.edit', [$service, $field]) }}">Edit Field</a><form method="POST" action="{{ route('admin.services.document-fields.destroy', [$service, $field]) }}" data-service-submit>@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $template->version }}"><button class="btn btn-outline-danger" type="submit" aria-label="Delete {{ $field->label }}">Delete Field</button></form></div>
            </article>
        @empty<div class="service-empty p-3"><h3>No document fields yet</h3><p>Add a system field or a custom field to begin configuring this document.</p></div>@endforelse</div>
    </div>
</section>
@endif
@endsection
