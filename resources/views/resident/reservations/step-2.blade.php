<h2 id="reservation-step-heading">Review what to prepare</h2>
<p class="text-secondary">Requirements for <strong>{{ $service->name }}</strong>. Read the list before choosing your schedule.</p>
@include('resident.reservations.requirements-list')
<form method="POST" action="{{ route('resident.reservations.requirements.review') }}" data-service-submit>
    @csrf
    <div class="reservation-actions"><a href="{{ route('resident.reservations.create') }}" class="btn btn-outline-secondary">Change service</a><button class="btn btn-primary" type="submit">Continue to schedules</button></div>
</form>
