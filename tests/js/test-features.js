/* Profile features: what each feature does in the browser and the events it sends, with DOM doubles. */
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const checker = require('./lib/checks').create();
const { check, equals } = checker;
const source = fs.readFileSync(require.resolve('../../plugin/homeward-rescue/assets/js/features.js'), 'utf8');

class El {
  constructor(attrs = {}, children = []) {
    this.attrs = { ...attrs };
    this.hidden = 'hidden' in attrs;
    delete this.attrs.hidden;
    this.children = children;
    this.events = {};
    this.parent = null;
    this.removed = false;
    this.clicks = 0;
    this.textContent = '';
    this.open = false;
    children.forEach((child) => (child.parent = this));
  }
  getAttribute(name) {
    return name in this.attrs ? this.attrs[name] : null;
  }
  hasAttribute(name) {
    return name in this.attrs;
  }
  setAttribute(name, value) {
    this.attrs[name] = String(value);
  }
  addEventListener(name, fn) {
    (this.events[name] ||= []).push(fn);
  }
  fire(name, event = {}) {
    (this.events[name] || []).forEach((fn) => fn({ target: this, stopPropagation() { event.stopped = true; }, ...event }));
    return event;
  }
  click() {
    this.clicks++;
    this.fire('click');
  }
  focus() {
    this.focused = true;
  }
  remove() {
    this.removed = true;
  }
  descendants() {
    return this.children.flatMap((child) => [child, ...child.descendants()]);
  }
  matches(selector) {
    const match = /^\[([\w-]+)\]$/.exec(selector);
    if (match) return this.hasAttribute(match[1]);
    if (selector === 'summary') return this.tag === 'summary';
    if (selector === '.homeward-animal-page') return this.hasAttribute('class:page');
    return false;
  }
  querySelectorAll(selector) {
    return this.descendants().filter((el) => !el.removed && el.matches(selector));
  }
  querySelector(selector) {
    return this.querySelectorAll(selector)[0] || null;
  }
  closest(selector) {
    for (let el = this; el; el = el.parent) if (el.matches(selector)) return el;
    return null;
  }
}

function feature(key, inner = [], extra = {}) {
  return new El({ 'data-homeward-feature': key, ...extra }, inner);
}

function flagged(key, fallback, inner = []) {
  return feature(key, inner, {
    'data-homeward-flag': 'homeward-' + key,
    'data-homeward-fallback': fallback,
    hidden: '',
  });
}

function setup(nodes, options = {}) {
  const timers = [];
  const events = [];
  const root = new El({ 'class:page': '' }, nodes);
  const document = new El({}, [root]);
  document.readyState = 'complete';
  document.documentElement = { lang: 'en' };
  const window = {
    document,
    location: { pathname: '/animals/pepper/' },
    setTimeout(fn, ms) {
      timers.push({ fn, at: clock.now + (ms || 0), done: false });
      return timers.length;
    },
    clearTimeout(id) {
      if (timers[id - 1]) timers[id - 1].done = true;
    },
    Date,
    Intl,
  };
  const clock = { now: 0 };
  if (options.analytics !== false) {
    window.homewardAnalytics = { capture: (name, props) => events.push([name, JSON.parse(JSON.stringify(props))]) };
  }
  if (options.posthog) window.posthog = options.posthog;
  if (options.io) window.IntersectionObserver = options.io;
  const context = vm.createContext({ window, document, Array, Object, Math, Date, Intl, String, parseInt, isNaN });
  vm.runInContext(source, context);
  const api = window.homewardFeatures;
  if (options.now) api.now = () => options.now;
  // Run timers due up to ms from now, in order.
  const advance = (ms) => {
    const until = clock.now + ms;
    for (;;) {
      const next = timers.filter((t) => !t.done && t.at <= until).sort((a, b) => a.at - b.at)[0];
      if (!next) break;
      clock.now = next.at;
      next.done = true;
      next.fn();
    }
    clock.now = until;
  };
  return { window, document, root, api, events, advance };
}

function livePostHog(flags, options = {}) {
  const exposures = [];
  const ph = {
    __loaded: options.loaded !== false,
    getAllFeatureFlags: () => (options.list ? Object.keys(flags).map((key) => ({ key, enabled: true, variant: flags[key] })) : flags),
    getFeatureFlag(name, opts) {
      if (!opts) exposures.push(name);
      return flags[name];
    },
    onFeatureFlags(fn) {
      ph.flagsCallback = fn;
    },
    has_opted_out_capturing: () => Boolean(options.optedOut),
    capture() {},
  };
  return { ph, exposures };
}

