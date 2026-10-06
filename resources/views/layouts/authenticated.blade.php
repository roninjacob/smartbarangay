@extends('layouts.app')
@section('title', $pageTitle ?? $roleLabel.' Dashboard')

@section('content')
<div class="app-shell">
    <aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="app-sidebar" aria-labelledby="app-sidebar-heading">
        <div class="app-sidebar-brand">
            @include('partials.brand')
            <button type="button" class="btn-close d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#app-sidebar" aria-label="Close navigation"></button>
        </div>
        <div class="app-sidebar-body">
            <h2 id="app-sidebar-heading" class="app-role-tag">{{ $roleLabel }} Area</h2>
            <x-app-navigation :groups="$navigation" :role-label="$roleLabel"/>
            <div class="app-sidebar-note"><span class="app-office-dot" aria-hidden="true"></span><span>Serving Barangay Calayo<br><small>Nasugbu, Batangas</small></span></div>
        </div>
    </aside>

    <div class="app-workspace">
        <header class="app-topbar">
            <div class="d-flex align-items-center gap-3 min-w-0">
                <button type="button" class="app-menu-button btn d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#app-sidebar" aria-controls="app-sidebar" aria-label="Open navigation"><x-app-icon name="menu"/></button>
                <div class="app-topbar-location"><strong>Barangay Calayo</strong><span>Nasugbu, Batangas</span></div>
            </div>
            <button type="button" class="app-user-button" data-bs-toggle="modal" data-bs-target="#account-summary" aria-label="View your profile overview">
                <span class="app-avatar" aria-hidden="true">@if(auth()->user()->ownedProfilePicturePath())<img src="{{ route(auth()->user()->role->value.'.profile.picture') }}" alt="">@else{{ mb_substr(auth()->user()->name, 0, 1) }}@endif</span>
                <span class="app-user-text"><strong>{{ auth()->user()->name }}</strong><small>{{ $roleLabel }}</small></span>
                <x-app-icon name="account" class="app-user-icon"/>
            </button>
        </header>

        <main id="main-content" class="app-main" tabindex="-1">
            <div class="app-page-heading">
                <div><span class="app-eyebrow">{{ $roleLabel }} / {{ $pageSection ?? 'Overview' }}</span><h1>{{ $pageHeading ?? 'Dashboard' }}</h1></div>
                <time datetime="{{ $dashboardDate->toDateString() }}" class="app-date"><x-app-icon name="calendar"/>{{ $dashboardDate->format('D, M j, Y') }}</time>
            </div>
            @if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
            @yield('dashboard')
            <footer class="app-footer"><span>SmartBarangay</span><span>Barangay Calayo · Nasugbu, Batangas</span></footer>
        </main>
    </div>
</div>
<x-account-summary :user="auth()->user()" :role-label="$roleLabel"/>
@endsection
