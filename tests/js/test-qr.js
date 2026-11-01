'use strict';
const assert = require('node:assert/strict');
const { createHash } = require('node:crypto');
const { qrMatrix } = require('../../plugin/homeward-rescue/assets/js/animals.js');

// The first matrix was independently verified in byte mode, level L.
// Remaining snapshots cover every supported version after porting the encoder.
// No library or network connection is needed to run these tests.
const vectors = [
  [17, '522e1a7f77cc7e57277fe1162652601d7addeeb25c0267ab9129fcd3bff0bf75'],
  [32, 'ca2d307af7345c5a694bae7cbf4d2a28618171cd9b60d21e3df30829208bf4bd'],
  [53, 'e59ca84ac41d0c4b74ecf5fa3766676deeaf6fd0d11067017d4e5d0adc9403f3'],
  [78, '1fa26995736cc56c68dc73467988808b41ec0a3404c65c5101a5f44fcb93988f'],
  [106, '59e2c79b99c970f4534b2c8b16910b394f4f6883e0c7ca91442b6b7ad961cbe0'],
  [134, 'efa22503739c0316ab562a30d1e2347738cb8afbaa1baa1aa50095f8bd54ce6d'],
  [154, '719ab4e219d1f95cd67a4069e849996b93f460a36c87e732a563dd279f87e0c6'],
  [192, '4ed730467896a0a23cd4678bc504f8ccae661b19c81da9a775b3cc22016f1420'],
  [230, 'f047ec5e64695c811262761fb7f30cc01f58f2f4f19ae1567ccaf7c7b13ca838'],
  [271, '1db3bf648a1c9d3ecf1e37f1a04b48b4695b8188ffc3ff1675cc8195f0fd8f53'],
];
vectors.forEach(([length, expected], i) => {
  const value = 'https://example.test/animals/synthetic/'.repeat(9).slice(0, length);
  const matrix = qrMatrix(value);
  assert.equal(matrix.length, (i + 1) * 4 + 17, 'smallest version at byte capacity ' + length);
  assert.equal(
    createHash('sha256').update(Buffer.from(matrix.flat())).digest('hex'),
    expected,
    'golden matrix for version ' + (i + 1)
  );
  if (i < 9) assert.equal(qrMatrix(value + 'x').length, matrix.length + 4, 'next version at capacity + 1');
});
assert.throws(() => qrMatrix('x'.repeat(272)), RangeError);
assert.throws(() => qrMatrix('é'.repeat(136)), RangeError, 'capacity is in UTF-8 bytes');
console.log('QR checks passed: ten matrix snapshots, capacity boundaries and UTF-8 limits.');
