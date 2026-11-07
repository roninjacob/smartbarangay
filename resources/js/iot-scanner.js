const root = document.querySelector('[data-qr-verification]');
if (root) {
    const panel = root.querySelector('[data-iot-panel]');
    const node = (name) => panel.querySelector(`[data-iot-${name}]`);
    const modes = root.querySelectorAll('[data-scanner-mode]');
    let timer;
    let version = 0;
    let expiry;
    let paired = false;
    let active = false;
    let request;
    root.dataset.scannerMode = 'laptop';

    const message = (text, error = false) => {
        if (node('status').textContent !== text) node('status').textContent = text;
        node('status').classList.toggle('alert-danger', error);
        node('status').classList.toggle('alert-info', !error);
    };
    const hideQr = () => {
        node('qr-wrap').hidden = true;
        node('qr').removeAttribute('src');
        node('countdown').textContent = '';
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
            if (!response.ok) {
                throw new Error([401, 403, 419].includes(response.status) ? 'Session unavailable. Refresh and sign in again.'
                    : response.status === 409 ? 'Scanner unavailable or pairing expired. Refresh scanners and pair again.'
                    : response.status === 429 ? 'Too many requests. Wait a minute and try again.'
                    : 'Scanner service unavailable. Check your connection and try again.');
            }
            return await response.json();
        } finally { clearTimeout(timeout); }
    };
    const stop = () => {
        version++;
        clearTimeout(timer);
        request?.abort();
        active = false;
        paired = false;
        expiry = undefined;
        hideQr();
        node('disconnect').disabled = true;
    };
    const disconnect = async (keepalive = false) => {
        stop();
        try {
            await api('disconnect', {}, keepalive);
            message('Scanner disconnected. Generate a new pairing QR to reconnect.');
            node('pair').disabled = !node('device').value;
        } catch (error) { message(error.message, true); }
    };
    const poll = async (current) => {
        if (!active || current !== version || document.hidden) return;
        try {
            const data = await api('poll', {});
            if (current !== version || !active) return;
            if (!['connected', 'waiting'].includes(data.state)) {
                stop();
                message(data.state === 'expired' ? 'Pairing expired or scanner disconnected. Generate a new pairing QR.' : 'Scanner is unpaired. Generate a pairing QR.');
                node('pair').disabled = !node('device').value;
                return;
            }
            expiry = Date.parse(data.expires_at);
            if (!Number.isFinite(expiry)) throw new Error('Invalid pairing response. Refresh and try again.');
            paired = data.state === 'connected';
            if (paired) hideQr();
            const seconds = Math.max(0, Math.ceil((expiry - Date.now()) / 1000));
            node('countdown').textContent = `Pairing QR expires in ${seconds}s`;
            message(paired ? `Connected to ${data.device}. Ready to scan a Resident ticket.` : 'Waiting for ESP32-CAM. Show it the pairing QR.');
            if (data.result) {
                document.dispatchEvent(new CustomEvent('qr:iot-result', { detail: data.result }));
                if (data.result.valid) {
                    // Preserve the established check-in evidence during the reservation handoff.
                    active = false;
                    hideQr();
                    return;
                }
                message('Invalid ticket. The scanner is ready for another ticket.', true);
            }
            timer = setTimeout(() => void poll(current), 1000);
        } catch (error) {
            if (current !== version) return;
            stop();
            message(error.name === 'AbortError' ? 'Scanner request timed out. Refresh scanners and pair again.' : error.message, true);
            node('pair').disabled = !node('device').value;
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
            node('pair').disabled = true;
            message(data.devices.length ? 'Select a scanner, then generate its pairing QR.' : 'No provisioned scanners available. Ask the project owner to provision a test device.');
        } catch (error) { if (current === version) message(error.message, true); }
    };
    modes.forEach((button) => button.addEventListener('click', async () => {
        if (button.disabled || button.dataset.scannerMode === root.dataset.scannerMode) return;
        if (root.dataset.scannerMode === 'iot') await disconnect();
        stop();
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
        if (!panel.hidden) await refresh();
    }));
    node('device').addEventListener('change', () => { node('pair').disabled = active || !node('device').value; });
    node('refresh').addEventListener('click', async () => { await disconnect(); await refresh(); });
    node('disconnect').addEventListener('click', () => void disconnect());
    node('pair').addEventListener('click', async () => {
        stop();
        const current = version;
        node('pair').disabled = true;
        message('Preparing a secure pairing QR…');
        try {
            const data = await api('challenge', { device_id: node('device').value });
            if (current !== version || root.dataset.scannerMode !== 'iot') return;
            node('qr').src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(data.qr_svg)}`;
            node('qr-wrap').hidden = false;
            node('disconnect').disabled = false;
            active = true;
            await poll(current);
        } catch (error) {
            if (current !== version) return;
            message(error.message, true);
            node('pair').disabled = !node('device').value;
        }
    });
    window.addEventListener('pagehide', () => {
        // A successful handoff expires naturally; clearing evidence here would break explicit check-in.
        if (active) void disconnect(true);
    });
    document.addEventListener('visibilitychange', () => { if (document.hidden && active) void disconnect(true); });
}
