import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = (await readFile(new URL('../../resources/js/qr-verification.js', import.meta.url), 'utf8'))
    .replace("import('@zxing/browser')", 'Promise.resolve(decoder)');
const flush = () => new Promise((resolve) => setImmediate(resolve));
const deferred = () => { let resolve; const promise = new Promise((done) => { resolve = done; }); return { promise, resolve }; };

function harness({ response = { valid: true, message: 'Valid QR Ticket', reservation: {
    resident: '<script>untrusted name</script>', service: 'Clearance', schedule: 'November 1, 2026', status: 'Approved',
} }, camera, fetcher } = {}) {
    const nodes = new Map();
    const events = {};
    const heading = { textContent: '' };
    const node = (name) => {
        if (!nodes.has(name)) {
            const classes = new Set();
            nodes.set(name, { textContent: '', hidden: false, disabled: false, srcObject: null,
                classList: { add: (...values) => values.forEach((v) => classes.add(v)), remove: (...values) => values.forEach((v) => classes.delete(v)) },
                querySelector: () => heading, addEventListener: (type, callback) => { events[`${name}:${type}`] = callback; } });
        }
        return nodes.get(name);
    };
    const requests = [];
    let callback;
    let stopped = 0;
    const stream = { getTracks: () => [{ stop: () => { stopped++; } }] };
    const controls = { stop: () => { stopped++; } };
    const root = { dataset: { verifyUrl: '/admin/qr-verification' }, querySelector: (selector) => node(selector.match(/data-qr-(.+)\]/)[1]) };
    const document = { hidden: false, querySelector: (selector) => selector.startsWith('meta') ? { content: 'csrf' } : root,
        addEventListener: (type, handler) => { events[type] = handler; } };
    const context = vm.createContext({ document, window: { isSecureContext: true, addEventListener: (type, handler) => { events[type] = handler; } },
        navigator: { mediaDevices: { getUserMedia: camera || (async () => stream) } }, AbortController, setTimeout, clearTimeout,
        decoder: { BrowserQRCodeReader: class { async decodeFromStream(value, video, handler) { video.srcObject = value; callback = handler; return controls; } } },
        fetch: async (url, options) => { requests.push({ url, options }); return fetcher ? fetcher(options) : { ok: true, json: async () => response }; } });
    vm.runInContext(source, context);
    return { node, heading, requests, document, stream, stopped: () => stopped,
        start: () => events['start:click'](), next: () => events['next:click'](), stop: () => events['stop:click'](),
        hidden: () => { document.hidden = true; events.visibilitychange(); },
        scan: (text = 'opaque credential') => callback({ getText: () => text }, undefined, controls) };
}

test('repeated frames submit once, release camera, render names as text, and require explicit next scan', async () => {
    const h = harness();
    await h.start();
    h.scan(); h.scan();
    await flush();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].options.method, 'POST');
    assert.equal(h.requests[0].options.headers['X-CSRF-TOKEN'], 'csrf');
    assert.deepEqual(JSON.parse(h.requests[0].options.body), { payload: 'opaque credential' });
    assert.equal(h.node('resident').textContent, '<script>untrusted name</script>');
    assert.equal(h.heading.textContent, 'Valid QR Ticket');
    assert.equal(h.node('video').hidden, true);
    assert.ok(h.stopped() > 0);
    assert.equal(h.node('start').hidden, true);
    assert.equal(h.node('next').hidden, false);
    await h.next(); h.scan(); await flush();
    assert.equal(h.requests.length, 2);
});

test('invalid results have no resident details', async () => {
    const h = harness({ response: { valid: false, message: 'Unknown or invalid QR ticket.' } });
    await h.start(); h.scan(); await flush();
    assert.equal(h.heading.textContent, 'Invalid QR Ticket');
    assert.equal(h.node('details').hidden, true);
    assert.equal(h.node('resident').textContent, '');
});

test('camera denial permits retry without any verification request', async () => {
    const h = harness({ camera: async () => { throw { name: 'NotAllowedError' }; } });
    await h.start();
    assert.match(h.node('status').textContent, /permission was denied/);
    assert.equal(h.node('start').disabled, false);
    assert.equal(h.requests.length, 0);
});

test('stop during permission request releases a late stream without activating scanner', async () => {
    const pending = deferred();
    const h = harness({ camera: () => pending.promise });
    const starting = h.start(); await flush(); h.stop(); pending.resolve(h.stream); await starting;
    assert.ok(h.stopped() > 0);
    assert.equal(h.node('video').hidden, true);
    assert.equal(h.requests.length, 0);
});

test('hidden page aborts verification and ignores late results', async () => {
    const pending = deferred();
    const h = harness({ fetcher: () => pending.promise });
    await h.start(); h.scan(); h.hidden();
    assert.equal(h.requests[0].options.signal.aborted, true);
    pending.resolve({ ok: true, json: async () => ({ valid: true, reservation: { resident: 'Stale resident' } }) });
    await flush();
    assert.equal(h.heading.textContent, 'Verification result');
    assert.equal(h.node('resident').textContent, '');
});

test('server failure never renders error body as validity or personal details', async () => {
    const h = harness({ fetcher: async () => ({ ok: false, status: 503, json: async () => ({ message: 'private database details' }) }) });
    await h.start(); h.scan(); await flush();
    assert.equal(h.heading.textContent, 'Verification unavailable');
    assert.equal(h.node('details').hidden, true);
    assert.equal(h.node('status').textContent, 'Verification is unavailable. Please try again.');
    assert.equal(h.node('next').hidden, false);
});
