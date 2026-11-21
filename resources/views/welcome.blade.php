@extends('layouts.app')
@section('title', 'Request barangay documents online')
@section('content')
@php
    use App\Enums\ReservationStatus;
    use App\Support\ServiceFee;

    $viewer = auth()->user();
    $verified = $viewer && $viewer->hasVerifiedEmail();
    $resident = $viewer && $viewer->role === \App\Enums\UserRole::Resident;
    $entry = $viewer ? route($viewer->entryRouteName()) : route('login');
    $requests = $verified ? route($resident ? 'resident.reservations.index' : 'admin.reservations.index') : $entry;
    $start = $verified ? route($resident ? 'resident.reservations.create' : 'admin.reservations.index') : $entry;

    $entryLabel = $verified ? 'Go to Dashboard' : 'Verify Email';
    $requestsLabel = $resident ? 'View My Requests' : 'View Reservations';
    $serviceLinkLabel = $viewer && ! $resident ? 'View reservations' : 'Request this';

    $flow = [ReservationStatus::Pending, ReservationStatus::UnderReview, ReservationStatus::Approved, ReservationStatus::ReadyForPickup, ReservationStatus::Completed];
    // Shortened from ReservationStatus::residentMessage().
    $statusMeaning = [
        'pending' => 'Submitted and waiting for review.',
        'under_review' => 'The barangay office is reviewing your request.',
        'approved' => 'Approved. Your QR ticket is issued.',
        'ready_for_pickup' => 'Your document is ready to collect.',
        'completed' => 'Document released. The request is closed.',
    ];
    $previewStatuses = collect(array_slice($flow, 0, 4))->map(fn ($status) => ['label' => $status->label(), 'tone' => $status->value])->values();

    // Bento layout: featured cards span two columns so the three-column grid has no gaps.
    $serviceCount = $services->count();
    $featuredCount = $serviceCount >= 3 ? (3 - $serviceCount % 3) % 3 : 0;
    if ($featuredCount === 0 && $serviceCount >= 6) {
        $featuredCount = 3;
    }

    $nav = ['services' => 'Services', 'how-it-works' => 'How it works', 'qr-pickup' => 'QR pickup', 'faq' => 'FAQ'];
