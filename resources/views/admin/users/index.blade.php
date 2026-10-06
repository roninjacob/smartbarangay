@extends('layouts.authenticated')
@section('dashboard')
<section class="dashboard-panel resident-account-catalog" aria-labelledby="resident-accounts-heading">
    <div class="dashboard-panel-heading"><div><h2 id="resident-accounts-heading">Barangay Calayo residents</h2><p class="panel-description">Find Resident accounts and manage access to barangay services.</p></div></div>
    @if($errors->any())<div class="alert alert-danger mx-3" role="alert">{{ $errors->first() }}</div>@endif
    <form method="GET" action="{{ route('admin.users.index') }}" class="resident-account-filters row g-3">
        <div class="col-xl-4"><label class="form-label" for="search">Search residents</label><input class="form-control" id="search" name="search" type="search" maxlength="255" value="{{ $filters['search'] ?? '' }}" placeholder="Name, email or contact number"></div>
        <div class="col-sm-6 col-xl-2"><label class="form-label" for="status">Account status</label><select class="form-select" id="status" name="status"><option value="">All accounts</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option></select></div>
        <div class="col-sm-6 col-xl-3"><label class="form-label" for="verification">Email verification</label><select class="form-select" id="verification" name="verification"><option value="">All verification states</option><option value="verified" @selected(($filters['verification'] ?? '') === 'verified')>Verified</option><option value="unverified" @selected(($filters['verification'] ?? '') === 'unverified')>Unverified</option></select></div>
        <div class="col-xl-3 resident-account-filter-actions"><button type="submit" class="btn btn-primary">Apply filters</button><a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Clear</a></div>
    </form>
    @if($users->isEmpty())
        <div class="service-empty"><span class="service-empty-icon"><x-app-icon name="users"/></span><h3>{{ ($filters['search'] ?? '') === '' && empty($filters['status']) && empty($filters['verification']) && $users->total() === 0 ? 'No Resident accounts yet' : 'No matching residents on this page' }}</h3><p>Try another search or filter, or return to the full Resident list.</p><a href="{{ route('admin.users.index') }}" class="btn btn-outline-primary">View all residents</a></div>
    @else
        <div class="table-responsive" tabindex="0" role="region" aria-label="Resident accounts table">
            <table class="table dashboard-table resident-account-table align-middle mb-0">
                <caption class="visually-hidden">Resident accounts, contact information, access and verification status, registration dates and details.</caption>
                <thead><tr><th scope="col">Resident / Contact</th><th scope="col">Account status</th><th scope="col">Verification</th><th scope="col">Registered</th><th scope="col">Action</th></tr></thead>
                <tbody>@foreach($users as $resident)
                    <tr>
                        <th scope="row"><span class="resident-account-name">{{ $resident->name }}</span><span class="resident-account-contact">{{ $resident->email }}</span><span class="resident-account-contact">{{ $resident->contact_number ?: 'No contact number provided' }}</span></th>
                        <td><span class="resident-account-field-label" aria-hidden="true">Account status</span><x-account-status :active="$resident->is_active"/></td>
                        <td><span class="resident-account-field-label" aria-hidden="true">Verification</span><span class="requirement-label">{{ $resident->hasVerifiedEmail() ? 'Verified' : 'Unverified' }}</span></td>
                        <td><span class="resident-account-field-label" aria-hidden="true">Registered</span><time datetime="{{ $resident->created_at->toIso8601String() }}">{{ $resident->created_at->timezone('Asia/Manila')->format('M j, Y') }}</time></td>
                        <td><a href="{{ route('admin.users.show', $resident) }}" class="btn btn-sm btn-outline-primary resident-account-details-link" aria-label="View Resident account #{{ $resident->id }}">View account</a></td>
                    </tr>
                @endforeach</tbody>
            </table>
        </div>
        <div class="service-pagination"><span class="app-note">Showing {{ $users->firstItem() }}–{{ $users->lastItem() }} of {{ $users->total() }} residents</span>{{ $users->links('pagination::bootstrap-5') }}</div>
    @endif
</section>
@endsection
