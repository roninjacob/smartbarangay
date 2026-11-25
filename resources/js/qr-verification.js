import { Modal } from 'bootstrap';

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
    // The result being reviewed: its scanner event (IoT only) and whether check-in is being submitted.
    let review;
    const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
    const resultModal = Modal.getOrCreateInstance(element('result-modal'), { backdrop: 'static', keyboard: true });

    // Wording follows the server's transaction outcome. Scanning never performs the transaction.
    const OUTCOMES = {
        check_in_authorized: { title: 'Check-in Authorized', panel: 'Check-in authorized',
            lead: 'This resident may proceed. Confirm their arrival to record check-in. Payment and completion remain separate steps.' },
        checked_in: { title: 'Already Checked In', panel: 'Already checked in',
            lead: 'Arrival is already recorded. Continue with payment or completion on the reservation page.' },
        not_ready: { title: 'Valid Ticket, Not Yet Ready', panel: 'Not yet ready for pickup',
            lead: 'This ticket is valid, but check-in opens only when the reservation is Ready for Pickup.' },
        denied: { title: 'Access Denied', panel: 'Access denied',
            lead: 'No transaction was recorded. Ask the resident to approach the barangay staff.' },
    };

    const status = (message) => { element('status').textContent = message; };
    const sameOriginPath = (value, pattern) => {
        if (typeof value !== 'string') return undefined;
        const url = new URL(value, window.location.href);
        return url.origin === window.location.origin && ! url.search && ! url.hash && pattern.test(url.pathname) ? url : undefined;
    };
    const fillDetails = (reservation) => {
        const values = reservation ?? {};
        ['resident', 'service', 'schedule'].forEach((name) => { element(name).textContent = values[name] ?? ''; });
        element('reservation-status').textContent = values.status ?? '';
        ['resident', 'reference', 'service', 'schedule', 'status'].forEach((name) => { element(`modal-${name}`).textContent = values[name] ?? ''; });
    };
    const resetModal = () => {
        review = undefined;
        fillDetails();
        ['title', 'lead', 'note', 'verified'].forEach((name) => { element(`modal-${name}`).textContent = ''; });
        element('modal-checkin').hidden = true;
        element('modal-checkin').removeAttribute('action');
        element('modal-event').disabled = true;
        element('modal-event').value = '';
        element('modal-open').hidden = true;
        element('modal-open').removeAttribute('href');
        element('modal-confirm').disabled = false;
        element('modal-done').disabled = false;
    };
    const clearResult = () => {
        element('result').classList.remove('is-valid', 'is-invalid');
        element('details').hidden = true;
        element('success-icon').hidden = true;
        element('result-message').textContent = 'No ticket scanned yet.';
        element('result-title').textContent = 'Verification result';
        const reviewing = review;
        resetModal();
        if (! reviewing) return;
        // Closing a result acknowledges exactly its scanner event; it never checks anyone in.
        if (reviewing.eventId && ! reviewing.submitting) {
            document.dispatchEvent(new CustomEvent('qr:iot-ack', { detail: { event_id: reviewing.eventId } }));
        }
        resultModal.hide();
    };
    const finishReview = () => {
        if (! review || review.submitting) return;
        clearResult();
        next.hidden = root.dataset.scannerMode === 'iot';
        status(root.dataset.scannerMode === 'iot' ? 'Ready. The scanner is waiting for the next ticket.'
            : 'Result closed. Select Scan Next Ticket to continue.');
    };
    element('result-modal').addEventListener('hidden.bs.modal', finishReview);
    element('modal-done').addEventListener('click', () => resultModal.hide());
    element('modal-open').addEventListener('click', () => {
        if (review?.eventId) document.dispatchEvent(new CustomEvent('qr:iot-ack', { detail: { event_id: review.eventId, keepalive: true } }));
        if (review) review.submitting = true;
    });
    element('modal-checkin').addEventListener('submit', (event) => {
        if (! review || review.submitting) { event.preventDefault(); return; }
        // The server revalidates eligibility and evidence and records the arrival at most once.
        review.submitting = true;
        element('modal-confirm').disabled = true;
        element('modal-done').disabled = true;
        element('modal-note').textContent = 'Recording check-in…';
    });

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
    const showResult = (result, current) => {
        if (typeof result.valid !== 'boolean') throw new Error('Verification is unavailable. Please try again.');
        const outcome = result.valid ? result.outcome ?? 'check_in_authorized' : 'denied';
        if (! OUTCOMES[outcome] || (result.valid && (outcome === 'denied' || ! result.reservation))) {
            throw new Error('Verification is unavailable. Please try again.');
        }
        let reservationUrl;
        let checkInUrl;
        if (result.valid) {
            // Only use server-generated destinations. Never build a URL from scanned/client IDs.
            reservationUrl = sameOriginPath(result.reservation_url, /^\/admin\/reservations\/[1-9]\d*$/);
            if (! reservationUrl) throw new Error('Verification is unavailable. Please try again.');
            if (outcome === 'check_in_authorized') {
                checkInUrl = sameOriginPath(result.check_in_url, /^\/admin\/reservations\/[1-9]\d*\/check-in$/);
                if (! checkInUrl || checkInUrl.pathname !== `${reservationUrl.pathname}/check-in`) {
                    throw new Error('Verification is unavailable. Please try again.');
                }
            }
        }
        const copy = OUTCOMES[outcome];
        resetModal();
        review = { eventId: UUID.test(result.event_id ?? '') ? result.event_id : undefined, generation: current, submitting: false };

        element('result').classList.add(result.valid ? 'is-valid' : 'is-invalid');
        element('result-title').textContent = copy.panel;
        element('result-message').textContent = result.valid ? copy.lead : result.message;
        element('details').hidden = ! result.valid;
        element('success-icon').hidden = ! result.valid;
        if (result.valid) fillDetails(result.reservation);

        element('modal-content').classList.toggle('is-denied', ! result.valid);
        element('modal-icon-success').hidden = ! result.valid;
        element('modal-icon-denied').hidden = result.valid;
        element('modal-title').textContent = copy.title;
        element('modal-lead').textContent = result.valid ? copy.lead : `${result.message} ${copy.lead}`;
        element('modal-details').hidden = ! result.valid;
        element('modal-verified').textContent = new Date().toLocaleString();
        if (outcome === 'checked_in' && result.reservation.checked_in_at) {
            element('modal-note').textContent = `Arrival recorded ${result.reservation.checked_in_at} (Philippine time).`;
        }
        if (reservationUrl) {
            element('modal-open').href = reservationUrl.href;
            element('modal-open').hidden = false;
        }
        if (checkInUrl) {
            element('modal-checkin').action = checkInUrl.href;
            element('modal-checkin').hidden = false;
            if (review.eventId) {
                element('modal-event').value = review.eventId;
                element('modal-event').disabled = false;
            }
        }
        resultModal.show();
        // For the optional verification sound: only server answers reach this point.
        document.dispatchEvent(new CustomEvent('qr:result-shown', { detail: { valid: result.valid, outcome, event_id: review.eventId } }));
        status(result.valid ? `${copy.panel}. Review the result window.` : 'Ticket denied. Review the result window.');
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
            showResult(result, current);
        } catch (error) {
            if (current !== generation) return;
            clearResult();
            element('result-title').textContent = 'Verification unavailable';
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
                next.hidden = Boolean(review);
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
    document.addEventListener('qr:mode-change', stopScanning);
    document.addEventListener('qr:iot-result', (event) => {
        if (root.dataset.scannerMode !== 'iot' || document.hidden) return;
        // One result at a time: a delayed or duplicate delivery never replaces the one under review.
        if (review) return;
        releaseCamera();
        try { showResult(event.detail, generation); }
        catch { status('Verification is unavailable. Please resume the scanner and scan again.'); }
    });
    start.addEventListener('click', startScanning);
    next.addEventListener('click', startScanning);
    stop.addEventListener('click', stopScanning);
    window.addEventListener('pagehide', () => { if (! review?.submitting) stopScanning(); });
    document.addEventListener('visibilitychange', () => { if (document.hidden && ! review) stopScanning(); });
}
