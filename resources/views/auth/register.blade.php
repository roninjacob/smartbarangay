@extends('layouts.auth')
@section('title', 'Resident registration')
@section('shell-class', 'auth-shell-register')

@section('auth-intro')
<a class="back-link" href="{{ route('login') }}"><span aria-hidden="true">←</span> Back to Sign In</a>
<div class="auth-heading">
    <span class="section-eyebrow">FOR BARANGAY CALAYO RESIDENTS</span>
    <h2 id="auth-heading">Create your account</h2>
    <p>Create your resident account to access SmartBarangay services in Barangay Calayo.</p>
    <p class="auth-audience">Reserve barangay documents, choose available schedules, track request progress, and access your QR tickets through one resident account.</p>
</div>
@endsection

@section('service-info-heading', 'Your SmartBarangay account gives you access to:')
@section('support-heading', 'Need help creating your account?')
@section('document-feature')
<strong class="auth-feature-title">Online Document Requests</strong>
Reserve available Barangay Calayo document services.
@endsection
@section('tracking-feature')
<strong class="auth-feature-title">Reservation Tracking</strong>
Monitor the status of your submitted requests.
@endsection
@section('qr-feature')
<strong class="auth-feature-title">QR Ticket Access</strong>
Access QR-based verification after approval.
@endsection

@section('form')
@if($errors->any())
    <div class="alert alert-danger" role="alert">Please correct the highlighted fields to create your account.</div>
@endif

<form method="POST" action="{{ route('register.store') }}" data-auth-form>
    @csrf
    <div class="row g-3">
        <div class="col-12">
            <x-form-field name="name" label="Full Name" autocomplete="name" maxlength="255" />
        </div>
        <div class="col-md-6">
            <x-form-field name="email" label="Email" type="email" autocomplete="email" maxlength="255" />
        </div>
        <div class="col-md-6">
            <x-form-field name="contact_number" label="Contact Number" type="tel" autocomplete="tel"
                maxlength="30" placeholder="0917 123 4567" />
        </div>
        <div class="col-12">
            <label for="address" class="form-label">Address in Barangay Calayo</label>
            <textarea id="address" name="address" rows="2" maxlength="1000" autocomplete="street-address"
                @class(['form-control', 'is-invalid' => $errors->has('address')])
                aria-invalid="{{ $errors->has('address') ? 'true' : 'false' }}"
                aria-describedby="address-hint @error('address') address-error @enderror" required>{{ old('address') }}</textarea>
            <div id="address-hint" class="form-text">House number, street or sitio within Barangay Calayo, Nasugbu, Batangas.</div>
            @error('address')<div id="address-error" class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <x-form-field name="password" label="Password" type="password" autocomplete="new-password"
                minlength="8" maxlength="72" hint="At least 8 characters, including letters and numbers." />
        </div>
        <div class="col-md-6">
            <x-form-field name="password_confirmation" label="Confirm Password" type="password"
                autocomplete="new-password" minlength="8" maxlength="72" />
        </div>
        <div class="col-12 pt-2">
            <button type="submit" class="btn btn-primary w-100 auth-submit" data-submit-label="Creating account…">Create Resident Account</button>
        </div>
    </div>
</form>
<div class="auth-divider"><span>Already registered?</span></div>
<a class="btn btn-outline-primary w-100" href="{{ route('login') }}">Sign in to your account</a>
@endsection
