'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../plugin/homeward-rescue/assets/js/wizard.js'), 'utf8');
const checker = require('./lib/checks').create();
const { check } = checker;
function node(value = '') {
  return {
    value,
    textContent: '',
    hidden: false,
    children: [],
    events: {},
    attrs: {},
    css: {},
    setCustomValidity(message) {
      this.validationMessage = message;
    },
    addEventListener(name, fn) {
      this.events[name] = fn;
    },
    setAttribute(key, val) {
      this.attrs[key] = val;
    },
    removeAttribute(key) {
      delete this.attrs[key];
    },
    appendChild(child) {
      this.children.push(child);
    },
    replaceChildren() {
      this.children = [];
    },
    style: {
      setProperty(key, val) {
        this[key] = val;
      },
    },
  };
}
function setup(selectors, { media, cards } = {}) {
  const timers = new Map();
  let sequence = 0;
  const requests = [];
  const config = {
    url: '/wp-admin/admin-ajax.php',
    nonce: 'valid',
    checking: 'Checking...',
    failed: 'Check failed',
    fonts: { inter: { stack: '"Inter", sans-serif' }, lora: { stack: '"Lora", serif' } },
    posthogKeyError: 'Enter a PostHog project key or leave blank',
    chooseLogo: 'Choose logo',
    useLogo: 'Use logo',
  };
  const root = {
    querySelector: (selector) => selectors[selector] || null,
    querySelectorAll: () => cards || [],
  };
  vm.runInNewContext(source, {
    document: { querySelector: () => root, createElement: () => node() },
    window: { wp: media ? { media } : undefined },
    homewardWizard: config,
    URLSearchParams,
    AbortController,
    setTimeout(fn) {
      const id = ++sequence;
      timers.set(id, fn);
      return id;
    },
    clearTimeout(id) {
      timers.delete(id);
    },
    fetch(url, options) {
      return new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }));
    },
  });
  return {
    requests,
    tick() {
      const jobs = [...timers.values()];
      timers.clear();
      jobs.forEach((fn) => fn());
    },
  };
}
const flush = () => new Promise((resolve) => setImmediate(resolve));
(async () => {
  const projectKey = node();
  setup({ '#homeward-posthog_key': projectKey });
  check('empty statistics key is valid', projectKey.validationMessage === '');
  projectKey.value = 'bad';
  projectKey.events.input();
  check('bad statistics key has friendly error', projectKey.validationMessage.includes('PostHog'));
  projectKey.value = 'phc_' + 'x'.repeat(24);
  projectKey.events.input();
  check('fake statistics key is accepted', projectKey.validationMessage === '');
  projectKey.value = 'phc_' + 'x_-'.repeat(30);
  projectKey.events.input();
  check('key accepts underscores, hyphens and longer keys', projectKey.validationMessage === '');
  projectKey.value = '';
  projectKey.events.input();
  check('clearing key clears error', projectKey.validationMessage === '');
  const shelter = node('0'),
    result = node(),
    button = node();
  const app = setup({
    '#homeward-shelter_id': shelter,
    '[data-homeward-source-check]': button,
    '[data-homeward-source-result]': result,
  });
  app.tick();
  check('fixture is checked on load', app.requests.length === 1);
  check(
    'source AJAX carries nonce and zero shelter',
    app.requests[0].options.body.get('nonce') === 'valid' &&
      app.requests[0].options.body.get('shelter_id') === '0'
  );
  shelter.value = '123';
  shelter.events.input();
  check('changed input aborts older request', app.requests[0].options.signal.aborted);
  app.tick();
  app.requests[1].resolve({
    ok: true,
    json: async () => ({ success: true, data: { count: 3, message: '3 animals found' } }),
  });
  await flush();
  check('animal count appears', result.textContent === '3 animals found');
  app.requests[0].resolve({
    ok: true,
    json: async () => ({ success: true, data: { count: 9, message: '9 stale animals' } }),
  });
  await flush();
  check('stale response ignored', result.textContent === '3 animals found');
  button.events.click();
  app.tick();
  app.requests[2].resolve({
    ok: false,
    json: async () => ({ success: false, data: { message: 'Check the shelter number' } }),
  });
  await flush();
  check('server validation displayed', result.textContent === 'Check the shelter number');
  check('busy indicator cleared', result.attrs['aria-busy'] === 'false');
  const preview = node(),
    contrast = node();
  const selectors = {
    '.homeward-setup-preview': preview,
    '[data-homeward-contrast]': contrast,
    '#homeward-font_heading': node('lora'),
    '#homeward-font_body': node('inter'),
  };
  for (const key of ['primary', 'accent', 'ink', 'background', 'surface'])
    selectors['#homeward-color_' + key] = node('#123456');
  const colors = setup(selectors);
  check('heading font preview uses local stack', preview.style['--setup-heading'] === '"Lora", serif');
  selectors['#homeward-font_body'].value = 'lora';
  selectors['#homeward-font_body'].events.change();
  check('body font preview updates', preview.style['--setup-body'] === '"Lora", serif');
  // Pairing cards set both selects and the preview; changing a select picks the matching card.
  const mkRadio = (value, heading, body) =>
    Object.assign(node(value), { checked: false, dataset: { heading, body } });
  const warm = mkRadio('warm', 'lora', 'inter'),
    easy = mkRadio('easy', 'inter', 'inter'),
    mine = Object.assign(node('custom'), { checked: false, dataset: {} });
  const cardSelectors = {
    '.homeward-setup-preview': node(),
    '#homeward-font_heading': node('lora'),
    '#homeward-font_body': node('inter'),
  };
  const cardApp = setup(cardSelectors, { cards: [warm, easy, mine] });
  easy.checked = true;
  easy.events.change();
  check('card sets heading select', cardSelectors['#homeward-font_heading'].value === 'inter');
  check(
    'card updates preview',
    cardSelectors['.homeward-setup-preview'].style['--setup-heading'] === '"Inter", sans-serif'
  );
  cardSelectors['#homeward-font_body'].value = 'lora';
  cardSelectors['#homeward-font_body'].events.change();
  check('mixing selects chooses Use my own mix', mine.checked === true);
  cardSelectors['#homeward-font_body'].value = 'inter';
  cardSelectors['#homeward-font_body'].events.change();
  check('matching selects choose the card again', easy.checked === true);
  selectors['#homeward-color_ink'].value = '#ffffff';
  selectors['#homeward-color_ink'].events.input();
  colors.tick();
  check('color preview updates immediately', preview.style['--setup-ink'] === '#ffffff');
  check(
    'server contrast receives current color',
    colors.requests[0].options.body.get('colors[color_ink]') === '#ffffff'
  );
  colors.requests[0].resolve({
    ok: true,
    json: async () => ({
      success: true,
      data: {
        checks: [
          { passes: false, message: 'Text is below 4.5:1.' },
          { passes: true, message: 'Easy to read.' },
        ],
      },
    }),
  });
  await flush();
  check(
    'contrast warning rendered',
    contrast.children[0].className === 'homeward-contrast-warning' &&
      contrast.children[0].textContent.includes('4.5:1')
  );
  check('passing contrast has no warning', !contrast.children[1].className);
  let selected,
    opened = false;
  const logo = node(),
    logoId = node('0'),
    logoPreview = node(),
    remove = node();
  setup(
    {
      '[data-homeward-logo]': logo,
      '#homeward-logo_id': logoId,
      '[data-homeward-logo-preview]': logoPreview,
      '[data-homeward-logo-remove]': remove,
    },
    {
      media(options) {
        check(
          'media picker restricted to one image',
          options.library.type === 'image' && options.multiple === false
        );
        return {
          on(event, callback) {
            selected = callback;
          },
          open() {
            opened = true;
          },
          state() {
            return {
              get() {
                return {
                  first() {
                    return {
                      toJSON() {
                        return { id: 7, url: 'https://example.test/logo.png' };
                      },
                    };
                  },
                };
              },
            };
          },
        };
      },
    }
  );
  logo.events.click();
  selected();
  check('logo picker opens and saves image ID', opened && logoId.value === 7);
  check('logo preview displayed', !logoPreview.hidden && logoPreview.src === 'https://example.test/logo.png');
  remove.events.click();
  check('logo removal resets field', logoId.value === '0' && logoPreview.hidden);
  console.log(`${checker.count} wizard interaction checks passed.`);
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
