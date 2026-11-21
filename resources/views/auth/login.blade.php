@extends('layouts.auth')
@section('title', 'Sign in')
@section('shell-class', 'auth-shell-login')
@section('brand-title', 'Your requests and QR tickets, ready when you sign in.')
@section('heading', 'Sign in to SmartBarangay')
@section('subtitle', 'For Barangay Calayo residents and barangay administrators.')

@section('form')
@if(session('status'))
    <div class="au-alert au-alert-success" role="status"><span>{{ session('status') }}</span></div>
@endif
@if($errors->any())
    <div class="au-alert au-alert-danger" role="alert"><span>Unable to sign in. Please check the message below.</span></div>
@endif

<form method="POST" action="{{ route('login.store') }}" class="au-form" data-auth-form>
    @csrf
    <x-form-field name="email" label="Email" type="email" autocomplete="username"
        maxlength="255" placeholder="you@example.com" />
    <x-form-field name="password" label="Password" type="password"
        autocomplete="current-password" maxlength="72" :label-action="route('password.request')" />
    <button type="submit" class="btn btn-primary btn-lg w-100 au-submit" data-submit-label="Signing in…">
        <span>Sign In</span><span aria-hidden="true"> &rarr;</span>
    </button>
</form>

<p class="au-switch">New to SmartBarangay? <a class="au-link" href="{{ route('register') }}">Create a resident account</a></p>
@endsection
