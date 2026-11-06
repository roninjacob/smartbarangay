// Host-only regression checks. Run: node test/qr03-web.test.cjs
// These mocks do not establish physical camera/JPEG or QR performance.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert/strict');
const path = require('path');
const source = fs.readFileSync(path.join(__dirname, '../src/main.cpp'), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];
new Function(script);
const ids = ['video','status','start','stop','direct','qr','preview','snapshot','qrResult','qrDetails','snapshotStatus','snapshotFreshness','scanState','qrSuccess'];
const nodes = Object.fromEntries(ids.map(id => [id, {
    writes: 0, value: '', hidden: true,
    set textContent(value) { this.value = value; this.writes++; },
    get textContent() { return this.value; },
    removeAttribute(name) { delete this[name]; }
}]));
const timers = new Map(), events = {}, revoked = [];
const intervals = new Map();
let clock = 0, sequence = 1, frameFetches = 0, downloadMs = 0, decodeMs = 0;
let timerId = 0, imageId = 0, mode = 'preview', payload = '', frameStatus = 200;
let mime = 'image/jpeg', badImage = false, deferredFrame = null, releaseFrame = null;
let deferredStatus = null, releaseStatus = null;
let fetches = 0;
const safe = process.argv.includes('--safe');
let eventId = 'boot-a:1:1', eventState = 'fresh';
let statusFailure = false;
const context = {
    document: {getElementById: id => nodes[id]}, location: {hostname: '192.0.2.1'},
    Date, AbortController, Set, performance: {now: () => clock},
    URL: {createObjectURL() { return 'blob:test-' + (++imageId); }, revokeObjectURL(url) { revoked.push(url); }},
    Image: class { async decode() { clock += decodeMs; if (badImage) throw new Error('Invalid image'); } },
    setTimeout(fn, ms) { const id = ++timerId; timers.set(id, {fn, ms}); return id; },
    clearTimeout(id) { timers.delete(id); },
    setInterval(fn) { const id = ++timerId; intervals.set(id, fn); return id; },
    clearInterval(id) { intervals.delete(id); },
    window: {addEventListener: (name, fn) => events[name] = fn},
    async fetch(url) {
        fetches++;
        if (url.startsWith('/qr/status') && statusFailure) throw new Error('Synthetic network outage');
        if (url.startsWith('/qr/status') && deferredStatus) {
            const pending = deferredStatus; deferredStatus = null; await pending;
        }
        if (url.startsWith('/qr/frame') && deferredFrame) {
            const pending = deferredFrame; deferredFrame = null; await pending;
        }
        const frame = url.startsWith('/qr/frame');
        if (frame) frameFetches++;
        const status = frame ? frameStatus : 200;
        const text = url.startsWith('/mode') ? 'Switching to requested mode.' :
            JSON.stringify({mode, payload, event_id: eventId, event_state: eventState, decoded_bytes: payload.length,
                format_match: false, camera_diagnostics: !safe, events: 1, event_age_ms: 50,
                snapshot_sequence: sequence, scanning: true, text: 'Non-sensitive counters'});
        return {ok: status === 200, status, headers: {get(name) {
            if (name === 'X-Snapshot-Sequence') return String(sequence);
            if (name === 'X-Snapshot-Age-Ms') return '100';
            return mime;
        }}, async blob() { if (frame) clock += downloadMs; return {size: 128, async text() { return text; }}; }};
    }
};
vm.runInNewContext(script, context);
const flush = () => new Promise(resolve => setImmediate(resolve));
function tick(ms) {
    const entry = [...timers.entries()].find(([,timer]) => timer.ms === ms);
    assert(entry, 'Expected timer ' + ms);
    timers.delete(entry[0]); clock += ms; entry[1].fn();
}
async function poll() {
    clock += 2000; // Advance enough for the optional image refresh throttle.
    const entry = [...timers.entries()].find(([, timer]) => timer.fn === context.poll);
    assert(entry, 'Expected exactly one scheduled status poll');
    tick(entry[1].ms); await flush();
}

