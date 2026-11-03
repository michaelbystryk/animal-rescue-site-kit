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






// Without visitor statistics (no homewardAnalytics) clicks still work and send nothing.
{
  const link = new El({ 'data-homeward-donate-link': 'profile' });
  const { events, advance } = setup([feature('donate-card', [link])], { analytics: false });
  advance(0);
  link.click();
  equals('no analytics, no events', events, []);
}

console.log(`${checker.count} profile feature checks passed (DOM doubles).`);
