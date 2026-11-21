import { countUpValue, headerIsCondensed } from './landing-helpers';

// Presentation-only enhancements for the authenticated shell and dashboards.
// Values are rendered by the server; without JS or under reduced motion they simply stay as rendered.
const topbar = document.querySelector('[data-app-topbar]');
if (topbar) {
    const update = () => topbar.classList.toggle('is-scrolled', headerIsCondensed(window.scrollY, 4));
    window.addEventListener('scroll', update, { passive: true });
    update();
}

const dashboard = document.querySelector('.app-main .dash-hero')?.closest('.app-main');
const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

if (dashboard && !reduced) {
    dashboard.classList.add('dash-motion');

    // Count up whole-number values (KPI cards and the "scheduled today" figure).
    dashboard.querySelectorAll('[data-count-to]').forEach((element) => {
        const target = Number(element.dataset.countTo);
        if (!Number.isInteger(target) || target <= 0) return;
        const finalText = element.textContent;
        const startedAt = performance.now();
        const tick = (now) => {
            const progress = (now - startedAt) / 900;
            element.textContent = progress >= 1 ? finalText : countUpValue(target, progress).toLocaleString('en-US');
            if (progress < 1) requestAnimationFrame(tick);
        };
        element.textContent = '0';
        requestAnimationFrame(tick);
    });

    // Fill the status bars after the first paint.
    dashboard.querySelectorAll('[data-status-bars]').forEach((bars) => {
        requestAnimationFrame(() => requestAnimationFrame(() => bars.classList.add('is-filled')));
    });
}
