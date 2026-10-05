@extends('layouts.auth-action')
@section('title', 'Change email address')
@section('eyebrow', 'CHANGE EMAIL ADDRESS')
@section('heading', 'Entered the wrong email?')
@section('description', "Update your email address and we'll send a new verification link.")
@section('form')
<x-auth-feedback />
<form method="POST" action="{{ route('verification.email.update') }}" data-auth-form>
    @csrf
    @method('PUT')
    <div class="row g-3">
        <div class="col-12"><x-form-field name="email" label="New Email Address" type="email" autocomplete="email" maxlength="255" /></div>
        <div class="col-12"><x-form-field name="current_password" label="Current Password" type="password" autocomplete="current-password" maxlength="72" hint="Confirm your password to protect your account." /></div>
        <div class="col-12"><button type="submit" class="btn btn-primary w-100" data-submit-label="Updating…">Update Email &amp; Send Verification</button></div>
    </div>
</form>
@if(auth()->user()->role === \App\Enums\UserRole::Resident)
<p class="auth-action-hint mt-3">Changing your email does not extend the {{ \App\Models\User::UNVERIFIED_RETENTION_DAYS }}-day verification period from your original registration.</p>
@endif
<a class="auth-secondary-link" href="{{ route('verification.notice') }}">← Back to Email Verification</a>
@endsection
