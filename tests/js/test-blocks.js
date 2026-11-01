'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { readCss } = require('./lib/css');
const root = path.resolve(__dirname, '../../plugin/homeward-rescue');
const registered = new Map();
// WordPress merges block.json into the registered block type; mirror that so the editor can read it back.
const serverMetadata = new Map();
for (const dir of fs.readdirSync(path.join(root, 'blocks'))) {
  const metadata = JSON.parse(fs.readFileSync(path.join(root, 'blocks', dir, 'block.json'), 'utf8'));
  serverMetadata.set(metadata.name, metadata);
}
const wp = {
  i18n: { __: (text) => text },
  blocks: {
    registerBlockType: (name, def) => registered.set(name, def),
    getBlockType: (name) => ({ ...serverMetadata.get(name), ...registered.get(name) }),
  },
  element: { createElement: (type, props, ...children) => ({ type, props, children }) },
  blockEditor: { useBlockProps: () => ({}), InspectorControls: 'inspector' },
  components: {
    ToggleControl: 'toggle',
    PanelBody: 'panel',
    SelectControl: 'select',
    RangeControl: 'range',
    TextControl: 'text',
  },
  serverSideRender: 'preview',
};
vm.runInNewContext(fs.readFileSync(path.join(root, 'assets/js/editor.js'), 'utf8'), { window: { wp } });
const checker = require('./lib/checks').create();
const { check } = checker;
check('ten editor blocks', registered.size === 10);
for (const name of fs.readdirSync(path.join(root, 'blocks'))) {
  const dir = path.join(root, 'blocks', name);
  const metadata = JSON.parse(fs.readFileSync(path.join(dir, 'block.json'), 'utf8'));
  const def = registered.get(metadata.name);
  check(name + ' metadata API 3', metadata.apiVersion === 3);
  check(name + ' render file', fs.existsSync(path.join(dir, metadata.render.replace('file:', ''))));
  check(
    name + ' view module',
    ['apply-button', 'upcoming-events', 'contact-form'].includes(name)
      ? !metadata.viewScriptModule
      : metadata.viewScriptModule.startsWith('homeward-')
  );
  check(name + ' editor does not duplicate the block.json schema', !('attributes' in def));
  check(name + ' editor registers every block.json block name', registered.has(metadata.name));
  const attributes = Object.fromEntries(
    Object.entries(metadata.attributes).map(([key, field]) => [key, field.default])
  );
  const tree = def.edit({ attributes, setAttributes() {} });
  const controls = tree.children[0].children[0].children[0];
  check(name + ' one editor control per attribute', controls.length === Object.keys(attributes).length);
  check(name + ' editor preview', tree.type === 'div' && tree.children[1].props.block === metadata.name);
  check(name + ' dynamic save', def.save() === null);
}
const css = readCss(path.join(root, 'assets/css/homeward.css'));
check('reduced motion', css.includes('prefers-reduced-motion:reduce'));
check('44px targets', css.includes('min-height:44px'));
check(
  'theme token fallbacks',
  css.includes('--wp--preset--color--base') && css.includes('--wp--preset--color--contrast')
);
check(
  'only kit or core selectors',
  (css.match(/\.[a-zA-Z][\w-]*/g) || []).every((c) => /^\.(homeward|wp-|is-|has-|screen-reader-text)/.test(c))
);
console.log(`${checker.count} block/editor/style checks passed.`);

// Primary labels must survive a theme's normal link colour, including visited links.
// Check the declarations that participate in the cascade and their resolved tokens.
const primaryRules = [...css.matchAll(/([^{}]+)\{([^{}]+)\}/g)].filter(
  ([, selector]) => selector.trim() === '.homeward-animal-btn.homeward-animal-btn--primary'
);
assert.equal(primaryRules.length, 1, 'primary colour rule has component specificity');
const primaryDeclarations = Object.fromEntries(
  primaryRules[0][2]
    .trim()
    .split(';')
    .filter(Boolean)
    .map((decl) => {
      const colon = decl.indexOf(':');
      return [decl.slice(0, colon).trim(), decl.slice(colon + 1).trim()];
    })
);
assert.match(primaryDeclarations.color, /!important$/, 'primary label beats theme link and visited colours');
function resolveButtonValue(value, tokens) {
  return value
    .replace(/!important$/, '')
    .trim()
    .replace(/var\((--[\w-]+)(?:,([^()]+))?\)/g, (_, name, fallback) => tokens[name] || fallback);
}
for (const [background, foreground] of [
  ['#1F4E79', '#ffffff'],
  ['#fff080', '#111111'],
]) {
  const tokens = { '--homeward-primary': background, '--homeward-primary-foreground': foreground };
  const textColour = resolveButtonValue(primaryDeclarations.color, tokens);
  const backgroundColour = resolveButtonValue(primaryDeclarations.background, tokens);
  assert.notEqual(
    textColour.toLowerCase(),
    backgroundColour.toLowerCase(),
    'primary button text differs from background'
  );
}
assert.match(
  css,
  /\.homeward-animal-btn\s*\{[^}]*background:transparent;[^}]*border:1\.5px solid/s,
  'unmodified copy button has outline styling'
);
assert.match(
  css,
  /\.homeward-donate > h2\s*\{[^}]*font-size:clamp\(1\.5rem,3vw,2rem\)/,
  'donate heading fits narrow containers'
);
console.log('Button contrast, outline and donation heading regressions passed.');

// A theme underline on the card anchor propagates through all its text descendants.
const cardRule = [...css.matchAll(/([^{}]+)\{([^{}]+)\}/g)].find(([, selector]) =>
  selector.includes('.homeward-kit a.homeward-animal-card__link,')
);
assert.ok(cardRule, 'card anchor has component specificity');
assert.match(
  cardRule[2],
  /text-decoration:none !important/,
  'theme link underline is removed on the card anchor'
);
assert.match(
  css,
  new RegExp(
    ':where\\(\\.homeward-animal-card__link:is\\(:hover,:focus-visible\\) ' +
      '\\.homeward-animal-card__name\\)\\{[^}]*text-decoration:underline',
    's'
  ),
  'only the animal name is underlined on hover or keyboard focus'
);
console.log('Animal card link decoration regressions passed.');
