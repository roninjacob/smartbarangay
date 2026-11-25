{{-- Admin "Mark as missed": records the missed visit and cancels the request. Uses the page's required-confirmation pattern. --}}
<form method="POST" action="{{ route('admin.reservations.mark-missed.store', $reservation) }}" class="{{ $compact ?? false ? 'text-start' : 'mt-3' }}" data-service-submit>
    @csrf
    <input type="hidden" name="expected_status" value="{{ $reservation->status->value }}">
    <div class="form-check {{ $compact ?? false ? 'mb-2' : 'my-3' }}">
        <input id="confirm-mark-missed-{{ $reservation->id }}" class="form-check-input" type="checkbox" name="confirm_mark_missed" value="1" required>
        <label class="form-check-label" for="confirm-mark-missed-{{ $reservation->id }}">I confirmed the resident missed the appointment.</label>
    </div>
    <button class="btn btn-outline-danger {{ $compact ?? false ? 'btn-sm' : 'w-100' }}" type="submit">Mark as missed</button>
    <p class="app-note mt-2 mb-0">Cancels this request and frees the slot.</p>
</form>
