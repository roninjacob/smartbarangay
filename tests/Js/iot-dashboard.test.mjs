import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/iot-dashboard.js', import.meta.url), 'utf8');
const flush = () => new Promise((resolve) => setImmediate(resolve));
function harness() {
    const nodes = new Map(), events = {}, timers = new Map(), requests = [];
    let sequence = 0;
    const node = (name) => {
        if (!nodes.has(name)) nodes.set(name, { value: '', hidden: true, children: [], textContent: '',
            dataset: { monitorValue: name }, addEventListener(event, callback) { this[event] = callback; },
            replaceChildren() { this.children = []; }, append(...items) { this.children.push(...items); } });
        return nodes.get(name);
    };
    const values = ['name', 'online', 'avg_us', 'p95_us', 'heap', 'attempts'].map(node);
    const root = { dataset: { url: '/admin/iot-dashboard/data' }, querySelectorAll: () => values,
        querySelector: (selector) => node(selector.match(/data-monitor-([^\]]+)/)[1]) };
    const document = { hidden: false, querySelector: () => root,
        createElement: () => ({ textContent: '', children: [], append(...children) { this.children.push(...children); } }),
        addEventListener(name, callback) { events[name] = callback; } };
    const data = { device: { name: '<script>synthetic</script>', online: true, telemetry: { attempts: 0, avg_us: 0, p95_us: 0, heap: 2048 },
        counts_since: null, heartbeat_at: null, last_seen_at: null, handoff_age_ms: null },
        events: [{ kind: 'transport', details: { category: 'dns_timeout', http: -1 }, at: '2026-11-10T04:00:00Z' }],
        pagination: { page: 1, last_page: 2 }, observed_at: '2026-11-10T04:00:00Z' };
    vm.runInNewContext(source, { document, window: { addEventListener(name, callback) { events[name] = callback; } },
        AbortController, Date, URLSearchParams,
        setTimeout(callback, delay) { const id = ++sequence; timers.set(id, { callback, delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        fetch: async (url, options) => { requests.push({ url, options }); return { ok: true, json: async () => data }; } });
    return { node, document, events, timers, requests, data };
}
test('monitoring uses authenticated bounded polling and renders plain text with honest unknown metrics', async () => {
    const h = harness(); h.node('device').value = 'synthetic-device'; h.node('device').change(); await flush();
    assert.match(h.requests[0].url, /device=synthetic-device&page=1/);
    assert.equal(h.requests[0].options.credentials, 'same-origin');
    assert.equal(h.requests[0].options.cache, 'no-store');
    assert.equal(h.node('name').textContent, '<script>synthetic</script>');
    assert.equal(h.node('avg_us').textContent, 'Not reported');
    assert.equal(h.node('heap').textContent, '2.0 KiB');
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
    assert.equal(h.node('content').hidden, true);
});
