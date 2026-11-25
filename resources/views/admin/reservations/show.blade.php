@extends('layouts.authenticated')
@section('breadcrumbs')<li><a href="{{ route('admin.reservations.index') }}">Reservations</a></li>@endsection
@section('dashboard')
@php
    use App\Enums\ReservationStatus;
    $status = $reservation->status;
    $residentName = $reservation->resident_snapshot ? $reservation->resident_snapshot['resident_name'] : $reservation->user->name;
    $contact = $reservation->resident_snapshot ? ($reservation->resident_snapshot['resident_contact'] ?? null) : $reservation->user->contact_number;
    $date = $reservation->appointment->date;
    $timeRange = \Illuminate\Support\Carbon::parse($reservation->appointment->start_time)->format('g:i A').' – '.\Illuminate\Support\Carbon::parse($reservation->appointment->end_time)->format('g:i A');
@endphp
<a href="{{ route('admin.reservations.index') }}" class="rv-back reservation-details-back"><x-app-icon name="arrow"/>Back to reservations</a>
@if($errors->any())<div class="alert alert-danger" role="alert"><strong>Please check your request.</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<div class="rv-detail-grid">
    {{-- Summary --}}
    <section class="rv-summary tone-{{ $status->value }}" aria-labelledby="admin-request-heading">
        <div class="rv-summary-top">
            <div>
                <span class="rv-summary-ref">Request #{{ $reservation->id }}</span>
                <h2 id="admin-request-heading">{{ $reservation->service->name }}</h2>
                @if($reservation->service->description)<p class="rv-summary-desc">{{ $reservation->service->description }}</p>@endif
            </div>
            <x-reservation-status :status="$status"/>
        </div>
        <div class="rv-summary-row">
            <div class="rv-person"><x-resident-avatar :user="$reservation->user" :initials="true"/><div><span class="rv-person-name">{{ $residentName }}</span><span class="reservation-reference">{{ $reservation->user->email }}</span></div></div>
            <div class="rv-date">
                <span class="rv-date-block" aria-hidden="true"><span class="rv-date-month">{{ $date->format('M') }}</span><span class="rv-date-day">{{ $date->format('j') }}</span></span>
                <span class="rv-date-text"><strong>{{ $date->format('l, F j, Y') }}</strong><span>{{ $timeRange }}</span></span>
            </div>
        </div>
        <x-status-stepper :status="$status"/>
    </section>

    {{-- Actions sidebar (moves under the summary on smaller screens) --}}
    <aside class="rv-detail-aside" aria-label="Request actions">
        <section class="card dashboard-panel rv-actions-card rv-section" aria-labelledby="processing-heading">
            <div class="dashboard-panel-heading"><h2 id="processing-heading"><span class="panel-icon" aria-hidden="true"><x-app-icon name="status"/></span>Process this request</h2></div>
            <div class="rv-side-block">
                <div class="rv-callout tone-{{ $status->value }}">
                    <span class="rv-callout-icon" aria-hidden="true"><x-app-icon name="bell"/></span>
                    <div>
                        <strong>Current task</strong>
                        <p>{{ match($status) {
                            ReservationStatus::Pending => 'Review the submitted requirements, then move this request to Under Review.',
                            ReservationStatus::UnderReview => 'Check the recorded information and supporting files before approving or rejecting.',
                            ReservationStatus::Approved => 'Mark Ready for Pickup when the resident can collect. The certificate is finalized after check-in.',
                            ReservationStatus::ReadyForPickup => 'Record check-in when the resident arrives, then finalize and print the certificate, record payment, and mark Completed.',
                            default => 'This request is closed. Its recorded information remains available.',
                        } }}</p>
                        @if($residentExplanation)<p><strong>Explanation shared with the Resident:</strong> {{ $residentExplanation }}</p>@endif
                        @if($status === ReservationStatus::ReadyForPickup && $completionBlockReason === \App\Http\Controllers\Admin\ReservationController::COMPLETION_CHECKIN_REQUIRED)<a class="btn btn-sm btn-primary" href="#checkin-heading">Record check-in</a>
                        @elseif($status === ReservationStatus::ReadyForPickup && $completionBlockReason)<a class="btn btn-sm btn-primary" href="#payment-heading">Review payment</a>@endif
                    </div>
                </div>
            </div>
            <div class="rv-side-block">
                @if($completionBlockReason)
                    <p class="alert alert-warning mb-0" role="alert">{{ $completionBlockReason }}</p>
                @elseif(count($transitions) === 0)
                    <p class="mb-0 app-note">This request is {{ strtolower($status->label()) }}. No further status changes are available.</p>
                @else
                    @if($status === ReservationStatus::ReadyForPickup && $paymentStatus === \App\Enums\PaymentStatus::FeeNotRecorded)
                        <p class="app-note">This historical reservation has no recorded fee and retains the legacy completion workflow.</p>
                    @endif
                    <form method="POST" action="{{ route('admin.reservations.status', $reservation) }}" data-service-submit @if($status === ReservationStatus::Approved) data-ready-modal @endif>
                        @csrf @method('PATCH')
                        <input type="hidden" name="expected_status" value="{{ $status->value }}">
                        <label class="form-label" for="next-status">Next status</label>
                        <select id="next-status" name="status" class="form-select" required data-reservation-status><option value="">Choose a status</option>@foreach($transitions as $next)<option value="{{ $next->value }}" @selected(old('status') === $next->value)>{{ $next->label() }}</option>@endforeach</select>
                        @if($status === ReservationStatus::Approved)
                            <div class="mt-3" data-ready-confirmation data-certificate-prepared="{{ $hasPreparedCertificate ? '1' : '0' }}" @if(old('status') !== 'ready_for_pickup') hidden @endif>
                                @if(! $hasPreparedCertificate)<p class="app-note">Certificate is finalized after check-in.</p>@endif
                                <div class="form-check"><input class="form-check-input" type="checkbox" id="confirm-ready" name="confirm_ready" value="1" data-ready-accepted><label class="form-check-label" for="confirm-ready">Mark this request as Ready for Pickup?</label></div>
                                @error('confirm_ready')<p class="text-danger mb-0" role="alert">{{ $message }}</p>@enderror
                            </div>
                            <noscript><p class="app-note mt-3">Before marking Ready for Pickup, confirm the action below. Certificate is finalized after check-in.</p><div class="form-check"><input class="form-check-input" type="checkbox" id="confirm-ready-noscript" name="confirm_ready" value="1"><label class="form-check-label" for="confirm-ready-noscript">Mark this request as Ready for Pickup?</label></div></noscript>
                        @endif
                        <div class="mt-3" data-rejection-message>
                            <label class="form-label" for="resident-message">Resident explanation for rejection</label>
                            <textarea id="resident-message" name="resident_message" class="form-control" rows="3" maxlength="1000" aria-describedby="resident-message-help">{{ old('resident_message') }}</textarea>
                            <p id="resident-message-help" class="form-text">Required when rejecting. The Resident reads this explanation, so leave out internal notes and other people's private information.</p>
                        </div>
                        <label class="form-label mt-3" for="processing-notes">Processing note</label>
                        <textarea class="form-control" id="processing-notes" name="notes" rows="3" maxlength="2000" aria-describedby="notes-help" data-reservation-notes>{{ old('notes') }}</textarea>
                        <p id="notes-help" class="form-text">Required for rejection, optional otherwise. Visible to Admin users only. Each change is recorded with your name and time.</p>
                        <button type="submit" class="btn btn-primary w-100 mt-2" data-saving-label="Saving…">{{ $status === ReservationStatus::ReadyForPickup ? 'Mark Completed' : 'Update status' }}</button>
                    </form>
                    @if($status === ReservationStatus::Approved)
                        @push('modals')
                        <div class="modal fade reservation-confirmation" id="ready-confirmation-modal" tabindex="-1" aria-labelledby="ready-modal-heading" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h3 class="modal-title h5" id="ready-modal-heading">Mark this request as Ready for Pickup?</h3><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                                <div class="modal-body">
                                    @if(! $hasPreparedCertificate)<p>Certificate is finalized after check-in. This status change will be recorded in history.</p>
                                    @else<p>The prepared certificate is available. This status change will be recorded in history.</p>@endif
                                </div>
                                <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="button" data-ready-continue>Confirm Ready for Pickup</button></div>
                            </div></div>
                        </div>
                        @endpush
                    @endif
                @endif
            </div>
            @if($reservation->qrTicket)
                <div class="rv-side-block" aria-labelledby="qr-lifecycle-heading">
                    <h3 id="qr-lifecycle-heading"><x-app-icon name="qr"/>QR Ticket</h3>
                    <dl class="rv-facts">
                        <div><dt>Status</dt><dd>{{ $reservation->qrTicket->isUsed() || $status === ReservationStatus::Completed ? 'Used / Historical' : ($status->canReceiveQrTicket() ? 'Active' : 'Unavailable') }}</dd></div>
                        <div><dt>QR ticket issued</dt><dd>{{ $reservation->qrTicket->generated_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</dd></div>
                        @if($reservation->qrTicket->isUsed())
                            <div><dt>Completed</dt><dd><time datetime="{{ $reservation->qrTicket->used_at->toIso8601String() }}">{{ $reservation->qrTicket->used_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</time></dd></div>
                            <div><dt>Processed by</dt><dd>{{ $reservation->qrTicket->usedBy?->name ?? 'Former account' }}</dd></div>
                        @endif
                    </dl>
                    <p class="app-note mt-2 mb-0">{{ $reservation->qrTicket->isUsed() || $status === ReservationStatus::Completed ? 'This ticket cannot be reused. Its original credential is kept as a historical record.' : 'Scanning verifies this ticket only. It becomes used when an Admin completes the reservation.' }}</p>
                    @if($status === ReservationStatus::Completed && ! $reservation->qrTicket->isUsed())<p class="app-note mt-2 mb-0">QR usage metadata was not recorded for this earlier completion. Refer to the activity below.</p>@endif
                </div>
            @endif
            <div class="rv-side-block">@include('reservations.payment-status', ['admin' => true])</div>
        </section>
        @include('admin.reservations.check-in')
        @include('reservations.followups')
    </aside>

    {{-- Details --}}
    <div class="rv-detail-main">
        <section class="card dashboard-panel rv-section" aria-labelledby="request-info-heading">
            <div class="dashboard-panel-heading"><h2 id="request-info-heading"><span class="panel-icon is-indigo" aria-hidden="true"><x-app-icon name="account"/></span>Resident and reservation information</h2></div>
            <div class="rv-section-body">
                <dl class="rv-facts">
                    <div><dt>Service Fee</dt><dd>{{ \App\Support\ServiceFee::format($reservation->fee_snapshot) }}</dd></div>
                    <div><dt>Resident</dt><dd>{{ $residentName }}</dd></div>
                    <div><dt>Email</dt><dd>{{ $reservation->user->email }}</dd></div>
                    @if($contact)<div><dt>Contact</dt><dd>{{ $contact }}</dd></div>@endif
                    <div><dt>Schedule</dt><dd>{{ $date->format('M j, Y') }} · {{ $timeRange }}</dd></div>
                    <div><dt>Submitted</dt><dd>{{ $reservation->created_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</dd></div>
                    <div><dt>Last updated</dt><dd>{{ $reservation->updated_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</dd></div>
                </dl>
                @include('reservations.appointment-note')
                @include('resident.reservations.document-information')
                @if($documentPreviewAvailable && ! $status->canPrepareDocument())<a class="btn btn-outline-primary mt-3" href="{{ route('admin.reservations.document-preview.show', $reservation) }}">Preview Document</a>@endif
            </div>
        </section>
        <section class="card dashboard-panel rv-section" aria-labelledby="requirements-heading">
            <div class="dashboard-panel-heading"><h2 id="requirements-heading"><span class="panel-icon is-gold" aria-hidden="true"><x-app-icon name="document"/></span>Requirements and attachments</h2></div>
            <div class="rv-section-body">
                @include('resident.reservations.historical-requirements')
                @include('resident.reservations.attachments', ['attachmentRoute' => 'admin.reservations.attachments.download'])
            </div>
        </section>
        @include('admin.reservations.document-preparation')
        @include('admin.reservations.activity-timeline')
    </div>
</div>
@endsection
