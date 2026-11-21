@extends('layouts.auth-action', ['icon' => 'clock'])
@section('title', 'Please wait')
@section('heading', 'Try again shortly')
@section('description', $verification ? 'Please wait before requesting another verification email.' : 'Please wait before making another password reset request.')
@section('form')
<div class="au-alert au-alert-warning" role="alert"><span>We limit repeated requests to help keep your SmartBarangay account secure.</span></div>
<a class="btn btn-primary btn-lg w-100" href="{{ route($verification ? 'verification.notice' : 'password.request') }}">{{ $verification ? 'Back to Email Verification' : 'Back to Password Reset' }}</a>
@endsection
