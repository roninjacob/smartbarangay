import { countUpValue, headerIsCondensed, nextCycleIndex } from './landing-helpers';

// Presentation-only behaviour for the public landing page. Content is fully visible without JS;
// under prefers-reduced-motion nothing animates and values are shown as rendered by the server.
const root = document.querySelector('[data-landing]');

if (root) {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    const canObserve = 'IntersectionObserver' in window;
    const animate = canObserve && !reduced.matches;
    if (animate) root.classList.add('lp-motion');

    // Header: transparent at the top, solid and compact after scrolling.
    const header = root.querySelector('[data-lp-header]');
    if (header) {
        const update = () => header.classList.toggle('is-condensed', headerIsCondensed(window.scrollY));
        window.addEventListener('scroll', update, { passive: true });
        update();
    }

    // Close the mobile menu after choosing an in-page link.
    const menu = root.querySelector('#public-nav');
    menu?.querySelectorAll('a[href^="#"]').forEach((link) => link.addEventListener('click', () => {
        if (menu.classList.contains('show')) root.querySelector('[data-bs-target="#public-nav"]')?.click();
    }));

    // Highlight the anchor link of the section currently in view.
    const navLinks = [...root.querySelectorAll('[data-lp-nav]')];
    if (canObserve && navLinks.length) {
        const visible = new Map();
        const sectionObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => visible.set(entry.target.id, entry.isIntersecting));
            const current = navLinks.map((link) => link.dataset.lpNav).find((id) => visible.get(id));
            navLinks.forEach((link) => link.classList.toggle('is-active', link.dataset.lpNav === current));
        }, { rootMargin: '-45% 0px -50% 0px' });
        navLinks.forEach((link) => {
            const section = document.getElementById(link.dataset.lpNav);
            if (section) sectionObserver.observe(section);
        });
    }

    if (animate) {
        // Scroll reveal, connector line and timeline fill.
        const revealObserver = new IntersectionObserver((entries) => {
            entries.forEach(({ target, isIntersecting }) => {
                if (!isIntersecting) return;
                target.classList.add('is-visible');
                revealObserver.unobserve(target);
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
        root.querySelectorAll('[data-lp-reveal],[data-lp-steps],[data-lp-timeline]').forEach((el) => revealObserver.observe(el));

        // Count up the real service count once it is in view.
        root.querySelectorAll('[data-lp-count]').forEach((el) => {
            const target = Number(el.dataset.lpCount);
            if (!Number.isFinite(target) || target <= 0) return;
            el.textContent = '0';
            const counter = new IntersectionObserver(([entry]) => {
                if (!entry.isIntersecting) return;
                counter.disconnect();
                const startedAt = performance.now();
                const tick = (now) => {
                    const progress = (now - startedAt) / 900;
                    el.textContent = String(countUpValue(target, progress));
                    if (progress < 1) requestAnimationFrame(tick);
                };
                requestAnimationFrame(tick);
            });
            counter.observe(el);
        });

        // Cycle the decorative status chip in the hero preview.
        const chip = root.querySelector('[data-lp-status-cycle]');
        const steps = [...root.querySelectorAll('.lp-card-progress li')];
        let statuses = [];
        try { statuses = JSON.parse(chip?.dataset.lpStatusCycle || '[]'); } catch { statuses = []; }
        if (chip && statuses.length > 1) {
            let index = 0;
            const show = () => {
                chip.classList.add('is-changing');
                window.setTimeout(() => {
                    chip.textContent = statuses[index].label;
                    chip.dataset.tone = statuses[index].tone;
                    steps.forEach((step, i) => step.classList.toggle('is-done', i <= index));
                    chip.classList.remove('is-changing');
                }, 220);
            };
            steps.forEach((step, i) => step.classList.toggle('is-done', i === 0));
            window.setInterval(() => {
                if (document.hidden) return;
                index = nextCycleIndex(index, statuses.length);
                show();
            }, 2600);
        }

        // Cursor spotlight on service cards, for precise pointers only.
        if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            root.querySelectorAll('[data-lp-spotlight]').forEach((card) => {
                card.addEventListener('pointermove', (event) => {
                    const rect = card.getBoundingClientRect();
                    card.style.setProperty('--lp-mx', `${event.clientX - rect.left}px`);
                    card.style.setProperty('--lp-my', `${event.clientY - rect.top}px`);
                });
            });
        }
    }
}
