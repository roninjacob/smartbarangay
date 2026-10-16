<section class="card dashboard-panel reservation-panel mt-4" aria-labelledby="checkin-heading">
    <h2 id="checkin-heading">Resident Check-In</h2>
    @if($checkin)
        <p class="mb-2"><span class="badge text-bg-success">Checked In</span> <span class="text-secondary">Already checked in</span></p>
        <dl class="reservation-summary mb-0">
            <div><dt>Arrival</dt><dd>{{ $checkin->verified_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }} · Philippine time</dd></div>
            <div><dt>Recorded by</dt><dd>{{ $checkin->verifier?->name ?? 'Former account' }}</dd></div>
        </dl>
    @elseif($reservation->status !== \App\Enums\ReservationStatus::ReadyForPickup)
        <p class="mb-0 text-secondary">{{ $reservation->status === \App\Enums\ReservationStatus::Completed ? 'Transaction already completed.' : 'Check-in unavailable. This reservation must be Ready for Pickup.' }}</p>
    @elseif($checkinVerified)
        <p>QR verified. Confirm that this Resident has physically arrived for this reservation.</p>
        <p class="app-note">Check-in records arrival only. Payment and completion remain separate actions.</p>
        <form method="POST" action="{{ route('admin.reservations.check-in.store', $reservation) }}" data-service-submit>
            @csrf
            <div class="form-check mb-3"><input class="form-check-input" id="confirm-checkin" type="checkbox" name="confirm_checkin" value="1" required><label class="form-check-label" for="confirm-checkin">I confirm this Resident has arrived.</label></div>
            <button class="btn btn-primary" type="submit">Confirm Check-In</button>
        </form>
    @else
        <p class="mb-2">Not checked in. Scan and verify this QR ticket before confirming arrival.</p>
        <a class="btn btn-outline-primary" href="{{ route('admin.qr-verification.index') }}">Verify QR for Check-In</a>
    @endif
</section>
