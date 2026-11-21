import { headerIsCondensed } from './landing-helpers';
import { countUp, prefersReducedMotion } from './motion';

// Presentation-only behaviour for the authenticated shell. The server renders the final layout;
// nothing here changes the size or position of the topbar or sidebar.

// Topbar: a background fades in after scrolling. Only real scroll events toggle it, never page load.
const topbar = document.querySelector('[data-app-topbar]');
if (topbar) {
    window.addEventListener('scroll', () => topbar.classList.toggle('is-scrolled', headerIsCondensed(window.scrollY, 4)), { passive: true });
}

// Sidebar: remember the scroll position across pages (restored inline before paint) and fade the bottom
// edge only while more items are below.
const navScroll = document.querySelector('[data-app-sidebar-scroll]');
if (navScroll) {
    const updateFade = () => navScroll.classList.toggle('has-more', navScroll.scrollTop + navScroll.clientHeight < navScroll.scrollHeight - 2);
    navScroll.addEventListener('scroll', () => {
        updateFade();
        try { sessionStorage.setItem('sb-nav-scroll', String(Math.round(navScroll.scrollTop))); } catch { /* storage unavailable */ }
    }, { passive: true });
    window.addEventListener('resize', updateFade, { passive: true });
    updateFade();
}

// Dashboards: a short intro (staggered rise, count-up, bar fill) at most once per browser session.
const dashboard = document.querySelector('.app-main .dash-hero')?.closest('.app-main');
if (dashboard && !prefersReducedMotion()) {
    let firstVisit = true;
    try {
        firstVisit = !sessionStorage.getItem('sb-dash-intro');
        sessionStorage.setItem('sb-dash-intro', '1');
    } catch { /* storage unavailable: play once for this page */ }
    if (firstVisit) {
        dashboard.classList.add('dash-motion');
        dashboard.querySelectorAll('[data-count-to]').forEach((element) => countUp(element, Number(element.dataset.countTo)));
        dashboard.querySelectorAll('[data-status-bars]').forEach((bars) => {
            requestAnimationFrame(() => requestAnimationFrame(() => bars.classList.add('is-filled')));
        });
    }
}
