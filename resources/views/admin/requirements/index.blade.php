@extends('layouts.authenticated')
@section('dashboard')
<x-page-intro :subtitle="$service->name">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('admin.services.index') }}">Back to services</a>
        <a class="btn btn-primary" href="{{ route('admin.services.requirements.create', $service) }}"><x-app-icon name="plus"/> Add Requirement</a>
    </x-slot:actions>
</x-page-intro>
<section class="dashboard-panel rv-worklist admin-catalog" aria-labelledby="requirements-heading">
    <div class="dashboard-panel-heading">
        <h2 id="requirements-heading"><span class="panel-icon is-gold"><x-app-icon name="document"/></span>Submission requirements</h2>
        <span class="panel-count">{{ number_format($requirements->total()) }} {{ \Illuminate\Support\Str::plural('requirement', $requirements->total()) }}</span>
    </div>
    @if($errors->any())<div class="rv-toolbar"><div class="alert alert-danger mb-0" role="alert">{{ $errors->first() }}</div></div>@endif
    @if($requirements->isEmpty())
        <div class="service-empty"><span class="service-empty-icon"><x-app-icon name="document"/></span><h3>{{ $requirements->total() ? 'No requirements on this page' : 'No requirements yet' }}</h3>
            @if($requirements->total())<a href="{{ route('admin.services.requirements.index', $service) }}">Back to the first page</a>@endif
        </div>
    @else
        <div class="table-responsive admin-requirements-table" tabindex="0" role="region" aria-label="Service requirements table">
            <table class="table dashboard-table rv-worklist-table align-middle mb-0">
                <caption class="visually-hidden">Submission requirements for {{ $service->name }}</caption>
                <thead><tr><th scope="col">Requirement</th><th scope="col">Type</th><th scope="col">Submission</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                @foreach($requirements as $requirement)
                    <tr>
                        <th scope="row"><strong>{{ $requirement->name }}</strong>
                            @if($requirement->description)<span class="reservation-reference">{{ \Illuminate\Support\Str::limit($requirement->description, 140) }}</span>@endif
                        </th>
                        <td><span class="rv-badge is-muted"><x-app-icon name="document"/> File upload</span></td>
                        <td><span @class(['rv-badge', 'is-gold' => $requirement->is_required, 'is-muted' => ! $requirement->is_required])>{{ $requirement->is_required ? 'Required' : 'Optional' }}</span></td>
                        <td class="rv-row-action"><div class="admin-row-actions">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.services.requirements.edit', [$service, $requirement]) }}" aria-label="Edit {{ $requirement->name }}">Edit</a>
                            @if($requirement->attachments_exists)
                                <span class="app-note">In use · deletion unavailable</span>
                            @else
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" id="requirement-actions-{{ $requirement->id }}" aria-expanded="false" aria-label="More actions for {{ $requirement->name }}">More</button>
                                    <ul class="dropdown-menu dropdown-menu-end"><li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#delete-requirement-{{ $requirement->id }}" aria-label="Delete {{ $requirement->name }}">Delete</button></li></ul>
                                </div>
                            @endif
                        </div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="rv-pagination"><span class="app-note">Showing {{ $requirements->firstItem() }}–{{ $requirements->lastItem() }} of {{ $requirements->total() }} requirements</span>{{ $requirements->links('pagination.civic') }}</div>
    @endif
</section>
@foreach($requirements as $requirement)
    @unless($requirement->attachments_exists)
        <div class="modal fade requirement-delete-modal" id="delete-requirement-{{ $requirement->id }}" data-return-focus="requirement-actions-{{ $requirement->id }}" tabindex="-1" aria-labelledby="delete-heading-{{ $requirement->id }}" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                <div class="modal-header"><h2 class="modal-title fs-5" id="delete-heading-{{ $requirement->id }}">Delete requirement?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close deletion confirmation"></button></div>
                <div class="modal-body"><p>Delete <strong class="requirement-service-name">{{ $requirement->name }}</strong> from this service?</p><p class="app-note mb-0">This permanently removes the requirement.</p></div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><form method="POST" action="{{ route('admin.services.requirements.destroy', [$service, $requirement]) }}" data-service-submit>@csrf @method('DELETE')<button type="submit" class="btn btn-danger" data-saving-label="Deleting…">Delete Requirement</button></form></div>
            </div></div>
        </div>
    @endunless
@endforeach
<script>
    document.addEventListener('hidden.bs.modal', (event) => {
        if (event.target.matches('.requirement-delete-modal[data-return-focus]')) {
            document.getElementById(event.target.dataset.returnFocus)?.focus();
        }
    });
</script>
@endsection
