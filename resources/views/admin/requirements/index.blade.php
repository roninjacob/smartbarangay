@extends('layouts.authenticated')
@section('dashboard')
<a href="{{ route('admin.services.index') }}" class="service-back-link">&larr; Back to service catalog</a>
<section class="dashboard-panel service-catalog" aria-labelledby="requirements-heading">
    <div class="dashboard-panel-heading service-panel-heading">
        <div class="min-w-0"><h2 id="requirements-heading" class="requirement-service-name">{{ $service->name }}</h2><p class="panel-description">Manage the requirements for this Barangay Calayo service.</p></div>
        <a class="btn btn-primary service-create-link" href="{{ route('admin.services.requirements.create', $service) }}"><x-app-icon name="plus"/>Add Requirement</a>
    </div>
    @if($errors->any())<div class="alert alert-danger mx-3" role="alert">{{ $errors->first() }}</div>@endif
    @if($requirements->isEmpty())
        <div class="service-empty"><span class="service-empty-icon"><x-app-icon name="document"/></span><h3>No requirements yet</h3><p>Add the documents residents will need for this service.</p><a href="{{ route('admin.services.requirements.create', $service) }}" class="btn btn-outline-primary">Add your first requirement</a></div>
    @else
        <div class="table-responsive" role="region" aria-label="Service requirements table" tabindex="0">
            <table class="table dashboard-table service-table align-middle mb-0">
                <caption class="visually-hidden">Requirements for {{ $service->name }}, descriptions, required or optional setting and actions.</caption>
                <thead><tr><th scope="col">Requirement</th><th scope="col">Description</th><th scope="col">Needed</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                @foreach($requirements as $requirement)
                    <tr>
                        <th scope="row"><span class="service-name">{{ $requirement->name }}</span></th>
                        <td class="service-description">{{ \Illuminate\Support\Str::limit($requirement->description ?? 'No description provided.', 140) }}</td>
                        <td><span @class(['service-status', 'is-active' => $requirement->is_required])>{{ $requirement->is_required ? 'Required' : 'Optional' }}</span></td>
                        <td><div class="service-actions">
                            <a href="{{ route('admin.services.requirements.edit', [$service, $requirement]) }}" class="btn btn-sm btn-outline-primary" aria-label="Edit {{ $requirement->name }}">Edit</a>
                            @if($requirement->attachments_exists)
                                <span class="app-note">In use · deletion unavailable</span>
                            @else
                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delete-requirement-{{ $requirement->id }}" aria-label="Delete {{ $requirement->name }}">Delete</button>
                            @endif
                        </div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="service-pagination"><span class="app-note">Showing {{ $requirements->firstItem() }}–{{ $requirements->lastItem() }} of {{ $requirements->total() }} requirements</span>{{ $requirements->links('pagination::bootstrap-5') }}</div>
    @endif
</section>
<p class="app-note mt-3">Requirements belong to this service. Requirements linked to existing attachments are protected from deletion.</p>
@foreach($requirements as $requirement)
    @unless($requirement->attachments_exists)
        <div class="modal fade requirement-delete-modal" id="delete-requirement-{{ $requirement->id }}" tabindex="-1" aria-labelledby="delete-heading-{{ $requirement->id }}" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                <div class="modal-header"><h2 class="modal-title fs-5" id="delete-heading-{{ $requirement->id }}">Delete requirement?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close deletion confirmation"></button></div>
                <div class="modal-body"><p>Delete <strong class="requirement-service-name">{{ $requirement->name }}</strong> from this service?</p><p class="app-note mb-0">This cannot be undone. The service and its other requirements will remain unchanged.</p></div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><form method="POST" action="{{ route('admin.services.requirements.destroy', [$service, $requirement]) }}" data-service-submit>@csrf @method('DELETE')<button type="submit" class="btn btn-danger" data-saving-label="Deleting…">Delete Requirement</button></form></div>
            </div></div>
        </div>
    @endunless
@endforeach
@endsection
