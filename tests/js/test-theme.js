/* Offline interaction checks. Core navigation still needs real browser QA. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../theme/homeward/assets/js/theme.js'), 'utf8');
const checker = require('./lib/checks').create();
const { check } = checker;
function button(dataset = {}) {
  return {
    dataset,
    hidden: true,
    disabled: false,
    textContent: '',
    attributes: {},
    events: {},
    setAttribute(key, value) {
      this.attributes[key] = value;
    },
    addEventListener(type, callback) {
      this.events[type] = callback;
    },
    click() {
      return this.events.click();
    },
  };
}
function setup({ reduced = false, logo = true, video = true, secure = true, rejectCopy = false } = {}) {
  const classes = new Set();
  const root = {
    classList: {
      add: (value) => classes.add(value),
      toggle(value, enabled) {
        if (enabled) classes.add(value);
        else classes.delete(value);
      },
    },
  };
  const preference = {
    matches: reduced,
    addEventListener(type, callback) {
      this.change = callback;
    },
  };
  const motion = button({
    pauseLabel: 'Pause motion',
    resumeLabel: 'Resume motion',
    reducedLabel: 'Motion off',
  });
  const secondMotion = button(motion.dataset);
  const status = { textContent: '' };
  const statusTwo = { textContent: '' };
  const copy = button({
    homewardCopy: 'donations@example.test',
    success: 'Example email copied.',
    failure: 'Select and copy the address above.',
  });
  const copyTwo = button({ ...copy.dataset, homewardCopy: 'other@example.test' });
  copy.closest = () => ({ querySelector: () => status });
  copyTwo.closest = () => ({ querySelector: () => statusTwo });
  let paused = true,
    copied = '',
    timer = 0;
  const timers = new Map();
  const movie = {
    pause() {
      paused = true;
    },
    play() {
      paused = false;
      return Promise.resolve();
    },
  };
  const document = {
    documentElement: root,
    querySelector: () => (logo ? {} : null),
    querySelectorAll(selector) {
      if (selector === '[data-homeward-motion]') return [motion, secondMotion];
      if (selector === '.homeward-hero video[data-homeward-autoplay]') return video ? [movie] : [];
      if (selector === '[data-homeward-copy]') return [copy, copyTwo];
      return [];
    },
  };
  const window = {
    isSecureContext: secure,
    matchMedia: () => preference,
    clearTimeout: (id) => timers.delete(id),
    setTimeout(fn) {
      timers.set(++timer, fn);
      return timer;
    },
  };
  const navigator = {
    clipboard: {
      async writeText(text) {
        if (rejectCopy) throw new Error('denied');
        copied = text;
      },
    },
  };
  vm.runInNewContext(source, { document, window, navigator });
  return {
    classes,
    preference,
    motion,
    secondMotion,
    copy,
    copyTwo,
    status,
    statusTwo,
    timers,
    get paused() {
      return paused;
    },
    get copied() {
      return copied;
    },
  };
}
(async () => {
  const normal = setup();
  check('motion controls progressively revealed', !normal.motion.hidden && !normal.secondMotion.hidden);
  check('logo entrance starts only after preference check', normal.classes.has('homeward-motion-ready'));
  check('video starts in standard mode', !normal.paused);
  await normal.motion.click();
  check('pause stops video and logo', normal.paused && normal.classes.has('homeward-motion-paused'));
  check(
    'pause announced on all controls',
    normal.motion.attributes['aria-pressed'] === 'true' && normal.secondMotion.textContent === 'Resume motion'
  );
  await normal.secondMotion.click();
  check('resume restores playback', !normal.paused && normal.motion.textContent === 'Pause motion');
  normal.preference.matches = true;
  normal.preference.change();
  check(
    'changing system preference stops playback',
    normal.paused && normal.motion.disabled && normal.classes.has('homeward-motion-paused')
  );
  normal.preference.matches = false;
  normal.preference.change();
  check('system preference can restore playback', !normal.paused && !normal.motion.disabled);
  await normal.motion.click();
  normal.preference.matches = true;
  normal.preference.change();
  normal.preference.matches = false;
  normal.preference.change();
  check(
    'explicit user pause survives preference change',
    normal.paused && normal.motion.attributes['aria-pressed'] === 'true'
  );
  const reduced = setup({ reduced: true });
  check(
    'initial reduced motion never starts video',
    reduced.paused && reduced.motion.disabled && reduced.motion.textContent === 'Motion off'
  );
  const staticPage = setup({ logo: false, video: false });
  check('no unnecessary pause controls on static page', staticPage.motion.hidden);
  check('copy control revealed with live feedback', !normal.copy.hidden);
  await normal.copy.click();
  check('exact address copied', normal.copied === 'donations@example.test');
  check(
    'success feedback is scoped to its own panel',
    normal.status.textContent === 'Example email copied.' && normal.statusTwo.textContent === ''
  );
  await normal.copyTwo.click();
  check(
    'multiple give cards work independently',
    normal.copied === 'other@example.test' && normal.statusTwo.textContent === 'Example email copied.'
  );
  await normal.copy.click();
  check('repeat clicks replace own feedback timer', normal.timers.size === 2);
  for (const callback of normal.timers.values()) callback();
  check(
    'toast clears after announcement window',
    normal.status.textContent === '' && normal.statusTwo.textContent === ''
  );
  const insecure = setup({ secure: false });
  await insecure.copy.click();
  check(
    'insecure clipboard fails with manual guidance',
    insecure.copied === '' && insecure.status.textContent.includes('Select and copy')
  );
  const denied = setup({ rejectCopy: true });
  await denied.copy.click();
  check(
    'denied permission fails with manual guidance',
    denied.copied === '' && denied.status.textContent.includes('Select and copy')
  );
  console.log(`${checker.count} theme interaction checks passed (DOM doubles).`);
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
