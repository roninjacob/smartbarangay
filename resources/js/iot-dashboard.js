import { Modal } from 'bootstrap';

// Readiness from reported facts only. Order matters: connection, then restart, operational state,
// camera and peer link. Nothing here is assumed; unknown values stay "Not reported".
export function readiness(data) {
    const device = data.device;
    const control = data.control ?? {};
    const metrics = device.telemetry || {};
    const restart = (control.commands ?? []).find((c) => c.type === 'restart' && ['pending', 'delivered', 'restarting'].includes(c.status));
    if (device.revoked) return { tone: 'danger', label: 'Revoked', note: 'This scanner can no longer authenticate.' };
    if (!device.online) {
        const age = device.heartbeat_age_ms;
        return { tone: 'danger', label: restart ? 'Restarting' : 'Offline',
            note: restart ? 'Waiting for the scanner to boot and report in.'
                : age === null || age === undefined ? 'No authenticated heartbeat received yet.' : `No authenticated heartbeat for ${Math.round(age / 1000)} s.` };
    }
    if (restart) return { tone: 'warning', label: 'Restarting', note: 'Restart requested; completion is confirmed when a new boot reports in.' };
    if (control.actual && control.actual.enabled === false) {
        return { tone: 'neutral', label: 'Scanner off', note: 'Connected and healthy. Scanning and previews are stopped until it is switched on.' };
    }
    if (device.camera_state === 'camera_error') return { tone: 'danger', label: 'Camera unavailable', note: 'The scanner is online but its camera failed. Check the camera ribbon and power.' };
    if (device.camera_state === 'detection_error') return { tone: 'warning', label: 'Decoder unavailable', note: 'The camera works but QR decoding is not running.' };
    const link = metrics.feedback_age_ms;
    if (link !== undefined && link > 5000) {
        return { tone: 'warning', label: 'Feedback device unreachable', note: 'The scanner works, but the OLED/LED device is not answering over ESP-NOW.' };
    }
    return { tone: 'success', label: 'Ready', note: 'Connected, scanning and reporting normally.' };
}

const RESETS = { 0: 'Unknown', 1: 'Power on', 2: 'External pin', 3: 'Software (restart)', 4: 'Crash (panic)', 5: 'Interrupt watchdog',
    6: 'Task watchdog', 7: 'Watchdog', 8: 'Deep sleep', 9: 'Brownout (power)', 10: 'SDIO' };

export function format(key, value) {
    if (value === null || value === undefined || value === '') return 'Not reported';
    if (['heap', 'largest', 'psram', 'min_heap'].includes(key)) return `${(value / 1024).toFixed(1)} KiB`;
    if (key.endsWith('_us')) return `${(value / 1000).toFixed(0)} ms`;
    if (key === 'http_ms') return `${value} ms`;
    if (key === 'rssi') return `${value} dBm`;
    if (key === 'reset') return RESETS[value] ?? `Code ${value}`;
    if (key === 'scan_rate') return `${(value / 10).toFixed(1)} per second`;
    return String(value);
}

const COMMAND_LABELS = { set_enabled: (c) => (c.enabled ? 'Switch ON' : 'Switch OFF'), restart: () => 'Restart' };
const STATUS_LABELS = { pending: 'Waiting for the scanner', delivered: 'Sent; waiting for confirmation', restarting: 'Restarting',
    applied: 'Confirmed by the scanner', failed: 'Failed', expired: 'Expired (scanner did not respond)', superseded: 'Replaced by a newer request' };