(async () => {
    if (safe) {
        await flush();
        assert.equal(nodes.video.src, undefined);
        assert(![...timers.values()].some(timer => timer.ms === 250), 'Default must never schedule a stream');
        mode = 'qr'; payload = 'SBQ1:550e8400-e29b-41d4-a716-446655440000';
        await poll();
        assert.equal(frameFetches, 0);
        assert.equal(nodes.snapshot.src, undefined);
        assert.equal(nodes.direct.hidden, true);
        assert.match(nodes.qrResult.textContent, /NOT server verified/);
        assert(!Object.values(nodes).some(node => node.textContent.includes(payload)), 'Even a malicious legacy payload response is ignored');
        const count = nodes.qrSuccess.writes;
        await poll(); assert.equal(nodes.qrSuccess.writes, count, 'Polling the same event is not a new scan');
        eventState = 'expired'; await poll();
        assert.match(nodes.qrResult.textContent, /No fresh scan/);
        assert.equal(nodes.qrSuccess.textContent, '');
        mode = 'preview'; eventState = 'fresh'; await poll();
        assert.match(nodes.qrResult.textContent, /No fresh scan/, 'Preview cannot promote retained metadata');
        mode = 'qr'; eventId = 'boot-a:2:2'; await poll();
        assert.match(nodes.qrSuccess.textContent, /boot-a:2:2/);
        eventId = 'boot-b:1:1'; await poll();
        assert.match(nodes.qrSuccess.textContent, /boot-b:1:1/);
        clock += 6000; for (const fn of intervals.values()) fn();
        assert.match(nodes.qrResult.textContent, /No fresh scan/, 'A stopped network cannot leave success visible forever');
        await poll(); assert.match(nodes.qrResult.textContent, /No fresh scan/, 'Repeated cached age must not renew an expired event');
        eventId = 'boot-b:1:2'; await poll();
        assert.match(nodes.qrSuccess.textContent, /boot-b:1:2/);
        statusFailure = true; await poll();
        assert.equal(nodes.qrSuccess.textContent, '');
        assert.match(nodes.qrResult.textContent, /No fresh scan/);
        statusFailure = false; await poll();
        assert.match(nodes.qrResult.textContent, /No fresh scan/, 'Recovery must not replay a pre-outage event');
        assert.equal(frameFetches, 0);
        const before = fetches; events.pagehide(); await poll();
        assert.equal(fetches, before);
        console.log('PASS: safe-default UI: no camera requests, no payload display, expired/mode/reboot identity and repeated-poll handling.');
        return;
    }
    await flush(); tick(250);
    assert.match(nodes.video.src, /:81\/stream/);
    await nodes.qr.onclick(); mode = 'switching'; await poll();
    assert.equal(nodes.video.src, undefined);
    mode = 'qr'; payload = '<script>SMARTBARANGAY-TEST-001</script>'; await poll();
    assert.match(nodes.status.textContent, /QR mode active/);
    assert.match(nodes.qrResult.textContent, /Other QR format; NOT server verified/);
    assert(!nodes.qrResult.textContent.includes(payload));
    assert.match(nodes.snapshot.src, /^blob:/);
    const first = nodes.snapshot.src, writes = nodes.qrResult.writes;
    assert.match(nodes.scanState.textContent, /Scanning/);
    assert.match(nodes.qrSuccess.textContent, /decoded successfully/);
    assert.match(nodes.qrSuccess.textContent, /event age 50ms/);
    const successWrites = nodes.qrSuccess.writes, beforeRepeat = frameFetches;
    await poll(); assert.equal(frameFetches, beforeRepeat, 'Same sequence must not re-download old image');
    assert.equal(nodes.qrSuccess.writes, successWrites, 'Same QR must not repeat success announcement');
    clock += 6000; for (const fn of intervals.values()) fn();
    assert.match(nodes.snapshotFreshness.textContent, /STALE/);

    sequence++; frameStatus = 503; await poll();
    assert.equal(nodes.snapshot.src, first);
    assert.match(nodes.snapshotStatus.textContent, /HTTP 503/);
    assert.match(nodes.qrResult.textContent, /No fresh scan/, 'Old metadata must expire rather than remain a successful scan');

    frameStatus = 200; mime = 'text/html'; await poll();
    assert.equal(nodes.snapshot.src, first);
    mime = 'image/jpeg'; badImage = true; await poll();
    assert.equal(nodes.snapshot.src, first);
    assert(revoked.includes('blob:test-2'), 'Undecodable candidate must be revoked');
    badImage = false; await poll();
    assert.notEqual(nodes.snapshot.src, first);
    assert(revoked.includes(first), 'Replaced image URL must be revoked');

    sequence++; deferredFrame = new Promise(resolve => { releaseFrame = resolve; });
    await poll();
    const pendingImageCount = frameFetches;
    payload = 'SECOND-DUMMY-QR'; eventId = 'boot-a:1:2'; await poll();
    assert.match(nodes.qrSuccess.textContent, /boot-a:1:2/, 'New event must display while an older image is pending');
    assert.equal(frameFetches, pendingImageCount, 'No second image request may queue behind the pending image');
    await nodes.preview.onclick(); releaseFrame(); await flush();
    assert(!nodes.snapshot.src.endsWith('test-4'), 'Stale in-flight snapshot must not replace current image');
    mode = 'preview'; await poll(); tick(250);
    assert.match(nodes.video.src, /:81\/stream/);
    assert.equal(nodes.snapshot.src, undefined);
    assert.match(nodes.status.textContent, /Preview mode active/);

    for (let i = 0; i < 3; i++) {
        sequence++; await nodes.qr.onclick(); mode = 'qr'; await poll();
        assert.match(nodes.snapshot.src, /^blob:/);
        await nodes.preview.onclick(); mode = 'preview'; await poll(); tick(250);
        assert.match(nodes.video.src, /:81\/stream/);
    }
    deferredStatus = new Promise(resolve => { releaseStatus = resolve; });
    await poll();
    const goodPayload = payload; payload = 'STALE-STATUS';
    await nodes.preview.onclick(); releaseStatus(); await flush();
    assert.notEqual(nodes.qrResult.textContent, 'STALE-STATUS', 'A pre-mode-change status response must not overwrite the result');
    payload = goodPayload; mode = 'preview'; await poll(); tick(250);
    sequence++; await nodes.qr.onclick(); mode = 'qr'; downloadMs = 300; decodeMs = 40; await poll();
    assert.match(nodes.snapshotStatus.textContent, /340ms/);
    assert([...timers.values()].some(timer => timer.fn === context.poll && timer.ms === 500), 'QR status polling must be independent of image refresh duration');
    mode = 'error'; await poll(); assert.match(nodes.status.textContent, /Camera unavailable/);
    const before = fetches; events.pagehide();
    if ([...timers.values()].some(timer => timer.fn === context.poll)) await poll();
    assert.equal(fetches, before, 'Closed page must not start a new request');
    assert.equal(intervals.size, 0, 'Freshness timer must stop on page exit');
    console.log('PASS: QR-06 independent result polling, bounded image requests and notification progress; freshness/error/mode/cleanup regressions.');
})().catch(error => { console.error(error); process.exitCode = 1; });
