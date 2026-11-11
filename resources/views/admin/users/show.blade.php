@extends('layouts.authenticated')
@section('dashboard')
<div class="reservation-wizard resident-account-details">
    <a href="{{ route('admin.users.index') }}" class="btn btn-link px-0 mb-3 reservation-details-back"><x-app-icon name="arrow"/> Back to residents</a>
    <section class="dashboard-panel reservation-panel" aria-labelledby="resident-name">
        <div class="reservation-detail-header"><div class="resident-identity"><x-resident-avatar :user="$resident" :large="true"/><div class="min-w-0"><span class="reservation-step-label">RESIDENT ACCOUNT #{{ $resident->id }}</span><h2 id="resident-name">{{ $resident->name }}</h2></div></div><x-account-status :active="$resident->is_active"/></div>
        <p class="panel-description">Registered with SmartBarangay · Barangay Calayo, Nasugbu, Batangas</p>
        <dl class="reservation-summary">
            <div><dt>Email</dt><dd>{{ $resident->email }}</dd></div>
            <div><dt>Contact number</dt><dd>{{ $resident->contact_number ?: 'No contact number provided' }}</dd></div>
            <div><dt>Email verification</dt><dd>{{ $resident->hasVerifiedEmail() ? 'Verified' : 'Unverified' }}@if($resident->hasVerifiedEmail())<span class="resident-account-contact">{{ $resident->email_verified_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</span>@endif</dd></div>
            <div><dt>Registered</dt><dd><time datetime="{{ $resident->created_at->toIso8601String() }}">{{ $resident->created_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</time></dd></div>
        </dl>
    </section>
    <section class="dashboard-panel reservation-panel mt-4" aria-labelledby="residency-review-heading">
        <h2 id="residency-review-heading">Review Barangay Calayo residency</h2>
        <p class="reservation-intro">{{ $resident->residency_status->label() }}. Review the resident's information and supporting evidence through your office procedure.</p>
        <dl class="reservation-summary">
            <div><dt>Birthdate</dt><dd>{{ $resident->profile?->birthdate?->format('F j, Y') ?: 'Not provided' }}</dd></div>
            @if($resident->hasStructuredName())
                <div><dt>First name</dt><dd>{{ $resident->first_name }}</dd></div>
                <div><dt>Middle name</dt><dd>{{ $resident->middle_name ?: 'Not provided' }}</dd></div>
                <div><dt>Last name</dt><dd>{{ $resident->last_name }}</dd></div>
                <div><dt>Suffix</dt><dd>{{ $resident->suffix ?: 'Not provided' }}</dd></div>
            @else
                <div><dt>Personal information</dt><dd>Legacy full name retained. Resident can complete separate name fields in Profile / Account Settings.</dd></div>
            @endif
            <div><dt>House / Unit number</dt><dd>{{ $resident->profile?->house_unit_number ?: 'Not provided' }}</dd></div>
            <div><dt>Recorded address</dt><dd>{{ $resident->address ?: 'Not provided' }}</dd></div>
            <div><dt>Street</dt><dd>{{ $resident->profile?->address_street ?: 'Not provided' }}</dd></div>
            <div><dt>Zone / Purok</dt><dd>{{ $resident->profile?->address_zone_purok ?: 'Not provided' }}</dd></div>
        </dl>
        @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('admin.users.residency', $resident) }}">
            @csrf @method('PATCH')
            <input type="hidden" name="expected_residency_revision" value="{{ $resident->residency_revision }}">
            <div class="mb-3"><label for="residency_status" class="form-label">Residency decision</label><select id="residency_status" name="residency_status" class="form-select" required>
                <option value="">Choose a decision</option>
                @foreach([\App\Enums\ResidencyStatus::Approved, \App\Enums\ResidencyStatus::NeedsCorrection, \App\Enums\ResidencyStatus::Rejected] as $state)<option value="{{ $state->value }}" @selected(old('residency_status') === $state->value)>{{ $state->label() }}</option>@endforeach
            </select></div>
            <div class="mb-3"><label for="resident_message" class="form-label">Message to resident</label><textarea id="resident_message" name="resident_message" class="form-control" rows="3" maxlength="1000">{{ old('resident_message') }}</textarea><p class="form-text">Explain requested corrections or rejection. This message is visible to the resident.</p></div>
            <button class="btn btn-primary" type="submit">Save residency decision</button>
        </form>
        <h3 class="h5 mt-4">Residency decision history</h3>
        @forelse($residencyReviews as $review)
            <article class="border-top py-3"><strong>{{ $review->from_status->label() }} → {{ $review->to_status->label() }}</strong><p class="small text-secondary mb-1">{{ $review->reviewer?->name }} · {{ $review->reviewed_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</p>@if($review->resident_message)<p class="mb-0">{{ $review->resident_message }}</p>@endif</article>
        @empty
            <p class="text-secondary">No residency decisions recorded.</p>
        @endforelse
        {{ $residencyReviews->links('pagination.civic') }}
        <h3 class="h5 mt-4">Identity and address change history</h3>
        @forelse($residencyChanges as $change)
            <article class="border-top py-3">
                <strong>{{ $change->user?->name }} · {{ $change->created_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</strong>
                <p class="small text-secondary">{{ \App\Enums\ResidencyStatus::from($change->old_values['residency_status'])->label() }} → {{ \App\Enums\ResidencyStatus::from($change->new_values['residency_status'])->label() }}</p>
                <dl class="reservation-summary">
                    @foreach($change->new_values['fields'] as $field => $value)
                        <div><dt>{{ \App\Services\ResidencyProfileChanges::LABELS[$field] }}</dt><dd>{{ $change->old_values['fields'][$field] ?? 'Not provided' }} → {{ $value ?? 'Not provided' }}</dd></div>
                    @endforeach
                </dl>
            </article>
        @empty
            <p class="text-secondary">No material profile changes recorded.</p>
        @endforelse
        {{ $residencyChanges->links('pagination.civic') }}
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
