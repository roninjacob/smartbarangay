<h2 id="reservation-step-heading">Confirm your reservation</h2>
<p class="text-secondary">Check the details below before submitting your request.</p>
<dl class="reservation-summary">
    <div><dt>Service</dt><dd>{{ $service->name }}</dd></div>
    <div><dt>Date</dt><dd>{{ $schedule->date->format('l, F j, Y') }}</dd></div>
    <div><dt>Time</dt><dd>{{ \Illuminate\Support\Carbon::parse($schedule->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($schedule->end_time)->format('g:i A') }}</dd></div>
    <div><dt>Resident</dt><dd>{{ auth()->user()->name }}</dd></div>
</dl>
<h3 class="h6 mt-4">Requirements to prepare</h3>
@include('resident.reservations.requirements-list')
<p class="reservation-notice">Your request will start as <strong>Pending</strong>. Submission does not mean the request has been approved.</p>
<form method="POST" action="{{ route('resident.reservations.store') }}" data-service-submit>
    @csrf
    <input type="hidden" name="confirmation_token" value="{{ $draft['confirmation_token'] }}">
    <div class="reservation-actions"><a href="{{ route('resident.reservations.schedule') }}" class="btn btn-outline-secondary">Change schedule</a><button class="btn btn-primary" type="submit" data-saving-label="Submitting…">Confirm Reservation</button></div>
</form>
