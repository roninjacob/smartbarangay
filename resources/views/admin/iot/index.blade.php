@extends('layouts.authenticated')
@section('dashboard')
<div class="iot-monitor" data-iot-dashboard data-url="{{ route('admin.iot-dashboard.data') }}"
     data-control-url="{{ url('/admin/iot-dashboard/devices') }}">
    <section class="dashboard-panel p-4 mb-4">
        <div class="d-flex flex-wrap align-items-end gap-3">
            <div class="flex-grow-1"><h2 class="h5">IoT control center</h2><p class="app-note">Authenticated reports and software controls for your standalone scanner.</p>
                <label for="monitor-device" class="form-label">Registered scanner</label>
                <select id="monitor-device" class="form-select" data-monitor-device>
                    <option value="">Select a device</option>
                    @foreach($devices as $device)<option value="{{ $device['id'] }}">{{ $device['name'] }}{{ $device['revoked'] ? ' (revoked)' : '' }}</option>@endforeach
                </select>
            </div>
            <a href="{{ route('admin.qr-verification.index') }}" class="btn btn-primary">Open QR Verification</a>
        </div>
        <p class="app-note mt-3 mb-0" data-monitor-status role="status" aria-live="polite">Select a scanner to view its latest reports.</p>
    </section>
    <div data-monitor-content hidden>
        <section class="dashboard-panel p-4 mb-4 iot-overview" aria-labelledby="device-overview">
            <div class="iot-overview-head">
                <h2 id="device-overview" class="h5 m-0">Operational overview</h2>
                <span class="iot-readiness" data-monitor-readiness data-tone="unknown">Not reported</span>
            </div>
            <p class="app-note mt-2" data-monitor-readiness-note></p>
            <dl class="iot-monitor-grid">
                @foreach(['name' => 'Registered name', 'online' => 'ESP32-CAM connection', 'feedback' => 'ESP32 feedback device (via ESP-NOW)', 'scanner' => 'Scanner', 'heartbeat' => 'Last heartbeat', 'last_seen' => 'Last authenticated communication', 'camera' => 'Camera and decoder', 'preview' => 'Preview viewing session'] as $key => $label)
                    <div><dt>{{ $label }}</dt><dd data-monitor-value="{{ $key }}">Not reported</dd></div>
                @endforeach
            </dl>
        </section>
        <section class="dashboard-panel p-4 mb-4" aria-labelledby="device-controls">
            <h2 id="device-controls" class="h5">Device controls</h2>
            <p class="app-note" data-control-support></p>
            <div class="iot-control-row">
                <div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="scanner-enabled" data-control-toggle disabled>
                    <label class="form-check-label" for="scanner-enabled"><strong>Scanner ON</strong> <span class="app-note">Off stops QR scanning and camera previews; Wi-Fi and monitoring stay on.</span></label>
                </div>
                <button type="button" class="btn btn-outline-danger" data-control-restart disabled>Restart scanner</button>
            </div>
            <p class="iot-control-state" data-control-state role="status" aria-live="polite"></p>
            <ol class="iot-command-list" data-control-commands aria-label="Recent commands"></ol>
        </section>
        <section class="dashboard-panel p-4 mb-4" aria-labelledby="device-metrics">
            <h2 id="device-metrics" class="h5">Device health</h2>
            <p class="app-note" data-monitor-reported>Telemetry not reported</p>
            <dl class="iot-monitor-grid">
                @foreach(['firmware' => 'Firmware', 'boot' => 'Boot identifier', 'uptime' => 'Uptime', 'reset' => 'Reset reason', 'rssi' => 'Wi-Fi signal', 'channel' => 'Wi-Fi channel', 'heap' => 'Free internal RAM', 'min_heap' => 'Lowest internal RAM since boot', 'largest' => 'Largest internal block', 'psram' => 'Free PSRAM', 'alloc_fail' => 'Internal allocation failures', 'capture_fail' => 'Capture failures', 'http_ms' => 'Last HTTPS duration', 'http_code' => 'Last HTTP result', 'error' => 'Transport category'] as $key => $label)
                    <div><dt>{{ $label }}</dt><dd data-monitor-value="{{ $key }}">Not reported</dd></div>
                @endforeach
            </dl>
        </section>
        <section class="dashboard-panel p-4 mb-4" aria-labelledby="scan-analytics">
            <h2 id="scan-analytics" class="h5">QR performance</h2>
            <p class="app-note" data-monitor-counts>Waiting for recorded scan totals</p>
            <dl class="iot-monitor-grid">
                @foreach(['scan_rate' => 'Scan attempts per second', 'half_us' => 'Fast attempt (320x240)', 'full_us' => 'Full attempt (640x480)', 'avg_us' => 'Mean attempt (last 32)', 'p95_us' => 'p95 attempt (last 32)', 'attempts' => 'Attempts this boot', 'decodes' => 'QR codes read this boot', 'scans' => 'Verification requests', 'valid' => 'Valid results', 'invalid' => 'Denied results', 'latest' => 'Latest result', 'handoff' => 'Capture age at Admin handoff'] as $key => $label)
                    <div><dt>{{ $label }}</dt><dd data-monitor-value="{{ $key }}">Not reported</dd></div>
                @endforeach
            </dl>
            <p class="app-note mb-0">Attempt timings are running averages reported by the scanner. Verification totals count accepted requests since monitoring began; check-ins are confirmed separately by an Admin.</p>
        </section>
        <section class="dashboard-panel p-4" aria-labelledby="device-activity">
            <h2 id="device-activity" class="h5">Activity and diagnostics</h2>
            <p class="app-note">Latest seven days, up to 500 observations per device. Images and QR credentials are excluded.</p>
            <ol class="iot-diagnostic-feed" data-monitor-events aria-label="Device activity"></ol>
            <p class="app-note" data-monitor-empty>No observations in this period.</p>
            <nav class="d-flex flex-wrap align-items-center gap-3 mt-3" aria-label="Device activity pages">
                <button type="button" class="btn btn-outline-secondary" data-monitor-previous disabled>Previous</button>
                <span class="app-note" data-monitor-page></span>
                <button type="button" class="btn btn-outline-secondary" data-monitor-next disabled>Next</button>
            </nav>
        </section>
    </div>
</div>
@push('modals')
<div class="modal fade" id="restart-confirmation" tabindex="-1" aria-labelledby="restart-heading" aria-describedby="restart-description" aria-hidden="true" data-control-restart-modal>
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title h5" id="restart-heading">Restart the scanner?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><p id="restart-description" class="mb-0">The ESP32-CAM reboots and reconnects in about 15 seconds. Scanning pauses meanwhile; its ON/OFF setting is kept. No reservation is changed.</p></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" data-control-restart-confirm>Restart scanner</button></div>
    </div></div>
</div>
@endpush
@endsection
