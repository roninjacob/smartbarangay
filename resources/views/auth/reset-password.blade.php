@extends('layouts.auth-action', ['icon' => 'lock'])
@section('title', 'Reset password')
@section('eyebrow', 'SECURE PASSWORD RESET')
@section('heading', 'Choose a new password')
@section('description', 'Set a new password for your SmartBarangay account. Use at least 8 characters, including letters and numbers.')
@section('form')
<x-auth-feedback />
<form method="POST" action="{{ route('password.update') }}" data-auth-form>
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label" for="email">Email Address</label>
            <input id="email" name="email" type="email" autocomplete="email" maxlength="255" value="{{ old('email', $email) }}"
                @class(['form-control', 'is-invalid' => $errors->has('email')]) required
                aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" @error('email') aria-describedby="email-error" @enderror>
            @error('email')<div id="email-error" class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6"><x-form-field name="password" label="New Password" type="password" autocomplete="new-password" minlength="8" maxlength="72" hint="At least 8 characters, including letters and numbers." /></div>
        <div class="col-md-6"><x-form-field name="password_confirmation" label="Confirm New Password" type="password" autocomplete="new-password" minlength="8" maxlength="72" /></div>
        <div class="col-12"><button type="submit" class="btn btn-primary w-100" data-submit-label="Resetting…">Reset Password</button></div>
    </div>
</form>
<a class="auth-secondary-link" href="{{ route('login') }}">← Back to Sign In</a>
@endsection
