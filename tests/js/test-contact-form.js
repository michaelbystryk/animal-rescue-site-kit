/* Dependency-free DOM doubles for fetch enhancement; no real submissions. */
'use strict';
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const code = fs.readFileSync(
  require.resolve('../../plugin/homeward-rescue/assets/js/contact-form.js'),
  'utf8'
);
const checker = require('./lib/checks').create();
const { check } = checker;
class Element {
  constructor() {
    this.attrs = {};
    this.events = {};
    this.textContent = '';
    this.dataset = {};
  }
  setAttribute(k, v) {
    this.attrs[k] = v;
  }
  removeAttribute(k) {
    delete this.attrs[k];
  }
  getAttribute(k) {
    return k in this.attrs ? this.attrs[k] : null;
  }
  addEventListener(k, fn) {
    this.events[k] = fn;
  }
  focus() {
    this.focused = true;
  }
}
function fixture(fetcher, missing) {
  const status = new Element(),
    button = new Element(),
    form = new Element();
  const names = ['contact_name', 'email', 'phone', 'message'];
  const fields = Object.fromEntries(names.map((key) => [key, new Element()]));
  fields.message.value = 'My original question';
  const errors = ['form', ...names].map((key) =>
    Object.assign(new Element(), { dataset: { contactError: key } })
  );
  // Like a real form: the hidden <input name="action"> shadows form.action; the URL lives in the attribute.
  form.action = Object.assign(new Element(), { name: 'action', value: 'homeward_contact' });
  form.attrs = Object.assign(form.attrs || {}, { action: 'https://example.test/wp-admin/admin-post.php' });
  form.elements = { namedItem: (key) => fields[key] };
  form.querySelector = (selector) =>
    selector === missing ? null : selector === '[data-contact-status]' ? status : button;
  form.querySelectorAll = (selector) =>
    selector === '[data-contact-error]'
      ? errors
      : Object.values(fields).filter((field) => field.attrs['aria-invalid']);
  form.reset = () => {
    form.wasReset = true;
  };
  class FormData extends Map {
    constructor() {
      super([
        ['context', 'signed'],
        ['message', fields.message.value],
      ]);
    }
  }
  const window = {
    fetch: fetcher,
    FormData,
    homewardContactL10n: {
      connectionError: 'Receipt could not be confirmed. Your message is still in the form.',
    },
  };
  vm.runInNewContext(code, {
    window,
    document: { querySelectorAll: () => [form] },
    FormData,
    fetch: fetcher,
  });
  const submit = () =>
    form.events.submit({
      preventDefault() {
        form.prevented = true;
      },
    });
  return { form, status, button, fields, errors, submit };
}
(async () => {
  let request;
  const f = fixture(async (url, options) => {
    request = { url, options };
    return { ok: true, json: async () => ({ success: true, message: 'Received', errors: [] }) };
  });
  await f.submit();
  check(
    'POST uses form endpoint and signed context',
    request.url === 'https://example.test/wp-admin/admin-post.php' &&
      request.options.method === 'POST' &&
      request.options.body.get('context') === 'signed' &&
      request.options.body.get('homeward_fetch') === '1'
  );
  check(
    'success announces and focuses receipt, resets only after save',
    f.status.textContent === 'Received' && f.status.focused && f.form.wasReset && f.form.prevented
  );
  check('success releases busy state', !f.button.disabled && !f.form.attrs['aria-busy']);
  const invalid = fixture(async () => ({
    ok: false,
    json: async () => ({
      success: false,
      message: 'Check the form.',
      errors: { email: 'Invalid email', message: '<script>text only</script>', form: 'Please wait' },
    }),
  }));
  await invalid.submit();
  check(
    'field errors show inline as text and mark associated inputs',
    invalid.errors.find((n) => n.dataset.contactError === 'message').textContent ===
      '<script>text only</script>' && invalid.fields.email.attrs['aria-invalid'] === 'true'
  );
  check(
    'all server errors are announced with focus',
    invalid.status.textContent.includes('Invalid email') &&
      invalid.status.textContent.includes('Please wait') &&
      invalid.status.focused
  );
  check(
    'invalid form retains user message',
    !invalid.form.wasReset && invalid.fields.message.value === 'My original question'
  );
  const offline = fixture(async () => {
    throw new Error('Offline');
  });
  await offline.submit();
  check(
    'network failure retains fields, announces uncertain receipt and enables retry',
    !offline.form.wasReset &&
      offline.status.textContent.includes('could not be confirmed') &&
      !offline.button.disabled
  );
  const bad = fixture(async () => ({
    ok: true,
    json: async () => {
      throw new Error('Invalid JSON');
    },
  }));
  await bad.submit();
  check(
    'unexpected response cannot claim success',
    !bad.form.wasReset && bad.status.textContent.includes('could not be confirmed')
  );
  let finish,
    requests = 0;
  const pending = fixture(() => {
    requests++;
    return new Promise((resolve) => {
      finish = resolve;
    });
  });
  const task = pending.submit();
  await pending.submit();
  check(
    'double submit sends one request and disables button',
    requests === 1 && pending.button.disabled && pending.form.attrs['aria-busy'] === 'true'
  );
  finish({ ok: true, json: async () => ({ success: true, message: 'Received' }) });
  await task;
  let queried = false;
  vm.runInNewContext(code, {
    window: {},
    document: {
      querySelectorAll() {
        queried = true;
      },
    },
  });
  check('unsupported fetch leaves native POST untouched', !queried);
  for (const missing of ['[data-contact-status]', '[type="submit"]']) {
    const partial = fixture(() => {
      throw new Error('Must keep native submission');
    }, missing);
    check(`missing ${missing} leaves native POST available without errors`, !partial.form.events.submit);
  }
  console.log(`${checker.count} contact interaction checks passed (DOM doubles).`);
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
