'use strict';
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const vm = require('node:vm');
const checker = require('./lib/checks').create();
const { check } = checker;
function run(navigator, cookies = false, windowDnt) {
  const html = execFileSync(
    'php',
    [path.join(__dirname, '../php/analytics-browser-fixture.php'), ...(cookies ? ['--cookies'] : [])],
    { encoding: 'utf8' }
  );
  const loaded = [],
    events = [];
  const context = {
    navigator,
    doNotTrack: windowDnt,
    location: { pathname: '/' },
    document: {
      createElement: () => ({}),
      getElementsByTagName: () => [{ parentNode: { insertBefore: (script) => loaded.push(script.src) } }],
      addEventListener: (name) => events.push(name),
    },
  };
  context.window = context;
  vm.createContext(context);
  for (const match of html.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)) vm.runInContext(match[1], context);
  return { context, loaded, events };
}
for (const [navigator, windowDnt] of [
  [{ globalPrivacyControl: true }],
  [{ doNotTrack: '1' }],
  [{ doNotTrack: 'yes' }],
  [{}, '1'],
]) {
  const result = run(navigator, true, windowDnt);
  check('privacy signal prevents external script load', result.loaded.length === 0);
  check('privacy signal prevents custom event listeners', result.events.length === 0);
  check('privacy signal prevents analytics initialization', !result.context.posthog);
}
for (const cookies of [false, true]) {
  const result = run({ doNotTrack: '0', globalPrivacyControl: false }, cookies);
  check('visitor analytics loads once', result.loaded.length === 1 && result.events.length === 3);
  const config = result.context.posthog._i[0][1];
  check('text and attributes masked', config.mask_all_text && config.mask_all_element_attributes);
  check('dead clicks disabled', config.capture_dead_clicks === false);
  check(
    'persistent storage requires opt in',
    config.persistence === (cookies ? 'localStorage+cookie' : 'memory')
  );
  check('SDK DNT protection enabled', config.respect_dnt === true);
}
console.log(`${checker.count} analytics privacy checks passed.`);
