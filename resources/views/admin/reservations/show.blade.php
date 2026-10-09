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
            <form method="POST" action="{{ route('admin.reservations.status', $reservation) }}" data-service-submit @if($reservation->status === \App\Enums\ReservationStatus::Approved) data-ready-modal @endif>
                @csrf @method('PATCH')
                <input type="hidden" name="expected_status" value="{{ $reservation->status->value }}">
                <label class="form-label" for="next-status">Next status</label>
                <select id="next-status" name="status" class="form-select" required data-reservation-status><option value="">Choose a status</option>@foreach($transitions as $next)<option value="{{ $next->value }}" @selected(old('status') === $next->value)>{{ $next->label() }}</option>@endforeach</select>
                @if($reservation->status === \App\Enums\ReservationStatus::Approved)
                    <div class="mt-3" data-ready-confirmation data-certificate-prepared="{{ $hasPreparedCertificate ? '1' : '0' }}" @if(old('status') !== 'ready_for_pickup') hidden @endif>
                        @if(! $hasPreparedCertificate)
                            <div class="alert alert-warning" role="alert">No prepared certificate/document was found for this request. The resident may arrive without a system-generated certificate. Do you want to continue? Enter a reason below, such as “Existing certificate was prepared manually.”</div>
                        @endif
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="confirm-ready" name="confirm_ready" value="1" data-ready-accepted><label class="form-check-label" for="confirm-ready">Mark this request as Ready for Pickup?</label></div>
                        @error('confirm_ready')<p class="text-danger mb-0" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <noscript><p class="app-note mt-3">Before marking Ready for Pickup, confirm the action below. If no prepared certificate exists, a reason is required.</p><div class="form-check"><input class="form-check-input" type="checkbox" id="confirm-ready-noscript" name="confirm_ready" value="1"><label class="form-check-label" for="confirm-ready-noscript">Mark this request as Ready for Pickup?</label></div></noscript>
                @endif
                <label class="form-label mt-3" for="processing-notes">Processing note</label>
                <textarea class="form-control" id="processing-notes" name="notes" rows="3" maxlength="2000" aria-describedby="notes-help" data-reservation-notes>{{ old('notes') }}</textarea>
                <p id="notes-help" class="form-text">A reason is required for rejection or when marking Ready for Pickup without a prepared certificate. Notes are visible to Admin users.</p>
                <button type="submit" class="btn btn-primary mt-3" data-saving-label="Saving…">Update status</button>
            </form>
            @if($reservation->status === \App\Enums\ReservationStatus::Approved)
                <div class="modal fade" id="ready-confirmation-modal" tabindex="-1" aria-labelledby="ready-modal-heading" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h3 class="modal-title h5" id="ready-modal-heading">Mark this request as Ready for Pickup?</h3><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                        <div class="modal-body">
                            @if(! $hasPreparedCertificate)
                                <p class="alert alert-warning">No prepared certificate/document was found for this request. Confirm that the certificate was prepared manually before continuing.</p>
                                <label class="form-label" for="ready-reason">Reason</label><textarea id="ready-reason" class="form-control" rows="3" maxlength="2000" required placeholder="Existing certificate was prepared manually." data-ready-reason></textarea>
                                <p class="form-text">The reason will be recorded in the request's status history.</p>
                            @else<p>The prepared certificate is available. This status change will be recorded in history.</p>@endif
                        </div>
                        <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="button" data-ready-continue>{{ $hasPreparedCertificate ? 'Confirm Ready for Pickup' : 'Continue Anyway' }}</button></div>
                    </div></div>
                </div>
            @endif
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
