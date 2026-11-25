// Verification sounds through the Admin's own speakers (no hardware). Generated locally with the
// Web Audio API: no files, CDNs or dependencies. Sounds are an optional aid; any failure is silent
// and never affects verification.

const STORAGE = 'sb-iot-audio';
const PLAYED = 'sb-iot-audio-played';

// Short, gentle patterns: rising = authorized, three-step chime = check-in recorded,
// low falling pair = denied (staff should step in).
export const SOUNDS = {
    authorized: [[523.25, 0.12], [783.99, 0.18]],
    checkin: [[523.25, 0.1], [659.25, 0.1], [783.99, 0.1], [1046.5, 0.22]],
    denied: [[392, 0.18], [261.63, 0.3]],
};

export const soundFor = (result) => {
    if (!result || typeof result.valid !== 'boolean') return null;
    if (!result.valid) return 'denied';
    return result.outcome === 'check_in_authorized' || result.outcome === undefined ? 'authorized' : null;
};

const read = (storage, key, fallback) => {
    try { return JSON.parse(storage.getItem(key)) ?? fallback; } catch { return fallback; }
};
const write = (storage, key, value) => {
    try { storage.setItem(key, JSON.stringify(value)); } catch { /* storage unavailable: session only */ }
};

export function createAudio({ scope, storage, AudioContextClass, now = () => Date.now() }) {
    const key = `${STORAGE}:${scope}`;
    const prefs = { enabled: true, volume: 0.6, ...read(storage, key, {}) };
    let context;
    const state = () => {
        if (!AudioContextClass) return 'unsupported';
        if (!prefs.enabled) return 'off';
        if (!context || context.state !== 'running') return 'needs-activation';
        return 'on';
    };
    const save = () => write(storage, key, { enabled: prefs.enabled, volume: prefs.volume });
    // Call only from a user gesture (browser autoplay rules).
    const activate = async () => {
        if (!AudioContextClass) return state();
        try {
            context ??= new AudioContextClass();
            if (context.state !== 'running') await context.resume();
        } catch { /* blocked: report the state, never throw into the workflow */ }
        return state();
    };
    const play = (name) => {
        if (!SOUNDS[name] || state() !== 'on') return false;
        try {
            const master = context.createGain();
            master.gain.value = Math.max(0, Math.min(1, prefs.volume)) * 0.25;
            master.connect(context.destination);
            let at = context.currentTime + 0.02;
            for (const [frequency, duration] of SOUNDS[name]) {
                const oscillator = context.createOscillator();
                const envelope = context.createGain();
                oscillator.type = name === 'denied' ? 'triangle' : 'sine';
                oscillator.frequency.value = frequency;
                envelope.gain.setValueAtTime(0, at);
                envelope.gain.linearRampToValueAtTime(1, at + 0.015);
                envelope.gain.exponentialRampToValueAtTime(0.001, at + duration);
                oscillator.connect(envelope).connect(master);
                oscillator.start(at);
                oscillator.stop(at + duration + 0.02);
                at += duration + 0.03;
            }
            return true;
        } catch {
            return false;
        }
    };
    // Once per verification event across refreshes and tabs of this browser. A result without an
    // event id (laptop camera) is a fresh server answer and always counts as new.
    const playOnce = (name, eventId) => {
        if (eventId) {
            const played = read(storage, PLAYED, []);
            if (played.includes(eventId)) return false;
            write(storage, PLAYED, [...played, eventId].slice(-50));
        }
        return play(name);
    };
    return {
        prefs, state, activate, play, playOnce, now,
        setEnabled(enabled) { prefs.enabled = enabled; save(); },
        setVolume(volume) { prefs.volume = Math.max(0, Math.min(1, volume)); save(); },
    };
}

const STATUS = {
    on: 'Sound on.',
    off: 'Sound off.',
    'needs-activation': 'Select Enable sound once; browsers block audio until you interact with the page.',
    unsupported: 'This browser cannot play verification sounds.',
};

const browser = typeof document !== 'undefined';
const controls = browser ? document.querySelector('[data-iot-audio]') : null;
const trigger = browser ? document.querySelector('[data-iot-sound]') : null;
const scope = controls?.dataset.audioScope ?? trigger?.dataset.audioScope;
if (scope) {
    const audio = createAudio({ scope, storage: window.localStorage,
        AudioContextClass: window.AudioContext ?? window.webkitAudioContext });
    const node = (name) => controls?.querySelector(`[data-iot-audio-${name}]`);
    const render = () => {
        if (!controls) return;
        node('enabled').checked = audio.prefs.enabled;
        node('volume').value = String(Math.round(audio.prefs.volume * 100));
        node('volume').disabled = !audio.prefs.enabled;
        node('test').disabled = !audio.prefs.enabled;
        node('activate').hidden = audio.state() !== 'needs-activation';
        node('status').textContent = STATUS[audio.state()];
    };
    if (controls) {
        node('enabled').addEventListener('change', async (event) => {
            audio.setEnabled(event.target.checked);
            if (event.target.checked) await audio.activate();
            render();
        });
        node('volume').addEventListener('input', (event) => { audio.setVolume(Number(event.target.value) / 100); });
        node('activate').addEventListener('click', async () => { await audio.activate(); render(); });
        node('test').addEventListener('click', async () => { await audio.activate(); render(); audio.play('authorized'); });
        // Any interaction may unlock audio; harmless if it stays blocked.
        document.addEventListener('pointerdown', () => { if (audio.prefs.enabled) void audio.activate().then(render); }, { once: true });
        // Authoritative results only: qr-verification.js dispatches this after a server answer is shown.
        document.addEventListener('qr:result-shown', (event) => {
            const name = soundFor(event.detail);
            if (name) audio.playOnce(name, event.detail.event_id);
        });
        render();
    }
    // The reservation page after a recorded check-in (one-time flash, so a refresh does not replay).
    if (trigger?.dataset.iotSound === 'checkin' && audio.prefs.enabled) {
        void audio.activate().then(() => audio.play('checkin'));
    }
}
