@extends('layouts.app')

{{-- Centered card for verification, throttling, expired-link and access pages. --}}
@section('content')
<main id="main-content" class="auth-page au-center @yield('shell-class')" data-auth-page>
    <div class="au-brand-bg" aria-hidden="true"></div>
    <x-theme-toggle tone="on-dark" class="au-theme-corner" />
    <div class="au-center-inner">
        <a class="au-lockup" href="{{ route('home') }}"><x-brand-mark/><span><strong>SmartBarangay</strong><small>Barangay Calayo &middot; Nasugbu, Batangas</small></span></a>
        <section class="au-card" aria-labelledby="auth-heading">
            <x-auth-symbol :type="$icon ?? 'mail'" />
            <h1 id="auth-heading">@yield('heading')</h1>
            @hasSection('description')<p class="au-card-lead">@yield('description')</p>@endif
            @yield('form')
        </section>
        <p class="au-center-foot">SmartBarangay &middot; Barangay Calayo, Nasugbu, Batangas</p>
    </div>
    <div class="au-skyline" aria-hidden="true">@include('partials.skyline')</div>
</main>
@endsection
