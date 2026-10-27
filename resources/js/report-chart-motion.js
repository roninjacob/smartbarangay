export function chartSeries(points, rows) {
    const raw = points.trim() ? points.trim().split(/\s+/) : [];
    if (!raw.length || raw.length !== rows.length) return [];
    if (raw.some((point) => point.split(',').some((value) => value.trim() === ''))) return [];
    const coordinates = raw.map((point) => point.split(',').map(Number));
    if (coordinates.some((pair) => pair.length !== 2 || pair.some((value) => !Number.isFinite(value)))) return [];
    return coordinates.map(([x, y], index) => ({ x, y, label: rows[index] }));
}

// Animations never change source geometry, widths, monetary values or labels.
export function chartMotion(line, bars, preference, browserDocument) {
    let active = [];
    const stop = () => { active.forEach((animation) => animation.cancel()); active = []; };
    const play = () => {
        stop();
        if (preference.matches || browserDocument.hidden) return;
        if (line?.animate) {
            let length = 0;
            try { length = line.getTotalLength(); } catch { /* Keep the original visible geometry. */ }
            if (Number.isFinite(length) && length > 0) {
                active.push(line.animate([
                    { strokeDasharray: `${length} ${length}`, strokeDashoffset: length },
                    { strokeDasharray: `${length} ${length}`, strokeDashoffset: 0 },
                ], { duration: 1050, easing: 'cubic-bezier(.22,.65,.3,1)' }));
            }
        }
        bars.forEach((bar, index) => {
            if (bar.animate) active.push(bar.animate([{ transform: 'scaleX(0)' }, { transform: 'scaleX(1)' }],
                { fill: 'backwards', duration: 700, delay: Math.min(index * 65, 260), easing: 'cubic-bezier(.22,.65,.3,1)' }));
        });
    };
    preference.addEventListener('change', stop);
    browserDocument.addEventListener('visibilitychange', () => { if (browserDocument.hidden) stop(); });
    return { play, stop };
}
