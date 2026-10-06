@extends('layouts.authenticated')
@section('dashboard')
<div class="reservation-wizard resident-account-details">
    <a href="{{ route('admin.users.index') }}" class="btn btn-link px-0 mb-3 reservation-details-back"><x-app-icon name="arrow"/> Back to residents</a>
    <section class="dashboard-panel reservation-panel" aria-labelledby="resident-name">
        <div class="reservation-detail-header"><div><span class="reservation-step-label">RESIDENT ACCOUNT #{{ $resident->id }}</span><h2 id="resident-name">{{ $resident->name }}</h2></div><x-account-status :active="$resident->is_active"/></div>
        <p class="panel-description">Registered with SmartBarangay · Barangay Calayo, Nasugbu, Batangas</p>
        <dl class="reservation-summary">
            <div><dt>Email</dt><dd>{{ $resident->email }}</dd></div>
            <div><dt>Contact number</dt><dd>{{ $resident->contact_number ?: 'No contact number provided' }}</dd></div>
            <div><dt>Email verification</dt><dd>{{ $resident->hasVerifiedEmail() ? 'Verified' : 'Unverified' }}@if($resident->hasVerifiedEmail())<span class="resident-account-contact">{{ $resident->email_verified_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</span>@endif</dd></div>
            <div><dt>Registered</dt><dd><time datetime="{{ $resident->created_at->toIso8601String() }}">{{ $resident->created_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</time></dd></div>
        </dl>
    </section>
    <section class="dashboard-panel reservation-panel mt-4" aria-labelledby="account-access-heading">
        <h2 id="account-access-heading">Manage account access</h2>
        <p class="reservation-intro">{{ $resident->is_active ? 'Deactivating prevents this Resident from signing in or continuing to use protected pages. Existing reservations and transaction records remain preserved.' : 'Activating allows this Resident to sign in again. Email verification is still required before accessing protected Resident pages.' }}</p>
        @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('admin.users.status', $resident) }}" data-service-submit>
            @csrf @method('PATCH')
            <input type="hidden" name="expected_is_active" value="{{ (int) $resident->is_active }}">
            <input type="hidden" name="is_active" value="{{ (int) ! $resident->is_active }}">
            <button type="submit" @class(['btn', 'btn-outline-danger' => $resident->is_active, 'btn-primary' => ! $resident->is_active]) data-saving-label="Updating account…">{{ $resident->is_active ? 'Deactivate Resident' : 'Activate Resident' }}</button>
        </form>
    </section>
</div>
@endsection
