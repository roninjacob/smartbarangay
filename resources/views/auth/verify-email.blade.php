@extends('layouts.auth-action')
@section('title', 'Verify your email')
@section('heading', 'Check your inbox')
@section('description', 'We sent a verification link to your email. Open it to activate your SmartBarangay account.')
@section('form')
<ol class="au-progress" aria-label="Account setup progress">
    <li class="is-done"><span aria-hidden="true">✓</span> Account created</li>
    <li aria-current="step"><span aria-hidden="true">2</span> Verify email</li>
    <li><span aria-hidden="true">3</span> Open your dashboard</li>
</ol>
<x-auth-feedback />
<div class="au-destination"><span>Verification email destination</span><strong>{{ auth()->user()->email }}</strong></div>
<p class="au-muted au-sync" data-verification-sync data-status-url="{{ route('verification.status') }}" role="status" aria-live="polite">This page checks automatically when your email is verified, including on another device.</p>
<p class="au-muted">Didn't receive the email? Check your Spam or Junk folder, or request another verification link.</p>
@if(auth()->user()->role === \App\Enums\UserRole::Resident)
<p class="au-muted">Wrong email address? <a class="au-link" href="{{ route('verification.email.edit') }}">Change Email Address</a></p>
<p class="au-muted au-small">For security and account maintenance, unverified Resident accounts may be removed {{ \App\Models\User::UNVERIFIED_RETENTION_DAYS }} days after registration.</p>
@endif
<div class="au-actions">
    <form method="POST" action="{{ route('verification.send') }}" data-auth-form>
        @csrf
        <button type="submit" class="btn btn-primary btn-lg w-100" data-submit-label="Sending…">Resend Verification Email</button>
    </form>
    <form method="POST" action="{{ route('logout') }}" data-auth-form>
        @csrf
        <button type="submit" class="btn btn-outline-secondary btn-lg w-100" data-submit-label="Signing out…">Logout</button>
    </form>
</div>
@endsection
