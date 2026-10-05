@extends('layouts.auth-action', ['icon' => 'lock'])
@section('title', 'Password reset link unavailable')
@section('eyebrow', 'PASSWORD RESET')
@section('heading', 'Request a fresh link')
@section('description', 'This password reset link is invalid or has expired.')
@section('form')
<div class="alert alert-warning" role="alert">For your security, password reset links expire and can only be used once.</div>
<a class="btn btn-primary w-100" href="{{ route('password.request') }}">Request a New Reset Link</a>
<a class="auth-secondary-link" href="{{ route('login') }}">← Back to Sign In</a>
@endsection
