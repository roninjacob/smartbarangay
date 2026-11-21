// Pure helpers for the public landing page; kept DOM-free so they can be unit tested.

export function nextCycleIndex(index, length) {
    if (!Number.isInteger(length) || length <= 0) return 0;
    return (index + 1) % length;
}

// Ease-out count-up; always lands exactly on the real target value.
export function countUpValue(target, progress) {
    const total = Math.max(0, Math.floor(Number(target) || 0));
    const clamped = Math.min(1, Math.max(0, progress));
    if (clamped >= 1) return total;
    return Math.round(total * (1 - (1 - clamped) ** 3));
}

export function headerIsCondensed(scrollY, threshold = 12) {
    return scrollY > threshold;
}
