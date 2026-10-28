import test from 'node:test';
import assert from 'node:assert/strict';
import { paperSize, paperSummary } from '../../resources/js/paper-size.js';

test('detects actual PDF sizes including Legal versus Philippine long paper', () => {
    assert.equal(paperSize(595.276, 841.89).name, 'A4');
    assert.equal(paperSize(612, 792).name, 'Letter');
    assert.equal(paperSize(612, 1008).name, 'Legal');
    assert.equal(paperSize(612, 936).name, 'Long / Folio');
    assert.equal(paperSize(792, 612).orientation, 'Landscape');
    assert.equal(paperSize(500, 500).name, 'Custom');
});
test('mixed-size guidance preserves actual dimensions without resizing documents', () => {
    assert.match(paperSummary([paperSize(612, 792), paperSize(612, 1008)]), /Mixed paper sizes/);
    assert.doesNotMatch(paperSummary([paperSize(612, 792), paperSize(792, 612)]), /Mixed/);
    for (const n of [0, -1, NaN, Infinity]) assert.throws(() => paperSize(n, 792));
});
