@extends('layouts.app')
@section('title', $pageTitle ?? $roleLabel.' Dashboard')

@section('content')
@php
    $shellUser = auth()->user();
    $shellPicture = $shellUser->ownedProfilePicturePath() ? route($shellUser->role->value.'.profile.picture') : null;
    $shellInitial = mb_substr($shellUser->name, 0, 1);
@endphp
<div class="app-shell app-role-{{ $shellUser->role->value }}">
    <aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="app-sidebar" aria-labelledby="app-sidebar-heading">
        <div class="app-sidebar-brand">
            @include('partials.brand')
            <button type="button" class="btn-close d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#app-sidebar" aria-label="Close navigation"></button>
        </div>
        <h2 id="app-sidebar-heading" class="visually-hidden">{{ $roleLabel }} Area</h2>
        <div class="app-sidebar-body">
            <x-app-navigation :groups="$navigation" :role-label="$roleLabel"/>
        </div>
        <button type="button" class="app-sidebar-user" data-bs-toggle="modal" data-bs-target="#account-summary" aria-label="Open your account overview">
            <span class="app-avatar" aria-hidden="true">@if($shellPicture)<img src="{{ $shellPicture }}" alt="">@else{{ $shellInitial }}@endif</span>
            <span class="app-sidebar-user-text"><strong>{{ $shellUser->name }}</strong><small>{{ $roleLabel }}</small></span>
            <x-app-icon name="arrow" class="app-sidebar-user-arrow"/>
        </button>
    </aside>

    <div class="app-workspace">
        <header class="app-topbar" data-app-topbar>
            <div class="app-topbar-start">
                <button type="button" class="app-menu-button d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#app-sidebar" aria-controls="app-sidebar" aria-label="Open navigation"><x-app-icon name="menu"/></button>
                <span class="app-topbar-title" aria-hidden="true">{{ $pageHeading ?? 'Dashboard' }}</span>
            </div>
            <div class="app-header-actions">
                <x-theme-toggle/>
                @include('partials.notification-bell')
                <button type="button" class="app-user-button" data-bs-toggle="modal" data-bs-target="#account-summary" aria-label="View your profile overview">
                    <span class="app-avatar" aria-hidden="true">@if($shellPicture)<img src="{{ $shellPicture }}" alt="">@else{{ $shellInitial }}@endif</span>
                </button>
            </div>
        </header>

        <main id="main-content" class="app-main" tabindex="-1">
            <div class="app-page-heading">
                <div class="app-page-title">
                    <h1>{{ $pageHeading ?? 'Dashboard' }}</h1>
                    @isset($pageSubtitle)<p>{{ $pageSubtitle }}</p>@endisset
                </div>
                <time datetime="{{ $dashboardDate->toDateString() }}" class="app-date"><x-app-icon name="calendar"/>{{ $dashboardDate->format('D, M j, Y') }}</time>
            </div>
            @if(session('status'))<div class="alert alert-success civic-feedback" role="status" @if(request()->routeIs('*.profile.*', '*.settings.*')) data-routine-toast @endif><x-app-icon name="check"/><span>{{ session('status') }}</span></div>@endif
            @yield('dashboard')
            <footer class="app-footer">SmartBarangay &middot; Barangay Calayo, Nasugbu, Batangas</footer>
        </main>
    </div>
</div>
@if($shellUser->role === \App\Enums\UserRole::Resident)
    <x-resident-mobile-navigation/>
@endif
<x-account-summary :user="$shellUser" :role-label="$roleLabel"/>
@endsection
