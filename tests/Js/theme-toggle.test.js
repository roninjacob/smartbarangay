import test from 'node:test';
import assert from 'node:assert/strict';
import { nextTheme, normalizeTheme, preferenceFormData } from '../../resources/js/theme-toggle-helpers.js';

test('theme toggles between light and dark and unknown values fall back to light', () => {
    assert.equal(nextTheme('light'), 'dark');
    assert.equal(nextTheme('dark'), 'light');
    assert.equal(nextTheme('purple'), 'dark');
    assert.equal(normalizeTheme(null), 'light');
});

test('account save sends only the theme, the CSRF token and the PUT override', () => {
    assert.deepEqual(preferenceFormData('dark', 'abc'), { theme_preference: 'dark', _token: 'abc', _method: 'PUT' });
    assert.equal(preferenceFormData('<script>', 'abc').theme_preference, 'light');
});
