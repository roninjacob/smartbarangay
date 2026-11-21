@extends('layouts.auth-action')
@section('title', 'Change email address')
@section('heading', 'Entered the wrong email?')
@section('description', "Update your email address and we'll send a new verification link.")
@section('form')
<x-auth-feedback />
<form method="POST" action="{{ route('verification.email.update') }}" class="au-form" data-auth-form>
    @csrf
    @method('PUT')
    <x-form-field name="email" label="New Email Address" type="email" autocomplete="email" maxlength="255" />
    <x-form-field name="current_password" label="Current Password" type="password" autocomplete="current-password" maxlength="72" hint="Confirm your password to protect your account." />
    <button type="submit" class="btn btn-primary btn-lg w-100 au-submit" data-submit-label="Updating…">Update Email &amp; Send Verification</button>
</form>
@if(auth()->user()->role === \App\Enums\UserRole::Resident)
<p class="au-muted au-small">Changing your email does not extend the {{ \App\Models\User::UNVERIFIED_RETENTION_DAYS }}-day verification period from your original registration.</p>
@endif
<p class="au-switch"><a class="au-link" href="{{ route('verification.notice') }}">&larr; Back to Email Verification</a></p>
@endsection
