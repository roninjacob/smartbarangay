const form = document.querySelector('[data-preferences-form]');

if (form) {
    const toggle = form.querySelector('#dark-mode');
    const feedback = form.querySelector('[data-theme-feedback]');
    const savedTheme = document.documentElement.getAttribute('data-bs-theme');

    toggle.addEventListener('change', () => {
        const theme = toggle.checked ? 'dark' : 'light';
        document.documentElement.setAttribute('data-bs-theme', theme);
        feedback.textContent = theme === savedTheme
            ? `Saved appearance: ${theme === 'dark' ? 'Dark' : 'Light'}.`
            : 'Preview only. Save appearance to keep this change.';
    });

    window.addEventListener('pageshow', () => {
        toggle.checked = savedTheme === 'dark';
        document.documentElement.setAttribute('data-bs-theme', savedTheme);
        feedback.textContent = `Saved appearance: ${savedTheme === 'dark' ? 'Dark' : 'Light'}.`;
    });
}
