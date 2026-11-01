'use strict';
/**
 * Shared assertion helper for the JavaScript suites.
 *
 * Every suite builds one counter with create(), uses check() for plain truthy assertions and
 * reports the total at the end, so the summary line always matches what actually ran.
 */
const assert = require('node:assert/strict');

function create() {
  const state = { count: 0 };
  return {
    /** Assert a truthy value; the label names the behaviour being protected. */
    check(label, value) {
      assert.ok(value, label);
      state.count++;
    },
    /** Assert strict deep equality between an actual and an expected value. */
    equals(label, actual, expected) {
      assert.deepStrictEqual(actual, expected, label);
      state.count++;
    },
    /** Number of assertions that have passed so far. */
    get count() {
      return state.count;
    },
  };
}

module.exports = { create };
