@extends('layouts.authenticated')
@section('dashboard')
<div class="qr-verification" data-qr-verification data-verify-url="{{ route('admin.qr-verification.verify') }}"
     data-iot-url="{{ url('/admin/iot-scanner') }}">
    <section class="dashboard-panel qr-scanner-panel" aria-labelledby="scanner-heading">
        <div class="dashboard-panel-heading"><div><h2 id="scanner-heading">Scan a Resident ticket</h2><p class="panel-description">Choose a scanner. Laravel verifies every ticket securely.</p></div></div>
        <div class="px-4 pt-3 d-flex flex-wrap gap-2" role="group" aria-label="Scanner mode">
            <button type="button" class="btn btn-primary" data-scanner-mode="laptop" aria-pressed="true">Laptop Camera</button>
            <button type="button" class="btn btn-outline-primary" data-scanner-mode="iot" aria-pressed="false"
                @disabled(! config('iot.enabled') || config('session.driver') !== 'database')>IoT Scanner</button>
        </div>
        @if (! config('iot.enabled') || config('session.driver') !== 'database')
            <p class="app-note px-4 pt-3 mb-0">IoT Scanner is unavailable in this environment. Laptop Camera remains available.</p>
        @endif
        <div data-laptop-panel>
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
        </div>
        <div class="p-4" data-iot-panel hidden>
            <p class="app-note">Provisioned scanners authenticate automatically. Select a scanner to view live results. Scanning never checks in a Resident automatically.</p>
            <label class="form-label" for="iot-device">Scanner</label>
            <select class="form-select mb-3" id="iot-device" data-iot-device><option value="">Loading scanners…</option></select>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <button type="button" class="btn btn-primary" data-iot-connect disabled>Connect scanner</button>
                <button type="button" class="btn btn-outline-secondary" data-iot-refresh>Refresh scanners</button>
                <button type="button" class="btn btn-outline-danger" data-iot-disconnect disabled>Disconnect</button>
            </div>
            <p class="alert alert-info" data-iot-status role="status" aria-live="polite">Select a scanner to begin.</p>
            <dl class="row small"><dt class="col-sm-3">Device activity</dt><dd class="col-sm-9" data-iot-activity>No recent activity</dd>
                <dt class="col-sm-3">Latest scan</dt><dd class="col-sm-9" data-iot-latest>No scan received</dd></dl>
            <section class="border rounded-4 p-3" aria-labelledby="iot-preview-title">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3"><h3 id="iot-preview-title" class="h6 me-auto mb-0">Camera Preview</h3>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-iot-preview-start disabled>Start preview</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-iot-preview-stop disabled>Stop preview</button></div>
                <p class="app-note" data-iot-preview-note>Preview is off. Images are private and expire shortly. Keep only the ticket in view.</p>
                <img class="img-fluid rounded-3" width="320" height="240" alt="Live ESP32-CAM image" data-iot-preview-image hidden>
            </section>
        </div>
    </section>
    <section class="dashboard-panel qr-result-panel" tabindex="-1" aria-labelledby="result-heading" aria-live="polite" aria-atomic="true" data-qr-result>
        <div class="dashboard-panel-heading"><div><h2 id="result-heading"><span class="qr-success-icon" data-qr-success-icon hidden><x-app-icon name="check"/></span><span data-qr-result-title>Verification result</span></h2><p class="panel-description" data-qr-result-message>No ticket scanned yet.</p></div></div>
        <div class="qr-result-body">
            <p class="qr-verification-note">Verification only. Scanning does not check in the Resident or change the reservation status.</p>
            <dl class="qr-result-details" data-qr-details hidden>
                <div><dt>Resident</dt><dd data-qr-resident></dd></div><div><dt>Service</dt><dd data-qr-service></dd></div><div><dt>Schedule</dt><dd data-qr-schedule></dd></div><div><dt>Reservation Status</dt><dd data-qr-reservation-status></dd></div>
            </dl>
        </div>
    </section>
    <div class="modal qr-success-modal" tabindex="-1" aria-labelledby="qr-success-heading" aria-describedby="qr-success-message" data-qr-success-modal>
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header"><h2 class="modal-title h5" id="qr-success-heading"><span class="qr-success-icon"><x-app-icon name="check"/></span>QR Verified Successfully</h2></div>
                <div class="modal-body">
                    <dl class="qr-result-details mt-0">
                        <div><dt>Resident</dt><dd data-qr-modal-resident></dd></div>
                        <div><dt>Service</dt><dd data-qr-modal-service></dd></div>
                        <div><dt>Schedule</dt><dd data-qr-modal-schedule></dd></div>
                        <div><dt>Status</dt><dd data-qr-modal-status></dd></div>
                    </dl>
                    <p class="qr-verification-note mt-4 mb-0" id="qr-success-message" role="status">This QR ticket is valid. Opening reservation detailsâ€¦</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
