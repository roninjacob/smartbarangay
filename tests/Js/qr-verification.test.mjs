import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = (await readFile(new URL('../../resources/js/qr-verification.js', import.meta.url), 'utf8'))
    .replace("import { Modal } from 'bootstrap';", '')
    .replace("import('@zxing/browser')", 'Promise.resolve(decoder)');
const flush = () => new Promise((resolve) => setImmediate(resolve));
const deferred = () => { let resolve; const promise = new Promise((done) => { resolve = done; }); return { promise, resolve }; };

function harness({ response = { valid: true, message: 'Valid QR Ticket', reservation_url: 'http://localhost/admin/reservations/7', reservation: {
    resident: '<script>untrusted name</script>', service: 'Clearance', schedule: 'November 1, 2026', status: 'Approved',
} }, camera, fetcher } = {}) {
    const nodes = new Map();
    const events = {};
    const heading = { textContent: '' };
    const node = (name) => {
        if (name === 'result-title') return heading;
        if (!nodes.has(name)) {
            const classes = new Set();
            nodes.set(name, { textContent: '', hidden: false, disabled: false, srcObject: null,
                removeAttribute(attribute) { delete this[attribute]; }, focus() { this.focused = true; },
                classList: { add: (...values) => values.forEach((v) => classes.add(v)), remove: (...values) => values.forEach((v) => classes.delete(v)) },
                querySelector: () => heading, addEventListener: (type, callback) => { events[`${name}:${type}`] = callback; } });
        }
        return nodes.get(name);
    };
    const requests = [];
    const navigations = [];
    const timers = new Map();
    let clock = 0;
    let timerId = 0;
    let callback;
    let stopped = 0;
    const stream = { getTracks: () => [{ stop: () => { stopped++; } }] };
    const controls = { stop: () => { stopped++; } };
    const root = { dataset: { verifyUrl: '/admin/qr-verification' }, querySelector: (selector) => node(selector.match(/data-qr-(.+)\]/)[1]) };
    const document = { hidden: false, querySelector: (selector) => selector.startsWith('meta') ? { content: 'csrf' } : root,
        addEventListener: (type, handler) => { events[type] = handler; } };
    const location = { href: 'http://localhost/admin/qr-verification', origin: 'http://localhost',
        assign(url) { this.href = url; navigations.push(url); } };
    const context = vm.createContext({ document, window: { location, isSecureContext: true, addEventListener: (type, handler) => { events[type] = handler; } },
        navigator: { mediaDevices: { getUserMedia: camera || (async () => stream) } }, AbortController, URL,
        setTimeout: (callback, delay) => { const id = ++timerId; timers.set(id, { callback, due: clock + delay }); return id; },
        clearTimeout: (id) => timers.delete(id),
        Modal: { getOrCreateInstance: () => ({
            show() { node('success-modal').visible = true; events['success-modal:shown.bs.modal'](); },
            hide() { node('success-modal').visible = false; },
        }) },
        decoder: { BrowserQRCodeReader: class { async decodeFromStream(value, video, handler) { video.srcObject = value; callback = handler; return controls; } } },
        fetch: async (url, options) => { requests.push({ url, options }); return fetcher ? fetcher(options) : { ok: true, json: async () => response }; } });
    vm.runInContext(source, context);
    return { node, heading, requests, document, stream, location, navigations, stopped: () => stopped,
        advance(ms) { clock += ms; for (const [id, timer] of [...timers]) { if (timer.due <= clock) { timers.delete(id); timer.callback(); } } },
        start: () => events['start:click'](), next: () => events['next:click'](), stop: () => events['stop:click'](),
        hidden: () => { document.hidden = true; events.visibilitychange(); },
        scan: (text = 'opaque credential') => callback({ getText: () => text }, undefined, controls) };
}

test('repeated frames submit once and success modal precedes automatic navigation by 1500ms', async () => {
    const h = harness();
    await h.start();
    h.scan(); h.scan();
    await flush();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].options.method, 'POST');
    assert.equal(h.requests[0].options.headers['X-CSRF-TOKEN'], 'csrf');
    assert.deepEqual(JSON.parse(h.requests[0].options.body), { payload: 'opaque credential' });
    assert.equal(h.node('resident').textContent, '<script>untrusted name</script>');
    assert.equal(h.heading.textContent, 'QR Verified Successfully');
    assert.equal(h.node('success-modal').visible, true);
    assert.equal(h.node('modal-resident').textContent, '<script>untrusted name</script>');
    assert.equal(h.location.href, 'http://localhost/admin/qr-verification');
    assert.equal(h.node('video').hidden, true);
    assert.ok(h.stopped() > 0);
    assert.equal(h.node('start').hidden, true);
    assert.equal(h.node('next').hidden, true);
    h.advance(1499);
    assert.equal(h.navigations.length, 0);
    h.advance(1);
    assert.deepEqual(h.navigations, ['http://localhost/admin/reservations/7']);
    h.advance(5000);
    assert.equal(h.navigations.length, 1);
});

test('invalid results have no resident details', async () => {
    const h = harness({ response: { valid: false, message: 'Unknown or invalid QR ticket.' } });
    await h.start(); h.scan(); await flush();
    assert.equal(h.heading.textContent, 'Invalid QR Ticket');
    assert.equal(h.node('details').hidden, true);
    assert.equal(h.node('resident').textContent, '');
    assert.equal(h.node('success-modal').visible, false);
    h.advance(2000);
    assert.equal(h.navigations.length, 0);
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

test('completed QR cannot expose a navigation action even if a stale URL is supplied', async () => {
    const h = harness({ response: { valid: false, message: 'This QR ticket has already completed its transaction.', reservation_url: 'http://localhost/admin/reservations/7' } });
    await h.start(); h.scan(); await flush();
    assert.equal(h.heading.textContent, 'Invalid QR Ticket');
    assert.equal(h.node('success-modal').visible, false);
    h.advance(2000);
    assert.equal(h.location.href, 'http://localhost/admin/qr-verification');
});

for (const destination of ['https://example.com/admin/reservations/7', 'javascript:alert(1)', '/admin/users/7', '/admin/reservations/7?user_id=9', undefined]) {
    test(`unexpected success destination is rejected: ${destination}`, async () => {
        const h = harness({ response: { valid: true, message: 'Valid QR Ticket', reservation_url: destination, reservation: {
            resident: 'Resident', service: 'Clearance', schedule: 'November 1', status: 'Approved',
        } } });
        await h.start(); h.scan(); await flush();
        assert.equal(h.heading.textContent, 'Verification unavailable');
        assert.equal(h.node('success-modal').visible, false);
        h.advance(2000);
        assert.equal(h.location.href, 'http://localhost/admin/qr-verification');
    });
}

test('hiding the page cancels pending success navigation and clears popup details', async () => {
    const h = harness();
    await h.start(); h.scan(); await flush();
    h.hidden(); h.advance(2000);
    assert.equal(h.navigations.length, 0);
    assert.equal(h.node('success-modal').visible, false);
    assert.equal(h.node('modal-resident').textContent, '');
});

test('new scan cancels a stale redirect timer', async () => {
    const h = harness();
    await h.start(); h.scan(); await flush();
    h.advance(1000);
    await h.next();
    h.advance(500);
    assert.equal(h.navigations.length, 0);
    h.scan(); await flush();
    h.advance(1500);
    assert.equal(h.requests.length, 2);
    assert.deepEqual(h.navigations, ['http://localhost/admin/reservations/7']);
});
