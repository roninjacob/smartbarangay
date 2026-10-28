export function paperSize(width, height) {
    if (![width, height].every((n) => Number.isFinite(n) && n > 0)) throw new Error('Invalid PDF page dimensions');
    const w = width * 25.4 / 72;
    const h = height * 25.4 / 72;
    const [short, long] = [w, h].sort((a, b) => a - b);
    const formats = [['A4', 210, 297], ['Letter', 215.9, 279.4], ['Legal', 215.9, 355.6], ['Long / Folio', 215.9, 330.2], ['A5', 148, 210]];
    const name = formats.find(([, a, b]) => Math.abs(short - a) <= 1 && Math.abs(long - b) <= 1)?.[0] || 'Custom';
    const orientation = w > h ? 'Landscape' : 'Portrait';
    return { name, orientation, width: w, height: h, key: `${short.toFixed(1)}x${long.toFixed(1)}`,
        label: `${name} · ${orientation} · ${w.toFixed(1)} × ${h.toFixed(1)} mm` };
}

export function paperSummary(pages) {
    const sizes = [...new Map(pages.map((page) => [page.key, page])).values()];
    return `${sizes.length > 1 ? 'Mixed paper sizes — select matching paper for each page: ' : 'Paper: '}${sizes.map((page) => page.name === 'Custom' ? page.label : `${page.name} (${page.width.toFixed(1)} × ${page.height.toFixed(1)} mm)`).join('; ')}`;
}
