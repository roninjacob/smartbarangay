@extends('layouts.authenticated')
@section('dashboard')
@if ($errors->has('checkin'))
    <div class="alert alert-danger qr-checkin-error" role="alert">{{ $errors->first('checkin') }} The reservation was not changed.</div>
@endif
<div class="qr-verification" data-qr-verification data-verify-url="{{ route('admin.qr-verification.verify') }}"
     data-iot-url="{{ url('/admin/iot-scanner') }}">
    <section class="dashboard-panel qr-scanner-panel" aria-labelledby="scanner-heading">
        <div class="dashboard-panel-heading"><div><h2 id="scanner-heading">Scan a Resident ticket</h2><p class="panel-description">Position the complete ticket inside the camera view.</p></div></div>
        <div class="px-4 pt-3 d-flex flex-wrap gap-2" role="group" aria-label="Scanner mode">
            <button type="button" class="btn btn-primary" data-scanner-mode="laptop" aria-pressed="true">Laptop Camera</button>
            <button type="button" class="btn btn-outline-primary" data-scanner-mode="iot" aria-pressed="false"
                @disabled(! config('iot.enabled') || config('session.driver') !== 'database')>IoT Scanner</button>
        </div>
        <div class="qr-audio-controls px-4 pt-3" data-iot-audio data-audio-scope="{{ auth()->id() }}" role="group" aria-label="Verification sounds">
            <div class="form-check form-switch m-0">
                <input class="form-check-input" type="checkbox" role="switch" id="iot-audio-enabled" data-iot-audio-enabled>
                <label class="form-check-label" for="iot-audio-enabled">Verification sounds</label>
            </div>
            <label class="visually-hidden" for="iot-audio-volume">Sound volume</label>
            <input type="range" class="form-range qr-audio-volume" id="iot-audio-volume" min="0" max="100" step="5" data-iot-audio-volume>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-iot-audio-test>Test sound</button>
            <button type="button" class="btn btn-sm btn-primary" data-iot-audio-activate hidden>Enable sound</button>
            <p class="app-note m-0 qr-audio-status" data-iot-audio-status role="status" aria-live="polite"></p>
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
        <div class="qr-scanner-body pt-3" data-iot-panel hidden>
            <div class="iot-device-controls">
                <div><label class="form-label" for="iot-device">ESP32-CAM scanner</label>
                    <select class="form-select" id="iot-device" data-iot-device><option value="">Select a scanner</option></select></div>
                <button type="button" class="btn btn-outline-secondary" data-iot-refresh>Refresh</button>
                <button type="button" class="btn btn-outline-primary" data-iot-retry hidden>Resume scanner</button>
                <button type="button" class="btn btn-outline-secondary" data-iot-disconnect disabled>Pause</button>
            </div>
            <p class="qr-scan-status" data-iot-status role="status" aria-live="polite">Select a scanner to connect and open its camera.</p>
            <h3 class="visually-hidden">Camera Preview</h3>
            <div class="qr-camera-frame iot-camera-viewport" aria-label="Authenticated ESP32-CAM camera preview">
                <img alt="Recent image from the selected ESP32-CAM" data-iot-preview-image hidden>
                <div class="qr-camera-placeholder" data-iot-preview-placeholder><x-app-icon name="qr"/><p data-iot-preview-note>Waiting for a scanner</p></div>
                <div class="iot-scan-guide" data-iot-guide aria-hidden="true" hidden><span></span></div>
                <span class="iot-live-badge" data-iot-live hidden>Recent snapshot</span>
            </div>
            <p class="iot-preview-meta" data-iot-preview-meta aria-live="off"></p>
            <p class="app-note mb-0">Periodic snapshots, not live video. Hold the whole QR flat inside the guide, about 15–25 cm from the camera, with screen brightness up.</p>
            <dl class="iot-scanner-summary"><div><dt>Device activity</dt><dd data-iot-activity>Not connected</dd></div>
                <div><dt>Latest scan</dt><dd data-iot-latest>No scan received</dd></div></dl>
            <p class="app-note mb-0">The camera opens automatically for your session. Keep the complete ticket QR visible. Each result stays on screen, and on the scanner's lights and display, until you select Done or confirm check-in.</p>
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
    {{-- One result window for every outcome. It stays open until the Admin acts; the scanner's LED and OLED follow it. --}}
    <div class="modal qr-result-modal" tabindex="-1" aria-labelledby="qr-result-modal-title" aria-describedby="qr-result-modal-lead" data-qr-result-modal>
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" data-qr-modal-content>
                <div class="modal-header">
                    <span class="qr-outcome-icon is-success" data-qr-modal-icon-success aria-hidden="true"><x-app-icon name="check"/></span>
                    <span class="qr-outcome-icon is-denied" data-qr-modal-icon-denied aria-hidden="true" hidden>
                        <svg class="app-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/></svg>
                    </span>
                    <div class="qr-outcome-heading">
                        <p class="qr-outcome-eyebrow" data-qr-modal-eyebrow>QR ticket verification</p>
                        <h2 class="modal-title h5" id="qr-result-modal-title" data-qr-modal-title></h2>
                    </div>
                </div>
                <div class="modal-body">
                    <p class="qr-outcome-lead" id="qr-result-modal-lead" data-qr-modal-lead></p>
                    <dl class="qr-outcome-details" data-qr-modal-details>
                        <div><dt>Resident</dt><dd data-qr-modal-resident></dd></div>
                        <div><dt>Reference</dt><dd data-qr-modal-reference></dd></div>
                        <div><dt>Service</dt><dd data-qr-modal-service></dd></div>
                        <div><dt>Schedule</dt><dd data-qr-modal-schedule></dd></div>
                        <div><dt>Reservation status</dt><dd data-qr-modal-status></dd></div>
                        <div><dt>Verified</dt><dd data-qr-modal-verified></dd></div>
                    </dl>
                    <p class="qr-outcome-note" data-qr-modal-note role="status" aria-live="polite"></p>
                </div>
                <div class="modal-footer qr-outcome-actions">
                    <button type="button" class="btn btn-outline-secondary" data-qr-modal-done>Done</button>
                    <a class="btn btn-outline-primary" data-qr-modal-open hidden>Open reservation</a>
                    <form method="POST" data-qr-modal-checkin hidden>
                        @csrf
                        <input type="hidden" name="confirm_checkin" value="1">
                        <input type="hidden" name="iot_event" data-qr-modal-event disabled>
                        <button class="btn btn-primary" type="submit" data-qr-modal-confirm>Confirm Check-in</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
