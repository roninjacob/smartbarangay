@extends('layouts.authenticated')
@section('dashboard')
<div class="iot-monitor" data-iot-dashboard data-url="{{ route('admin.iot-dashboard.data') }}">
    <section class="dashboard-panel p-4 mb-4">
        <div class="d-flex flex-wrap align-items-end gap-3">
            <div class="flex-grow-1"><h2 class="h5">Device monitoring</h2><p class="app-note">Authenticated health reports from your standalone scanner.</p>
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
        <section class="dashboard-panel p-4 mb-4" aria-labelledby="device-overview">
            <h2 id="device-overview" class="h5">Device overview</h2>
            <dl class="iot-monitor-grid">
                @foreach(['name' => 'Registered name', 'online' => 'Connection', 'heartbeat' => 'Last heartbeat', 'last_seen' => 'Last successful communication', 'camera' => 'Camera', 'preview' => 'Preview viewing session', 'firmware' => 'Firmware', 'uptime' => 'Reported uptime'] as $key => $label)
                    <div><dt>{{ $label }}</dt><dd data-monitor-value="{{ $key }}">Not reported</dd></div>
                @endforeach
            </dl>
        </section>
        <section class="dashboard-panel p-4 mb-4" aria-labelledby="scan-analytics">
            <h2 id="scan-analytics" class="h5">Scan verification activity</h2>
            <p class="app-note" data-monitor-counts>Waiting for recorded scan totals</p>
            <dl class="iot-monitor-grid">
                @foreach(['scans' => 'Accepted scan requests', 'valid' => 'Valid results', 'invalid' => 'Invalid results', 'latest' => 'Latest scan result'] as $key => $label)
                    <div><dt>{{ $label }}</dt><dd data-monitor-value="{{ $key }}">Not reported</dd></div>
                @endforeach
            </dl>
            <p class="app-note mb-0">These totals count accepted verification requests. Replays and duplicate submissions are excluded. Confirmed check-ins are recorded through the separate Admin confirmation action.</p>
        </section>
        <section class="dashboard-panel p-4 mb-4" aria-labelledby="device-metrics">
            <h2 id="device-metrics" class="h5">Hardware, network and camera health</h2>
            <p class="app-note" data-monitor-reported>Telemetry not reported</p>
            <dl class="iot-monitor-grid">
                @foreach(['rssi' => 'Wi-Fi RSSI', 'heap' => 'Free internal RAM', 'largest' => 'Largest internal block', 'psram' => 'Free PSRAM', 'capture_fail' => 'Capture failures', 'attempts' => 'QR attempts', 'decodes' => 'Successful decodes including repeats', 'avg_us' => 'Mean attempt latency', 'p95_us' => 'p95 attempt latency', 'capture_us' => 'Last capture', 'convert_us' => 'Last JPEG conversion', 'gray_us' => 'Last grayscale conversion', 'detect_us' => 'Last candidate detection', 'decode_us' => 'Last QR decode', 'preview_copy_us' => 'Last preview copy', 'preview_sent' => 'Successful preview uploads', 'http_ms' => 'Last HTTPS duration', 'http_code' => 'Last HTTP result', 'error' => 'Transport category', 'alloc_fail' => 'Internal allocation failures', 'reset' => 'Reset reason code', 'handoff' => 'Last capture age at Admin handoff'] as $key => $label)
                    <div><dt>{{ $label }}</dt><dd data-monitor-value="{{ $key }}">Not reported</dd></div>
                @endforeach
            </dl>
            <p class="app-note mb-0">Attempt statistics cover the latest 32 measured attempts. Handoff age uses synchronized device and server clocks. Offline reflects a stale heartbeat; its physical cause is unknown.</p>
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
@endsection
