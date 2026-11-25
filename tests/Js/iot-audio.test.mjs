import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createAudio, soundFor, SOUNDS } from '../../resources/js/iot-audio.js';

class FakeStorage {
    constructor() { this.data = new Map(); }
    getItem(key) { return this.data.has(key) ? this.data.get(key) : null; }
    setItem(key, value) { this.data.set(key, String(value)); }
}
class FakeContext {
    static instances = [];
    constructor() { this.state = 'suspended'; this.currentTime = 0; this.started = []; this.destination = {}; FakeContext.instances.push(this); }
    async resume() { if (!FakeContext.blocked) this.state = 'running'; }
    createGain() { return { gain: { value: 1, setValueAtTime() {}, linearRampToValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect: (next) => next }; }
    createOscillator() {
        const started = this.started;
        return { type: '', frequency: { value: 0 }, connect: (next) => next, start() { started.push(this.frequency.value); }, stop() {} };
    }
}
const make = (scope = 'admin-1', storage = new FakeStorage()) => createAudio({ scope, storage, AudioContextClass: FakeContext });

test('only authoritative outcomes map to sounds; unavailable is never a denial', () => {
    assert.equal(soundFor({ valid: true, outcome: 'check_in_authorized' }), 'authorized');
    assert.equal(soundFor({ valid: false, outcome: 'denied' }), 'denied');
    assert.equal(soundFor({ valid: true, outcome: 'checked_in' }), null);
    assert.equal(soundFor(undefined), null);
    assert.equal(soundFor({ message: 'Verification is unavailable' }), null);
    assert.notDeepEqual(SOUNDS.authorized, SOUNDS.checkin);
});

test('nothing plays until the browser allows audio, and a blocked context is reported', async () => {
    FakeContext.blocked = true;
    const audio = make();
    assert.equal(audio.state(), 'needs-activation');
    assert.equal(audio.play('authorized'), false);
    assert.equal(await audio.activate(), 'needs-activation');
    FakeContext.blocked = false;
    assert.equal(await audio.activate(), 'on');
    assert.equal(audio.play('authorized'), true);
});

test('each verification event sounds once across refreshes and tabs of the same browser', async () => {
    const storage = new FakeStorage();
    const first = make('admin-1', storage);
    await first.activate();
    assert.equal(first.playOnce('authorized', 'event-1'), true);
    assert.equal(first.playOnce('authorized', 'event-1'), false);
    const otherTab = make('admin-1', storage);
    await otherTab.activate();
    assert.equal(otherTab.playOnce('authorized', 'event-1'), false);
    assert.equal(otherTab.playOnce('authorized', 'event-2'), true, 'A new event with the same outcome still sounds.');
});

test('mute and volume are stored per Admin account in this browser', async () => {
    const storage = new FakeStorage();
    const admin = make('admin-1', storage);
    await admin.activate();
    admin.setEnabled(false);
    admin.setVolume(1.7);
    assert.equal(admin.play('denied'), false);
    assert.equal(make('admin-1', storage).prefs.enabled, false);
    assert.equal(make('admin-1', storage).prefs.volume, 1);
    assert.equal(make('admin-2', storage).prefs.enabled, true, 'Another Admin keeps their own setting.');
});
