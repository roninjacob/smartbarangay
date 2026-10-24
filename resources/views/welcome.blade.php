@extends('layouts.app')
@section('title', 'Barangay services, made simpler')
@section('content')
@php
    $viewer = auth()->user();
    $verified = $viewer && $viewer->hasVerifiedEmail();
    $resident = $viewer && $viewer->role === \App\Enums\UserRole::Resident;
    $entry = $viewer ? route($viewer->entryRouteName()) : route('login');
    $requests = $verified ? route($resident ? 'resident.reservations.index' : 'admin.reservations.index') : $entry;
    $start = $verified ? route($resident ? 'resident.reservations.create' : 'admin.reservations.index') : $entry;
@endphp
<div class="public-site">
    <header class="public-header">
        <nav class="navbar navbar-expand-lg container py-3" aria-label="Public navigation">
            <a class="public-brand text-decoration-none" href="{{ route('home') }}"><span class="public-brand-mark" aria-hidden="true">SB<span></span></span><span><strong>SmartBarangay</strong><small>Barangay Calayo &middot; Nasugbu, Batangas</small></span></a>
            <button class="navbar-toggler border-0 shadow-sm rounded-3" type="button" data-bs-toggle="collapse" data-bs-target="#public-nav" aria-controls="public-nav" aria-expanded="false" aria-label="Toggle public navigation"><x-app-icon name="menu"/></button>
            <div class="collapse navbar-collapse mt-3 mt-lg-0" id="public-nav">
                <ul class="navbar-nav ms-auto gap-lg-2 align-items-lg-center"><li class="nav-item"><a class="nav-link" href="#services">Services</a></li><li class="nav-item"><a class="nav-link" href="#how-it-works">How it works</a></li><li class="nav-item"><a class="nav-link" href="#about">About</a></li><li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
                    @if($viewer)<li class="nav-item"><a class="btn btn-primary rounded-pill px-4" href="{{ $entry }}">{{ $verified ? 'Go to Dashboard' : 'Verify Email' }}</a></li>
                    @else<li class="nav-item"><a class="nav-link fw-semibold" href="{{ route('login') }}">Sign In</a></li><li class="nav-item"><a class="btn btn-primary rounded-pill px-4" href="{{ route('register') }}">Register</a></li>@endif
                </ul>
            </div>
        </nav>
    </header>
    <main id="main-content">
        <section class="public-hero" aria-labelledby="hero-title">
            <div class="container py-5"><div class="row align-items-center gx-4 gy-5 py-lg-4">
                <div class="col-lg-7"><span class="public-eyebrow"><span aria-hidden="true"></span> YOUR COMMUNITY, CONNECTED</span><h1 class="display-5 fw-bold mt-4 mb-4" id="hero-title">Barangay services, made simpler.</h1>
                    <p class="lead public-copy mb-4">Request a barangay document, choose an available schedule, and follow your request from wherever you are.</p>
                    <div class="d-flex flex-wrap gap-3">@if($viewer)<a class="btn btn-primary btn-lg rounded-pill px-4" href="{{ $entry }}">{{ $verified ? 'Go to Dashboard' : 'Verify Email' }} <span aria-hidden="true">&rarr;</span></a>@else<a class="btn btn-primary btn-lg rounded-pill px-4" href="{{ $start }}">Request a Document <span aria-hidden="true">&rarr;</span></a>@endif
                        <a class="btn btn-outline-primary btn-lg rounded-pill px-4" href="{{ $viewer ? $requests : route('register') }}">{{ $viewer ? ($resident ? 'View My Requests' : 'View Reservations') : 'Create an Account' }}</a></div>
                    <p class="small text-secondary mt-4 mb-0">Built for Barangay Calayo residents. Your documents and personal information stay in your protected account.</p>
                </div>
                <div class="col-lg-5"><div class="public-journey p-4 p-xl-5 rounded-5 shadow-sm"><div class="d-flex align-items-center gap-3 mb-4"><span class="public-icon"><x-app-icon name="document"/></span><div><span class="public-eyebrow">A CLEARER WAY FORWARD</span><h2 class="h4 mt-1 mb-0">Your next visit starts here.</h2></div></div>
                    <ol class="public-journey-steps list-unstyled mb-0"><li><span>01</span><div><h3 class="h6">Make your request</h3><p>Choose a service and send the required information.</p></div></li><li><span>02</span><div><h3 class="h6">Stay in the loop</h3><p>See updates and know when your request is ready.</p></div></li><li><span>03</span><div><h3 class="h6">Visit with confidence</h3><p>Bring your QR ticket when your request is Ready for Pickup.</p></div></li></ol>
                    <div class="public-community-note mt-4"><span class="public-dot" aria-hidden="true"></span> One barangay. A more connected community.</div>
                </div></div>
            </div></div>
        </section>
        <section class="container py-5" id="services" aria-labelledby="services-title"><div class="public-section-heading mb-4"><div><span class="public-eyebrow">LET'S GET YOU STARTED</span><h2 class="display-6 fw-bold mt-2" id="services-title">What can we help you with?</h2></div><p class="text-secondary mb-0">Explore available services. Sign in to review requirements and request a schedule.</p></div>
            <div class="row g-4">@forelse($services as $service)<article class="col-md-6 col-xl-4"><div class="card border-0 shadow-sm h-100 rounded-4 public-service-card"><div class="card-body p-4"><span class="public-icon mb-4"><x-app-icon name="document"/></span><h3 class="h5">{{ $service->name }}</h3>@if($service->description)<p class="text-secondary public-copy">{{ $service->description }}</p>@else<p class="text-secondary">Review this service's requirements in your account.</p>@endif<a class="public-service-link stretched-link" href="{{ $start }}">{{ $viewer && ! $resident ? 'View reservations' : 'Start a request' }} <span aria-hidden="true">&rarr;</span></a></div></div></article>
            @empty<div class="col-12"><div class="card border-0 shadow-sm rounded-4 p-4"><h3 class="h5">Services will appear here when available.</h3><p class="text-secondary mb-0">Please check back or contact the Barangay Calayo office for assistance.</p></div></div>@endforelse</div>
            @if($services->hasPages())<div class="public-pagination mt-4">{{ $services->links('pagination::bootstrap-5') }}</div>@endif
        </section>
        <section class="public-soft-section py-5" id="how-it-works" aria-labelledby="how-title"><div class="container"><span class="public-eyebrow">SIMPLE STEPS, LESS GUESSWORK</span><h2 class="display-6 fw-bold mt-2 mb-4" id="how-title">From your screen to your barangay.</h2><div class="row g-4">
            @foreach(['Create your account' => 'Register as a Resident and verify your email to access your protected account.', 'Send your request' => 'Choose a service, complete the document information, submit supporting files, and select an available schedule.', 'Track, then visit' => 'Follow status updates in your account. Wait until Ready for Pickup, bring your QR ticket, and follow the office collection instructions.'] as $heading => $copy)<div class="col-md-4"><div class="public-step h-100 p-4 rounded-4"><span class="public-step-number">{{ $loop->iteration }}</span><h3 class="h5 mt-4">{{ $heading }}</h3><p class="text-secondary mb-0">{{ $copy }}</p></div></div>@endforeach
        </div></div></section>
        <section class="container py-5" id="about" aria-labelledby="about-title"><div class="row gx-4 gy-5 align-items-start"><div class="col-lg-5"><span class="public-eyebrow">MADE FOR OUR COMMUNITY</span><h2 class="display-6 fw-bold mt-2" id="about-title">A helpful connection to Barangay Calayo.</h2></div><div class="col-lg-7"><p class="lead public-copy">SmartBarangay helps Barangay Calayo residents reserve document services online and keep track of their requests in one place.</p></div></div></section>
        <section class="container pb-5" id="contact" aria-labelledby="contact-title"><div class="public-contact rounded-5 p-4 p-md-5"><div class="row g-4"><div class="col-lg-5"><span class="public-eyebrow">WE'RE HERE TO HELP</span><h2 class="h2 mt-2" id="contact-title">Connect with your barangay.</h2><p class="mb-0">Barangay Calayo<br>Nasugbu, Batangas</p></div><div class="col-lg-7"><dl class="row g-4 mb-0">
            <div class="col-md-6"><dt>Office address</dt><dd class="public-copy">Office address details have not been posted yet.</dd></div><div class="col-md-6"><dt>Office hours</dt><dd class="public-copy">Office hours have not been posted yet.</dd></div>
            <div class="col-md-6"><dt>Public contact number</dt><dd>Contact number has not been posted yet.</dd></div><div class="col-md-6"><dt>Public email</dt><dd>Public email has not been posted yet.</dd></div>
        </dl></div></div></div></section>
    </main>
    <footer class="public-footer py-4"><div class="container d-flex flex-wrap justify-content-between gap-3"><div><strong>SmartBarangay</strong><p class="small mb-0">Barangay Calayo &middot; Nasugbu, Batangas</p></div><a href="#hero-title">Back to top &uarr;</a></div></footer>
</div>
@endsection