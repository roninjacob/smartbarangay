const root = document.querySelector('[data-qr-verification]');
if (root) {
    const panel = root.querySelector('[data-iot-panel]');
    const node = (name) => panel.querySelector(`[data-iot-${name}]`);
    const modes = root.querySelectorAll('[data-scanner-mode]');
    let timer, frameTimer, frameController, frameUrl;
    let version = 0, active = false, preview = false, handoff = false, request;
    root.dataset.scannerMode = 'laptop';
    const message = (text, error = false) => {
        node('status').textContent = text;
        node('status').classList.toggle('alert-danger', error);
        node('status').classList.toggle('alert-info', !error);
    };
    const api = async (path, body, keepalive = false) => {
        const controller = new AbortController();
        if (!keepalive) request = controller;
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(`${root.dataset.iotUrl}/${path}`, {
                method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', keepalive,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: body === undefined ? undefined : JSON.stringify(body), signal: controller.signal,
            });
            if (!response.ok) throw new Error([401, 403, 419].includes(response.status)
                ? 'Session unavailable. Sign in again.' : response.status === 409
                    ? 'Scanner connection expired or is in use. Reconnect the scanner.' : response.status === 429
                        ? 'Request limit reached. Wait a minute before reconnecting.' : 'Scanner service unavailable. Check your connection.');
            return await response.json();
        } finally { clearTimeout(timeout); }
    };
    const hidePreview = () => {
        preview = false;
        clearTimeout(frameTimer);
        frameController?.abort();
        if (frameUrl) URL.revokeObjectURL(frameUrl);
        frameUrl = undefined;
        node('preview-image').hidden = true;
        node('preview-image').removeAttribute('src');
        node('preview-start').disabled = !active;
        node('preview-stop').disabled = true;
        node('preview-note').textContent = 'Preview is off. Images are private and expire shortly.';
    };
    const stop = () => {
        version++;
        clearTimeout(timer);
        request?.abort();
        active = false;
        hidePreview();
        node('disconnect').disabled = true;
        node('device').disabled = false;
        node('connect').disabled = !node('device').value;
    };
    const disconnect = async (keepalive = false) => {
        stop();
        try { await api('disconnect', {}, keepalive); message('Scanner disconnected. Select a scanner to reconnect.'); }
        catch (error) { message(error.message, true); }
    };
    const frame = async (current) => {
        if (!active || !preview || current !== version || document.hidden) return;
        frameController = new AbortController();
        const timeout = setTimeout(() => frameController?.abort(), 7000);
        try {
            const response = await fetch(`${root.dataset.iotUrl}/frame`, { credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'image/jpeg' }, signal: frameController.signal });
            if (!preview || current !== version) return;
            if (response.status === 404) {
                node('preview-note').textContent = 'Waiting for a fresh camera image…';
                node('preview-image').hidden = true;
            } else {
                if (!response.ok || !response.headers.get('content-type')?.startsWith('image/jpeg')) throw new Error('Preview unavailable.');
                const blob = await response.blob();
                if (blob.size > 32768 || !preview || current !== version) return;
                if (frameUrl) URL.revokeObjectURL(frameUrl);
                frameUrl = URL.createObjectURL(blob);
                node('preview-image').src = frameUrl;
                node('preview-image').hidden = false;
                node('preview-note').textContent = 'Live ESP32-CAM · low frame rate. QR scanning remains active.';
            }
        } catch (error) {
            if (current === version && preview) { node('preview-image').hidden = true; node('preview-note').textContent = 'Preview interrupted. Waiting for the device…'; }
        } finally { clearTimeout(timeout); }
        if (preview && current === version) frameTimer = setTimeout(() => void frame(current), 1100);
    };
    const date = (value) => value ? new Date(value).toLocaleString() : 'not yet received';
    const poll = async (current) => {
        if (!active || current !== version || document.hidden) return;
        try {
            const data = await api('poll', {});
            if (!active || current !== version) return;
            if (data.state !== 'connected') throw new Error('Scanner disconnected. Reconnect to continue.');
            message(`${data.device} · ${data.online ? 'Online — ready for a ticket' : 'Offline — check device power and Wi-Fi'}`);
            node('activity').textContent = `Last seen: ${date(data.last_seen_at)} · Camera: ${data.camera_state || 'unknown'}`;
            node('latest').textContent = data.last_scan_at ? `${date(data.last_scan_at)} · ${data.last_result}` : 'No scan received';
            if (preview && !data.preview) hidePreview();
            if (data.result) {
                document.dispatchEvent(new CustomEvent('qr:iot-result', { detail: data.result }));
                if (data.result.valid) {
                    // Stop images without deleting the session evidence needed for manual check-in.
                    hidePreview();
                    void api('preview', { enabled: false }).catch(() => {});
                    active = false;
                    handoff = true;
                    return;
                }
                message('Invalid ticket. Present another ticket to the scanner.', true);
            }
            timer = setTimeout(() => void poll(current), 1000);
        } catch (error) {
            if (current !== version) return;
            void api('preview', { enabled: false }, true).catch(() => {});
            stop(); message(error.name === 'AbortError' ? 'Scanner request timed out. Reconnect to retry.' : error.message, true);
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
                option.disabled = !device.available; node('device').append(option);
            }
            node('connect').disabled = true;
            message(data.devices.length ? 'Select a provisioned scanner to view its automatic scans.' : 'No provisioned scanners available.');
        } catch (error) { if (current === version) message(error.message, true); }
    };
    modes.forEach((button) => button.addEventListener('click', async () => {
        if (button.disabled || button.dataset.scannerMode === root.dataset.scannerMode) return;
        if (root.dataset.scannerMode === 'iot') await disconnect();
        stop(); handoff = false; root.dataset.scannerMode = button.dataset.scannerMode;
        document.dispatchEvent(new Event('qr:mode-change'));
        modes.forEach((mode) => {
            const selected = mode === button;
            mode.setAttribute('aria-pressed', String(selected));
            mode.classList.toggle('btn-primary', selected); mode.classList.toggle('btn-outline-primary', !selected);
        });
        panel.hidden = button.dataset.scannerMode !== 'iot';
        root.querySelector('[data-laptop-panel]').hidden = !panel.hidden;
        if (!panel.hidden) await refresh();
    }));
    node('device').addEventListener('change', () => { node('connect').disabled = active || !node('device').value; });
    node('refresh').addEventListener('click', async () => { await disconnect(); await refresh(); });
    node('disconnect').addEventListener('click', () => void disconnect());
    node('connect').addEventListener('click', async () => {
        stop(); const current = version; node('connect').disabled = true;
        try {
            await api('connect', { device_id: node('device').value });
            if (current !== version || root.dataset.scannerMode !== 'iot') return;
            active = true; node('device').disabled = true; node('disconnect').disabled = false; node('preview-start').disabled = false;
            await poll(current);
        } catch (error) { if (current === version) { stop(); message(error.message, true); } }
    });
    node('preview-start').addEventListener('click', async () => {
        const current = version; node('preview-start').disabled = true;
        try {
            await api('preview', { enabled: true });
            if (!active || current !== version) return;
            preview = true; node('preview-stop').disabled = false; void frame(current);
        } catch (error) { hidePreview(); message(error.message, true); }
    });
    node('preview-stop').addEventListener('click', async () => {
        hidePreview(); try { await api('preview', { enabled: false }); } catch (error) { message(error.message, true); }
    });
    window.addEventListener('pagehide', () => { if (!handoff && root.dataset.scannerMode === 'iot') void disconnect(true); });
    document.addEventListener('visibilitychange', () => { if (document.hidden && !handoff && root.dataset.scannerMode === 'iot') void disconnect(true); });
}
