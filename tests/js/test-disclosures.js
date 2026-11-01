/* Offline height-animation checks for filters and profile disclosures. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../plugin/homeward-rescue/assets/js/animals.js'), 'utf8');
const checker = require('./lib/checks').create();
const { check } = checker;
function setup({ reduced = false, animate = true, marked = false } = {}) {
  const summary = {
    offsetHeight: 44,
    events: {},
    addEventListener(key, fn) {
      this.events[key] = fn;
    },
  };
  const classes = new Set();
  const runs = [];
  const details = {
    dataset: marked ? { homewardSlide: '1' } : {},
    style: {},
    open: false,
    scrollHeight: 248,
    classList: {
      add: (key) => classes.add(key),
      remove: (key) => classes.delete(key),
      contains: (key) => classes.has(key),
    },
    querySelector: () => summary,
    getBoundingClientRect: () => ({ height: details.open ? 250 : 50 }),
  };
  if (animate)
    details.animate = (frames, options) => {
      const run = {
        frames,
        options,
        cancel() {
          this.cancelled = true;
          if (this.oncancel) this.oncancel();
        },
      };
      runs.push(run);
      return run;
    };
  const document = {
    querySelectorAll: (selector) =>
      selector === '.homeward-animals-extra, .homeward-animal-more' ? [details] : [],
  };
  const motion = { matches: reduced };
  const window = { matchMedia: () => motion, addEventListener() {} };
  const getComputedStyle = (node) =>
    node === summary
      ? { marginTop: '1px', marginBottom: '1px' }
      : { paddingTop: '1px', paddingBottom: '1px', borderTopWidth: '1px', borderBottomWidth: '1px' };
  vm.runInNewContext(source, { document, window, getComputedStyle });
  const click = () => {
    let prevented = false;
    if (summary.events.click)
      summary.events.click({
        preventDefault() {
          prevented = true;
        },
      });
    return prevented;
  };
  return { details, summary, runs, click, motion };
}
const normal = setup();
check('opening uses height animation', normal.click() && normal.details.open && normal.runs.length === 1);
check('opening measures actual content and borders', normal.runs[0].frames.height.join(',') === '50px,250px');
check('opening uses source timing', normal.runs[0].options.duration === 340);
check('animation clips content', normal.details.style.overflow === 'hidden');
normal.runs[0].onfinish();
check(
  'open finish clears clipping and keeps open',
  normal.details.style.overflow === '' && normal.details.open
);
normal.click();
check(
  'closing leaves content visible until finish',
  normal.details.open && normal.details.classList.contains('is-closing')
);
check(
  'closed height includes summary, margins, padding and borders',
  normal.runs[1].frames.height.join(',') === '250px,50px'
);
check('closing uses source timing', normal.runs[1].options.duration === 260);
normal.runs[1].onfinish();
check(
  'closing finishes at native closed state',
  !normal.details.open &&
    !normal.details.classList.contains('is-closing') &&
    normal.details.style.overflow === ''
);
normal.click();
normal.click();
normal.click();
check('rapid clicks cancel previous animations', normal.runs[2].cancelled && normal.runs[3].cancelled);
normal.runs[4].onfinish();
check(
  'reversing close reopens cleanly',
  normal.details.open &&
    !normal.details.classList.contains('is-closing') &&
    normal.details.style.overflow === ''
);
normal.motion.matches = true;
check('changed reduced-motion preference keeps native toggle', !normal.click() && normal.runs.length === 5);
const reduced = setup({ reduced: true });
check(
  'reduced motion never intercepts toggle or animates',
  !reduced.click() && !reduced.runs.length && reduced.details.style.overflow === undefined
);
const unsupported = setup({ animate: false });
check('unsupported browsers retain native toggle', !unsupported.click());
const marked = setup({ marked: true });
check('already enhanced disclosures are not bound twice', !marked.summary.events.click);
console.log(`${checker.count} disclosure animation checks passed.`);
