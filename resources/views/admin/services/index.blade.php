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
        <div class="table-responsive" role="region" aria-label="Service catalog table" tabindex="0">
            <table class="table dashboard-table service-table align-middle mb-0">
                <caption class="visually-hidden">Barangay Calayo services, descriptions, availability and management actions.</caption>
                <thead><tr><th scope="col">Service / Document</th><th scope="col">Description</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                @foreach($services as $service)
                    <tr>
                        <th scope="row"><span class="service-name">{{ $service->name }}</span></th>
                        <td class="service-description">{{ \Illuminate\Support\Str::limit($service->description ?? 'No description provided.', 140) }}</td>
                        <td><span @class(['service-status', 'is-active' => $service->is_active])>{{ $service->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td><div class="service-actions">
                            <a href="{{ route('admin.services.requirements.index', $service) }}" class="btn btn-sm btn-outline-primary" aria-label="Requirements for {{ $service->name }}">Requirements</a>
                            <a href="{{ route('admin.services.edit', $service) }}" class="btn btn-sm btn-outline-primary" aria-label="Edit {{ $service->name }}">Edit</a>
                            <form method="POST" action="{{ route('admin.services.status', $service) }}" data-service-submit>
                                @csrf @method('PATCH')
                                <input type="hidden" name="is_active" value="{{ $service->is_active ? '0' : '1' }}">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" aria-label="{{ $service->is_active ? 'Deactivate' : 'Activate' }} {{ $service->name }}">{{ $service->is_active ? 'Deactivate' : 'Activate' }}</button>
                            </form>
                        </div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="service-pagination"><span class="app-note">Showing {{ $services->firstItem() }}–{{ $services->lastItem() }} of {{ $services->total() }} services</span>{{ $services->links('pagination::bootstrap-5') }}</div>
    @endif
</section>
<p class="app-note mt-3">Deactivate a service to mark it unavailable. Existing reservations and history are preserved.</p>
@endsection
