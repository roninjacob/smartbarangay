const root = document.querySelector('[data-qr-verification]');
if (root) {
    const element = (name) => root.querySelector(`[data-qr-${name}]`);
    const video = element('video');
    const start = element('start');
    const stop = element('stop');
    const next = element('next');
    let stream;
    let controls;
    let request;
    let generation = 0;
    let scanning = false;
    let busy = false;

    const status = (message) => { element('status').textContent = message; };
    const clearResult = () => {
        element('result').classList.remove('is-valid', 'is-invalid');
        element('details').hidden = true;
        ['resident', 'service', 'schedule', 'reservation-status'].forEach((name) => { element(name).textContent = ''; });
        element('result-message').textContent = 'No ticket scanned yet.';
        element('result').querySelector('h2').textContent = 'Verification result';
    };
    const releaseCamera = () => {
        scanning = false;
        controls?.stop();
        controls = undefined;
        stream?.getTracks().forEach((track) => track.stop());
        stream = undefined;
        video.srcObject = null;
        video.hidden = true;
        element('placeholder').hidden = false;
    };
    const stopScanning = () => {
        generation++;
        request?.abort();
        request = undefined;
        busy = false;
        releaseCamera();
        start.disabled = false;
        start.hidden = false;
        stop.hidden = false;
        stop.disabled = true;
        next.hidden = true;
        clearResult();
        status('Camera stopped. Start the camera to scan a ticket.');
    };
    const verify = async (payload, current) => {
        if (busy || ! scanning || current !== generation) return;
        busy = true;
        releaseCamera(); // One result per explicit scan; repeated frames cannot submit again.
        status('Verifying ticket with Barangay Calayo…');
        const abort = new AbortController();
        request = abort;
        const timeout = setTimeout(() => abort.abort(), 15000);
        try {
            const response = await fetch(root.dataset.verifyUrl, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ payload }), signal: abort.signal,
            });
            if (current !== generation) return;
            if (! response.ok) {
                const message = response.status === 429 ? 'Too many scans. Wait a minute before trying again.'
                    : [401, 403, 419].includes(response.status) ? 'Your session is unavailable. Refresh and sign in again.'
                    : 'Verification is unavailable. Please try again.';
                throw new Error(message);
            }
            const result = await response.json();
            if (current !== generation) return;
            if (typeof result.valid !== 'boolean' || (result.valid && ! result.reservation)) {
                throw new Error('Verification is unavailable. Please try again.');
            }
            element('result').classList.add(result.valid ? 'is-valid' : 'is-invalid');
            element('result').querySelector('h2').textContent = result.valid ? 'Valid QR Ticket' : 'Invalid QR Ticket';
            element('result-message').textContent = result.message;
            if (result.valid) {
                ['resident', 'service', 'schedule'].forEach((name) => { element(name).textContent = result.reservation[name]; });
                element('reservation-status').textContent = result.reservation.status;
                element('details').hidden = false;
            }
            status('Verification complete. Select Scan Next Ticket to continue.');
        } catch (error) {
            if (current !== generation) return;
            clearResult();
            element('result').querySelector('h2').textContent = 'Verification unavailable';
            element('result-message').textContent = 'No validity decision was made. Scan again after resolving the error.';
            status(error.name === 'AbortError' ? 'Verification timed out. Please try again.'
                : error instanceof TypeError ? 'Cannot reach the server. Check your connection and try again.' : error.message);
        } finally {
            clearTimeout(timeout);
            if (current === generation) {
                busy = false;
                request = undefined;
                stop.disabled = true;
                start.disabled = true;
                start.hidden = true;
                stop.hidden = true;
                next.hidden = false;
            }
        }
    };
    const startScanning = async () => {
        if (scanning || busy) return;
        const current = ++generation;
        clearResult();
        next.hidden = true;
        start.hidden = false;
        stop.hidden = false;
        start.disabled = true;
        stop.disabled = false;
        busy = true;
        status('Requesting camera access…');
        try {
            if (! window.isSecureContext || ! navigator.mediaDevices?.getUserMedia) {
                throw new Error('Camera scanning requires HTTPS or localhost and a supported browser.');
            }
            const { BrowserQRCodeReader } = await import('@zxing/browser');
            if (current !== generation) return;
            const acquired = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: 'environment' } });
            if (current !== generation) { acquired.getTracks().forEach((track) => track.stop()); return; }
            stream = acquired;
            video.hidden = false;
            element('placeholder').hidden = true;
            scanning = true;
            busy = false;
            status('Camera ready. Hold the QR ticket steady. Adjust its distance if it is not detected.');
            const reader = new BrowserQRCodeReader(undefined, { delayBetweenScanAttempts: 300, delayBetweenScanSuccess: 1000 });
            const active = await reader.decodeFromStream(stream, video, (result, error, scannerControls) => {
                if (result && scanning && ! busy && current === generation) {
                    controls = scannerControls;
                    void verify(result.getText(), current);
                }
                // No QR in a frame is expected, not an invalid ticket.
                if (error && ! ['NotFoundException', 'ChecksumException', 'FormatException'].includes(error.getKind?.() ?? error.name)
                    && scanning && current === generation) {
                    stopScanning();
                    status('Camera decoding failed. Stop other camera apps and try again.');
                }
            });
            if (current !== generation || ! scanning) active.stop();
            else controls = active;
        } catch (error) {
            if (current !== generation) return;
            releaseCamera();
            busy = false;
            start.disabled = false;
            stop.disabled = true;
            const errors = {
                NotAllowedError: 'Camera permission was denied. Allow camera access in your browser and try again.',
                NotFoundError: 'No camera was found. Connect a webcam and try again.',
                NotReadableError: 'The camera is unavailable or used by another app. Close the other app and try again.',
                OverconstrainedError: 'The camera does not support these settings. Try another webcam.',
            };
            status(errors[error.name] || 'Unable to start camera scanning. Use HTTPS or localhost, check your camera, and try again.');
        }
    };
    start.addEventListener('click', startScanning);
    next.addEventListener('click', startScanning);
    stop.addEventListener('click', stopScanning);
    window.addEventListener('pagehide', stopScanning);
    document.addEventListener('visibilitychange', () => { if (document.hidden) stopScanning(); });
}
