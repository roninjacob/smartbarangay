@extends('layouts.authenticated')
@section('dashboard')
<section class="dashboard-panel service-catalog" aria-labelledby="catalog-heading">
    <div class="dashboard-panel-heading service-panel-heading">
        <div><h2 id="catalog-heading">Barangay service catalog</h2><p class="panel-description">Manage the documents and services offered by Barangay Calayo.</p></div>
        <a class="btn btn-primary service-create-link" href="{{ route('admin.services.create') }}"><x-app-icon name="plus"/>Create Service</a>
    </div>
    @if($errors->any())
        <div class="alert alert-danger mx-3" role="alert">{{ $errors->first() }}</div>
    @endif
    @if($services->isEmpty())
        <div class="service-empty"><span class="service-empty-icon"><x-app-icon name="services"/></span><h3>No services yet</h3><p>Create the first service or document for Barangay Calayo.</p><a href="{{ route('admin.services.create') }}" class="btn btn-outline-primary">Create your first service</a></div>
    @else
        <ul class="service-catalog-list list-unstyled mb-0" aria-label="Barangay Calayo services">
                @foreach($services as $service)
                    <li>
                        <article class="service-catalog-card" aria-labelledby="service-heading-{{ $service->id }}">
                            <div class="service-card-details">
                                <h3 id="service-heading-{{ $service->id }}" class="service-card-name">{{ $service->name }}</h3>
                                <p class="service-description mb-3">{{ $service->description ?? 'No description provided.' }}</p>
                                <dl class="service-card-metadata mb-0">
                                    <div><dt>Official File</dt><dd>{{ $service->documentTemplate?->format() ?? 'Not uploaded' }}
                                        @if($service->documentTemplate)<span class="service-card-filename">{{ $service->documentTemplate->original_filename }}</span>@endif
                                    </dd></div>
                                    <div><dt>Status</dt><dd><span @class(['service-status', 'is-active' => $service->is_active])>{{ $service->is_active ? 'Active' : 'Inactive' }}</span></dd></div>
                                </dl>
                            </div>
                            <div class="service-card-actions" role="group" aria-label="Actions for {{ $service->name }}">
                                <a href="{{ route('admin.services.certificates.index', $service) }}" class="btn btn-primary service-card-primary" aria-label="Preview Certificate for {{ $service->name }}">Preview Certificate</a>
                                @if($service->documentTemplate?->format() === 'PDF')
                                    <div class="service-card-secondary">
                                        <a href="{{ route('admin.services.template.print', $service) }}" class="btn btn-outline-primary" aria-label="Print Certificate for {{ $service->name }}">Print Certificate</a>
                                        <a href="{{ route('admin.services.template.download', $service) }}" class="btn btn-outline-primary" aria-label="Download PDF for {{ $service->name }}">Download PDF</a>
                                    </div>
                                @endif
                                <div class="service-card-management">
                                    <a href="{{ route('admin.services.requirements.index', $service) }}" class="btn btn-outline-secondary" aria-label="Requirements for {{ $service->name }}">Requirements</a>
                                    <a href="{{ route('admin.services.edit', $service) }}" class="btn btn-outline-secondary" aria-label="Edit {{ $service->name }}">Edit</a>
                                    <form method="POST" action="{{ route('admin.services.status', $service) }}" data-service-submit>
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="is_active" value="{{ $service->is_active ? '0' : '1' }}">
                                        <button type="submit" @class(['btn', 'btn-outline-danger' => $service->is_active, 'btn-outline-success' => ! $service->is_active]) aria-label="{{ $service->is_active ? 'Deactivate' : 'Activate' }} {{ $service->name }}">{{ $service->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    </li>
                @endforeach
        </ul>
        <div class="service-pagination"><span class="app-note">Showing {{ $services->firstItem() }}–{{ $services->lastItem() }} of {{ $services->total() }} services</span>{{ $services->links('pagination::bootstrap-5') }}</div>
    @endif
</section>
<p class="app-note mt-3">Deactivate a service to mark it unavailable. Existing reservations and history are preserved.</p>
@endsection