const dashboard = document.querySelector('[data-iot-dashboard]');
if (dashboard) {
    const node = (name) => dashboard.querySelector(`[data-monitor-${name}]`);
    const control = (name) => dashboard.querySelector(`[data-control-${name}]`);
    const values = dashboard.querySelectorAll('[data-monitor-value]');
    const restartModalElement = document.querySelector('[data-control-restart-modal]');
    const restartModal = restartModalElement ? Modal.getOrCreateInstance(restartModalElement) : null;
    const date = (value) => value ? new Date(value).toLocaleString() : 'Not reported';
    const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
    let page = 1;
    let lastPage = 1;
    let version = 0;
    let timer;
    let request;
    let busy = false;
    let latest;
    const eventNames = {
        boot: 'Device boot observed', online_observed: 'Authenticated communication resumed',
        offline_observed: 'Stale heartbeat observed', ready: 'Camera and decoder ready',
        camera_error: 'Camera unavailable', detection_error: 'QR decoder unavailable',
        scan_valid: 'Valid QR verification', scan_invalid: 'Invalid QR verification', transport: 'Transport report',
        control_requested: 'Control requested by an Admin', scanner_enabled: 'Scanner switched ON (confirmed)',
        scanner_disabled: 'Scanner switched OFF (confirmed)', restart_confirmed: 'Restart completed (new boot)',
    };
    const describeFeedback = (age) => {
        if (age === undefined || age === null) return null;
        if (age >= 4294967295) return 'No request received since the scanner booted';
        return age <= 5000 ? `Connected (last request ${age} ms before the report)` : `Not reachable for ${Math.round(age / 1000)} s`;
    };
    const describeScanner = (data) => {
        const { supported, desired, actual } = data.control;
        if (!supported) return desired.enabled ? 'On (firmware without remote control)' : 'Off requested; firmware without remote control';
        if (actual.revision === desired.revision && actual.enabled === desired.enabled) return actual.enabled ? 'On (confirmed)' : 'Off (confirmed)';
        return desired.enabled ? 'Switching on; waiting for the scanner' : 'Switching off; waiting for the scanner';
    };
    const renderControls = (data) => {
        const { supported, desired, commands } = data.control;
        const toggle = control('toggle');
        toggle.checked = desired.enabled;
        toggle.disabled = busy || data.device.revoked || !supported;
        control('restart').disabled = busy || data.device.revoked || !supported;
        control('support').textContent = supported ? 'Commands are delivered with the next authenticated heartbeat (normally within 5 s) and shown as confirmed only after the scanner reports them.'
            : 'This scanner\'s firmware does not report control support yet. Upload firmware 16.6 or newer to enable remote ON/OFF and restart.';
        const open = commands.find((c) => ['pending', 'delivered', 'restarting'].includes(c.status));
        control('state').textContent = open ? `${COMMAND_LABELS[open.type](open)}: ${STATUS_LABELS[open.status]}.`
            : data.device.online ? '' : 'The scanner is offline; a new request waits until it reconnects.';
        control('commands').replaceChildren(...commands.map((command) => {
            const item = document.createElement('li');
            item.textContent = `#${command.revision} ${COMMAND_LABELS[command.type]?.(command) ?? command.type} · ${STATUS_LABELS[command.status] ?? command.status}`
                + (command.latency_ms !== null ? ` · acknowledged in ${(command.latency_ms / 1000).toFixed(1)} s` : '')
                + ` · ${date(command.created_at)}`;
            item.dataset.status = command.status;
            return item;
        }));
        return Boolean(open);
    };
    const render = (data) => {
        latest = data;
        const device = data.device;
        const metrics = device.telemetry || {};
        const ready = readiness(data);
        node('readiness').textContent = ready.label;
        node('readiness').dataset.tone = ready.tone;
        node('readiness-note').textContent = ready.note;
        const display = {
            ...metrics, name: device.name, online: device.revoked ? 'Revoked' : device.online ? 'Online' : 'Offline',
            feedback: device.online ? describeFeedback(metrics.feedback_age_ms) : 'Unknown while the scanner is offline',
            scanner: describeScanner(data), boot: device.boot_id,
            heartbeat: device.heartbeat_at ? `${date(device.heartbeat_at)} (${Math.round((device.heartbeat_age_ms ?? 0) / 1000)} s ago)` : null,
            last_seen: date(device.last_seen_at),
            camera: !device.online ? 'Last report is stale' : { ready: 'Ready', camera_error: 'Camera error', detection_error: 'Decoder unavailable' }[device.camera_state] ?? null,
            preview: device.preview ? 'Authorized snapshot session' : 'Off',
            uptime: metrics.uptime_ms === undefined ? null : `${Math.floor(metrics.uptime_ms / 3600000)} h ${Math.floor(metrics.uptime_ms / 60000) % 60} min`,
            scan_rate: metrics.scan_rate_x10, scans: device.scans, valid: device.valid, invalid: device.invalid,
            latest: device.last_scan_at ? `${date(device.last_scan_at)} - ${device.last_result}` : null,
            handoff: device.handoff_age_ms === null ? null : `${device.handoff_age_ms} ms`,
        };
        if (!metrics.attempts) for (const key of ['avg_us', 'p95_us', 'half_us', 'full_us']) display[key] = null;
        values.forEach((element) => { element.textContent = format(element.dataset.monitorValue, display[element.dataset.monitorValue]); });
        const pending = renderControls(data);
        node('content').hidden = false;
        const telemetryAge = device.telemetry_at ? Date.now() - new Date(device.telemetry_at).getTime() : null;
        node('reported').textContent = !device.telemetry_at ? 'Telemetry not reported.'
            : `Latest telemetry ${date(device.telemetry_at)}${telemetryAge > 30000 ? ' (stale: values describe that report)' : ''}.`;
        node('counts').textContent = device.counts_since ? `Recorded since ${date(device.counts_since)}.` : 'No accepted scans recorded since monitoring was enabled.';
        node('status').textContent = `Updated ${date(data.observed_at)}. Refreshes every ${pending ? 2 : 5} seconds while this page is visible.`;
        node('events').replaceChildren();
        for (const event of data.events) {
            const item = document.createElement('li');
            const title = document.createElement('strong');
            title.textContent = eventNames[event.kind] || 'Device observation';
            const description = document.createElement('p');
            description.textContent = event.kind === 'transport' ? `${event.details.category} - HTTP ${event.details.http ?? 'not reported'}`
                : event.kind === 'control_requested' ? `${event.details.action} (revision ${event.details.revision})`
                : event.kind === 'restart_confirmed' ? `Reset reason: ${format('reset', event.details.reset)}` : '';
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
        return pending;
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
            const pending = render(data);
            // Faster refresh only while a command waits for the scanner's confirmation.
            timer = setTimeout(() => void load(current), pending ? 2000 : 5000);
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
        void load(version);
    };
    const send = async (path, body) => {
        const device = node('device').value;
        if (!device || busy) return;
        busy = true;
        if (latest) renderControls(latest);
        control('state').textContent = 'Sending the request…';
        try {
            const response = await fetch(`${dashboard.dataset.controlUrl}/${encodeURIComponent(device)}/${path}`, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify(body),
            });
            if (!response.ok) {
                throw new Error(response.status === 429 ? 'Too many control requests. Wait a minute, then try again.'
                    : response.status === 409 ? 'This scanner cannot accept commands (revoked).'
                    : [401, 403, 419].includes(response.status) ? 'Session unavailable. Sign in again.' : 'The request was not accepted. Nothing was changed.');
            }
        } catch (error) {
            control('state').textContent = error instanceof TypeError ? 'Cannot reach the server. Nothing was changed.' : error.message;
        } finally {
            busy = false;
            refresh();
        }
    };
    control('toggle').addEventListener('change', (event) => void send('scanner', { enabled: event.target.checked }));
    control('restart').addEventListener('click', () => restartModal?.show());
    document.querySelector('[data-control-restart-confirm]')?.addEventListener('click', () => {
        restartModal?.hide();
        void send('restart', { confirm: true });
    });
    node('device').addEventListener('change', () => { page = 1; node('content').hidden = true; refresh(); });
    node('previous').addEventListener('click', () => { if (page > 1) { page--; refresh(); } });
    node('next').addEventListener('click', () => { if (page < lastPage) { page++; refresh(); } });
    document.addEventListener('visibilitychange', refresh);
    window.addEventListener('pagehide', () => { clearTimeout(timer); request?.abort(); version++; });
}
