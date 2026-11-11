export function watchEmailVerification(element, win, doc, fetcher) {
    const endpoint = new URL(element.dataset.statusUrl, win.location.origin);
    if (endpoint.origin !== win.location.origin) return;
    let timer;
    let controller;
    let stopped = false;
    let paused = false;
    let lastCheck = -Infinity;

    function schedule(delay = 10000) {
        win.clearTimeout(timer);
        if (!stopped && !paused && !doc.hidden) timer = win.setTimeout(check, delay);
    }

    async function check() {
        if (stopped || paused || doc.hidden || controller) return;
        const elapsed = Date.now() - lastCheck;
        if (elapsed < 5000) { schedule(5000 - elapsed); return; }
        lastCheck = Date.now();
        controller = new AbortController();
        const timeout = win.setTimeout(() => controller?.abort(), 8000);
        let delay = 10000;
        try {
            const response = await fetcher(endpoint.href, {
                headers: {Accept: 'application/json'}, credentials: 'same-origin',
                cache: 'no-store', redirect: 'error', signal: controller.signal,
            });
            if ([401, 403, 419].includes(response.status)) {
                stopped = true;
                element.textContent = 'Your session has expired. Sign in again to continue.';
            } else if (response.status === 429) {
                delay = Math.max(60000, Math.min(300000, Number(response.headers.get('Retry-After')) * 1000 || 60000));
            } else if (response.ok) {
                const result = await response.json();
                if (result.verified === true && typeof result.redirect === 'string') {
                    const destination = new URL(result.redirect, win.location.origin);
                    if (destination.origin === win.location.origin && /^\/(admin|resident)\/home$/.test(destination.pathname)
                        && !destination.search && !destination.hash && !doc.hidden && !paused && !stopped) {
                        stopped = true;
                        element.textContent = 'Email verified. Opening your dashboard…';
                        win.location.replace(destination.href);
                    }
                }
            }
        } catch {
            // A temporary network failure keeps the existing resend form usable.
        } finally {
            win.clearTimeout(timeout);
            controller = null;
            schedule(delay);
        }
    }

    doc.addEventListener('visibilitychange', () => {
        win.clearTimeout(timer);
        if (doc.hidden) controller?.abort();
        else check();
    });
    win.addEventListener('pageshow', () => { paused = false; if (!stopped) check(); });
    win.addEventListener('pagehide', () => { paused = true; win.clearTimeout(timer); controller?.abort(); });
    check();
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-verification-sync]').forEach(element => {
        watchEmailVerification(element, window, document, window.fetch.bind(window));
    });
}
