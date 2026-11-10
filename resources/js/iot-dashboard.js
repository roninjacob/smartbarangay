const dashboard = document.querySelector('[data-iot-dashboard]');
if (dashboard) {
    const node = (name) => dashboard.querySelector(`[data-monitor-${name}]`);
    const values = dashboard.querySelectorAll('[data-monitor-value]');
    const date = (value) => value ? new Date(value).toLocaleString() : 'Not reported';
    let page = 1;
    let lastPage = 1;
    let version = 0;
    let timer;
    let request;
    const eventNames = {
        boot: 'Device boot observed', online_observed: 'Authenticated communication resumed',
        offline_observed: 'Stale heartbeat observed', ready: 'Camera and decoder ready',
        camera_error: 'Camera unavailable', detection_error: 'QR decoder unavailable',
        scan_valid: 'Valid QR verification', scan_invalid: 'Invalid QR verification', transport: 'Transport report',
    };
    const format = (key, value) => {
        if (value === null || value === undefined) return 'Not reported';
        if (['heap', 'largest', 'psram'].includes(key)) return `${(value / 1024).toFixed(1)} KiB`;
        if (key.endsWith('_us')) return `${(value / 1000).toFixed(1)} ms`;
        if (key === 'http_ms') return `${value} ms`;
        if (key === 'rssi') return `${value} dBm`;
        return String(value);
    };
    const render = (data) => {
        const device = data.device;
        const metrics = device.telemetry || {};
        const display = {
            ...metrics, name: device.name, online: device.revoked ? 'Revoked' : device.online ? 'Online' : 'Offline',
            heartbeat: date(device.heartbeat_at), last_seen: date(device.last_seen_at),
            camera: { ready: 'Ready', camera_error: 'Error', detection_error: 'Decoder unavailable' }[device.camera_state] || 'Not reported',
            preview: device.preview ? 'Authorized viewing active' : 'Off',
            uptime: metrics.uptime_ms === undefined ? null : `${Math.floor(metrics.uptime_ms / 60000)} minutes`,
            scans: device.scans, valid: device.valid, invalid: device.invalid,
            latest: device.last_scan_at ? `${date(device.last_scan_at)} - ${device.last_result}` : null,
            handoff: device.handoff_age_ms === null ? null : `${device.handoff_age_ms} ms`,
        };
        if (!metrics.attempts) {
            for (const key of ['avg_us', 'p95_us', 'capture_us', 'convert_us', 'gray_us', 'detect_us', 'decode_us']) display[key] = null;
        }
        if (!metrics.preview_sent) display.preview_copy_us = null;
        values.forEach((element) => { element.textContent = format(element.dataset.monitorValue, display[element.dataset.monitorValue]); });
        node('content').hidden = false;
        node('reported').textContent = `Latest telemetry ${date(device.telemetry_at)}. Values describe that report.`;
        node('counts').textContent = device.counts_since ? `Recorded since ${date(device.counts_since)}.` : 'No accepted scans recorded since monitoring was enabled.';
        node('status').textContent = `Updated ${date(data.observed_at)}. Refreshes every five seconds while this page is visible.`;
        node('events').replaceChildren();
        for (const event of data.events) {
            const item = document.createElement('li');
            const title = document.createElement('strong');
            title.textContent = eventNames[event.kind] || 'Device observation';
            const description = document.createElement('p');
            description.textContent = event.kind === 'transport' ? `${event.details.category} - HTTP ${event.details.http ?? 'not reported'}` : '';
            const at = document.createElement('time');
            at.dateTime = event.at;
            at.textContent = date(event.at);
            item.append(title, description, at);
            node('events').append(item);
        }
        node('empty').hidden = data.events.length > 0;
        lastPage = data.pagination.last_page;
        node('page').textContent = `Page ${data.pagination.page} of ${lastPage}`;
        node('previous').disabled = page <= 1;
        node('next').disabled = page >= lastPage;
    };
    const load = async (current) => {
        if (current !== version || document.hidden || !node('device').value) return;
        const controller = new AbortController();
        request = controller;
        const timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const query = new URLSearchParams({ device: node('device').value, page });
            const response = await fetch(`${dashboard.dataset.url}?${query}`, {
                credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller.signal,
            });
            if (!response.ok) throw new Error([401, 403, 419].includes(response.status) ? 'Session unavailable. Sign in again.' : 'Monitoring unavailable. Select the scanner to retry.');
            const data = await response.json();
            if (current !== version || document.hidden) return;
            render(data);
            timer = setTimeout(() => void load(current), 5000);
        } catch (error) {
            if (current === version && !document.hidden) {
                node('status').textContent = error.name === 'AbortError' ? 'Monitoring request timed out. Select the scanner to retry.' : error.message;
                node('content').hidden = true;
            }
        } finally { clearTimeout(timeout); }
    };
    const refresh = () => {
        clearTimeout(timer);
        request?.abort();
        version++;
        node('content').hidden = true;
        void load(version);
    };
    node('device').addEventListener('change', () => { page = 1; refresh(); });
    node('previous').addEventListener('click', () => { if (page > 1) { page--; refresh(); } });
    node('next').addEventListener('click', () => { if (page < lastPage) { page++; refresh(); } });
    document.addEventListener('visibilitychange', refresh);
    window.addEventListener('pagehide', () => { clearTimeout(timer); request?.abort(); version++; });
}
