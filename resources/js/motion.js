import { countUpValue, nextCycleIndex } from './landing-helpers';

// Shared motion helpers: every animated effect in the app has exactly one implementation here.
// All of them leave the server-rendered value in place when motion is reduced.

export const COUNT_DURATION = 600;

export function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

// Counts a whole number up from zero, then restores the exact server-rendered text.
export function countUp(element, target, duration = COUNT_DURATION) {
    if (!Number.isInteger(target) || target <= 0) return;
    const finalText = element.textContent;
    const startedAt = performance.now();
    element.textContent = '0';
    const tick = (now) => {
        const progress = (now - startedAt) / duration;
        element.textContent = progress >= 1 ? finalText : countUpValue(target, progress).toLocaleString('en-US');
        if (progress < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
}

// Decorative status chip that cycles through real status labels (landing and account pages only).
export function cycleStatusChip(chip, statuses, onStep = () => {}, interval = 2600) {
    if (!chip || statuses.length < 2) return;
    let index = 0;
    window.setInterval(() => {
        if (document.hidden) return;
        index = nextCycleIndex(index, statuses.length);
        chip.classList.add('is-changing');
        window.setTimeout(() => {
            chip.textContent = statuses[index].label;
            chip.dataset.tone = statuses[index].tone;
            chip.classList.remove('is-changing');
            onStep(index);
        }, 200);
    }, interval);
}

export function readStatuses(chip, attribute) {
    try { return JSON.parse(chip?.dataset[attribute] || '[]'); } catch { return []; }
}
