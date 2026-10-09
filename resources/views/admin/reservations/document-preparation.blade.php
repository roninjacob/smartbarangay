@if($reservation->status === \App\Enums\ReservationStatus::Approved)
<section class="card dashboard-panel reservation-panel mt-4" aria-labelledby="certificate-heading">
    <h2 id="certificate-heading">Certificate Preview</h2>
    <p class="app-note">The official certificate PDF assigned to this service. Printing does not change request status. Update Ready for Pickup manually when the certificate is ready.</p>
    @include('admin.services.document-preview')
    @if($preview['kind'] === 'pdf')
        <a class="btn btn-primary" href="{{ route('admin.reservations.document.print', $reservation) }}">Print Certificate</a>
    @else
        <button class="btn btn-primary mb-3" type="button" disabled>Print Certificate</button>
        <p class="alert alert-info">Upload an Official Certificate PDF in Edit Service before printing this request.</p>
    @endif
</section>
@endif
