import test from 'node:test';
import assert from 'node:assert/strict';
import {watchEmailVerification} from '../../resources/js/email-verification.js';

function fixture(t, responses = []) {
    let now = 10000;
    t.mock.method(Date, 'now', () => now);
    const timers = new Map();
    const handlers = {};
    const calls = [];
    const redirects = [];
    let timerId = 0;
    const doc = {hidden: false, addEventListener: (name, fn) => { handlers[name] = fn; }};
    const win = {
        location: {origin: 'https://smartbarangay.test', replace: url => redirects.push(url)},
        setTimeout: (fn, delay) => { timers.set(++timerId, {fn, delay}); return timerId; },
        clearTimeout: id => timers.delete(id),
        addEventListener: (name, fn) => { handlers[name] = fn; },
    };
    const element = {dataset: {statusUrl: '/email/verification-status'}, textContent: ''};
    const fetcher = async (url, options) => {
        calls.push({url, options});
        return responses.shift() ?? response({verified: false, redirect: null});
    };
    const advance = async () => {
        const entry = [...timers].find(([, timer]) => timer.delay >= 10000);
        assert.ok(entry);
        timers.delete(entry[0]);
        now += entry[1].delay;
        await entry[1].fn();
    };
    watchEmailVerification(element, win, doc, fetcher);
    return {element, win, doc, handlers, timers, calls, redirects, advance};
}

function response(data, status = 200) {
    return {ok: status === 200, status, headers: {get: () => '60'}, json: async () => data};
}

async function flush() {
    for (let i = 0; i < 8; i++) await Promise.resolve();
}

test('periodic authenticated rechecking redirects only after confirmed verification', async t => {
    const f = fixture(t, [response({verified: false}), response({verified: true, redirect: '/resident/home'})]);
    await flush();
    assert.equal(f.calls.length, 1);
    assert.deepEqual(f.redirects, []);
    assert.equal(f.calls[0].options.credentials, 'same-origin');
    assert.equal(f.calls[0].options.cache, 'no-store');
    assert.equal(f.calls[0].options.redirect, 'error');
    await f.advance();
    assert.deepEqual(f.redirects, ['https://smartbarangay.test/resident/home']);
    assert.equal(f.timers.size, 0);
});

test('hidden or departed pages abort and pause verification checks', async t => {
    const f = fixture(t);
    await flush();
    f.doc.hidden = true;
    f.handlers.visibilitychange();
    assert.equal(f.timers.size, 0);
    const count = f.calls.length;
    f.handlers.pageshow();
    await flush();
    assert.equal(f.calls.length, count);
    f.handlers.pagehide();
    f.doc.hidden = false;
    f.handlers.visibilitychange();
    await flush();
    assert.equal(f.calls.length, count);
});

test('rate-limited requests back off while expired sessions stop polling', async t => {
    const f = fixture(t, [response({}, 429), response({}, 401)]);
    await flush();
    assert.ok([...f.timers.values()].some(timer => timer.delay === 60000));
    await f.advance();
    assert.equal(f.timers.size, 0);
    assert.match(f.element.textContent, /Sign in again/);
});

test('untrusted destinations cannot navigate the original browser', async t => {
    const f = fixture(t, [response({verified: true, redirect: 'https://attacker.test/resident/home'})]);
    await flush();
    assert.deepEqual(f.redirects, []);
    assert.ok(f.timers.size > 0);
});

test('visibility changes never duplicate an in-flight request', async t => {
    let resolve;
    const pending = new Promise(done => { resolve = done; });
    const f = fixture(t, [pending]);
    f.handlers.pageshow();
    f.handlers.visibilitychange();
    assert.equal(f.calls.length, 1);
    f.doc.hidden = true;
    f.handlers.visibilitychange();
    assert.equal(f.calls[0].options.signal.aborted, true);
    resolve(response({verified: true, redirect: '/resident/home'}));
    await flush();
    assert.deepEqual(f.redirects, []);
    assert.equal(f.timers.size, 0);
});
