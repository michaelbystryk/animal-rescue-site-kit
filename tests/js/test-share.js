/* Offline DOM doubles cover share routing and keyboard/copy behaviour. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../plugin/homeward-rescue/assets/js/animals.js'), 'utf8');

function setup(options = {}) {
  let active,
    copied,
    selected = false,
    timerID = 0;
  const calls = [],
    timers = new Map();
  class Element {
    constructor(tag = 'div') {
      this.tagName = tag;
      this.children = [];
      this.attrs = {};
      this.events = {};
      this.style = {};
      this.dataset = {};
      this.hidden = false;
      this.textContent = '';
      this.classList = { remove() {}, add() {} };
    }
    append(...nodes) {
      this.children.push(...nodes);
    }
    setAttribute(key, value) {
      this.attrs[key] = String(value);
    }
    contains(node) {
      return node === this || this.children.some((child) => child.contains(node));
    }
    addEventListener(name, callback) {
      (this.events[name] ||= []).push(callback);
    }
    async fire(name, data = {}) {
      for (const callback of this.events[name] || [])
        await callback({ target: this, preventDefault() {}, ...data });
    }
    click() {
      return this.fire('click');
    }
    focus() {
      active = this;
    }
    select() {
      selected = true;
    }
    getBoundingClientRect() {
      return { top: 500, bottom: 544, left: 265, right: 330 };
    }
    get offsetWidth() {
      return Math.min(368, parseFloat(this.style.maxWidth) || Infinity);
    }
    get offsetHeight() {
      return Math.min(320, parseFloat(this.style.maxHeight) || Infinity);
    }
    querySelector() {
      return null;
    }
    querySelectorAll() {
      return [];
    }
  }
  const button = new Element('button'),
    status = new Element('p');
  button.dataset = {
    title: 'Synthetic & "Pet" $& 🐾',
    url: 'https://example.test/animals/synthetic/?a=1&b=%26#meet',
    thumb: '/synthetic-thumb.jpg',
  };
  button.closest = () => ({ querySelector: () => status });
  const document = new Element();
  document.body = new Element('body');
  document.documentElement = { clientWidth: 360 };
  document.createElement = (tag) => new Element(tag);
  document.createElementNS = (_, tag) => new Element(tag);
  document.querySelectorAll = (selector) =>
    selector === '[data-homeward-share]'
      ? [button]
      : selector.startsWith('header,')
        ? options.header
          ? [options.header]
          : []
        : [];
  document.execCommand = (command) => {
    assert.equal(command, 'copy');
    return options.legacyCopy !== false;
  };
  const window = new Element();
  window.location = new URL('https://example.test/animals/synthetic/');
  window.innerHeight = 600;
  window.matchMedia = (query) => ({
    matches:
      (query === '(pointer: coarse)' && !!options.coarse) ||
      (query === '(max-width: 600px)' && !!options.small),
  });
  window.getComputedStyle = () => ({ position: options.headerPosition || 'sticky', top: '0px' });
  window.isSecureContext = true;
  window.homewardAnimalsL10n = options.labels || {};
  window.setTimeout = (callback, delay) => {
    assert.equal(delay, 2000);
    timers.set(++timerID, callback);
    return timerID;
  };
  window.clearTimeout = (id) => timers.delete(id);
  const navigator = {
    maxTouchPoints: options.touch === false ? 0 : 5,
    clipboard: {
      writeText: async (value) => {
        if (options.clipboardFails) throw new Error('Denied');
        copied = value;
      },
    },
    canShare: options.canShare || (() => true),
  };
  if (options.native !== false)
    navigator.share = async (data) => {
      calls.push(data);
      if (options.shareError) throw { name: options.shareError };
    };
  const context = vm.createContext({ window, document, navigator, URL, URLSearchParams, TextEncoder });
  vm.runInContext(fs.readFileSync(require.resolve('../../plugin/homeward-rescue/assets/js/qr.js'), 'utf8'), context);
  vm.runInContext(source, context);
  const panel = document.body.children[0];
  const find = (cls) => {
    const walk = (node) => (node.className === cls ? node : node.children.map(walk).find(Boolean));
    return walk(panel);
  };
  return {
    button,
    panel,
    status,
    calls,
    find,
    document,
    window,
    navigator,
    timers,
    active: () => active,
    copied: () => copied,
    selected: () => selected,
  };
}

(async () => {
  const phone = setup({ coarse: true });
  await phone.button.click();
  assert.equal(phone.calls.length, 1, 'phone opens native share');
  assert.equal(phone.calls[0].title, phone.button.dataset.title);
  assert.equal(phone.calls[0].url, phone.button.dataset.url);
  assert.equal(phone.calls[0].text, 'Meet Synthetic & "Pet" $& 🐾');
  assert.ok(phone.panel.hidden);

  const cancel = setup({ coarse: true, shareError: 'AbortError' });
  await cancel.button.click();
  assert.ok(cancel.panel.hidden, 'cancel must not open fallback');
  assert.equal(cancel.status.textContent, '', 'cancel is silent');
  assert.equal(cancel.copied(), undefined);

  const desktop = setup();
  assert.equal(desktop.button.attrs['aria-expanded'], 'false');
  assert.equal(desktop.button.attrs['aria-controls'], desktop.panel.id);
  await desktop.button.click();
  assert.equal(
    desktop.calls.length,
    0,
    'fine-pointer desktop ignores navigator.share even with touch hardware'
  );
  assert.equal(desktop.button.attrs['aria-expanded'], 'true');
  assert.equal(desktop.panel.hidden, false);
  assert.equal(desktop.panel.attrs['aria-modal'], 'false');
  const copy = desktop.find('homeward-animal-share__copy'),
    input = desktop.find('homeward-animal-share__input');
  assert.equal(desktop.active(), copy, 'focus enters panel');
  assert.equal(input.value, desktop.button.dataset.url);
  assert.ok(input.readOnly);
  assert.ok(parseFloat(desktop.panel.style.left) >= 12);
  assert.ok(
    parseFloat(desktop.panel.style.left) + desktop.panel.offsetWidth <= 348,
    'narrow viewport contains panel'
  );
  assert.ok(parseFloat(desktop.panel.style.top) + desktop.panel.offsetHeight <= 588);

  const links = desktop.find('homeward-animal-share__socials').children;
  const message = phone.calls[0].text,
    url = desktop.button.dataset.url;
  const expected = [
    'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url),
    'https://x.com/intent/post?text=' + encodeURIComponent(message) + '&url=' + encodeURIComponent(url),
    'mailto:?subject=' +
      encodeURIComponent('Meet ' + desktop.button.dataset.title) +
      '&body=' +
      encodeURIComponent(message + '\n\n' + url),
    'https://wa.me/?text=' + encodeURIComponent(message + '\n' + url),
  ];
  links.forEach((link, i) => {
    assert.equal(link.href, expected[i], 'encoded social destination ' + i);
    assert.equal(link.target, '_blank');
    assert.equal(link.rel, 'noopener');
    assert.ok(link.textContent);
  });
  await desktop.document.fire('click', { target: input });
  assert.ok(!desktop.panel.hidden, 'inside clicks keep panel open');
  await copy.click();
  assert.equal(desktop.copied(), url);
  assert.equal(copy.textContent, 'Copied ✓');
  assert.equal(desktop.status.textContent, 'Link copied');
  [...desktop.timers.values()][0]();
  assert.equal(copy.textContent, 'Copy', 'feedback resets after 2 seconds');
  assert.equal(desktop.status.textContent, '', 'copy toast clears after 2 seconds');

  const toggle = desktop.find('homeward-animal-share__qr-toggle'),
    qr = desktop.find('homeward-animal-share__qr');
  assert.ok(qr.hidden);
  assert.equal(qr.children.length, 0, 'QR generation is lazy');
  await toggle.click();
  assert.ok(!qr.hidden);
  assert.equal(toggle.attrs['aria-expanded'], 'true');
  assert.ok(qr.children[0].src.startsWith('data:image/svg+xml;'));
  assert.equal(desktop.find('homeward-animal-share__save').download, 'animal-page-qr.svg');
  await toggle.click();
  assert.ok(qr.hidden);
  await desktop.document.fire('keydown', { key: 'Escape' });
  assert.ok(desktop.panel.hidden);
  assert.equal(desktop.active(), desktop.button);
  assert.equal(desktop.button.attrs['aria-expanded'], 'false');
  await desktop.button.click();
  await desktop.button.click();
  assert.ok(desktop.panel.hidden, 'second Share click closes');
  await desktop.button.click();
  await desktop.document.fire('click', { target: desktop.document.body });
  assert.ok(desktop.panel.hidden, 'outside click closes');
  assert.equal(desktop.active(), desktop.button);
  await desktop.button.click();
  await desktop.find('homeward-animal-share__close').click();
  assert.ok(desktop.panel.hidden);

  for (const options of [
    { coarse: true, native: false },
    { coarse: true, shareError: 'NotAllowedError' },
  ]) {
    const fallback = setup(options);
    await fallback.button.click();
    assert.ok(!fallback.panel.hidden, 'unsupported or failed native sharing opens panel');
  }
  const manual = setup({ clipboardFails: true, legacyCopy: false });
  await manual.button.click();
  await manual.find('homeward-animal-share__copy').click();
  assert.ok(manual.selected());
  assert.equal(manual.active(), manual.find('homeward-animal-share__input'));
  assert.equal(manual.status.textContent, 'Select and copy the link to share it.');
  const legacy = setup({ clipboardFails: true });
  await legacy.button.click();
  await legacy.find('homeward-animal-share__copy').click();
  assert.equal(legacy.find('homeward-animal-share__copy').textContent, 'Copied ✓');
  const translated = setup({
    labels: { copy: 'Copier', shareHeading: 'Partager la page de %s', shareText: 'Rencontrez %s' },
  });
  assert.equal(translated.find('homeward-animal-share__copy').textContent, 'Copier');
  assert.equal(
    translated.find('homeward-animal-share__intro').children[1].textContent,
    'Partager la page de ' + translated.button.dataset.title
  );
  assert.equal(
    new URL(translated.find('homeward-animal-share__socials').children[1].href).searchParams.get('text'),
    'Rencontrez ' + translated.button.dataset.title
  );

  const small = setup({ small: true });
  await small.button.click();
  assert.equal(small.calls.length, 1, 'small viewport uses native share');
  const restricted = setup({ labels: { shareNetworks: ['email', 'whatsapp', 'untrusted'] } });
  assert.deepEqual(
    restricted.find('homeward-animal-share__socials').children.map((link) => link.textContent),
    ['Email', 'WhatsApp']
  );
  assert.equal(
    setup({ labels: { shareNetworks: [] } }).find('homeward-animal-share__socials').children.length,
    0,
    'empty setting hides all networks'
  );
  const header = { getBoundingClientRect: () => ({ top: 0, bottom: 120 }) };
  const sticky = setup({ header });
  await sticky.button.click();
  assert.ok(parseFloat(sticky.panel.style.top) >= 132, 'panel stays below sticky header');
  assert.ok(
    parseFloat(sticky.panel.style.top) + sticky.panel.offsetHeight <= 588,
    'panel also stays within viewport'
  );
  sticky.window.innerHeight = 300;
  await sticky.window.fire('resize');
  assert.ok(parseFloat(sticky.panel.style.maxHeight) <= 156, 'short viewport scrolls panel below header');
  header.getBoundingClientRect = () => ({ top: 0, bottom: 160 });
  sticky.window.requestAnimationFrame = (callback) => callback();
  await sticky.document.fire('scroll');
  assert.ok(parseFloat(sticky.panel.style.top) >= 172, 'position follows header on scroll');
  const staticHeader = setup({ header, headerPosition: 'static' });
  await staticHeader.button.click();
  assert.equal(
    staticHeader.panel.style.maxHeight,
    '576px',
    'ordinary page headers do not reserve sticky space'
  );
  const tabAway = setup();
  await tabAway.button.click();
  await tabAway.document.fire('focusin', { target: tabAway.document.body });
  assert.ok(tabAway.panel.hidden, 'tabbing outside a nonmodal popover closes it');
  assert.notEqual(tabAway.active(), tabAway.button, 'tabbing outside does not steal focus');
  console.log(
    'Share DOM checks passed: phone/desktop routing, configured networks, encoded links, copy, focus, ' +
      'dismissal, local QR and sticky header clearance.'
  );
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
