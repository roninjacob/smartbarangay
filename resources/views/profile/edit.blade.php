@extends('layouts.authenticated')
@section('dashboard')
@php
    $isResident = $user->role === \App\Enums\UserRole::Resident;
    $hasPicture = (bool) $user->ownedProfilePicturePath();
@endphp
<section class="ac-profile-head" aria-labelledby="profile-name-heading">
    <span class="ac-avatar" aria-hidden="true">
        @if($hasPicture)<img src="{{ route($profileRoutePrefix.'.picture') }}" alt="" width="88" height="88">@else{{ mb_substr($user->name, 0, 1) }}@endif
    </span>
    <div class="ac-profile-text">
        <h2 id="profile-name-heading">{{ $user->name }}</h2>
        <p>{{ $user->email }}</p>
        <div class="ac-profile-chips">
            <span class="ac-chip">{{ $isResident ? 'Resident' : 'Admin' }}</span>
            @if($isResident)<span @class(['ac-chip', 'is-approved' => $user->residency_status === \App\Enums\ResidencyStatus::Approved, 'is-pending' => $user->residency_status !== \App\Enums\ResidencyStatus::Approved])>{{ $user->residency_status->label() }}</span>@endif
        </div>
    </div>
</section>

@if($isResident)
    @include('partials.residency-status', ['resident' => $user])
    <div class="rv-callout mb-4" role="status">
        <span class="rv-callout-icon" aria-hidden="true"><x-app-icon name="account"/></span>
        <div>
            <strong>Your profile supports your document requests.</strong>
            <p>Changing your name, birthdate or address information requires another residency review before you can submit new requests.</p>
            <p>You can complete this information gradually. Only the profile fields required by your selected document must be filled before continuing that request. Emergency contacts are optional and stay private.</p>
        </div>
    </div>
@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert"><strong>Please check your profile.</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
@if($resumeReservation)
    <div class="rv-callout tone-approved mb-4"><span class="rv-callout-icon" aria-hidden="true"><x-app-icon name="arrow"/></span><div><strong>Continue your document request</strong><p>Save your profile changes, then return to review the information for your selected service.</p><a class="btn btn-primary btn-sm" href="{{ route('resident.reservations.requirements') }}">Return to your request</a></div></div>
@endif
<p class="app-note mb-3">Each section saves separately. Save your changes before moving to another section.</p>

<div class="ac-grid profile-settings">
    <section class="card dashboard-panel" aria-labelledby="picture-heading">
        <div class="dashboard-panel-heading"><h2 id="picture-heading"><span class="panel-icon is-teal" aria-hidden="true"><x-app-icon name="account"/></span>Profile picture</h2></div>
        <div class="ac-card-body">
            <div class="ac-photo">
                @if($hasPicture)
                    <img src="{{ route($profileRoutePrefix.'.picture') }}" class="profile-image" alt="Your current profile picture" width="128" height="128" data-profile-image>
                @else
                    <span class="profile-placeholder" role="img" aria-label="Default avatar">{{ mb_substr($user->name, 0, 1) }}</span>
                @endif
                <p class="app-note text-center m-0">A photo is optional. You can use your account without one.</p>
            </div>
            <form method="POST" action="{{ route($profileRoutePrefix.'.picture.update') }}" enctype="multipart/form-data" data-profile-picture-form data-service-submit>
                @csrf
                <div class="rv-upload">
                    <label for="profile_picture" class="form-label">Choose a picture</label>
                    <input type="file" data-friendly-upload id="profile_picture" name="profile_picture" class="form-control @error('profile_picture') is-invalid @enderror" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required aria-describedby="picture-help picture-feedback @error('profile_picture') picture-error @enderror" @error('profile_picture') aria-invalid="true" @enderror>
                    @error('profile_picture')<div id="picture-error" class="invalid-feedback">{{ $message }}</div>@enderror
                    <p id="picture-help" class="form-text mb-0">JPG, JPEG, PNG or WebP. Up to 2 MB and 4096 × 4096 pixels.</p>
                    <p id="picture-feedback" class="form-text mb-0" aria-live="polite" data-picture-feedback></p>
                </div>
                <button type="submit" class="btn btn-primary w-100 mt-3" data-saving-label="Uploading…">{{ $hasPicture ? 'Replace picture' : 'Upload picture' }}</button>
            </form>
            @if($hasPicture)
                <form method="POST" action="{{ route($profileRoutePrefix.'.picture.destroy') }}" class="mt-2" data-service-submit>
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger w-100" data-saving-label="Removing…">Remove picture</button>
                </form>
            @endif
        </div>
    </section>

    <div class="ac-stack">
        <section class="card dashboard-panel" aria-labelledby="personal-heading">
            <div class="dashboard-panel-heading"><div><h2 id="personal-heading"><span class="panel-icon is-green" aria-hidden="true"><x-app-icon name="users"/></span>Personal information</h2><p class="panel-description">Update your name, contact number and address.</p></div></div>
            <div class="ac-card-body">
                <form method="POST" action="{{ route($profileRoutePrefix.'.update') }}" data-service-submit>
                    @csrf @method('PUT')
                    @if($isResident)
                        @unless($user->hasStructuredName())
                            <div class="alert alert-info"><strong>Complete your personal information</strong><p class="mb-0">Your existing name is {{ $user->name }}. Your recorded address is {{ $user->address ?: 'Not provided' }}. Enter the separate name and address fields below when you are ready. Saving identity or address changes requires Admin review.</p></div>
                        @endunless
                        @include('partials.resident-identity-fields', ['identityUser' => $user, 'identityProfile' => $residentProfile])
                        <p class="form-text">Verified email · {{ $user->email }}. Email changes are unavailable here.</p>
                    @else
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label">Full name</label>
                                <input id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" required maxlength="255" autocomplete="name" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                                @error('name')<div id="name-error" class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="profile-email" class="form-label">Email address</label>
                                <input id="profile-email" class="form-control" value="{{ $user->email }}" readonly aria-describedby="email-help">
                                <p id="email-help" class="form-text">Your email is verified. Email changes are not available on this page.</p>
                            </div>
                            <div class="col-md-6">
                                <label for="contact_number" class="form-label">Contact number <span class="text-secondary fw-normal">(optional)</span></label>
                                <input type="tel" id="contact_number" name="contact_number" class="form-control @error('contact_number') is-invalid @enderror" value="{{ old('contact_number', $user->contact_number) }}" maxlength="30" autocomplete="tel" aria-describedby="contact-help @error('contact_number') contact-error @enderror" @error('contact_number') aria-invalid="true" @enderror>
                                <p id="contact-help" class="form-text">Philippine mobile or landline, for example 0917 123 4567.</p>
                                @error('contact_number')<div id="contact-error" class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12">
                                <label for="address" class="form-label">Full address for documents <span class="text-secondary fw-normal">(optional)</span></label>
                                <textarea id="address" name="address" class="form-control @error('address') is-invalid @enderror" rows="3" maxlength="1000" autocomplete="street-address" @error('address') aria-invalid="true" aria-describedby="address-error" @enderror>{{ old('address', $user->address) }}</textarea>
                                @error('address')<div id="address-error" class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    @endif
                    <div class="ac-form-actions"><a href="{{ route($user->homeRouteName()) }}" class="btn btn-outline-secondary">Back to dashboard</a><button type="submit" class="btn btn-primary" data-saving-label="Saving…">Save changes</button></div>
                </form>
            </div>
        </section>
        @if($isResident)
            @include('profile.demographics')
        @endif
    </div>
</div>
@endsection
