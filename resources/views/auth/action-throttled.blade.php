@extends('layouts.auth-action')
@section('title', 'Please wait')
@section('eyebrow', 'ACCOUNT SECURITY')
@section('heading', 'Try again shortly')
@section('description', $verification ? 'Please wait before requesting another verification email.' : 'Please wait before making another password reset request.')
@section('form')
<div class="alert alert-warning" role="alert">We limit repeated requests to help keep your SmartBarangay account secure.</div>
<a class="btn btn-primary w-100" href="{{ route($verification ? 'verification.notice' : 'password.request') }}">{{ $verification ? 'Back to Email Verification' : 'Back to Password Reset' }}</a>
@endsection
