@extends('layouts.authenticated')
@section('breadcrumbs')<li><a href="{{ route('resident.request-status.index') }}">Request Status</a></li>@endsection
@section('dashboard')
@php($status = $reservation->status)
<a href="{{ route('resident.request-status.index') }}" class="rv-back reservation-details-back"><x-app-icon name="arrow"/>Back to Request Status</a>
<div class="rv-detail-grid">
    <section class="rv-summary tone-{{ $status->value }}" aria-labelledby="progress-heading">
        <div class="rv-summary-top">
            <div><span class="rv-summary-ref">Request #{{ $reservation->id }}</span><h2 id="progress-heading">{{ $reservation->service->name }}</h2></div>
            <x-reservation-status :status="$status"/>
        </div>
        <x-status-stepper :status="$status"/>
        <div class="rv-callout tone-{{ $status->value }}">
            <span class="rv-callout-icon" aria-hidden="true"><x-app-icon name="bell"/></span>
            <div><strong>What this means</strong><p>{{ $status->residentMessage() }}</p></div>
        </div>
    </section>

    <aside class="rv-detail-aside" aria-label="Request summary and actions">
        <section class="card dashboard-panel rv-section" aria-labelledby="summary-heading">
            <div class="dashboard-panel-heading"><h2 id="summary-heading"><span class="panel-icon is-green" aria-hidden="true"><x-app-icon name="calendar"/></span>Summary</h2></div>
            <div class="rv-section-body">
                <dl class="rv-facts">
                    <div><dt>Schedule</dt><dd>{{ $reservation->appointment->date->format('l, F j, Y') }}<br>{{ \Illuminate\Support\Carbon::parse($reservation->appointment->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($reservation->appointment->end_time)->format('g:i A') }}</dd></div>
                    <div><dt>Submitted</dt><dd><time datetime="{{ $reservation->created_at->toIso8601String() }}">{{ $reservation->created_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</time></dd></div>
                    <div><dt>Latest update</dt><dd><time datetime="{{ $reservation->updated_at->toIso8601String() }}">{{ $reservation->updated_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</time></dd></div>
                </dl>
                <a class="btn btn-outline-primary w-100 mt-3" href="{{ route('resident.reservations.show', $reservation) }}">View reservation details</a>
            </div>
        </section>
        @include('resident.reservations.cancellation-form')
        @include('reservations.followups')
        <p class="app-note">For assistance, contact the Barangay Calayo office in Nasugbu, Batangas.</p>
    </aside>

    <div class="rv-detail-main">
        <section class="card dashboard-panel rv-section" aria-labelledby="timeline-heading">
            <div class="dashboard-panel-heading"><div><h2 id="timeline-heading"><span class="panel-icon is-indigo" aria-hidden="true"><x-app-icon name="status"/></span>Request timeline</h2><p class="panel-description">Oldest first, in Philippine time.</p></div></div>
            <div class="rv-section-body">
                <ol class="rv-timeline">
                    @if($histories->currentPage() === 1)
                        <li class="tone-pending"><span class="rv-timeline-dot" aria-hidden="true"></span><div class="rv-timeline-head"><h3>Reservation submitted · Pending</h3><time datetime="{{ $reservation->created_at->toIso8601String() }}">{{ $reservation->created_at->timezone('Asia/Manila')->format('M j, Y, g:i A') }}</time></div></li>
                    @endif
                    @foreach($histories as $history)
                        <li class="tone-{{ $history->to_status->value }}">
                            <span class="rv-timeline-dot" aria-hidden="true"></span>
                            <div class="rv-timeline-head">
                                <div class="rv-timeline-change">@if($history->from_status)<x-reservation-status :status="$history->from_status"/><span aria-hidden="true">→</span><span class="visually-hidden"> changed to </span>@else<span>Initial status</span>@endif<x-reservation-status :status="$history->to_status"/></div>
                                <time datetime="{{ $history->changed_at->toIso8601String() }}">{{ $history->changed_at->timezone('Asia/Manila')->format('M j, Y, g:i A') }}</time>
                            </div>
                            @if($history->resident_message)<p class="rv-note"><strong>Message from the office:</strong> {{ $history->resident_message }}</p>@endif
                            @if($history->cancellation_reason)<p class="rv-note">Your cancellation reason: {{ $history->cancellation_reason }}</p>@endif
                        </li>
                    @endforeach
                </ol>
                @if($histories->total() === 0)<p class="app-note mb-0">No status changes recorded yet. Your current status is {{ $status->label() }}.</p>@elseif($histories->isEmpty())<p>No timeline entries on this page.</p><a href="{{ route('resident.request-status.show', $reservation) }}">Back to first page</a>@endif
                {{ $histories->links('pagination.civic', ['showSummary' => true]) }}
            </div>
        </section>
    </div>
</div>
@endsection
