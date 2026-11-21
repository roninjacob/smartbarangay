@extends('layouts.auth-action', ['icon' => 'warning'])
@section('title', 'Verification link unavailable')
@section('heading', 'Request a fresh link')
@section('description', 'This verification link is invalid or has expired.')
@section('form')
<div class="au-alert au-alert-warning" role="alert"><span>Use the latest email verification link sent to your account.</span></div>
@if(auth()->check() && ! auth()->user()->hasVerifiedEmail())
    <form method="POST" action="{{ route('verification.send') }}" data-auth-form>
        @csrf
        <button type="submit" class="btn btn-primary btn-lg w-100" data-submit-label="Sending…">Request New Verification Email</button>
    </form>
    <p class="au-switch"><a class="au-link" href="{{ route('verification.notice') }}">Back to Email Verification</a></p>
@else
    <a class="btn btn-primary btn-lg w-100" href="{{ route('home') }}">Continue to SmartBarangay</a>
@endif
@endsection