@endphp
<div class="landing" data-landing>
    <header class="lp-header" data-lp-header>
        <nav class="navbar navbar-expand-lg lp-container" aria-label="Public navigation">
            <a class="lp-brand" href="{{ route('home') }}"><x-brand-mark/><span><strong>SmartBarangay</strong><small>Barangay Calayo &middot; Nasugbu, Batangas</small></span></a>
            <x-theme-toggle class="lp-theme" />
            <button class="navbar-toggler lp-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#public-nav" aria-controls="public-nav" aria-expanded="false" aria-label="Toggle public navigation"><x-app-icon name="menu"/></button>
            <div class="collapse navbar-collapse" id="public-nav">
                <ul class="navbar-nav lp-nav-links">
                    @foreach($nav as $anchor => $label)<li class="nav-item"><a class="nav-link" href="#{{ $anchor }}" data-lp-nav="{{ $anchor }}">{{ $label }}</a></li>@endforeach
                </ul>
                <div class="lp-nav-actions">
                    @if($viewer)
                        <a class="btn btn-primary" href="{{ $entry }}">{{ $entryLabel }}</a>
                    @else
                        <a class="btn btn-ghost" href="{{ route('login') }}">Sign In</a>
                        <a class="btn btn-primary" href="{{ route('register') }}">Register</a>
                    @endif
                </div>
            </div>
        </nav>
    </header>

    <main id="main-content">
        {{-- Hero --}}
        <section class="lp-hero" aria-labelledby="hero-title">
            <div class="lp-hero-bg" aria-hidden="true"></div>
            <div class="lp-container lp-hero-grid">
                <div class="lp-hero-copy">
                    <h1 id="hero-title" class="lp-hero-title">Request Barangay Calayo documents <span class="lp-highlight">online.<svg viewBox="0 0 200 14" preserveAspectRatio="none" aria-hidden="true" focusable="false"><path d="M3 10 C 50 3, 120 3, 197 8" pathLength="1"/></svg></span></h1>
                    <p class="lp-hero-lead">Choose a service, pick a schedule, follow your request status, and claim your document at the barangay hall with a QR ticket.</p>
                    <div class="lp-actions">
                        @if($viewer)
                            <a class="btn btn-primary btn-lg lp-arrow" href="{{ $entry }}">{{ $entryLabel }} <span aria-hidden="true">&rarr;</span></a>
                            <a class="btn btn-outline-primary btn-lg" href="{{ $requests }}">{{ $requestsLabel }}</a>
                        @else
                            <a class="btn btn-primary btn-lg lp-arrow" href="{{ $start }}">Request a Document <span aria-hidden="true">&rarr;</span></a>
                            <a class="btn btn-outline-primary btn-lg" href="{{ route('register') }}">Create an Account</a>
                        @endif
                    </div>
                    <ul class="lp-facts" aria-label="Service facts">
                        <li><strong data-lp-count="{{ $activeServiceCount }}">{{ $activeServiceCount }}</strong> {{ \Illuminate\Support\Str::plural('service', $activeServiceCount) }} available</li>
                        <li><x-app-icon name="qr"/> QR-verified pickup</li>
                        <li><x-app-icon name="bell"/> Status updates by notification</li>
                    </ul>
                </div>

                <div class="lp-preview" aria-hidden="true">
                    <div class="lp-preview-stack">
                        <div class="lp-glass lp-card-request">
                            <div class="lp-card-row">
                                <span class="lp-tile"><x-app-icon name="document"/></span>
                                <div class="lp-card-text"><small>Document request</small><strong>{{ $services->first()?->name ?? 'Barangay document' }}</strong></div>
                            </div>
                            <dl class="lp-card-meta">
                                <div><dt>Schedule</dt><dd><x-app-icon name="calendar"/> Your chosen date</dd></div>
                                <div><dt>Status</dt><dd><span class="lp-chip" data-lp-status-cycle='@json($previewStatuses)' data-tone="pending">Pending</span></dd></div>
                            </dl>
                            <ol class="lp-card-progress">@foreach($previewStatuses as $status)<li data-step="{{ $status['tone'] }}"></li>@endforeach</ol>
                        </div>
                        <div class="lp-glass lp-card-qr">
                            <div class="lp-qr-grid">@for($i = 0; $i < 49; $i++)<span @class(['on' => in_array($i, [0,1,2,4,6,7,9,13,14,15,16,18,20,22,24,25,27,30,32,34,35,36,38,40,41,42,43,44,46,48])])></span>@endfor</div>
                            <div><small>QR ticket</small><strong>Show at pickup</strong></div>
                        </div>
                        <div class="lp-glass lp-card-note"><x-app-icon name="bell"/><span>Status updated</span></div>
                    </div>
                </div>
            </div>
            <div class="lp-skyline" aria-hidden="true">@include('partials.skyline')</div>
        </section>

        {{-- Services --}}
        <section class="lp-section lp-surface-soft" id="services" aria-labelledby="services-title">
            <div class="lp-container">
                <div class="lp-section-head" data-lp-reveal>
                    <h2 id="services-title">Available services</h2>
                    <p>Each card shows the fee and how many requirements to prepare. Sign in to see the full list of requirements and open schedules.</p>
                </div>
                <div @class(['lp-services', 'lp-services-bento' => $featuredCount > 0])>
                    @forelse($services as $service)
                        @php
                            $featured = $loop->index < $featuredCount * 2 && $loop->index % 2 === 0;
                            $fee = $service->fee_amount === null ? null : ((float) $service->fee_amount === 0.0 ? 'Free' : ServiceFee::format($service->fee_amount));
                            $requirements = $service->service_requirements_count;
                        @endphp
                        <article @class(['lp-service', 'lp-service-featured' => $featured]) data-lp-reveal data-lp-spotlight style="--lp-delay: {{ min($loop->index, 5) * 60 }}ms">
                            <div class="lp-service-top">
                                <span class="lp-tile"><x-app-icon name="document"/></span>
                                @if($fee)<span @class(['lp-fee', 'lp-fee-free' => $fee === 'Free'])>{{ $fee }}</span>@endif
                            </div>
                            <h3>{{ $service->name }}</h3>
                            @if($service->description)<p class="lp-service-desc">{{ $service->description }}</p>@else<p class="lp-service-desc">Sign in to review this service's requirements.</p>@endif
                            <div class="lp-service-foot">
                                @if($requirements > 0)<span class="lp-service-req"><x-app-icon name="check"/> {{ $requirements }} {{ \Illuminate\Support\Str::plural('requirement', $requirements) }}</span>@else<span></span>@endif
                                <a class="lp-link lp-arrow" href="{{ $start }}">{{ $serviceLinkLabel }} <span aria-hidden="true">&rarr;</span><span class="visually-hidden">: {{ $service->name }}</span></a>
                            </div>
                        </article>
                    @empty
                        <div class="lp-empty"><h3 class="h5">Services will appear here when available.</h3><p class="mb-0">Please check back or contact the Barangay Calayo office for assistance.</p></div>
                    @endforelse
                </div>
                @if($services->hasPages())<div class="lp-pagination">{{ $services->links('pagination.civic', ['showSummary' => true]) }}</div>@endif
            </div>
        </section>

        {{-- How it works --}}
        <section class="lp-section" id="how-it-works" aria-labelledby="how-title">
            <div class="lp-container">
                <div class="lp-section-head" data-lp-reveal>
                    <span class="lp-label">4 steps</span>
                    <h2 id="how-title">How it works</h2>
                </div>
                <ol class="lp-steps" data-lp-steps>
                    @foreach([
                        ['Register and verify your email', 'Create a Resident account, then open the link sent to your email address.', 'account'],
                        ['The office confirms your residency', 'The barangay office reviews your profile. New requests open once your residency is approved.', 'users'],
                        ['Choose a service and schedule', 'Fill in the document details, upload the requirements, and pick an available schedule.', 'calendar'],
                        ['Track it, then pick it up', 'Follow the status in your account. When it is Ready for Pickup, bring your QR ticket.', 'qr'],
                    ] as [$title, $copy, $icon])
                        <li class="lp-step" data-lp-reveal style="--lp-delay: {{ $loop->index * 90 }}ms">
                            <span class="lp-step-num">{{ $loop->iteration }}</span>
                            <span class="lp-step-icon"><x-app-icon :name="$icon"/></span>
                            <h3>{{ $title }}</h3>
                            <p>{{ $copy }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- Request status --}}
        <section class="lp-section lp-surface-soft" id="status" aria-labelledby="status-title">
            <div class="lp-container">
                <div class="lp-section-head" data-lp-reveal>
                    <h2 id="status-title">What each status means</h2>
                    <p>Your request moves through these statuses. You get a notification each time it changes.</p>
                </div>
                <ol class="lp-timeline" data-lp-timeline>
                    @foreach($flow as $status)
                        <li class="lp-timeline-item" style="--lp-i: {{ $loop->index }}">
                            <span class="lp-timeline-dot" aria-hidden="true"></span>
                            <h3>{{ $status->label() }}</h3>
                            <p>{{ $statusMeaning[$status->value] }}</p>
                        </li>
                    @endforeach
                </ol>
                <p class="lp-note" data-lp-reveal><strong>{{ ReservationStatus::Rejected->label() }}</strong> means the office could not approve the request and will explain why. <strong>{{ ReservationStatus::Cancelled->label() }}</strong> means the request was withdrawn and will not be processed.</p>
            </div>
        </section>

        {{-- QR pickup and IoT --}}
        <section class="lp-section lp-dark" id="qr-pickup" aria-labelledby="qr-title">
            <div class="lp-dark-glow" aria-hidden="true"></div>
            <div class="lp-container lp-qr-grid-layout">
                <div class="lp-qr-copy" data-lp-reveal>
                    <span class="lp-label lp-label-dark">At the barangay hall</span>
                    <h2 id="qr-title">Pick up with a QR ticket</h2>
                    <p>Your QR ticket is issued when your request is approved. It holds a random code, not your personal details.</p>
                    <ul class="lp-qr-points">
                        <li><x-app-icon name="qr"/><span>At pickup, the office scans your ticket with the ESP32-CAM scanner or the admin's camera.</span></li>
                        <li><x-app-icon name="check"/><span>The system checks the ticket against your request, then the admin records your arrival.</span></li>
                        <li><x-app-icon name="document"/><span>After any fee is paid at the office and the document is handed over, the request is marked Completed.</span></li>
                    </ul>
                </div>
                <div class="lp-scan" aria-hidden="true" data-lp-reveal>
                    <div class="lp-phone">
                        <span class="lp-phone-notch"></span>
                        <div class="lp-phone-qr"><div class="lp-qr-grid lp-qr-grid-lg">@for($i = 0; $i < 49; $i++)<span @class(['on' => in_array($i, [0,1,2,4,6,7,9,13,14,15,16,18,20,22,24,25,27,30,32,34,35,36,38,40,41,42,43,44,46,48])])></span>@endfor</div><span class="lp-scanline"></span></div>
                        <small>QR ticket</small>
                    </div>
                    <div class="lp-scan-link"><span></span><span></span><span></span></div>
                    <div class="lp-scanner">
                        <span class="lp-scanner-lens"></span>
                        <small>ESP32-CAM</small>
                        <div class="lp-verified"><x-app-icon name="check"/> Verified</div>
                    </div>
                </div>
            </div>
        </section>

        {{-- FAQ --}}
        <section class="lp-section" id="faq" aria-labelledby="faq-title">
            <div class="lp-container lp-faq-layout">
                <div class="lp-section-head" data-lp-reveal>
                    <h2 id="faq-title">Frequently asked questions</h2>
                    <p>Short answers about accounts, requests and pickup.</p>
                </div>
                <div class="accordion lp-faq" id="faq-list">
                    @foreach([
                        ['Who can register?', 'Residents of Barangay Calayo can create a Resident account on this site and verify their email. Admin accounts are not created through public registration.'],
                        ['Why do I need residency approval?', 'Documents are issued to Barangay Calayo residents. The office reviews your profile first, and may ask for corrections. You can submit requests once your residency is approved.'],
                        ['Can I cancel a request?', 'While it is Pending, you can cancel it yourself. When it is Under Review, Approved or Ready for Pickup, you can ask the office to cancel it, and they review the request. This is not possible after a payment is recorded, the final document is prepared, or you have checked in.'],
                        ['Do I pay online?', 'No. If the service has a fee, you pay at the barangay office and the admin records the payment. Paid services must be paid before the request can be completed. Free services need no payment.'],
                        ['When do I get my QR ticket?', 'As soon as your request is approved. It appears in your account under QR tickets. Bring it when your request is Ready for Pickup.'],
                        ['What if I miss my schedule?', 'Your request is not cancelled automatically. It stays Ready for Pickup, and the office may note the missed visit. You can still check in later with your QR ticket.'],
                    ] as [$question, $answer])
                        <div class="accordion-item">
                            <h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-{{ $loop->iteration }}" aria-expanded="false" aria-controls="faq-{{ $loop->iteration }}">{{ $question }}</button></h3>
                            <div id="faq-{{ $loop->iteration }}" class="accordion-collapse collapse" data-bs-parent="#faq-list"><div class="accordion-body">{{ $answer }}</div></div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Final call to action --}}
        <section class="lp-cta-wrap" aria-labelledby="cta-title">
            <div class="lp-container">
                <div class="lp-cta" data-lp-reveal>
                    <div>
                        <h2 id="cta-title">{{ $viewer ? 'Continue in your account.' : 'Ready to request a document?' }}</h2>
                        <p>{{ $viewer ? 'See your requests, statuses and QR tickets.' : 'Create a free Resident account to get started.' }}</p>
                    </div>
                    <div class="lp-actions">
                        @if($viewer)
                            <a class="btn btn-light btn-lg lp-arrow" href="{{ $entry }}">{{ $entryLabel }} <span aria-hidden="true">&rarr;</span></a>
                            <a class="btn btn-outline-light btn-lg" href="{{ $requests }}">{{ $requestsLabel }}</a>
                        @else
                            <a class="btn btn-light btn-lg lp-arrow" href="{{ route('register') }}">Register <span aria-hidden="true">&rarr;</span></a>
                            <a class="btn btn-outline-light btn-lg" href="{{ $start }}">Request a Document</a>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="lp-footer" id="contact">
        <div class="lp-container lp-footer-grid">
            <div class="lp-footer-brand">
                <a class="lp-brand" href="{{ route('home') }}"><x-brand-mark/><span><strong>SmartBarangay</strong><small>Barangay Calayo &middot; Nasugbu, Batangas</small></span></a>
                <p>Document reservations for Barangay Calayo, Nasugbu, Batangas.</p>
            </div>
            <nav aria-label="Page sections">
                <h2>On this page</h2>
                <ul>@foreach($nav as $anchor => $label)<li><a href="#{{ $anchor }}">{{ $label }}</a></li>@endforeach</ul>
            </nav>
            <nav aria-label="Account">
                <h2>Account</h2>
                <ul>
                    @if($viewer)
                        <li><a href="{{ $entry }}">{{ $entryLabel }}</a></li>
                        <li><a href="{{ $requests }}">{{ $requestsLabel }}</a></li>
                    @else
                        <li><a href="{{ route('login') }}">Sign In</a></li>
                        <li><a href="{{ route('register') }}">Register</a></li>
                    @endif
                </ul>
            </nav>
        </div>
        <div class="lp-container lp-footer-bottom">
            <small>&copy; {{ now()->year }} SmartBarangay</small>
            <a class="lp-top" href="#main-content">Back to top <span aria-hidden="true">&uarr;</span></a>
        </div>
    </footer>
</div>
@endsection
