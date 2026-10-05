@extends('layouts.auth-action')
@section('title', 'Verification link unavailable')
@section('eyebrow', 'EMAIL VERIFICATION')
@section('heading', 'Request a fresh link')
@section('description', 'This verification link is invalid or has expired.')
@section('form')
<div class="alert alert-warning" role="alert">Use the latest email verification link sent to your account.</div>
@if(auth()->check() && ! auth()->user()->hasVerifiedEmail())
    <form method="POST" action="{{ route('verification.send') }}" data-auth-form>
        @csrf
        <button type="submit" class="btn btn-primary w-100" data-submit-label="Sending…">Request New Verification Email</button>
    </form>
    <a class="auth-secondary-link" href="{{ route('verification.notice') }}">Back to Email Verification</a>
@else
    <a class="btn btn-primary w-100" href="{{ route('home') }}">Continue to SmartBarangay</a>
@endif
@endsection
