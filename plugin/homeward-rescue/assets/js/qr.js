/* Shared local QR encoder for animal sharing and kennel printing. */
(function () {
  'use strict';
  // SPDX-License-Identifier: GPL-2.0-or-later
  // Dependency-free QR Model 2, byte mode, level L, versions 1 through 10 (up to 271 bytes).
  // Keep the encoder self-contained so its output and minified size can be checked offline.
  const qrMatrix = (value) => {
    const bytes = new TextEncoder().encode(value);
    const blocks = [
      [19],
      [34],
      [55],
      [80],
      [108],
      [68, 68],
      [78, 78],
      [97, 97],
      [116, 116],
      [68, 68, 69, 69],
    ];
    const parity = [7, 10, 15, 20, 26, 18, 20, 24, 30, 18];
    const version =
      blocks.findIndex(
        (group, i) => 4 + (i < 9 ? 8 : 16) + bytes.length * 8 <= group.reduce((a, b) => a + b) * 8
      ) + 1;
    if (!version) throw new RangeError('QR payload too long');
    const lengths = blocks[version - 1],
      degree = parity[version - 1];
    const capacity = lengths.reduce((a, b) => a + b) * 8;
    const bits = [];
    const put = (n, count) => {
      for (let i = count - 1; i >= 0; i--) bits.push((n >>> i) & 1);
    };
    put(4, 4);
    put(bytes.length, version < 10 ? 8 : 16);
    bytes.forEach((byte) => put(byte, 8));
    put(0, Math.min(4, capacity - bits.length));
    while (bits.length % 8) bits.push(0);
    for (let pad = 0; bits.length < capacity; pad++) put(pad % 2 ? 17 : 236, 8);
    const data = Array.from({ length: capacity / 8 }, (_, i) =>
      bits.slice(i * 8, i * 8 + 8).reduce((a, b) => a * 2 + b, 0)
    );
    const multiply = (a, b) => {
      let result = 0;
      for (; b; b >>>= 1) {
        if (b & 1) result ^= a;
        a = (a << 1) ^ (a & 128 ? 285 : 0);
      }
      return result;
    };
    let generator = [1],
      root = 1;
    for (let i = 0; i < degree; i++) {
      const next = Array(generator.length + 1).fill(0);
      generator.forEach((v, j) => {
        next[j] ^= v;
        next[j + 1] ^= multiply(v, root);
      });
      generator = next;
      root = multiply(root, 2);
    }
    let offset = 0;
    const groups = lengths.map((length) => {
      const chunk = data.slice(offset, (offset += length)),
        remainder = Array(degree).fill(0);
      chunk.forEach((byte) => {
        const factor = byte ^ remainder.shift();
        remainder.push(0);
        for (let i = 0; i < degree; i++) remainder[i] ^= multiply(generator[i + 1], factor);
      });
      return { chunk, remainder };
    });
    const codewords = [];
    for (let i = 0; i < Math.max(...lengths); i++)
      groups.forEach((g) => {
        if (i < g.chunk.length) codewords.push(g.chunk[i]);
      });
    for (let i = 0; i < degree; i++) groups.forEach((g) => codewords.push(g.remainder[i]));
    const size = version * 4 + 17;
    const grid = Array.from({ length: size }, () => Array(size).fill(false));
    const reserved = grid.map((row) => row.slice());
    const set = (x, y, dark) => {
      grid[y][x] = !!dark;
      reserved[y][x] = true;
    };
    const finder = (cx, cy) => {
      for (let y = -4; y <= 4; y++)
        for (let x = -4; x <= 4; x++) {
          if (cx + x < 0 || cy + y < 0 || cx + x >= size || cy + y >= size) continue;
          const d = Math.max(Math.abs(x), Math.abs(y));
          set(cx + x, cy + y, d !== 2 && d !== 4);
        }
    };
    for (let i = 0; i < size; i++) {
      set(6, i, i % 2 === 0);
      set(i, 6, i % 2 === 0);
    }
    finder(3, 3);
    finder(size - 4, 3);
    finder(3, size - 4);
    const centers = version === 1 ? [] : version < 7 ? [6, size - 7] : [6, version * 2 + 8, size - 7];
    centers.forEach((cx, i) =>
      centers.forEach((cy, j) => {
        if ((!i && !j) || (!i && j === centers.length - 1) || (!j && i === centers.length - 1)) return;
        for (let y = -2; y <= 2; y++)
          for (let x = -2; x <= 2; x++) set(cx + x, cy + y, Math.max(Math.abs(x), Math.abs(y)) !== 1);
      })
    );
    const format = (mask) => {
      const value = 8 | mask;
      let remainder = value;
      for (let i = 0; i < 10; i++) remainder = (remainder << 1) ^ ((remainder >>> 9) * 0x537);
      const code = ((value << 10) | remainder) ^ 0x5412;
      const bit = (i) => (code >>> i) & 1;
      for (let i = 0; i < 6; i++) set(8, i, bit(i));
      set(8, 7, bit(6));
      set(8, 8, bit(7));
      set(7, 8, bit(8));
      for (let i = 9; i < 15; i++) set(14 - i, 8, bit(i));
      for (let i = 0; i < 8; i++) set(size - 1 - i, 8, bit(i));
      for (let i = 8; i < 15; i++) set(8, size - 15 + i, bit(i));
      set(8, size - 8, true);
    };
    format(0);
    if (version >= 7) {
      let remainder = version;
      for (let i = 0; i < 12; i++) remainder = (remainder << 1) ^ ((remainder >>> 11) * 0x1f25);
      const code = (version << 12) | remainder;
      for (let i = 0; i < 18; i++) {
        const a = size - 11 + (i % 3),
          b = Math.floor(i / 3);
        set(a, b, (code >>> i) & 1);
        set(b, a, (code >>> i) & 1);
      }
    }
    let bitIndex = 0;
    for (let right = size - 1; right >= 1; right -= 2) {
      if (right === 6) right--;
      for (let step = 0; step < size; step++)
        for (let col = 0; col < 2; col++) {
          const x = right - col,
            y = ((right + 1) & 2) === 0 ? size - 1 - step : step;
          if (reserved[y][x]) continue;
          grid[y][x] = !!((codewords[bitIndex >>> 3] >>> (7 - (bitIndex & 7))) & 1);
          bitIndex++;
        }
    }
    const masks = [
      (x, y) => (x + y) % 2 === 0,
      (x, y) => y % 2 === 0,
      (x) => x % 3 === 0,
      (x, y) => (x + y) % 3 === 0,
      (x, y) => (Math.floor(y / 2) + Math.floor(x / 3)) % 2 === 0,
      (x, y) => ((x * y) % 2) + ((x * y) % 3) === 0,
      (x, y) => (((x * y) % 2) + ((x * y) % 3)) % 2 === 0,
      (x, y) => (((x + y) % 2) + ((x * y) % 3)) % 2 === 0,
    ];
    let best,
      lowest = Infinity;
    masks.forEach((mask, index) => {
      format(index);
      const candidate = grid.map((row, y) =>
        row.map((dark, x) => (reserved[y][x] ? dark : dark !== mask(x, y)))
      );
      let score = 0,
        darkCount = 0;
      for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
          if (candidate[y][x]) darkCount++;
          if (
            x &&
            y &&
            candidate[y][x] === candidate[y][x - 1] &&
            candidate[y][x] === candidate[y - 1][x] &&
            candidate[y][x] === candidate[y - 1][x - 1]
          )
            score += 3;
        }
        [candidate[y], candidate.map((row) => row[y])].forEach((line) => {
          let run = 1;
          for (let i = 1; i < size; i++) {
            run = line[i] === line[i - 1] ? run + 1 : 1;
            if (run === 5) score += 3;
            else if (run > 5) score++;
          }
          const pattern = line.map(Number).join('');
          for (let i = 0; i <= size - 11; i++)
            if (['00001011101', '10111010000'].includes(pattern.slice(i, i + 11))) score += 40;
        });
      }
      score += Math.floor(Math.abs(darkCount * 20 - size * size * 10) / (size * size)) * 10;
      if (score < lowest) {
        lowest = score;
        best = candidate;
      }
    });
    return best;
  };
  // A four-module quiet zone keeps the local SVG readable when printed.
  const qrSource = (value) => {
    const matrix = qrMatrix(value),
      size = matrix.length + 8;
    const path = matrix
      .flatMap((row, y) => row.map((dark, x) => (dark ? `M${x + 4},${y + 4}h1v1h-1z` : '')))
      .join('');
    const svg =
      `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}"` +
      ` width="${size * 8}" height="${size * 8}" shape-rendering="crispEdges">` +
      `<path fill="#fff" d="M0 0h${size}v${size}H0z"/><path fill="#000" d="${path}"/></svg>`;
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  };
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { qrMatrix, qrSource };
  } else {
    window.homewardQR = { qrMatrix, qrSource };
  }
})();
