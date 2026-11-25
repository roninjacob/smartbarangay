import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = (await readFile(new URL('../../resources/js/qr-verification.js', import.meta.url), 'utf8'))
    .replace("import { Modal } from 'bootstrap';", '')
    .replace("import('@zxing/browser')", 'Promise.resolve(decoder)');
const flush = () => new Promise((resolve) => setImmediate(resolve));
const deferred = () => { let resolve; const promise = new Promise((done) => { resolve = done; }); return { promise, resolve }; };
const EVENT = '0b7d1e8a-6f1c-4c2a-9f3e-2d4b5a6c7d8e';
const authorized = (extra = {}) => ({ valid: true, outcome: 'check_in_authorized', message: 'Valid QR Ticket',
    reservation_url: 'http://localhost/admin/reservations/7', check_in_url: 'http://localhost/admin/reservations/7/check-in',
    reservation: { reference: '#7', resident: '<script>untrusted name</script>', service: 'Clearance', schedule: 'November 1, 2026',
        status: 'Ready for Pickup', checked_in_at: null }, ...extra });

function harness({ response = authorized(), camera, fetcher } = {}) {
    const nodes = new Map();
    const events = {};
    const dispatched = [];
    const heading = { textContent: '' };
    const node = (name) => {
        if (name === 'result-title') return heading;
        if (!nodes.has(name)) {
            const classes = new Set();
            nodes.set(name, { textContent: '', hidden: false, disabled: false, srcObject: null, value: '',
                removeAttribute(attribute) { delete this[attribute]; }, focus() { this.focused = true; },
                classList: { add: (...values) => values.forEach((v) => classes.add(v)), remove: (...values) => values.forEach((v) => classes.delete(v)),
                    toggle: (value, on) => { if (on) classes.add(value); else classes.delete(value); }, contains: (value) => classes.has(value) },
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
        addEventListener: (type, handler) => { events[type] = handler; }, dispatchEvent: (event) => { dispatched.push(event); } };
    const location = { href: 'http://localhost/admin/qr-verification', origin: 'http://localhost',
        assign(url) { this.href = url; navigations.push(url); } };
    const context = vm.createContext({ document, window: { location, isSecureContext: true, addEventListener: (type, handler) => { events[type] = handler; } },
        navigator: { mediaDevices: { getUserMedia: camera || (async () => stream) } }, AbortController, URL, Date,
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        setTimeout: (callback, delay) => { const id = ++timerId; timers.set(id, { callback, due: clock + delay }); return id; },
        clearTimeout: (id) => timers.delete(id),
        Modal: { getOrCreateInstance: () => ({
            show() { node('result-modal').visible = true; },
            hide() { if (!node('result-modal').visible) return; node('result-modal').visible = false; events['result-modal:hidden.bs.modal']?.(); },
        }) },
        decoder: { BrowserQRCodeReader: class { async decodeFromStream(value, video, handler) { video.srcObject = value; callback = handler; return controls; } } },
        fetch: async (url, options) => { requests.push({ url, options }); return fetcher ? fetcher(options) : { ok: true, json: async () => response }; } });
    vm.runInContext(source, context);
    return { node, heading, requests, document, stream, location, navigations, dispatched, stopped: () => stopped,
        acks: () => dispatched.filter((event) => event.type === 'qr:iot-ack').map((event) => event.detail.event_id),
        advance(ms) { clock += ms; for (const [id, timer] of [...timers]) { if (timer.due <= clock) { timers.delete(id); timer.callback(); } } },
        start: () => events['start:click'](), next: () => events['next:click'](), stop: () => events['stop:click'](),
        done: () => events['modal-done:click'](), open: () => events['modal-open:click'](),
        confirm: () => { const event = { prevented: false, preventDefault() { this.prevented = true; } }; events['modal-checkin:submit'](event); return event; },
        hidden: () => { document.hidden = true; events.visibilitychange(); },
        pagehide: () => events.pagehide(),
        mode(mode) { root.dataset.scannerMode = mode; events['qr:mode-change'](); },
        remote(result) { events['qr:iot-result']({ detail: result }); },
        scan: (text = 'opaque credential') => callback({ getText: () => text }, undefined, controls) };
}

test('IoT result opens a persistent check-in window bound to its scanner event', async () => {
    const h = harness();
    await h.start();
    h.mode('iot');
    assert.ok(h.stopped() > 0);
    h.remote(authorized({ event_id: EVENT }));
    assert.equal(h.node('result-modal').visible, true);
    assert.equal(h.node('modal-title').textContent, 'Check-in Authorized');
    assert.equal(h.node('modal-resident').textContent, '<script>untrusted name</script>');
    assert.equal(h.node('modal-reference').textContent, '#7');
    assert.equal(h.node('modal-checkin').hidden, false);
    assert.equal(h.node('modal-checkin').action, 'http://localhost/admin/reservations/7/check-in');
    assert.equal(h.node('modal-event').value, EVENT);
    assert.equal(h.node('modal-event').disabled, false);
    assert.equal(h.node('modal-icon-denied').hidden, true);
    h.advance(120000);
    assert.equal(h.node('result-modal').visible, true, 'No timer closes an active review.');
    assert.deepEqual(h.navigations, []);
    assert.deepEqual(h.acks(), []);
    h.done();
    assert.equal(h.node('result-modal').visible, false);
    assert.deepEqual(h.acks(), [EVENT]);
    assert.equal(h.requests.length, 0, 'Closing never posts a check-in.');
});

test('a second IoT result cannot replace the result under review', () => {
    const h = harness();
    h.mode('iot');
    h.remote(authorized({ event_id: EVENT }));
    h.remote(authorized({ event_id: '1b7d1e8a-6f1c-4c2a-9f3e-2d4b5a6c7d8e', reservation: { ...authorized().reservation, resident: 'Other' } }));
    assert.equal(h.node('modal-resident').textContent, '<script>untrusted name</script>');
    h.done();
    assert.deepEqual(h.acks(), [EVENT]);
});

test('confirming check-in submits once and is not treated as an acknowledgment', () => {
    const h = harness();
    h.mode('iot');
    h.remote(authorized({ event_id: EVENT }));
    assert.equal(h.confirm().prevented, false);
    assert.equal(h.node('modal-confirm').disabled, true);
    assert.equal(h.node('modal-done').disabled, true);
    assert.equal(h.confirm().prevented, true, 'A second submission is blocked.');
    h.pagehide();
    assert.equal(h.node('result-modal').visible, true);
    assert.deepEqual(h.acks(), []);
});

test('opening the reservation acknowledges the event before leaving', () => {
    const h = harness();
    h.mode('iot');
    h.remote(authorized({ event_id: EVENT, outcome: 'checked_in', check_in_url: null,
        reservation: { ...authorized().reservation, checked_in_at: 'November 1, 2026, 9:05 AM' } }));
    assert.equal(h.node('modal-title').textContent, 'Already Checked In');
    assert.equal(h.node('modal-checkin').hidden, true);
    assert.match(h.node('modal-note').textContent, /9:05 AM/);
    assert.equal(h.node('modal-open').href, 'http://localhost/admin/reservations/7');
    h.open();
    assert.deepEqual(JSON.parse(JSON.stringify(h.dispatched.filter((e) => e.type === 'qr:iot-ack').map((e) => e.detail))),
        [{ event_id: EVENT, keepalive: true }]);
});

test('laptop mode ignores IoT results and a mode switch closes and acknowledges the review', () => {
    const h = harness();
    h.mode('laptop');
    h.remote(authorized({ event_id: EVENT }));
    assert.ok(!h.node('result-modal').visible);
    h.mode('iot');
    h.remote(authorized({ event_id: EVENT }));
    h.mode('laptop');
    assert.equal(h.node('result-modal').visible, false);
    assert.deepEqual(h.acks(), [EVENT]);
    assert.deepEqual(h.navigations, []);
});

test('repeated frames submit once and the laptop result stays until Done', async () => {
    const h = harness();
    await h.start();
    h.scan(); h.scan();
    await flush();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].options.method, 'POST');
    assert.equal(h.requests[0].options.headers['X-CSRF-TOKEN'], 'csrf');
    assert.deepEqual(JSON.parse(h.requests[0].options.body), { payload: 'opaque credential' });
    assert.equal(h.node('resident').textContent, '<script>untrusted name</script>');
    assert.equal(h.heading.textContent, 'Check-in authorized');
    assert.equal(h.node('result-modal').visible, true);
    assert.equal(h.node('modal-event').disabled, true, 'Laptop scans have no scanner event.');
    assert.equal(h.node('video').hidden, true);
    assert.ok(h.stopped() > 0);
    assert.equal(h.node('next').hidden, true);
    h.advance(60000);
    assert.deepEqual(h.navigations, []);
    h.done();
    assert.equal(h.node('next').hidden, false);
    assert.deepEqual(h.acks(), []);
});

test('denied tickets open a denial window without details or actions', async () => {
    const h = harness({ response: { valid: false, outcome: 'denied', reason: 'unknown', message: 'Unknown or invalid QR ticket.' } });
    await h.start(); h.scan(); await flush();
    assert.equal(h.heading.textContent, 'Access denied');
    assert.equal(h.node('result-modal').visible, true);
    assert.equal(h.node('modal-title').textContent, 'Access Denied');
    assert.match(h.node('modal-lead').textContent, /^Unknown or invalid QR ticket\. No transaction was recorded/);
    assert.equal(h.node('modal-icon-success').hidden, true);
    assert.equal(h.node('modal-details').hidden, true);
    assert.equal(h.node('modal-checkin').hidden, true);
    assert.equal(h.node('modal-open').hidden, true);
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
    pending.resolve({ ok: true, json: async () => authorized({ reservation: { resident: 'Stale resident' } }) });
    await flush();
    assert.equal(h.heading.textContent, 'Verification result');
    assert.equal(h.node('resident').textContent, '');
    assert.equal(h.node('result-modal').visible, undefined);
});

test('server failure is unavailable, never a denial or personal details', async () => {
    const h = harness({ fetcher: async () => ({ ok: false, status: 503, json: async () => ({ message: 'private database details' }) }) });
    await h.start(); h.scan(); await flush();
    assert.equal(h.heading.textContent, 'Verification unavailable');
    assert.equal(h.node('details').hidden, true);
    assert.equal(h.node('result-modal').visible, undefined);
    assert.equal(h.node('status').textContent, 'Verification is unavailable. Please try again.');
    assert.equal(h.node('next').hidden, false);
});

test('completed QR cannot expose a navigation action even if a stale URL is supplied', async () => {
    const h = harness({ response: { valid: false, outcome: 'denied', reason: 'completed',
        message: 'This QR ticket has already completed its transaction.', reservation_url: 'http://localhost/admin/reservations/7' } });
    await h.start(); h.scan(); await flush();
    assert.equal(h.node('modal-title').textContent, 'Access Denied');
    assert.equal(h.node('modal-open').hidden, true);
    h.advance(2000);
    assert.deepEqual(h.navigations, []);
});

for (const destination of ['https://example.com/admin/reservations/7', 'javascript:alert(1)', '/admin/users/7', '/admin/reservations/7?user_id=9', undefined]) {
    test(`unexpected reservation destination is rejected: ${destination}`, async () => {
        const h = harness({ response: authorized({ reservation_url: destination }) });
        await h.start(); h.scan(); await flush();
        assert.equal(h.heading.textContent, 'Verification unavailable');
        assert.equal(h.node('result-modal').visible, undefined);
    });
}

for (const destination of ['http://localhost/admin/reservations/8/check-in', 'https://example.com/admin/reservations/7/check-in', null]) {
    test(`check-in action must target the verified reservation: ${destination}`, async () => {
        const h = harness({ response: authorized({ check_in_url: destination }) });
        await h.start(); h.scan(); await flush();
        assert.equal(h.heading.textContent, 'Verification unavailable');
        assert.equal(h.node('modal-checkin').action, undefined);
    });
}

test('unknown outcomes are not shown as verified', async () => {
    const h = harness({ response: authorized({ outcome: 'checked_out' }) });
    await h.start(); h.scan(); await flush();
    assert.equal(h.heading.textContent, 'Verification unavailable');
});
