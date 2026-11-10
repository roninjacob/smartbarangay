import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
const source = await readFile(new URL('../../resources/js/iot-scanner.js', import.meta.url), 'utf8');
const flush = () => new Promise((resolve) => setImmediate(resolve));
function harness({ poll = {}, frameStatus = 200, frameAge = '0' } = {}) {
    const nodes = new Map(), requests = [], timers = new Map(), events = {}, dispatched = [];
    let sequence = 0;
    const node = (name) => {
        if (!nodes.has(name)) nodes.set(name, { value: '', hidden: true, disabled: false, textContent: '',
            classList: { toggle() {} }, dataset: {}, addEventListener(event, callback) { this[event] = callback; },
            setAttribute() {}, removeAttribute(key) { delete this[key]; }, replaceChildren() {}, append() {}, async decode() {} });
        return nodes.get(name);
    };
    const modes = ['laptop', 'iot'].map((mode) => { const n = node(mode); n.dataset.scannerMode = mode; return n; });
    const panel = { querySelector: (selector) => node(selector.match(/data-iot-([^\]]+)/)[1]) };
    const root = { dataset: { iotUrl: '/admin/iot-scanner' }, querySelectorAll: () => modes,
        querySelector: (selector) => selector.includes('iot-panel') ? panel : node('laptop-panel') };
    const document = { hidden: false, querySelector: (selector) => selector.includes('meta') ? { content: 'synthetic-csrf' } : root,
        addEventListener(name, callback) { events[name] = callback; }, dispatchEvent(event) { dispatched.push(event); } };
    vm.runInNewContext(source, { document, window: { addEventListener(name, callback) { events[name] = callback; } },
        AbortController, Date, performance: { now: () => 0 }, URL: { createObjectURL: () => 'blob:private', revokeObjectURL() {} },
        Option: function (text, value) { this.text = text; this.value = value; },
        Event: class { constructor(type) { this.type = type; } }, CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        setTimeout(callback, delay) { const id = ++sequence; timers.set(id, { callback, delay }); return id; },
        clearTimeout(id) { timers.delete(id); }, fetch: async (url, options) => {
            requests.push({ url, options }); const path = url.split('/').pop();
            const data = path === 'devices' ? { devices: [{ id: 'synthetic-device', name: 'Test device', available: true }] }
                : path === 'poll' ? { state: 'connected', online: true, device: 'Test device', preview: true, ...poll } : { preview: true };
            return { ok: path === 'frame' ? frameStatus === 200 : true, status: path === 'frame' ? frameStatus : 200,
                headers: { get: (name) => name === 'X-SB-Frame-Age-Ms' ? frameAge : 'image/jpeg' }, blob: async () => ({ size: 100 }), json: async () => data };
        } });
    return { node, modes, requests, timers, events, document, dispatched };
}
async function connect(h) {
    await h.modes[1].click();
    h.node('device').value = 'synthetic-device';
    h.node('device').change();
    await flush();
    await flush();
}
test('selecting a scanner automatically connects, enables preview and starts polling', async () => {
    const h = harness(); await connect(h);
    const request = h.requests.find((r) => r.url.endsWith('/connect'));
    assert.equal(JSON.parse(request.options.body).preview, true);
    assert.ok(h.requests.some((r) => r.url.endsWith('/poll')));
    assert.equal(h.node('preview-image').src, 'blob:private');
    assert.equal(h.node('live').hidden, false);
    assert.ok(!h.requests.some((r) => /challenge|check-in|complete|payment/.test(r.url)));
});
test('offline scanners show no live or stale camera image', async () => {
    const h = harness({ poll: { online: false } }); await connect(h);
    assert.equal(h.node('preview-image').hidden, true);
    assert.equal(h.node('live').hidden, true);
    assert.match(h.node('status').textContent, /Offline/);
    assert.ok(!h.requests.some((r) => r.url.endsWith('/frame')));
});
test('expired frames never become live', async () => {
    const h = harness({ frameAge: '5000' }); await connect(h);
    assert.equal(h.node('preview-image').hidden, true);
    assert.equal(h.node('live').hidden, true);
});
test('live image has an independent expiry timer when polling stalls', async () => {
    const h = harness(); await connect(h);
    const expiry = [...h.timers.values()].find((t) => t.delay === 5000);
    assert.ok(expiry); expiry.callback();
    assert.equal(h.node('preview-image').hidden, true);
    assert.equal(h.node('live').hidden, true);
});
test('missing frame shows waiting state', async () => {
    const h = harness({ frameStatus: 404 }); await connect(h);
    assert.equal(h.node('preview-image').hidden, true);
    assert.match(h.node('preview-note').textContent, /Waiting/);
});
test('hidden page disconnects and removes preview immediately', async () => {
    const h = harness(); await connect(h);
    h.document.hidden = true; h.events.visibilitychange(); await flush();
    assert.equal(h.node('preview-image').hidden, true);
    assert.ok(h.requests.some((r) => r.url.endsWith('/disconnect') && r.options.keepalive));
});
test('changing device serializes disconnect before reconnect with automatic preview', async () => {
    const h = harness(); await connect(h);
    h.node('device').value = 'second-device'; h.node('device').change(); await flush(); await flush();
    const connections = h.requests.filter((r) => r.url.endsWith('/connect'));
    assert.equal(JSON.parse(connections.at(-1).options.body).device_id, 'second-device');
    const last = h.requests.indexOf(connections.at(-1));
    assert.ok(h.requests.slice(0, last).some((r) => r.url.endsWith('/disconnect')));
});
test('success hands off through existing verification UI and keeps manual check-in separate', async () => {
    const h = harness({ poll: { result: { valid: true, reservation_url: '/admin/reservations/7' } } }); await connect(h);
    assert.equal(h.dispatched.find((e) => e.type === 'qr:iot-result').detail.valid, true);
    assert.ok(!h.requests.some((r) => /check-in|complete|payment/.test(r.url)));
    assert.ok(h.requests.some((r) => r.url.endsWith('/preview') && JSON.parse(r.options.body).enabled === false));
});
