@extends('layouts.auth')
@section('title', 'Sign in')
@section('shell-class', 'auth-shell-login')

@section('auth-intro')
<div class="auth-heading">
    <span class="section-eyebrow">RESIDENT &amp; ADMIN ACCESS</span>
    <h2 id="auth-heading">Welcome Back</h2>
    <p>Sign in to access SmartBarangay and manage digital barangay service requests for Barangay Calayo.</p>
    <p class="auth-audience">For Barangay Calayo residents and authorized administrators.</p>
</div>
@endsection

@section('form')
@if(session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">Unable to sign in. Please check the message below.</div>
@endif

<form method="POST" action="{{ route('login.store') }}" data-auth-form>
    @csrf
    <div class="row g-4">
        <div class="col-12">
            <x-form-field name="email" label="Email" type="email" autocomplete="username"
                maxlength="255" placeholder="you@example.com" />
        </div>
        <div class="col-12">
            <x-form-field name="password" label="Password" type="password"
                autocomplete="current-password" maxlength="72" :label-action="route('password.request')" />
        </div>
        <div class="col-12">
            <button type="submit" class="btn btn-primary w-100 auth-submit" data-submit-label="Signing in…">
                <span>Sign In</span><span aria-hidden="true"> →</span>
            </button>
        </div>
    </div>
</form>

<div class="auth-divider"><span>New to SmartBarangay?</span></div>
<a class="btn btn-outline-primary w-100" href="{{ route('register') }}">Register as Resident</a>
@endsection
