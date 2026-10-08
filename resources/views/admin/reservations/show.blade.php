@extends('layouts.authenticated')
@section('dashboard')
<div class="reservation-wizard">
    <a href="{{ route('admin.reservations.index') }}" class="service-back-link reservation-details-back"><x-app-icon name="arrow"/>Back to reservations</a>
    @if($errors->any())<div class="alert alert-danger" role="alert"><strong>Please check your request.</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <section class="card dashboard-panel reservation-panel reservation-detail" aria-labelledby="admin-request-heading">
        <div class="reservation-detail-header"><div><span class="reservation-step-label">REQUEST #{{ $reservation->id }}</span><h2 id="admin-request-heading">{{ $reservation->service->name }}</h2></div><x-reservation-status :status="$reservation->status"/></div>
        @if($reservation->service->description)<p class="reservation-service-description">{{ $reservation->service->description }}</p>@endif
        <h3 class="h6 mt-4">Resident and reservation information</h3>
        <dl class="reservation-summary">
            <div><dt>Resident</dt><dd>{{ $reservation->user->name }}</dd></div>
            <div><dt>Email</dt><dd>{{ $reservation->user->email }}</dd></div>
            @if($reservation->user->contact_number)<div><dt>Contact</dt><dd>{{ $reservation->user->contact_number }}</dd></div>@endif
            <div><dt>Schedule</dt><dd>{{ $reservation->schedule->date->format('l, F j, Y') }}<br>{{ \Illuminate\Support\Carbon::parse($reservation->schedule->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($reservation->schedule->end_time)->format('g:i A') }}</dd></div>
            <div><dt>Submitted</dt><dd>{{ $reservation->created_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</dd></div>
            <div><dt>Last updated</dt><dd>{{ $reservation->updated_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</dd></div>
            @if($reservation->qrTicket)
                <div><dt>QR ticket issued</dt><dd>{{ $reservation->qrTicket->generated_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</dd></div>
            @endif
        </dl>
        <h3 class="h6 mt-4">Current service requirements</h3>
        @include('resident.reservations.requirements-list', ['service' => $reservation->service])
        @include('resident.reservations.attachments', ['attachmentRoute' => 'admin.reservations.attachments.download'])
    </section>
    @include('admin.reservations.document-preparation')
    <section class="card dashboard-panel reservation-panel mt-4" aria-labelledby="processing-heading">
        <h2 id="processing-heading">Process this request</h2>
        @if(count($transitions) === 0)
            <p class="mb-0 text-secondary">This request is {{ strtolower($reservation->status->label()) }}. No further status changes are available.</p>
        @else
            <p class="text-secondary">Choose the next allowed status. Each change is recorded with your name and timestamp.</p>
            <form method="POST" action="{{ route('admin.reservations.status', $reservation) }}" data-service-submit>
                @csrf @method('PATCH')
                <input type="hidden" name="expected_status" value="{{ $reservation->status->value }}">
                <label class="form-label" for="next-status">Next status</label>
                <select id="next-status" name="status" class="form-select" required data-reservation-status><option value="">Choose a status</option>@foreach($transitions as $next)<option value="{{ $next->value }}" @selected(old('status') === $next->value)>{{ $next->label() }}</option>@endforeach</select>
                <label class="form-label mt-3" for="processing-notes">Processing note</label>
                <textarea class="form-control" id="processing-notes" name="notes" rows="3" maxlength="2000" aria-describedby="notes-help" data-reservation-notes>{{ old('notes') }}</textarea>
                <p id="notes-help" class="form-text">A reason is required when rejecting a request. Notes are visible to Admin users.</p>
                <button type="submit" class="btn btn-primary mt-3" data-saving-label="Saving…">Update status</button>
            </form>
        @endif
    </section>
    <section class="card dashboard-panel reservation-panel mt-4" aria-labelledby="history-heading">
        <h2 id="history-heading">Status history</h2><p class="app-note">Times are shown in Philippine time.</p>
        @if($histories->isEmpty())<p class="text-secondary mb-0">No status changes recorded yet.</p>@else
            <ol class="reservation-history">
                @foreach($histories as $history)
                    <li><div>@if($history->from_status)<x-reservation-status :status="$history->from_status"/><span aria-hidden="true">→</span><span class="visually-hidden">changed to</span>@else<span>Initial status:</span>@endif<x-reservation-status :status="$history->to_status"/></div>
                        <p class="app-note">{{ $history->changedBy?->name ?? 'Former account' }} · <time datetime="{{ $history->changed_at->toIso8601String() }}">{{ $history->changed_at->timezone('Asia/Manila')->format('M j, Y, g:i A') }}</time></p>
                        @if($history->notes)<p class="history-note">{{ $history->notes }}</p>@endif
                    </li>
                @endforeach
            </ol>
            {{ $histories->links('pagination::bootstrap-5') }}
        @endif
    </section>
</div>
@endsection