// Switched-on features (no flag) are active at once and never wait.
{
  const link = new El({ 'data-homeward-donate-link': 'profile' });
  const card = feature('donate-card', [link]);
  const { events, advance } = setup([card]);
  advance(0);
  check('switched-on feature stays visible', !card.hidden && !card.removed);
  link.click();
  equals('donate click is captured with the feature key', events, [
    ['donate_click', { feature: 'donate-card', page: '/animals/pepper/', source: 'profile' }],
  ]);
}

// No PostHog (logged in, Do Not Track, Global Privacy Control): the switch decides immediately.
{
  const on = flagged('fee-explainer', 'on');
  const off = flagged('adoption-path', 'off');
  const { api, advance } = setup([on, off]);
  advance(0);
  check('no PostHog: fallback on shows', !on.hidden && !on.removed);
  check('no PostHog: fallback off removes', off.removed);
  equals('no PostHog: decided as fallback', api.variant('fee-explainer'), 'fallback');
}

// The snippet stub loads nothing (blocked): fallback after the 3 second timeout, not before.
{
  const el = flagged('waiting-counter', 'on');
  const stub = { onFeatureFlags() {}, capture() {}, getFeatureFlag() {} };
  const { api, advance } = setup([el], { posthog: stub });
  advance(2900);
  check('blocked PostHog keeps the feature hidden while waiting', el.hidden && !el.removed);
  equals('default timeout is 3 seconds', api.timeout, 3000);
  advance(200);
  check('timeout falls back to the switch', !el.hidden);
}

// Saved flags decide at once: test shows, control removes, exposure recorded once per flag.
for (const list of [false, true]) {
  const test = flagged('fee-explainer', 'off');
  const control = flagged('household-share', 'on');
  const { ph, exposures } = livePostHog({ 'homeward-fee-explainer': 'test', 'homeward-household-share': 'control' }, { list });
  const { api, advance } = setup([test, control], { posthog: ph });
  advance(0);
  const form = list ? 'list' : 'map';
  check(`test variant shows (${form})`, !test.hidden && !test.removed);
  check(`control variant removes even when the switch is on (${form})`, control.removed);
  equals(`exposure recorded once per flag (${form})`, exposures.sort(), ['homeward-fee-explainer', 'homeward-household-share']);
  equals(`variant reported (${form})`, [api.variant('fee-explainer'), api.variant('household-share')], ['test', 'control']);
}

// First visit: wait for onFeatureFlags; a flag PostHog does not know falls back to the switch.
{
  const known = flagged('adoption-path', 'off');
  const unknown = flagged('waiting-counter', 'on');
  const flags = {};
  const { ph, exposures } = livePostHog(flags, { loaded: false });
  const { advance } = setup([known, unknown], { posthog: ph });
  advance(500);
  check('first visit waits for flags', known.hidden && unknown.hidden);
  flags['homeward-adoption-path'] = 'test';
  ph.__loaded = true;
  ph.flagsCallback();
  check('onFeatureFlags decides', !known.hidden);
  check('missing flag follows the switch', !unknown.hidden && !unknown.removed);
  equals('no exposure for a missing flag', exposures, ['homeward-adoption-path']);
}

// A late answer after the timeout changes nothing.
{
  const el = flagged('donate-card', 'off');
  const { ph, exposures } = livePostHog({}, { loaded: false });
  const { advance } = setup([el], { posthog: ph });
  advance(3000);
  check('timed out to fallback off', el.removed);
  ph.__loaded = true;
  ph.getAllFeatureFlags = () => ({ 'homeward-donate-card': 'test' });
  ph.flagsCallback();
  check('late test answer is ignored', el.removed);
  equals('late answer records no exposure', exposures, []);
}

// A visitor who opted out of capturing is not tested.
{
  const el = flagged('donate-card', 'on');
  const { ph, exposures } = livePostHog({ 'homeward-donate-card': 'control' }, { optedOut: true });
  setup([el], { posthog: ph }).advance(0);
  check('opted out follows the switch', !el.hidden && !el.removed);
  equals('opted out records no exposure', exposures, []);
}

// Removed features never wire their events.
{
  const link = new El({ 'data-homeward-donate-link': 'adopted' });
  const el = flagged('adopted-donate', 'on', [link]);
  const { ph } = livePostHog({ 'homeward-adopted-donate': 'control' });
  const { events, advance } = setup([el], { posthog: ph });
  advance(0);
  link.click();
  equals('control group sends no feature events', events, []);
}


