const form = document.querySelector('[data-preferences-form]');

if (form) {
    const options = [...form.querySelectorAll('input[name="theme_preference"]')];
    const feedback = form.querySelector('[data-theme-feedback]');
    const savedTheme = document.documentElement.getAttribute('data-bs-theme');
    const label = (theme) => (theme === 'dark' ? 'Dark' : 'Light');

    options.forEach((option) => option.addEventListener('change', () => {
        if (!option.checked) return;
        document.documentElement.setAttribute('data-bs-theme', option.value);
        feedback.textContent = option.value === savedTheme
            ? `Saved appearance: ${label(savedTheme)}.`
            : 'Preview only. Save appearance to keep this change.';
    }));

    // Returning through the back/forward cache restores the saved theme, not an unsaved preview.
    window.addEventListener('pageshow', () => {
        options.forEach((option) => { option.checked = option.value === savedTheme; });
        document.documentElement.setAttribute('data-bs-theme', savedTheme);
        feedback.textContent = `Saved appearance: ${label(savedTheme)}.`;
    });
}
