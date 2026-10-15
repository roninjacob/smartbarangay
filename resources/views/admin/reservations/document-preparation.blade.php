@if($reservation->status->canPrepareDocument() || $reservation->document?->mime_type === 'application/pdf')
<section class="card dashboard-panel reservation-panel mt-4" aria-labelledby="certificate-heading">
    <h2 id="certificate-heading">Certificate</h2>
    <p class="app-note">Print Certificate uses this request’s saved personalized PDF. Printing does not change request status. Update Ready for Pickup or Completed manually.</p>
    @if($reservation->document?->mime_type === 'application/pdf')
        @include('reservations.generated-actions', ['documentRole' => 'admin'])
    @else
    <p class="alert alert-info" role="status">{{ $certificateState['message'] }}</p>
    @if($canGenerateCertificate)
        <form method="POST" action="{{ route('admin.reservations.generated.store', $reservation) }}" class="mb-3">
            @csrf
            <button class="btn btn-primary" type="submit">Generate Certificate</button>
        </form>
        <p class="app-note">Uses the information and layout saved with this request. A generated certificate is retained without overwriting.</p>
    @elseif(!$reservation->document)
        <p class="app-note">Generation is unavailable because the original PDF or complete historical information is missing. Do not substitute the current profile or layout.</p>
    @endif
    @endif
</section>
@endif
