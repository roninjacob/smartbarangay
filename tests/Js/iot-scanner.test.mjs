import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
const source = await readFile(new URL('../../resources/js/iot-scanner.js', import.meta.url), 'utf8');
const flush = () => new Promise((resolve) => setImmediate(resolve));
function harness({ poll = {}, frameStatus = 200 } = {}) {
    const nodes = new Map(), requests = [], timers = new Map(), events = {}, dispatched = [];
    let sequence = 0;
    const node = (name) => {
        if (!nodes.has(name)) nodes.set(name, { value: '', hidden: true, disabled: false, textContent: '',
            classList: { toggle() {} }, dataset: {}, addEventListener(event, callback) { this[event] = callback; },
            setAttribute() {}, removeAttribute(key) { delete this[key]; }, replaceChildren() {}, append() {} });
        return nodes.get(name);
    };
    const modes = ['laptop', 'iot'].map((mode) => { const n = node(mode); n.dataset.scannerMode = mode; return n; });
    const panel = { querySelector: (selector) => node(selector.match(/data-iot-([^\]]+)/)[1]) };
    const root = { dataset: { iotUrl: '/admin/iot-scanner' }, querySelectorAll: () => modes,
        querySelector: (selector) => selector.includes('iot-panel') ? panel : node('laptop-panel') };
    const document = { hidden: false, querySelector: (selector) => selector.includes('meta') ? { content: 'synthetic-csrf' } : root,
        addEventListener(name, callback) { events[name] = callback; }, dispatchEvent(event) { dispatched.push(event); } };
    vm.runInNewContext(source, { document, window: { addEventListener(name, callback) { events[name] = callback; } },
        AbortController, Date, URL: { createObjectURL: () => 'blob:private', revokeObjectURL() {} },
        Option: function (text, value) { this.text = text; this.value = value; },
        Event: class { constructor(type) { this.type = type; } }, CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        setTimeout(callback, delay) { const id = ++sequence; timers.set(id, { callback, delay }); return id; },
        clearTimeout(id) { timers.delete(id); }, fetch: async (url, options) => {
            requests.push({ url, options }); const path = url.split('/').pop();
            const data = path === 'devices' ? { devices: [{ id: 'synthetic-device', name: 'Test device', available: true }] }
                : path === 'poll' ? { state: 'connected', online: true, device: 'Test device', preview: true, ...poll } : { preview: true };
            return { ok: path === 'frame' ? frameStatus === 200 : true, status: path === 'frame' ? frameStatus : 200,
                headers: { get: () => 'image/jpeg' }, blob: async () => ({ size: 100 }), json: async () => data };
        } });
    return { node, modes, requests, timers, events, document, dispatched };
}
async function connect(h) {
    await h.modes[1].click(); h.node('device').value = 'synthetic-device'; h.node('device').change();
    await h.node('connect').click(); await flush();
}
test('provisioned scanner connects without a pairing QR and polls automatically', async () => {
    const h = harness(); await connect(h);
    assert.ok(h.requests.some((r) => r.url.endsWith('/connect')));
    assert.ok(h.requests.some((r) => r.url.endsWith('/poll')));
    assert.ok(!h.requests.some((r) => /challenge|check-in|complete|payment/.test(r.url)));
    assert.match(h.node('status').textContent, /Online/);
    assert.ok([...h.timers.values()].some((t) => t.delay === 1000));
});
test('preview starts explicitly, uses protected JPEG endpoint, and hides on stop', async () => {
    const h = harness(); await connect(h);
    assert.ok(!h.requests.some((r) => r.url.endsWith('/frame')));
    await h.node('preview-start').click(); await flush();
    assert.equal(h.node('preview-image').src, 'blob:private');
    const frame = h.requests.find((r) => r.url.endsWith('/frame'));
    assert.equal(frame.options.credentials, 'same-origin'); assert.equal(frame.options.cache, 'no-store');
    await h.node('preview-stop').click();
    assert.equal(h.node('preview-image').hidden, true);
    assert.ok(h.requests.some((r) => r.url.endsWith('/preview') && JSON.parse(r.options.body).enabled === false));
});
test('missing/expired frame shows waiting state without displaying a stale image', async () => {
    const h = harness({ frameStatus: 404 }); await connect(h); await h.node('preview-start').click(); await flush();
    assert.equal(h.node('preview-image').hidden, true); assert.match(h.node('preview-note').textContent, /Waiting/);
});
test('hidden page disconnects and stops the preview', async () => {
    const h = harness(); await connect(h); await h.node('preview-start').click(); await flush();
    h.document.hidden = true; h.events.visibilitychange(); await flush();
    assert.equal(h.node('preview-image').hidden, true);
    assert.ok(h.requests.some((r) => r.url.endsWith('/disconnect') && r.options.keepalive));
});
test('valid result hands off to existing verification handler without automatic check-in', async () => {
    const h = harness({ poll: { result: { valid: true, reservation_url: '/admin/reservations/7' } } });
    await connect(h); await flush();
    assert.equal(h.dispatched.find((e) => e.type === 'qr:iot-result').detail.valid, true);
    assert.ok(!h.requests.some((r) => /check-in|complete|payment/.test(r.url)));
    assert.ok(h.requests.some((r) => r.url.endsWith('/preview') && JSON.parse(r.options.body).enabled === false));
});
