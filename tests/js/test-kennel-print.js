'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const { create } = require('./lib/checks');
const { readCss } = require('./lib/css');
const qr = require('../../plugin/homeward-rescue/assets/js/qr.js');
const { check, equals } = create();
const base = '../../plugin/homeward-rescue/assets/';
const source = fs.readFileSync(require.resolve(base + 'js/kennel-print.js'), 'utf8');

async function setup(url, fallback) {
  const events = {};
  const select = (value) => ({ value, addEventListener(type, cb) { events[value] = cb; } });
  const layout = select('poster');
  const paper = select('a4');
  const button = { disabled: true, addEventListener(type, cb) { events.print = cb; } };
  const status = { hidden: false, dataset: { qrError: 'QR unavailable, use the printed link.' } };
  const image = { hidden: true, complete: true, dataset: { printQr: url, printQrFallback: fallback } };
  const controls = { querySelector: (key) => ({
    '[data-print-layout]': layout, '[data-print-paper]': paper,
    '[data-print-button]': button, '[data-print-status]': status,
  })[key] };
  let printed = false;
  let fontsReady;
  const document = {
    body: { dataset: { layout: 'half', paper: 'letter' } },
    querySelector: () => controls,
    querySelectorAll: (key) => key === '[data-print-qr]' ? [image] : image.hidden ? [] : [image],
    fonts: { ready: new Promise((resolve) => { fontsReady = resolve; }) },
  };
  vm.runInNewContext(source, { document, window: { homewardQR: qr, print() { printed = true; } } });
  check('print waits for local fonts', button.disabled);
  fontsReady();
  await new Promise((resolve) => setImmediate(resolve));
  check('print becomes available after assets settle', !button.disabled);
  events.poster();
  events.a4();
  equals('layout choice updates preview', document.body.dataset.layout, 'poster');
  equals('paper choice updates named page', document.body.dataset.paper, 'a4');
  events.print();
  check('print control opens browser print', printed);
  return { image, status };
}

(async () => {
  const url = 'https://example.test/animals/molly/?name=%22%3Cscript%3E';
  const normal = await setup(url, 'https://example.test/?p=12');
  equals('print uses the same QR encoder as sharing', normal.image.src, qr.qrSource(url));
  check('QR appears without an external service', !normal.image.hidden
    && normal.image.src.startsWith('data:image/svg+xml;') && normal.status.hidden);
  const svg = decodeURIComponent(normal.image.src.split(',')[1]);
  check('QR includes white quiet zone and black modules', svg.includes('fill="#fff"') && svg.includes('fill="#000"'));
  check('untrusted URL never enters SVG markup', !svg.includes('script') && !svg.includes(url));
  const long = await setup('https://example.test/' + 'x'.repeat(300), 'https://example.test/?p=12');
  equals('long canonical URLs use a local short permalink', long.image.src, qr.qrSource('https://example.test/?p=12'));
  const failed = await setup('x'.repeat(300), 'x'.repeat(300));
  check('QR failure leaves a clear message and printable URL', failed.image.hidden && !failed.status.hidden
    && failed.status.textContent === failed.status.dataset.qrError);
  const css = readCss(require.resolve(base + 'css/kennel-print.css'));
  check('Letter and A4 use explicit page sizes and margins',
    css.includes('@page homeward-letter{size:Letter;margin:10mm;}')
    && css.includes('@page homeward-a4{size:A4;margin:10mm;}'));
  check('half sheets contain two rows', css.includes('grid-template-rows:repeat(2,1fr)'));
  check('cards never split across sheets', css.includes('break-inside:avoid;page-break-inside:avoid'));
  check('print hides controls and forces high contrast', css.includes('.homeward-print-controls{display:none;}')
    && css.includes('.homeward-print-info h1{color:#111;}'));
  console.log('Kennel print checks passed: local QR, escaping, long URLs, controls, asset readiness and paged CSS.');
})();
