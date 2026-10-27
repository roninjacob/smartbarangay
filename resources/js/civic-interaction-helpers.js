export function filePresentation(file) {
    const extension = file.name.split('.').pop().toLowerCase();
    const previewTypes = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp' };
    return {
        name: file.name,
        size: file.size < 1024 * 1024 ? `${Math.max(1, Math.ceil(file.size / 1024))} KB` : `${(file.size / (1024 * 1024)).toFixed(1)} MB`,
        extension: extension.length <= 5 ? extension.toUpperCase() : 'FILE',
        image: previewTypes[extension] === file.type && file.size <= 5 * 1024 * 1024,
    };
}

export function landingMotion(hero, control, preference, browserDocument) {
    let paused = false;
    const update = () => {
        const reduced = preference.matches;
        hero.dataset.motionEnabled = String(!reduced);
        hero.dataset.motionPaused = String(paused || reduced || browserDocument.hidden);
        control.hidden = reduced;
        control.setAttribute('aria-pressed', String(paused));
        control.textContent = paused ? 'Resume page animation' : 'Pause page animation';
    };
    control.addEventListener('click', () => { paused = !paused; update(); });
    preference.addEventListener('change', update);
    browserDocument.addEventListener('visibilitychange', update);
    update();
}
