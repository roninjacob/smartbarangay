@extends('layouts.app')

@section('content')
<main id="main-content" class="auth-page container-fluid">
    <div class="auth-shell auth-shell-modern row g-0 @yield('shell-class')">
        <section class="brand-panel col-lg-5" aria-label="About SmartBarangay">
            <div class="brand-story">
            <header>@include('partials.brand')</header>
            <div class="brand-message">
                <span class="brand-eyebrow"><span aria-hidden="true"></span> YOUR COMMUNITY, CONNECTED</span>
                <h1>Barangay services,<br>
                    made simpler.</h1>
                <p class="brand-description brand-description-full">Reserve barangay documents, choose an available schedule, track your request, and use your QR ticket for verification—all through one organized system.</p>
                <p class="brand-description brand-description-compact">Reserve documents, choose a schedule, track requests, and verify your QR ticket.</p>
                <ul class="brand-capabilities list-unstyled">
                    <li>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8l-5-5Z"/>
                            <path d="M14 3v5h5M8 12h8M8 16h6"/>
                        </svg>
                        <span>Online Reservation</span>
                    </li>
                    <li>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>
                        </svg>
                        <span>Status Tracking</span>
                    </li>
                    <li>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path d="M3 3h6v6H3ZM15 3h6v6h-6ZM3 15h6v6H3ZM15 15h3v3h-3ZM21 14v4M14 21h4M21 21h-1"/>
                        </svg>
                        <span>QR Verification</span>
                    </li>
                </ul>
                <section class="brand-process" aria-labelledby="brand-process-heading">
                    <h2 id="brand-process-heading">HOW IT WORKS</h2>
                    <ol class="brand-process-steps list-unstyled mb-0">
                        <li><span class="brand-step-number">01</span> Reserve <span class="brand-step-arrow" aria-hidden="true">→</span></li>
                        <li><span class="brand-step-number">02</span> Track <span class="brand-step-arrow" aria-hidden="true">→</span></li>
                        <li><span class="brand-step-number">03</span> Verify</li>
                    </ol>
                </section>
            </div>
            <div class="brand-conclusion">
                <div class="civic-illustration" aria-hidden="true">
                    <svg viewBox="0 0 360 190" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M18 168H342" stroke="currentColor" stroke-opacity=".3"/>
                        <path d="M46 168V95L88 60L130 95V168M60 86V62H77V72M78 168V128H98V168" stroke="currentColor" stroke-width="2"/>
                        <path d="M228 168V104L275 64L322 104V168M249 124H263V138H249ZM287 124H301V138H287Z" stroke="currentColor" stroke-width="2"/>
                        <path d="M126 168V77L180 43L234 77V168M116 77H244M143 98H217M144 112V149M168 112V149M192 112V149M216 112V149M139 153H221M132 162H228" stroke="currentColor" stroke-width="2"/>
                        <path d="M180 43V17M180 17H205V32H180" stroke="#F2B84B" stroke-width="2"/>
                        <circle cx="41" cy="43" r="5" fill="#F2B84B"/>
                        <circle cx="316" cy="41" r="3" fill="currentColor" fill-opacity=".5"/>
                        <path d="M25 168V141M12 134C12 125 25 115 25 115S38 125 38 134C38 142 12 142 12 134Z" stroke="currentColor" stroke-width="2"/>
                        <path d="M337 168V152M327 146C327 139 337 132 337 132S347 139 347 146C347 152 327 152 327 146Z" stroke="currentColor" stroke-width="2"/>
                    </svg>
                </div>
            <p class="brand-footer mb-0"><strong>Designed for Barangay Calayo residents.</strong><br>A simpler way to access barangay document services.</p>
            </div>
            </div>
        </section>
        <section class="auth-form-panel col-lg-7" aria-labelledby="auth-heading">
            <div class="auth-form-content">
                @hasSection('auth-intro')
                    <header class="auth-page-intro">@yield('auth-intro')</header>
                @endif
                <div class="auth-form-card">
                    @yield('form')
                </div>
                <section class="auth-service-info" aria-labelledby="service-info-heading">
                    <h3 id="service-info-heading">@yield('service-info-heading', 'With SmartBarangay, you can:')</h3>
                    <ul class="auth-feature-list list-unstyled mb-0">
                        <li>
                            <span class="auth-feature-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                                    <path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8l-5-5Z"/>
                                    <path d="M14 3v5h5M8 12h8M8 16h6"/>
                                </svg>
                            </span>
                            <span>@yield('document-feature', 'Request barangay documents online')</span>
                        </li>
                        <li>
                            <span class="auth-feature-icon auth-feature-icon-green" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                                    <circle cx="12" cy="12" r="9"/>
                                    <path d="m8 12 3 3 5-6"/>
                                </svg>
                            </span>
                            <span>@yield('tracking-feature', 'Track your reservation progress')</span>
                        </li>
                        <li>
                            <span class="auth-feature-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                                    <path d="M3 3h6v6H3ZM15 3h6v6h-6ZM3 15h6v6H3ZM15 15h3v3h-3ZM21 14v4M14 21h4M21 21h-1"/>
                                </svg>
                            </span>
                            <span>@yield('qr-feature', 'Use QR-based verification')</span>
                        </li>
                    </ul>
                </section>
                <aside class="auth-support" aria-label="Account assistance">
                    <span class="auth-support-icon" aria-hidden="true">?</span>
                    <p class="mb-0"><strong>@yield('support-heading', 'Need help accessing your account?')</strong><br>Please contact the Barangay Calayo office.</p>
                </aside>
            </div>
            <footer class="auth-footer">SmartBarangay <span aria-hidden="true">·</span> Barangay Calayo, Nasugbu, Batangas</footer>
        </section>
    </div>
</main>
@endsection
