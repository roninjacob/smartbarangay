import { chartSeries, chartMotion } from './report-chart-motion';

if (!document.querySelector('[data-print-document]')) {
    const preference = window.matchMedia('(prefers-reduced-motion: reduce)');
    document.querySelectorAll('.report-chart-grid .dashboard-panel').forEach((panel, index) => {
        const line = panel.querySelector('.report-line polyline');
        const bars = [...panel.querySelectorAll('.report-bar-track > div')];
        if (!line && !bars.length) return;
        panel.classList.add('interactive-report-chart');
        const motion = chartMotion(line, bars, preference, document);
        window.addEventListener('beforeprint', motion.stop);
        const toolbar = document.createElement('div');
        toolbar.className = 'chart-tools';
        const graphic = panel.querySelector('.report-line,.report-services');
        let displayGraphic = graphic;
        let viewport;
        if (line) {
            viewport = document.createElement('div');
            viewport.className = 'chart-viewport'; viewport.tabIndex = 0;
            viewport.setAttribute('role', 'region');
            viewport.setAttribute('aria-label', 'Chart: scroll horizontally to see all dates on small screens');
            graphic.before(viewport); viewport.append(graphic); displayGraphic = viewport;
            const hint = document.createElement('p'); hint.className = 'chart-pan-hint';
            hint.textContent = 'Swipe the chart to see more dates, or use Explore dates below.';
            viewport.after(hint);
        }
        displayGraphic.before(toolbar);
        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) { motion.play(); observer.disconnect(); }
            }, { threshold: .2 });
            observer.observe(graphic);
        } else motion.play();

        if (!line) return;
        const table = panel.querySelector('.report-chart-data table');
        if (!table) return;
        const headers = [...table.querySelectorAll('thead th')].slice(1).map((cell) => cell.textContent.trim());
        const rows = [...table.querySelectorAll('tbody tr')].map((row) => [...row.children].map((cell, i) =>
            i === 0 ? cell.textContent.trim() : `${headers[i - 1] || 'Value'}: ${cell.textContent.trim()}`).join(' · '));
        const series = chartSeries(line.getAttribute('points') || '', rows);
        if (!series.length) return;
        const explorer = document.createElement('div');
        explorer.className = 'chart-explorer'; explorer.hidden = true; explorer.id = `chart-explorer-${index}`;
        const explore = document.createElement('button');
        explore.type = 'button'; explore.className = 'btn btn-outline-secondary btn-sm'; explore.textContent = 'Explore dates';
        explore.setAttribute('aria-expanded', 'false'); explore.setAttribute('aria-controls', explorer.id);
        toolbar.append(explore);
        const label = document.createElement('label');
        label.className = 'form-label'; label.htmlFor = `chart-date-${index}`; label.textContent = 'Choose a date / time';
        const slider = document.createElement('input');
        slider.id = label.htmlFor; slider.type = 'range'; slider.className = 'form-range';
        slider.min = 0; slider.max = series.length - 1; slider.step = 1; slider.value = 0;
        const output = document.createElement('output');
        output.className = 'chart-value'; output.setAttribute('for', slider.id); output.setAttribute('aria-live', 'polite');
        const marker = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        marker.setAttribute('r', '5'); marker.setAttribute('class', 'chart-active-point'); marker.setAttribute('aria-hidden', 'true');
        marker.style.display = 'none'; line.parentElement.append(marker);
        const select = () => {
            const row = series[Number(slider.value)];
            output.textContent = row.label; slider.setAttribute('aria-valuetext', row.label);
            marker.setAttribute('cx', row.x); marker.setAttribute('cy', row.y);
            if (!explorer.hidden && viewport) {
                const scale = graphic.getBoundingClientRect().width / graphic.viewBox.baseVal.width;
                viewport.scrollLeft = Math.max(0, Math.min(viewport.scrollWidth - viewport.clientWidth, row.x * scale - viewport.clientWidth / 2));
            }
        };
        slider.addEventListener('input', select);
        explore.addEventListener('click', () => {
            explorer.hidden = !explorer.hidden;
            explore.setAttribute('aria-expanded', String(!explorer.hidden));
            explore.textContent = explorer.hidden ? 'Explore dates' : 'Hide explorer';
            marker.style.display = explorer.hidden ? 'none' : '';
            if (!explorer.hidden) select();
        });
        explorer.append(label, slider, output); displayGraphic.after(explorer);
        select();
        if (viewport && 'ResizeObserver' in window) {
            new ResizeObserver(() => { if (!explorer.hidden) select(); }).observe(viewport);
        }
    });

    // Shortcuts use headings already visible on long detail/profile pages.
    const main = document.querySelector('.app-main');
    if (main && !main.querySelector('.reservation-reports')) {
        const headings = [...main.querySelectorAll('h2[id],h3[id]')].filter((heading) => !heading.closest('.modal,[data-pdf-preview]'));
        if (headings.length >= 2) {
            const nav = document.createElement('nav'); nav.className = 'module-shortcuts'; nav.setAttribute('aria-label', 'On this page');
            const caption = document.createElement('span'); caption.className = 'module-shortcuts-label'; caption.textContent = 'On this page'; nav.append(caption);
            headings.slice(0, 8).forEach((heading) => {
                const link = document.createElement('a'); link.href = `#${heading.id}`; link.textContent = heading.textContent.trim();
                heading.tabIndex = -1;
                link.addEventListener('click', () => heading.focus({ preventScroll: true }));
                nav.append(link);
            });
            main.querySelector('.app-page-heading')?.after(nav);
        }
    }
}
