@extends('layouts.authenticated')
@section('dashboard')
<p class="text-secondary mb-4">Keep your contact details up to date for Barangay Calayo services.</p>
@if($errors->any())
    <div class="alert alert-danger" role="alert"><strong>Please check your profile.</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
<div class="row g-4 profile-settings">
    <div class="col-lg-4">
        <section class="profile-card" aria-labelledby="picture-heading">
            <h2 id="picture-heading">Profile picture</h2>
            <div class="profile-image-area">
                @if($user->ownedProfilePicturePath())
                    <img src="{{ route('resident.profile.picture') }}" class="profile-image" alt="Your current profile picture" width="128" height="128" data-profile-image>
                @else
                    <span class="profile-placeholder" role="img" aria-label="Default avatar">{{ mb_substr($user->name, 0, 1) }}</span>
                @endif
            </div>
            <p class="text-secondary">A photo is optional. You can use every Resident service without one.</p>
            <form method="POST" action="{{ route('resident.profile.picture.update') }}" enctype="multipart/form-data" data-profile-picture-form data-service-submit>
                @csrf
                <label for="profile_picture" class="form-label">Choose a picture</label>
                <input type="file" id="profile_picture" name="profile_picture" class="form-control @error('profile_picture') is-invalid @enderror" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required aria-describedby="picture-help picture-feedback @error('profile_picture') picture-error @enderror" @error('profile_picture') aria-invalid="true" @enderror>
                @error('profile_picture')<div id="picture-error" class="invalid-feedback">{{ $message }}</div>@enderror
                <p id="picture-help" class="form-text">JPG, JPEG, PNG or WebP. Up to 2 MB and 4096 × 4096 pixels.</p>
                <p id="picture-feedback" class="form-text" aria-live="polite" data-picture-feedback></p>
                <button type="submit" class="btn btn-primary w-100" data-saving-label="Uploading…">{{ $user->ownedProfilePicturePath() ? 'Replace picture' : 'Upload picture' }}</button>
            </form>
            @if($user->ownedProfilePicturePath())
                <form method="POST" action="{{ route('resident.profile.picture.destroy') }}" class="mt-3" data-service-submit>
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger w-100" data-saving-label="Removing…">Remove picture</button>
                </form>
            @endif
        </section>
    </div>
    <div class="col-lg-8">
        <section class="profile-card" aria-labelledby="personal-heading">
            <span class="app-eyebrow">YOUR SMARTBARANGAY ACCOUNT</span>
            <h2 id="personal-heading">Personal information</h2>
            <p class="text-secondary">Update your name, contact number and address.</p>
            <form method="POST" action="{{ route('resident.profile.update') }}" data-service-submit>
                @csrf @method('PUT')
                <div class="mb-4">
                    <label for="name" class="form-label">Full name</label>
                    <input id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" required maxlength="255" autocomplete="name" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                    @error('name')<div id="name-error" class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-4">
                    <label for="profile-email" class="form-label">Email address</label>
                    <input id="profile-email" class="form-control" value="{{ $user->email }}" readonly aria-describedby="email-help">
                    <p id="email-help" class="form-text">Your email is verified. Email changes are not available on this page.</p>
                </div>
                <div class="mb-4">
                    <label for="contact_number" class="form-label">Contact number <span class="text-secondary fw-normal">(optional)</span></label>
                    <input type="tel" id="contact_number" name="contact_number" class="form-control @error('contact_number') is-invalid @enderror" value="{{ old('contact_number', $user->contact_number) }}" maxlength="30" autocomplete="tel" aria-describedby="contact-help @error('contact_number') contact-error @enderror" @error('contact_number') aria-invalid="true" @enderror>
                    <p id="contact-help" class="form-text">Philippine mobile or landline, for example 0917 123 4567.</p>
                    @error('contact_number')<div id="contact-error" class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-4">
                    <label for="address" class="form-label">Address <span class="text-secondary fw-normal">(optional)</span></label>
                    <textarea id="address" name="address" class="form-control @error('address') is-invalid @enderror" rows="3" maxlength="1000" autocomplete="street-address" @error('address') aria-invalid="true" aria-describedby="address-error" @enderror>{{ old('address', $user->address) }}</textarea>
                    @error('address')<div id="address-error" class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="profile-actions"><a href="{{ route('resident.home') }}" class="btn btn-outline-secondary">Back to dashboard</a><button type="submit" class="btn btn-primary" data-saving-label="Saving…">Save changes</button></div>
            </form>
        </section>
    </div>
</div>
@endsection
