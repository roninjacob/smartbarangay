@extends('layouts.app')

{{-- Split layout for the account forms: brand panel on the left, form column on the right. --}}
@section('content')
@php
    $previewStatuses = collect([
        \App\Enums\ReservationStatus::Pending,
        \App\Enums\ReservationStatus::UnderReview,
        \App\Enums\ReservationStatus::Approved,
        \App\Enums\ReservationStatus::ReadyForPickup,
    ])->map(fn ($status) => ['label' => $status->label(), 'tone' => $status->value])->values();
@endphp
<main id="main-content" class="auth-page au-split @yield('shell-class')" data-auth-page>
    <aside class="au-brand" aria-label="About SmartBarangay">
        <div class="au-brand-bg" aria-hidden="true"></div>
        <a class="au-lockup" href="{{ route('home') }}"><x-brand-mark/><span><strong>SmartBarangay</strong><small>Barangay Calayo &middot; Nasugbu, Batangas</small></span></a>
        <div class="au-brand-body">
            <p class="au-brand-title">@yield('brand-title', 'Request Barangay Calayo documents online.')</p>
            <ul class="au-facts">
                <li><span class="au-fact-icon"><x-app-icon name="qr"/></span>QR ticket after approval</li>
                <li><span class="au-fact-icon"><x-app-icon name="bell"/></span>A notification when your status changes</li>
                <li><span class="au-fact-icon"><x-app-icon name="users"/></span>Residency reviewed by the barangay office</li>
            </ul>
            <div class="au-mini" aria-hidden="true">
                <span class="au-mini-tile"><x-app-icon name="document"/></span>
                <div class="au-mini-text"><small>Your request</small><strong>Status</strong></div>
                <span class="au-chip" data-au-status-cycle='@json($previewStatuses)' data-tone="pending">Pending</span>
            </div>
        </div>
        <div class="au-skyline" aria-hidden="true">@include('partials.skyline')</div>
    </aside>

    <section class="au-main" aria-labelledby="auth-heading">
        <div class="au-main-inner">
            <div class="au-topbar">
                <a class="au-back" href="{{ route('home') }}"><span aria-hidden="true">&larr;</span> Back to home</a>
                <x-theme-toggle />
            </div>
            <header class="au-head">
                <h1 id="auth-heading">@yield('heading')</h1>
                @hasSection('subtitle')<p>@yield('subtitle')</p>@endif
            </header>
            @yield('form')
            <p class="au-help">Need help with your account? Visit the Barangay Calayo office.</p>
        </div>
    </section>
</main>
@endsection
