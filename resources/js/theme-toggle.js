import { THEME_STORAGE_KEY, nextTheme, normalizeTheme, preferenceFormData } from './theme-toggle-helpers';

// Light/dark toggle on the public and account pages.
// Signed-in verified users save to their account preference; guests save in this browser.
const toggles = [...document.querySelectorAll('[data-theme-toggle]')];

function applyTheme(theme) {
    document.documentElement.setAttribute('data-bs-theme', theme);
    toggles.forEach((button) => button.setAttribute('aria-pressed', String(theme === 'dark')));
}

// The head script may already have applied a browser-saved theme; reflect it on the buttons.
if (toggles.length) applyTheme(normalizeTheme(document.documentElement.getAttribute('data-bs-theme')));

toggles.forEach((button) => {
    button.addEventListener('click', async () => {
        const previous = normalizeTheme(document.documentElement.getAttribute('data-bs-theme'));
        const theme = nextTheme(previous);
        applyTheme(theme);
        const saveUrl = button.dataset.themeSaveUrl;
        if (!saveUrl) {
            try { window.localStorage.setItem(THEME_STORAGE_KEY, theme); } catch { /* storage unavailable: session-only */ }
            return;
        }
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        try {
            // Following the redirect lets the settings page consume its one-time "saved" message in the
            // background, so it does not reappear on the next page the user opens.
            const response = await fetch(saveUrl, {
                method: 'POST', credentials: 'same-origin', redirect: 'follow',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'text/html' },
                body: new URLSearchParams(preferenceFormData(theme, token)),
            });
            if (!response.ok || !response.redirected) applyTheme(previous);
        } catch {
            applyTheme(previous);
        }
    });
});
