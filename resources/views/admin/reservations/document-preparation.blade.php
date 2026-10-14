@if($reservation->status->canPrepareDocument() || $reservation->document?->mime_type === 'application/pdf')
<section class="card dashboard-panel reservation-panel mt-4" aria-labelledby="certificate-heading">
    @if($reservation->document?->mime_type === 'application/pdf')
    <h2 id="certificate-heading">Certificate</h2>
    <p class="app-note">Printing does not change request status. Update Ready for Pickup or Completed manually.</p>
    @include('reservations.generated-actions', ['documentRole' => 'admin'])
    @else
    <h2 id="certificate-heading">Certificate Preview</h2>
    <p class="app-note">The official certificate PDF assigned to this service. Printing does not change request status. @if($reservation->status === \App\Enums\ReservationStatus::Approved) Update Ready for Pickup manually when the certificate is ready. @else Mark Completed after the transaction is finished. @endif</p>
    @include('admin.services.document-preview')
    @if($reservation->document_template_snapshot && !$reservation->document)
        <form method="POST" action="{{ route('admin.reservations.generated.store', $reservation) }}" class="mb-3">
            @csrf
            <button class="btn btn-primary" type="submit">Generate Certificate</button>
        </form>
        <p class="app-note">Uses the information and layout saved with this request. A generated certificate is retained without overwriting.</p>
    @else
    @if($preview['kind'] === 'pdf')
        <a class="btn btn-primary" href="{{ route('admin.reservations.document.print', $reservation) }}">Print Certificate</a>
    @else
        <button class="btn btn-primary mb-3" type="button" disabled>Print Certificate</button>
        <p class="alert alert-info">Upload an Official Certificate PDF in Edit Service before printing this request.</p>
    @endif
    @endif
    @endif
</section>
@endif
