import test from 'node:test';
import assert from 'node:assert/strict';
import { countUpValue, headerIsCondensed, nextCycleIndex } from '../../resources/js/landing-helpers.js';

test('status preview cycles through every label and wraps around', () => {
    assert.deepEqual([0, 1, 2, 3].map((i) => nextCycleIndex(i, 4)), [1, 2, 3, 0]);
    assert.equal(nextCycleIndex(3, 0), 0);
});

test('count-up starts at zero, rises monotonically and ends on the exact real value', () => {
    const values = [0, 0.25, 0.5, 0.75, 1].map((p) => countUpValue(7, p));
    assert.equal(values[0], 0);
    assert.equal(values.at(-1), 7);
    values.slice(1).forEach((value, i) => assert.ok(value >= values[i]));
    assert.equal(countUpValue(7, 3), 7);
    assert.equal(countUpValue('bad', 1), 0);
});

test('header condenses only after scrolling past the threshold', () => {
    assert.equal(headerIsCondensed(0), false);
    assert.equal(headerIsCondensed(12), false);
    assert.equal(headerIsCondensed(40), true);
});
