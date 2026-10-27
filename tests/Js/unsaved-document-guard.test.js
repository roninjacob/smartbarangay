import test from 'node:test';
import assert from 'node:assert/strict';
import { protectUnsavedDocument } from '../../resources/js/unsaved-document-guard.js';

function fixture() {
    const handlers = {};
    const fields = [{ value: 'Initial answer' }];
    const browser = { addEventListener: (name, callback) => { handlers[name] = callback; } };
    const form = { querySelectorAll: () => fields, addEventListener: (name, callback) => { handlers[name] = callback; } };
    return { fields, handlers, guard: protectUnsavedDocument(form, browser) };
}
test('unchanged and restored answers do not warn, edited answers do', () => {
    const { fields, handlers, guard } = fixture();
    assert.equal(guard.dirty(), false);
    fields[0].value = 'Unsaved private answer';
    let prevented = false;
    const event = { preventDefault() { prevented = true; } };
    handlers.beforeunload(event);
    assert.equal(prevented, true);
    assert.equal(event.returnValue, '');
    fields[0].value = 'Initial answer';
    assert.equal(guard.dirty(), false);
});
test('normal submission proceeds and browser-history restoration protects again', () => {
    const { fields, handlers, guard } = fixture();
    fields[0].value = 'Changed';
    handlers.submit();
    assert.equal(guard.dirty(), false);
    handlers.pageshow();
    assert.equal(guard.dirty(), true);
});
