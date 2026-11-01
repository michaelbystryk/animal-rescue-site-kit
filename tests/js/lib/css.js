'use strict';
/**
 * Read a stylesheet in a compact canonical form so assertions do not depend on how it is laid out.
 * Comments are dropped and the whitespace around braces, colons, semicolons and commas is removed.
 */
const fs = require('node:fs');

function compactCss(source) {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/\s+/g, ' ')
    .replace(/\s*([{};,])\s*/g, '$1')
    .replace(/:\s+/g, ':')
    .trim();
}

function readCss(file) {
  return compactCss(fs.readFileSync(file, 'utf8'));
}

module.exports = { compactCss, readCss };
