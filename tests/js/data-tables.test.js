import assert from 'node:assert/strict';
import test from 'node:test';
import { PAGE_SIZES, compareValues, matchesFilters, pageRange } from '../../resources/js/data-tables.js';

test('column filters combine and handle case, whitespace, and literal punctuation', () => {
    const cells = ['CFCI-210', 'Ana  Dela\nCruz', '100%_literal'];
    assert.equal(matchesFilters(cells, { 0: '210', 1: 'ANA DELA CRUZ' }), true);
    assert.equal(matchesFilters(cells, { 0: '210', 1: 'Another scholar' }), false);
    assert.equal(matchesFilters(cells, { 2: '%_' }), true);
    assert.equal(matchesFilters(cells, {}), true);
});

test('ascending and descending sort text naturally, amounts numerically, and dates chronologically', () => {
    assert.ok(compareValues('Scholar 2', 'Scholar 10') < 0);
    assert.ok(compareValues('Scholar 2', 'Scholar 10', 'desc') > 0);
    assert.ok(compareValues('₱950.00', '₱1,000.00') < 0);
    assert.ok(compareValues('100.25', '100.50') < 0);
    assert.ok(compareValues('₱1,000.00 / ₱900.00', '₱950.00 / ₱900.00') > 0);
    assert.ok(compareValues('Dec 31, 2025', 'Jan 1, 2026') < 0);
    assert.ok(compareValues('2026-01-02', '2025-12-31', 'desc') < 0);
    assert.ok(compareValues('2026-01-02T08:00:00+08:00', '2026-01-02T01:00:00Z') < 0);
    assert.equal(compareValues('ANA', 'ana'), 0);
    assert.ok(compareValues('Unconfirmed', '₱100.00', 'desc') > 0);
    assert.ok(compareValues('Not recorded', 'Jan 1, 2026') > 0);
});

test('page boundaries clamp after filtering and support an empty result', () => {
    assert.deepEqual(pageRange(25, 10, 3), { page: 3, lastPage: 3, first: 21, last: 25 });
    assert.deepEqual(pageRange(2, 10, 3), { page: 1, lastPage: 1, first: 1, last: 2 });
    assert.deepEqual(pageRange(0, 20), { page: 1, lastPage: 1, first: 0, last: 0 });
    for (const size of PAGE_SIZES) assert.equal(pageRange(205, size).last, size);
});
