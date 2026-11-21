// Pure helpers for the light/dark toggle; DOM-free so they can be unit tested.
export const THEME_STORAGE_KEY = 'smartbarangay-theme';

export function normalizeTheme(value) {
    return value === 'dark' ? 'dark' : 'light';
}

export function nextTheme(current) {
    return normalizeTheme(current) === 'dark' ? 'light' : 'dark';
}

// Only the theme value, the CSRF token and the PUT override are sent; the server accepts nothing else.
export function preferenceFormData(theme, token) {
    return { theme_preference: normalizeTheme(theme), _token: String(token ?? ''), _method: 'PUT' };
}
