@php
    $isOffice = auth()->user()->role === \App\Enums\UserRole::Admin;
    $followupService = app(\App\Services\ReservationFollowups::class);
    $cancellationBlock = $followupService->cancellationBlock($reservation);
    $cancellationReviews = $reservation->cancellationRequests()->limit(10)->get();
    $openCancellation = $cancellationReviews->firstWhere('status', 'pending');
    $attendanceReview = $reservation->attendanceReview()
        ->when(! $isOffice, fn ($query) => $query->select(['id', 'reservation_id', 'recorded_at']))->first();
    $hasArrival = $reservation->checkinLogs()->where('verification_result', \App\Models\CheckinLog::ARRIVAL)->exists();
    $appointmentEnd = $followupService->appointmentEnd($reservation);
    $pastAppointment = $appointmentEnd && $appointmentEnd->isPast();
    $reviewBlock = $isOffice ? $followupService->attendanceBlock($reservation) : null;
@endphp
<section class="dashboard-panel reservation-panel mt-4 p-3 p-md-4 text-break" aria-labelledby="followup-heading">
    <h2 class="h5" id="followup-heading">Appointment &amp; cancellation follow-up</h2>
    @if($errors->has('followup') || $errors->has('decision_message') || $errors->has('confirm_review') || $errors->has('confirm_request') || $errors->has('confirm_missed'))
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif
    @if($attendanceReview)
        <div class="alert {{ $hasArrival ? 'alert-info' : 'alert-warning' }}">
            <strong>{{ $hasArrival ? 'Arrived after a recorded missed appointment.' : 'Missed appointment confirmed by the office.' }}</strong>
            <p class="mb-0">Recorded {{ $attendanceReview->recorded_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}. This does not cancel the request or mark it Completed. {{ $hasArrival ? 'The later arrival remains separately recorded.' : 'Contact the office before making a late visit.' }}</p>
            @if($isOffice)<p class="mt-2 mb-0">Office note: {{ $attendanceReview->notes }}</p>@endif
        </div>
    @elseif($pastAppointment && ! $hasArrival && ! in_array($reservation->status, [\App\Enums\ReservationStatus::Completed, \App\Enums\ReservationStatus::Cancelled, \App\Enums\ReservationStatus::Rejected], true))
        <div class="alert alert-info"><strong>Past appointment — no recorded arrival.</strong><p class="mb-0">This does not prove a no-show. {{ $reservation->status === \App\Enums\ReservationStatus::ReadyForPickup ? 'Contact the office before visiting late; your request remains Ready for Pickup.' : 'The document is still being processed. Wait for Ready for Pickup and contact the office for visit guidance.' }}</p></div>
    @elseif(! $reservation->hasAppointmentSnapshot())
        <p class="app-note">This legacy request has no preserved appointment snapshot. Its current schedule cannot establish a historical missed appointment.</p>
    @endif
    @if($isOffice && ! $reviewBlock)
        <form class="mt-3" method="POST" action="{{ route('admin.reservations.missed.store', $reservation) }}" data-service-submit>
            @csrf
            <label class="form-label" for="missed-notes">Office attendance review note (Admin only)</label>
            <textarea id="missed-notes" class="form-control" name="notes" rows="2" maxlength="1000" required></textarea>
            <div class="form-check my-3"><input id="confirm-missed" class="form-check-input" type="checkbox" name="confirm_missed" value="1" required><label class="form-check-label" for="confirm-missed">I confirmed the appointment was missed; absence of a scan alone is not proof.</label></div>
            <button class="btn btn-outline-secondary" type="submit">Record missed appointment</button>
        </form>
    @endif
    @if($isOffice && $reservation->status === \App\Enums\ReservationStatus::ReadyForPickup && $pastAppointment && ! $attendanceReview && $reviewBlock)<p class="app-note">Attendance review: {{ $reviewBlock }}</p>@endif
    @if($openCancellation)
        <div class="alert alert-warning mt-3"><strong>Cancellation requested — awaiting office decision.</strong><p class="mb-0">The reservation is not cancelled. Current processing status remains {{ $reservation->status->label() }}.</p></div>
        <p><strong>Resident's reason:</strong> {{ $openCancellation->reason }}</p>
        @if($isOffice)
            @if($cancellationBlock)<div class="alert alert-info">Approval blocked: {{ $cancellationBlock }} You may decline the request with an explanation.</div>@endif
            <form method="POST" action="{{ route('admin.reservations.cancellation-requests.review', [$reservation, $openCancellation->id]) }}" data-service-submit>
                @csrf
                <input type="hidden" name="expected_status" value="{{ $reservation->status->value }}">
                <label class="form-label" for="cancel-decision">Cancellation decision</label>
                <select id="cancel-decision" name="decision" class="form-select" required><option value="">Choose a decision</option>@unless($cancellationBlock)<option value="approved">Approve cancellation</option>@endunless<option value="declined">Decline cancellation</option></select>
                <label class="form-label mt-3" for="decision-message">Explanation to the Resident</label><textarea class="form-control" id="decision-message" name="decision_message" rows="2" maxlength="1000" required></textarea>
                <div class="form-check my-3"><input class="form-check-input" id="confirm-cancel-review" type="checkbox" name="confirm_review" value="1" required><label class="form-check-label" for="confirm-cancel-review">I reviewed the current status, documents, payment and arrival records.</label></div>
                <button class="btn btn-primary" type="submit">Save cancellation decision</button>
            </form>
        @endif
    @elseif(! $isOffice && ! $cancellationBlock)
        <h3 class="h6 mt-4">Need to stop this request?</h3><p class="app-note">Processing has started. Request cancellation and wait for the office decision. Submitting this form does not cancel the reservation.</p>
        <form method="POST" action="{{ route('resident.reservations.cancellation-requests.store', $reservation) }}" data-service-submit>
            @csrf
            <label for="followup-reason" class="form-label">Reason for cancellation</label><textarea class="form-control" id="followup-reason" name="reason" maxlength="1000" rows="3" required>{{ is_string(old('reason')) ? old('reason') : '' }}</textarea>
            @error('reason')<p class="text-danger">{{ $message }}</p>@enderror
            <div class="form-check my-3"><input class="form-check-input" id="confirm-cancel-request" type="checkbox" name="confirm_request" value="1" required><label class="form-check-label" for="confirm-cancel-request">I understand that the office must approve this cancellation.</label></div>
            <button class="btn btn-outline-danger" type="submit">Request cancellation</button>
        </form>
    @elseif(! $isOffice && in_array($reservation->status, [\App\Enums\ReservationStatus::UnderReview, \App\Enums\ReservationStatus::Approved, \App\Enums\ReservationStatus::ReadyForPickup], true))
        <p class="app-note mt-3">{{ $cancellationBlock }}</p>
    @endif
    @if($cancellationReviews->where('status', '!=', 'pending')->isNotEmpty())
        <h3 class="h6 mt-4">Recent cancellation decisions</h3>
        @foreach($cancellationReviews->where('status', '!=', 'pending') as $review)<div class="border rounded-3 p-3 mt-2"><strong>{{ ucfirst($review->status) }}</strong> · {{ $review->reviewed_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}<p class="mb-0 mt-2">{{ $review->decision_message }}</p></div>@endforeach
    @endif
    <p class="app-note mt-3 mb-0">Rescheduling requires office coordination. Existing appointment snapshots are not edited. Cancellation, arrival, payment and completion are separate actions.</p>
</section>
