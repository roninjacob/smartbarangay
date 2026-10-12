@extends('layouts.authenticated')
@section('dashboard')
<div class="qr-verification" data-qr-verification data-verify-url="{{ route('admin.qr-verification.verify') }}">
    <section class="dashboard-panel qr-scanner-panel" aria-labelledby="scanner-heading">
        <div class="dashboard-panel-heading"><div><h2 id="scanner-heading">Scan a Resident ticket</h2><p class="panel-description">Use the laptop camera to read a SmartBarangay QR ticket.</p></div></div>
        <div class="qr-scanner-body">
            <div class="qr-camera-frame">
                <video data-qr-video muted playsinline aria-label="QR camera preview" hidden></video>
                <div class="qr-camera-placeholder" data-qr-placeholder><x-app-icon name="qr"/><p>Camera is off</p><small>Position the entire ticket QR code inside the camera preview.</small></div>
            </div>
            <p class="qr-scan-status" data-qr-status role="status" aria-live="polite">Ready to scan. Start the camera when the Resident presents their ticket.</p>
            <div class="qr-scanner-actions"><button type="button" class="btn btn-primary" data-qr-start>Start Camera</button><button type="button" class="btn btn-outline-secondary" data-qr-stop disabled>Stop Camera</button><button type="button" class="btn btn-outline-primary" data-qr-next hidden>Scan Next Ticket</button></div>
            <p class="app-note mt-3 mb-0">Camera access requires permission and HTTPS or localhost. Only the ticket credential is sent for verification; camera images are not uploaded.</p>
            <noscript><p class="alert alert-warning mt-3">Enable JavaScript to use QR camera scanning.</p></noscript>
        </div>
    </section>
    <section class="dashboard-panel qr-result-panel" aria-labelledby="result-heading" aria-live="polite" aria-atomic="true" data-qr-result>
        <div class="dashboard-panel-heading"><div><h2 id="result-heading">Verification result</h2><p class="panel-description" data-qr-result-message>No ticket scanned yet.</p></div></div>
        <div class="qr-result-body">
            <p class="qr-verification-note">Verification only. Scanning does not check in the Resident or change the reservation status.</p>
            <dl class="qr-result-details" data-qr-details hidden>
                <div><dt>Resident</dt><dd data-qr-resident></dd></div><div><dt>Service</dt><dd data-qr-service></dd></div><div><dt>Schedule</dt><dd data-qr-schedule></dd></div><div><dt>Reservation Status</dt><dd data-qr-reservation-status></dd></div>
            </dl>
        </div>
    </section>
</div>
@endsection
