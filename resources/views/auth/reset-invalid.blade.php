@extends('layouts.auth-action', ['icon' => 'warning'])
@section('title', 'Password reset link unavailable')
@section('heading', 'Request a fresh link')
@section('description', 'This password reset link is invalid or has expired.')
@section('form')
<div class="au-alert au-alert-warning" role="alert"><span>For your security, password reset links expire and can only be used once.</span></div>
<a class="btn btn-primary btn-lg w-100" href="{{ route('password.request') }}">Request a New Reset Link</a>
<p class="au-switch"><a class="au-link" href="{{ route('login') }}">&larr; Back to Sign In</a></p>
@endsection
