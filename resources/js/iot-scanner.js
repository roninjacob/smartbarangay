const root = document.querySelector('[data-qr-verification]');
if (root) {
    const panel = root.querySelector('[data-iot-panel]');
    const node = (name) => panel.querySelector(`[data-iot-${name}]`);
    const modes = root.querySelectorAll('[data-scanner-mode]');
    let version = 0;
    let active = false;
    let online = false;
    let handoff = false;
    let frameRunning = false;
    let pollTimer, frameTimer, expiryTimer, frameUrl;
    let mutations = Promise.resolve();
    const reads = new Set();
    root.dataset.scannerMode = 'laptop';

    const message = (text, error = false) => {
        node('status').textContent = text;
        node('status').classList.toggle('text-danger', error);
    };
    const api = async (path, body, keepalive = false) => {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        if (body === undefined) reads.add(controller);
        try {
            const response = await fetch(`${root.dataset.iotUrl}/${path}`, {
                method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', keepalive,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: body === undefined ? undefined : JSON.stringify(body), signal: controller.signal,
            });
            if (!response.ok) {
                if ([401, 403, 419].includes(response.status)) throw new Error('Session unavailable. Sign in again.');
                if (response.status === 409) throw new Error('Scanner is in use or the viewing session expired. Select it again.');
                if (response.status === 429) throw new Error('Request limit reached. Wait a minute, then resume.');
                throw new Error('Scanner service unavailable. Check your connection, then resume.');
            }
            return await response.json();
        } finally {
            clearTimeout(timeout);
            reads.delete(controller);
        }
    };
    // Serialize session mutations, including fast device changes and late connections.
    const mutate = (path, body, keepalive = false) => {
        const result = mutations.catch(() => {}).then(() => api(path, body, keepalive));
        mutations = result;
        return result;
    };
    const clearImage = (text) => {
        clearTimeout(expiryTimer);
        if (frameUrl) URL.revokeObjectURL(frameUrl);
        frameUrl = undefined;
        node('preview-image').hidden = true;
        node('preview-image').removeAttribute('src');
        node('live').hidden = true;
        node('preview-placeholder').hidden = false;
        node('preview-note').textContent = text;
    };
    const stop = (text = 'Scanner paused') => {
        version++;
        active = false;
        online = false;
        clearTimeout(pollTimer);
        clearTimeout(frameTimer);
        frameTimer = undefined;
        reads.forEach((controller) => controller.abort());
        clearImage(text);
        node('disconnect').disabled = true;
        node('retry').hidden = !node('device').value;
        return version;
    };
    const disconnect = async (keepalive = false) => {
        stop();
        try { await mutate('disconnect', {}, keepalive); }
        catch (error) { message(error.message, true); }
    };
    const isCurrent = (current) => active && current === version && !document.hidden;
    const frame = async (current) => {
        if (!isCurrent(current) || !online || frameRunning) return;
        frameRunning = true;
        const controller = new AbortController();
        reads.add(controller);
        const timeout = setTimeout(() => controller.abort(), 6000);
        const began = performance.now();
        try {
            const response = await fetch(`${root.dataset.iotUrl}/frame`, {
                credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'image/jpeg' }, signal: controller.signal,
            });
            if (!isCurrent(current) || !online) return;
            if (response.status === 404) {
                clearImage('Waiting for a recent image from the scanner');
            } else {
                if (!response.ok || !response.headers.get('content-type')?.startsWith('image/jpeg')) throw new Error('Preview unavailable');
                const age = Number(response.headers.get('X-SB-Frame-Age-Ms'));
                if (!response.headers.get('X-SB-Frame-Age-Ms') || !Number.isFinite(age) || age < 0 || age >= 5000) throw new Error('Image expired');
                const blob = await response.blob();
                if (blob.size > 32768 || !isCurrent(current) || !online) return;
                if (frameUrl) URL.revokeObjectURL(frameUrl);
                frameUrl = URL.createObjectURL(blob);
                const image = node('preview-image');
                image.src = frameUrl;
                await image.decode();
                const remaining = 5000 - age - (performance.now() - began);
                if (!isCurrent(current) || !online) return;
                if (remaining <= 0) { clearImage('Image expired. Waiting for the next frame'); return; }
                clearTimeout(expiryTimer);
                image.hidden = false;
                node('preview-placeholder').hidden = true;
                node('live').hidden = false;
                expiryTimer = setTimeout(() => clearImage('Image expired. Waiting for the next frame'), remaining);
            }
        } catch (error) {
            if (isCurrent(current)) clearImage('Preview interrupted. Waiting for a recent image');
        } finally {
            clearTimeout(timeout);
            reads.delete(controller);
            frameRunning = false;
            if (isCurrent(current) && online) frameTimer = setTimeout(() => { frameTimer = undefined; void frame(current); }, 1200);
        }
    };
    const date = (value) => value ? new Date(value).toLocaleString() : 'Not reported';
    const cameras = { ready: 'Ready', camera_error: 'Camera unavailable', detection_error: 'QR decoder unavailable' };
    const poll = async (current) => {
        if (!isCurrent(current)) return;
        try {
            const data = await api('poll', {});
            if (!isCurrent(current)) return;
            if (data.state !== 'connected' || !data.preview) throw new Error('Viewing session expired. Resume the scanner.');
            online = data.online === true;
            message(online ? 'Online. Scanning for a SmartBarangay ticket.' : 'Offline. Waiting for authenticated device activity.');
            node('activity').textContent = `${cameras[data.camera_state] || 'Camera not reported'} - Last seen ${date(data.last_seen_at)}`;
            node('latest').textContent = data.last_scan_at ? `${date(data.last_scan_at)} - ${data.last_result}` : 'No scan received';
            if (!online || data.camera_state === 'camera_error') {
                clearTimeout(frameTimer);
                frameTimer = undefined;
                clearImage(online ? 'Camera unavailable. Check the device camera and power.' : 'Scanner offline');
            } else if (!frameRunning && !frameTimer) {
                void frame(current);
            }
            if (data.result) {
                document.dispatchEvent(new CustomEvent('qr:iot-result', { detail: data.result }));
                if (data.result.valid) {
                    handoff = true;
                    stop('Ticket verified. Opening reservation details');
                    void mutate('preview', { enabled: false }).catch(() => {});
                    return;
                }
                message(data.result.message || 'Invalid ticket. Present another SmartBarangay ticket.', true);
            }
            pollTimer = setTimeout(() => void poll(current), 1000);
        } catch (error) {
            if (current !== version) return;
            stop('Viewing session interrupted');
            void mutate('disconnect', {}, true).catch(() => {});
            message(error.name === 'AbortError' ? 'Request timed out. Resume the scanner to retry.' : error.message, true);
        }
    };
    const select = async () => {
        const id = node('device').value;
        const current = stop(id ? 'Connecting to the scanner' : 'Select a scanner');
        handoff = false;
        node('retry').hidden = true;
        message(id ? 'Connecting securely and opening the camera...' : 'Select a scanner to begin.');
        try {
            await mutate('disconnect', {});
            if (current !== version || !id || document.hidden) return;
            await mutate('connect', { device_id: id, preview: true });
            if (current !== version || document.hidden) return;
            active = true;
            node('disconnect').disabled = false;
            await poll(current);
        } catch (error) {
            if (current === version) { stop('Connection unavailable'); message(error.message, true); }
        }
    };
    const refresh = async () => {
        const current = version;
        try {
            const data = await api('devices');
            if (current !== version || root.dataset.scannerMode !== 'iot') return;
            node('device').replaceChildren(new Option('Select a scanner', ''));
            for (const device of data.devices) {
                const option = new Option(`${device.name}${device.available ? '' : ' (in use)'}`, device.id);
                option.disabled = !device.available;
                node('device').append(option);
            }
            message(data.devices.length ? 'Choose a scanner. Its camera opens automatically.' : 'No provisioned scanners available.');
        } catch (error) { if (current === version) message(error.message, true); }
    };
    modes.forEach((button) => button.addEventListener('click', async () => {
        if (button.disabled || button.dataset.scannerMode === root.dataset.scannerMode) return;
        const previous = root.dataset.scannerMode;
        stop('Select a scanner');
        handoff = false;
        root.dataset.scannerMode = button.dataset.scannerMode;
        document.dispatchEvent(new Event('qr:mode-change'));
        modes.forEach((mode) => {
            const selected = mode === button;
            mode.setAttribute('aria-pressed', String(selected));
            mode.classList.toggle('btn-primary', selected);
            mode.classList.toggle('btn-outline-primary', !selected);
        });
        panel.hidden = button.dataset.scannerMode !== 'iot';
        root.querySelector('[data-laptop-panel]').hidden = !panel.hidden;
        if (previous === 'iot') await mutate('disconnect', {}).catch(() => {});
        if (!panel.hidden) await refresh();
    }));
    node('device').addEventListener('change', () => void select());
    node('retry').addEventListener('click', () => void select());
    node('refresh').addEventListener('click', async () => { await disconnect(); await refresh(); });
    node('disconnect').addEventListener('click', () => { void disconnect(); message('Scanner paused. Resume when ready.'); });
    window.addEventListener('pagehide', () => { if (!handoff && root.dataset.scannerMode === 'iot') void disconnect(true); });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && !handoff && root.dataset.scannerMode === 'iot') void disconnect(true);
    });
}
