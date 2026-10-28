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
        const graphic = panel.querySelector('.report-line,.report-services');
        let displayGraphic = graphic;
        let viewport;
        if (line) {
            viewport = document.createElement('div');
            viewport.className = 'chart-viewport'; viewport.tabIndex = 0;
            viewport.setAttribute('role', 'region');
            viewport.setAttribute('aria-label', 'Reporting period chart');
            graphic.before(viewport); viewport.append(graphic); displayGraphic = viewport;
        }
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
        explorer.className = 'chart-explorer'; explorer.id = `chart-explorer-${index}`;
        // Preserve every source coordinate. The area and grid are visual context only.
        const svgNode = (tag, attributes) => {
            const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
            Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, value));
            return node;
        };
        const gradientId = `report-area-${index}`;
        const defs = svgNode('defs', {});
        const gradient = svgNode('linearGradient', { id: gradientId, x1: '0', y1: '0', x2: '0', y2: '1' });
        gradient.append(svgNode('stop', { offset: '0%', 'stop-color': '#4485e0', 'stop-opacity': '.32' }),
            svgNode('stop', { offset: '100%', 'stop-color': '#4485e0', 'stop-opacity': '.03' }));
        defs.append(gradient); graphic.prepend(defs);
        [65, 115, 165, 215].forEach((y) => line.before(svgNode('line', {
            x1: '55', y1: String(y), x2: '650', y2: String(y), class: 'chart-grid-line', 'aria-hidden': 'true',
        })));
        const outline = series.map(({ x, y }) => `${x},${y}`).join(' ');
        line.before(svgNode('polygon', { points: `${series[0].x},215 ${outline} ${series.at(-1).x},215`,
            fill: `url(#${gradientId})`, 'aria-hidden': 'true' }));
        series.filter(({ y }) => y < 215).forEach(({ x, y, label }) => {
            const dot = svgNode('circle', { cx: String(x), cy: String(y), r: '3', class: 'chart-data-point', 'aria-hidden': 'true' });
            const title = svgNode('title', {}); title.textContent = label; dot.append(title); graphic.append(dot);
        });
        const label = document.createElement('label');
        label.className = 'form-label'; label.htmlFor = `chart-date-${index}`; label.textContent = 'Explore the reporting period';
        const slider = document.createElement('input');
        slider.id = label.htmlFor; slider.type = 'range'; slider.className = 'form-range';
        slider.min = 0; slider.max = series.length - 1; slider.step = 1;
        slider.value = series.reduce((best, row, i) => row.y < series[best].y ? i : best, 0);
        const output = document.createElement('output');
        output.className = 'chart-value'; output.setAttribute('for', slider.id); output.setAttribute('aria-live', 'polite');
        const marker = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        marker.setAttribute('r', '5'); marker.setAttribute('class', 'chart-active-point'); marker.setAttribute('aria-hidden', 'true');
        line.parentElement.append(marker);
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
        explorer.append(label, slider, output); displayGraphic.after(explorer);
        select();
        if (viewport && 'ResizeObserver' in window) {
            new ResizeObserver(() => { if (!explorer.hidden) select(); }).observe(viewport);
        }
    });

    // Shortcuts use headings already visible on long detail/profile pages.
    const main = document.querySelector('.app-main');
    if (main && !main.querySelector('.reservation-reports,.dashboard-welcome')) {
        const headings = [...new Map([...main.querySelectorAll('h2[id],h3[id]')]
            .filter((heading) => !heading.closest('.modal,[data-pdf-preview],article'))
            .map((heading) => [heading.id, heading])).values()];
        if (headings.length >= 2) {
            const labels = { 'admin-request-heading': 'Request details', 'reservation-detail-heading': 'Request details', 'qr-lifecycle-heading': 'QR ticket',
                'checkin-heading': 'Arrival', 'followup-heading': 'Follow-up', 'payment-heading': 'Payment',
                'processing-heading': 'Processing', 'activity-heading': 'Activity', 'certificate-heading': 'Certificate' };
            const nav = document.createElement('nav'); nav.className = 'module-shortcuts'; nav.setAttribute('aria-label', 'On this page');
            const caption = document.createElement('span'); caption.className = 'module-shortcuts-label'; caption.textContent = 'Jump to section'; nav.append(caption);
            const items = document.createElement('div'); items.className = 'section-navigation-items'; nav.append(items);
            headings.slice(0, 8).forEach((heading) => {
                const link = document.createElement('a'); link.href = `#${heading.id}`;
                link.textContent = labels[heading.id] || heading.textContent.trim();
                link.setAttribute('aria-label', `${link.textContent}: ${heading.textContent.trim()}`);
                heading.tabIndex = -1;
                link.addEventListener('click', () => heading.focus({ preventScroll: true }));
                items.append(link);
            });
            // The server-rendered shortcuts remain the no-JavaScript fallback.
            const fallback = main.querySelector('.request-section-nav');
            if (fallback) fallback.hidden = true;
            main.classList.add('has-section-navigation');
            if (main.querySelector('.reservation-wizard')) {
                main.classList.add('has-reservation-sections');
                const back = main.querySelector('.reservation-details-back');
                if (back) main.querySelector('.app-page-heading')?.append(back);
            }
            main.querySelector('.app-page-heading')?.after(nav);
        }
    }
}
