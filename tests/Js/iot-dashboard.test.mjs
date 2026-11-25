import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = (await readFile(new URL('../../resources/js/iot-dashboard.js', import.meta.url), 'utf8'))
    .replace("import { Modal } from 'bootstrap';", '')
    .replaceAll('export function', 'function')
    + '\nglobalThis.__readiness = readiness; globalThis.__format = format;';
const flush = () => new Promise((resolve) => setImmediate(resolve));
const report = (overrides = {}) => ({
    device: { name: '<script>synthetic</script>', online: true, revoked: false, camera_state: 'ready',
        telemetry: { attempts: 0, avg_us: 0, p95_us: 0, heap: 2048, feedback_age_ms: 120, control_revision: 0, scanner_enabled: 1 },
        counts_since: null, heartbeat_at: null, heartbeat_age_ms: 900, last_seen_at: null, handoff_age_ms: null, ...overrides.device },
    control: { supported: true, desired: { revision: 0, enabled: true }, actual: { revision: 0, enabled: true }, commands: [], ...overrides.control },
    events: [{ kind: 'transport', details: { category: 'dns_timeout', http: -1 }, at: '2026-11-10T04:00:00Z' }],
    pagination: { page: 1, last_page: 2 }, observed_at: '2026-11-10T04:00:00Z',
});

function harness(data = report()) {
    const nodes = new Map(), events = {}, timers = new Map(), requests = [];
    let sequence = 0;
    const node = (name) => {
        if (!nodes.has(name)) nodes.set(name, { value: '', hidden: true, disabled: false, checked: false, children: [], textContent: '',
            dataset: { monitorValue: name }, addEventListener(event, callback) { this[event] = callback; },
            replaceChildren(...items) { this.children = items; }, append(...items) { this.children.push(...items); } });
        return nodes.get(name);
    };
    const values = ['name', 'online', 'avg_us', 'p95_us', 'heap', 'attempts', 'feedback', 'scanner'].map(node);
    const root = { dataset: { url: '/admin/iot-dashboard/data', controlUrl: '/admin/iot-dashboard/devices' }, querySelectorAll: () => values,
        querySelector: (selector) => node(selector.match(/data-(?:monitor|control)-([^\]]+)/)[1]) };
    const document = { hidden: false,
        querySelector: (selector) => selector.includes('csrf') ? { content: 'synthetic-csrf' } : selector.includes('restart-confirm') ? node('restart-confirm')
            : selector.includes('restart-modal') ? null : root,
        createElement: () => ({ textContent: '', dataset: {}, children: [], append(...children) { this.children.push(...children); } }),
        addEventListener(name, callback) { events[name] = callback; } };
    const context = { document, window: { addEventListener(name, callback) { events[name] = callback; } },
        AbortController, Date, URLSearchParams, Math, encodeURIComponent, TypeError, Error,
        setTimeout(callback, delay) { const id = ++sequence; timers.set(id, { callback, delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        fetch: async (url, options) => { requests.push({ url, options }); return { ok: true, status: 200, json: async () => data }; } };
    vm.runInNewContext(source, context);
    return { node, document, events, timers, requests, data, readiness: context.__readiness, format: context.__format };
}

test('monitoring uses authenticated bounded polling and renders plain text with honest unknown metrics', async () => {
    const h = harness(); h.node('device').value = 'synthetic-device'; h.node('device').change(); await flush();
    assert.match(h.requests[0].url, /device=synthetic-device&page=1/);
    assert.equal(h.requests[0].options.credentials, 'same-origin');
    assert.equal(h.requests[0].options.cache, 'no-store');
    assert.equal(h.node('name').textContent, '<script>synthetic</script>');
    assert.equal(h.node('avg_us').textContent, 'Not reported');
    assert.equal(h.node('heap').textContent, '2.0 KiB');
    assert.equal(h.node('readiness').textContent, 'Ready');
    assert.ok([...h.timers.values()].some((timer) => timer.delay === 5000));
    h.node('next').click(); await flush();
    assert.match(h.requests.at(-1).url, /page=2/);
    assert.ok(!h.requests.some((request) => /preview|scans|check-in|complete/.test(request.url)));
});

test('hidden monitoring stops requests and aborts the active controller', async () => {
    const h = harness(); h.node('device').value = 'synthetic-device'; h.node('device').change(); await flush();
    h.document.hidden = true; h.events.visibilitychange(); await flush();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].options.signal.aborted, true);
    assert.equal(h.timers.size, 0);
});

test('readiness distinguishes offline, restarting, switched off, camera faults and peer loss', () => {
    const { readiness } = harness();
    assert.equal(readiness(report()).label, 'Ready');
    assert.equal(readiness(report({ device: { online: false, heartbeat_age_ms: 42000 } })).label, 'Offline');
    assert.equal(readiness(report({ control: { actual: { revision: 2, enabled: false } } })).label, 'Scanner off');
    assert.equal(readiness(report({ control: { commands: [{ type: 'restart', status: 'restarting' }] } })).label, 'Restarting');
    assert.equal(readiness(report({ device: { camera_state: 'camera_error' } })).label, 'Camera unavailable');
    assert.equal(readiness(report({ device: { telemetry: { feedback_age_ms: 9000 } } })).label, 'Feedback device unreachable');
    assert.equal(readiness(report({ device: { revoked: true } })).label, 'Revoked');
});

test('a switch request is pending until the scanner confirms it', async () => {
    const h = harness(report({ control: { desired: { revision: 1, enabled: false }, actual: { revision: 0, enabled: true },
        commands: [{ id: 'c1', revision: 1, type: 'set_enabled', enabled: false, status: 'delivered', latency_ms: null, created_at: '2026-11-10T04:00:00Z' }] } }));
    h.node('device').value = 'synthetic-device'; h.node('device').change(); await flush();
    assert.equal(h.node('scanner').textContent, 'Switching off; waiting for the scanner');
    assert.equal(h.node('readiness').textContent, 'Ready', 'Not shown as off before confirmation.');
    assert.match(h.node('state').textContent, /Switch OFF: Sent; waiting for confirmation/);
    assert.ok([...h.timers.values()].some((timer) => timer.delay === 2000), 'Faster refresh while pending.');
    h.node('toggle').checked = true;
    await h.node('toggle').change({ target: { checked: true } }); await flush();
    const post = h.requests.find((r) => r.options.method === 'POST');
    assert.equal(post.url, '/admin/iot-dashboard/devices/synthetic-device/scanner');
    assert.deepEqual(JSON.parse(post.options.body), { enabled: true });
    assert.equal(post.options.headers['X-CSRF-TOKEN'], 'synthetic-csrf');
});

test('firmware without control support cannot be switched from the page', async () => {
    const h = harness(report({ control: { supported: false, actual: null } }));
    h.node('device').value = 'synthetic-device'; h.node('device').change(); await flush();
    assert.equal(h.node('toggle').disabled, true);
    assert.equal(h.node('restart').disabled, true);
    assert.match(h.node('support').textContent, /firmware 16\.6/);
});

test('metric formatting never invents values', () => {
    const { format } = harness();
    assert.equal(format('min_heap', undefined), 'Not reported');
    assert.equal(format('reset', 9), 'Brownout (power)');
    assert.equal(format('scan_rate', 18), '1.8 per second');
    assert.equal(format('half_us', 435000), '435 ms');
});