// Fee explainer: first open only, Escape closes.
{
  const summary = new El();
  summary.tag = 'summary';
  const details = new El({ 'data-homeward-fee-explainer': '' }, [summary]);
  const { events, advance } = setup([feature('fee-explainer', [details])]);
  advance(0);
  details.open = true;
  details.fire('toggle');
  details.open = false;
  details.fire('toggle');
  details.open = true;
  details.fire('toggle');
  equals('fee_explainer_opened fires once', events.map((e) => e[0]), ['fee_explainer_opened']);
  details.fire('keydown', { key: 'Escape' });
  check('Escape closes and returns focus', !details.open && summary.focused);
}

// Household share: shows only with a Share button, opens it and is captured.
{
  const open = new El({ 'data-homeward-household-open': '' });
  const card = new El({ 'data-homeward-household-share': '', hidden: '' }, [open]);
  const share = new El({ 'data-homeward-share': '' });
  const { events, advance } = setup([share, feature('household-share', [card])]);
  advance(0);
  check('household card appears with a Share button', !card.hidden);
  const event = open.fire('click', {});
  check('household click opens the usual Share box', share.clicks === 1);
  check('household click does not reach the outside-click handler', event.stopped);
  equals('household_share_clicked captured', events[0][0], 'household_share_clicked');
  const lonely = new El({ 'data-homeward-household-share': '', hidden: '' }, [new El({ 'data-homeward-household-open': '' })]);
  setup([feature('household-share', [lonely])]).advance(0);
  check('household card stays hidden without a Share button', lonely.hidden);
}

// The adoption steps report a view once, when they scroll into view.
{
  const observers = [];
  class IO {
    constructor(fn) {
      this.fn = fn;
      observers.push(this);
    }
    observe() {}
    disconnect() {}
  }
  const steps = new El({ 'data-homeward-adoption-path': '' });
  const { events, advance } = setup([feature('adoption-path', [steps])], { io: IO });
  advance(0);
  equals('adoption steps are not viewed before they scroll into view', events, []);
  observers.forEach((io) => io.fn([{ isIntersecting: true }]));
  observers.forEach((io) => io.fn([{ isIntersecting: true }]));
  equals('adoption_path_viewed fires once', events.map((e) => e[0]), ['adoption_path_viewed']);
}

// The waiting counter recounts from its start date and reports a view once.
{
  const observers = [];
  class IO {
    constructor(fn) {
      this.fn = fn;
      observers.push(this);
    }
    observe() {}
    disconnect() {}
  }
  const pill = new El({
    'data-homeward-waiting-counter': '',
    'data-since': '2026-01-01',
    'data-days': '10',
    'data-template': 'Waiting for a home: {days} days',
  });
  const { events, advance } = setup([feature('waiting-counter', [pill])], { io: IO, now: Date.UTC(2026, 0, 31, 12) });
  advance(0);
  equals('waiting counter is not viewed before it scrolls into view', events, []);
  observers.forEach((io) => io.fn([{ isIntersecting: true }]));
  observers.forEach((io) => io.fn([{ isIntersecting: true }]));
  equals('waiting_counter_viewed fires once', events.map((e) => e[0]), ['waiting_counter_viewed']);
  equals('waiting counter recounts from the start date', pill.textContent, 'Waiting for a home: 30 days');
  equals('waiting_counter_viewed reports the days shown', events[0][1].days, 30);
}

// A visitor clock behind the cached page keeps the server count; one day is singular.
{
  const pill = new El({ 'data-homeward-waiting-counter': '', 'data-since': '2026-01-01', 'data-days': '40', 'data-template': '{days} days' });
  setup([feature('waiting-counter', [pill])], { now: Date.UTC(2026, 0, 2) }).advance(0);
  check('slow clock keeps the server text', pill.textContent === '');
  const one = new El({ 'data-homeward-waiting-counter': '', 'data-since': '2026-01-01', 'data-days': '0', 'data-template': 'Waiting for a home: {days} days' });
  setup([feature('waiting-counter', [one])], { now: Date.UTC(2026, 0, 2, 9) }).advance(0);
  equals('one day is singular', one.textContent, 'Waiting for a home: 1 day');
}

// Without visitor statistics (no homewardAnalytics) clicks still work and send nothing.
{
  const link = new El({ 'data-homeward-donate-link': 'profile' });
  const { events, advance } = setup([feature('donate-card', [link])], { analytics: false });
  advance(0);
  link.click();
  equals('no analytics, no events', events, []);
}

console.log(`${checker.count} profile feature checks passed (DOM doubles).`);
