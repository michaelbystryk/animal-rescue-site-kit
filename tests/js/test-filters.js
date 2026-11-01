'use strict';
const assert = require('node:assert/strict');
const { matches, compare } = require('../../plugin/homeward-rescue/assets/js/animals.js');
const dog = {
  name: 'Éloise Junior',
  origin: 'local-bc',
  size: 'small',
  age: 'adult',
  sex: 'female',
  breed: 'bully-breeds',
  kids: null,
  dogs: true,
  cats: false,
  special: true,
};
let total = 0;
const expectMatch = (q, expected) => {
  assert.equal(matches(dog, new URLSearchParams(q)), expected, q);
  total++;
};
expectMatch('', true);
expectMatch('q=ELOISE', true);
expectMatch('q=Junior', true);
expectMatch('q=Scout', false);
expectMatch('origin=local-bc&size=small&age=adult&sex=female&dogs=1&special=1', true);
expectMatch('origin=local-bc&size=large', false);
expectMatch('breed=bully-breeds', true);
expectMatch('breed=terrier', false);
expectMatch('breed=bully-breeds&dogs=1&sort=newest', true);
expectMatch('breed=unknown', false);
expectMatch('kids=1', false);
expectMatch('cats=1', false);
expectMatch('dogs=1', true);
expectMatch('special=1', true);
expectMatch('origin=usa', false);
expectMatch('q=%3Cscript%3E', false);
expectMatch('age=senior', false);
expectMatch('sex=male', false);
expectMatch('cats=0', true);
expectMatch('sort=newest', true);
expectMatch('dogs=1&sort=longest-waiting', true);
for (const field of ['kids', 'dogs', 'cats']) {
  for (const value of [true, false, null, undefined, '', 'unknown', 'true', 0, 1]) {
    assert.equal(
      matches({ ...dog, [field]: value }, new URLSearchParams(`${field}=1`)),
      value === true,
      `${field}: ${value}`
    );
    total++;
  }
}
const dated = [
  { id: 1, first_synced: 10, waiting_since: 30 },
  { id: 2, first_synced: 30, waiting_since: 20 },
  { id: 3, first_synced: 30, waiting_since: 20 },
  { id: 4 },
];
for (const [sort, ids] of [
  ['newest', [3, 2, 1, 4]],
  ['longest-waiting', [2, 3, 1, 4]],
  ['', [1, 2, 3, 4]],
  ['invalid', [1, 2, 3, 4]],
]) {
  assert.deepEqual(
    [...dated].sort((a, b) => compare(a, b, sort)).map((d) => d.id),
    ids,
    sort
  );
  total++;
}
console.log(`${total} filter/sort checks passed.`);
