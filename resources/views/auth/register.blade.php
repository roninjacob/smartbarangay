@extends('layouts.auth')
@section('title', 'Resident registration')
@section('shell-class', 'auth-shell-register')
@section('brand-title', 'Register once, then request documents online.')
@section('heading', 'Create your resident account')
@section('subtitle', 'For residents of Barangay Calayo, Nasugbu, Batangas.')

@section('form')
@if($errors->any())
    <div class="au-alert au-alert-danger" role="alert"><span>Please correct the highlighted fields to create your account.</span></div>
@endif

<div class="au-note">
    <x-app-icon name="users"/>
    <p>After you verify your email, the barangay office reviews your residency. You can submit document requests once it is approved.</p>
</div>

<form method="POST" action="{{ route('register.store') }}" class="au-form" data-auth-form>
    @csrf
    <fieldset class="au-fieldset">
        <legend>Account</legend>
        <div class="au-grid">
            <div class="au-span-2"><x-form-field name="email" label="Email" type="email" autocomplete="email" maxlength="255" /></div>
            <x-form-field name="password" label="Password" type="password" autocomplete="new-password"
                minlength="8" maxlength="72" hint="At least 8 characters, including letters and numbers." />
            <x-form-field name="password_confirmation" label="Confirm Password" type="password"
                autocomplete="new-password" minlength="8" maxlength="72" />
        </div>
    </fieldset>

    @include('partials.resident-identity-fields', ['identityGrouped' => true])

    <button type="submit" class="btn btn-primary btn-lg w-100 au-submit" data-submit-label="Creating account…">Create Resident Account</button>
</form>

<p class="au-switch">Already registered? <a class="au-link" href="{{ route('login') }}">Sign in instead</a></p>
@endsection
