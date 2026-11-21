@extends('layouts.auth-action', ['icon' => 'warning'])
@section('title', 'Access restricted')
@section('heading', 'This area is restricted.')
@section('form')
@php($viewer = auth()->user()?->role instanceof \App\Enums\UserRole ? auth()->user() : null)
@if(($exception?->getMessage() ?? '') === \App\Enums\ResidencyStatus::BLOCKED_MESSAGE)
    <p class="au-card-lead">{{ \App\Enums\ResidencyStatus::BLOCKED_MESSAGE }}</p>
    <div class="au-actions">
        <a href="{{ route('resident.profile.edit') }}" class="btn btn-primary btn-lg w-100">Review residency status</a>
        <a href="{{ $viewer ? route($viewer->entryRouteName()) : route('home') }}" class="btn btn-outline-secondary btn-lg w-100">{{ $viewer ? 'Back to dashboard' : 'Back to home' }}</a>
    </div>
@else
    <p class="au-card-lead">Your account does not have access to this page.</p>
    <a href="{{ $viewer ? route($viewer->entryRouteName()) : route('home') }}" class="btn btn-primary btn-lg w-100">{{ $viewer ? 'Back to dashboard' : 'Back to home' }}</a>
@endif
@endsection
