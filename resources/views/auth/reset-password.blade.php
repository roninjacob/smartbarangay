@extends('layouts.auth')
@section('title', 'Reset password')
@section('brand-title', 'Choose a new password for your account.')
@section('heading', 'Choose a new password')
@section('subtitle', 'Use at least 8 characters, including letters and numbers.')

@section('form')
<x-auth-feedback />
<form method="POST" action="{{ route('password.update') }}" class="au-form" data-auth-form>
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <div class="form-field">
        <label class="form-label" for="email">Email Address</label>
        <input id="email" name="email" type="email" autocomplete="email" maxlength="255" value="{{ old('email', $email) }}"
            @class(['form-control', 'is-invalid' => $errors->has('email')]) required
            aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" @error('email') aria-describedby="email-error" @enderror>
        @error('email')<div id="email-error" class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <x-form-field name="password" label="New Password" type="password" autocomplete="new-password" minlength="8" maxlength="72" hint="At least 8 characters, including letters and numbers." />
    <x-form-field name="password_confirmation" label="Confirm New Password" type="password" autocomplete="new-password" minlength="8" maxlength="72" />
    <button type="submit" class="btn btn-primary btn-lg w-100 au-submit" data-submit-label="Resetting…">Reset Password</button>
</form>
<p class="au-switch"><a class="au-link" href="{{ route('login') }}">Back to Sign In</a></p>
@endsection
