@extends('layouts.authenticated')
@section('dashboard')
<div class="reservation-wizard">
    <a href="{{ route('resident.request-status.index') }}" class="service-back-link reservation-details-back"><x-app-icon name="arrow"/>Back to Request Status</a>
    <section class="dashboard-panel reservation-panel reservation-detail" aria-labelledby="progress-heading">
        <div class="reservation-detail-header"><div><span class="reservation-step-label">REQUEST #{{ $reservation->id }}</span><h2 id="progress-heading">{{ $reservation->service->name }}</h2></div><x-reservation-status :status="$reservation->status"/></div>
        <p class="reservation-notice">{{ $reservation->status->residentMessage() }}</p>
        @if($reservation->status !== \App\Enums\ReservationStatus::Rejected)
            <ol class="request-progress" aria-label="Request processing stages">
                @foreach($steps as $step)
                    <li @class(['is-current' => $step === $reservation->status]) @if($step === $reservation->status) aria-current="step" @endif><span class="request-progress-number" aria-hidden="true">{{ $loop->iteration }}</span><span>{{ $step->label() }}@if($step === $reservation->status)<small>Current status</small>@endif</span></li>
                @endforeach
            </ol>
        @endif
        <dl class="reservation-summary">
            <div><dt>Schedule</dt><dd>{{ $reservation->schedule->date->format('l, F j, Y') }}<span class="reservation-reference">{{ \Illuminate\Support\Carbon::parse($reservation->schedule->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($reservation->schedule->end_time)->format('g:i A') }}</span></dd></div>
            <div><dt>Submitted</dt><dd><time datetime="{{ $reservation->created_at->toIso8601String() }}">{{ $reservation->created_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</time></dd></div>
            <div><dt>Latest update</dt><dd><time datetime="{{ $reservation->updated_at->toIso8601String() }}">{{ $reservation->updated_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</time></dd></div>
        </dl>
        <a class="btn btn-outline-primary reservation-details-link" href="{{ route('resident.reservations.show', $reservation) }}">View reservation details</a>
    </section>
    <section class="dashboard-panel reservation-panel mt-4" aria-labelledby="timeline-heading">
        <h2 id="timeline-heading">Request timeline</h2><p class="app-note">Recorded status changes, oldest first. Times are shown in Philippine time.</p>
        <ol class="request-timeline">
            @if($histories->currentPage() === 1)
                <li><span class="request-timeline-marker" aria-hidden="true"></span><h3 class="h6">Reservation submitted · Pending</h3><time datetime="{{ $reservation->created_at->toIso8601String() }}">{{ $reservation->created_at->timezone('Asia/Manila')->format('M j, Y, g:i A') }}</time></li>
            @endif
            @foreach($histories as $history)
                <li><span class="request-timeline-marker" aria-hidden="true"></span><div class="request-timeline-transition">@if($history->from_status)<x-reservation-status :status="$history->from_status"/><span>→<span class="visually-hidden"> changed to </span></span>@else<span>Initial status</span>@endif<x-reservation-status :status="$history->to_status"/></div><time datetime="{{ $history->changed_at->toIso8601String() }}">{{ $history->changed_at->timezone('Asia/Manila')->format('M j, Y, g:i A') }}</time></li>
            @endforeach
        </ol>
        @if($histories->total() === 0)<p class="app-note">No status changes recorded yet. Your current status is {{ $reservation->status->label() }}.</p>@elseif($histories->isEmpty())<p>No timeline entries on this page.</p><a href="{{ route('resident.request-status.show', $reservation) }}">Back to first page</a>@endif
        {{ $histories->links('pagination::bootstrap-5') }}
    </section>
    <p class="reservation-help">For assistance, contact the Barangay Calayo office in Nasugbu, Batangas.</p>
</div>
@endsection
