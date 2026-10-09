// Prevent repeat submissions after native browser validation succeeds.
document.querySelectorAll('[data-service-submit]').forEach((form) => {
    if (form.hasAttribute('data-ready-modal')) return;
    const button = form.querySelector('button[type="submit"]');
    if (!button) return;
    const originalLabel = button.textContent;
    form.addEventListener('submit', () => {
        button.disabled = true;
        button.textContent = button.dataset.savingLabel || 'Updating…';
        form.setAttribute('aria-busy', 'true');
    });
    window.addEventListener('pageshow', () => {
        button.disabled = false;
        button.textContent = originalLabel;
        form.removeAttribute('aria-busy');
    });
});
