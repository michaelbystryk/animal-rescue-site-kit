/* Event/state regression tests. Native focus trapping and layout require a browser. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../plugin/homeward-rescue/assets/js/stories.js'), 'utf8');

function fixture({
  url = 'https://example.test/stories/?campaign=friend',
  initial = 'story',
  native = true,
  history = true,
  feature = false,
  unsafe = false,
} = {}) {
  let focused;
  class Element {
    constructor() {
      this.attrs = {};
      this.dataset = {};
      this.selectors = {};
      this.parents = {};
      this.events = {};
      this.hidden = false;
      this.textContent = '';
      this.innerHTML = '';
      this.tabIndex = 0;
      const classes = new Set();
      this.classList = {
        contains: (name) => classes.has(name),
        toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)),
      };
      const values = new Map();
      const property = (name) => name.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`);
      this.style = new Proxy(
        {
          getPropertyValue: (name) => values.get(name)?.[0] || '',
          getPropertyPriority: (name) => values.get(name)?.[1] || '',
          setProperty: (name, value, priority = '') => values.set(name, [value, priority]),
          removeProperty: (name) => values.delete(name),
        },
        {
          get: (target, name) => target[name] || values.get(property(name))?.[0] || '',
          set: (_, name, value) => {
            values.set(property(name), [value, '']);
            return true;
          },
        }
      );
    }
    querySelectorAll(selector) {
      return this.selectors[selector] || [];
    }
    querySelector(selector) {
      return this.querySelectorAll(selector)[0] || null;
    }
    closest(selector) {
      return this.parents[selector] || null;
    }
    setAttribute(name, value) {
      this.attrs[name] = String(value);
    }
    getAttribute(name) {
      return this.attrs[name] ?? null;
    }
    hasAttribute(name) {
      return name in this.attrs;
    }
    removeAttribute(name) {
      delete this.attrs[name];
    }
    addEventListener(name, callback) {
      (this.events[name] ||= []).push(callback);
    }
    fire(name, extra = {}) {
      const event = {
        target: this,
        button: 0,
        preventDefault() {
          this.defaultPrevented = true;
        },
        ...extra,
      };
      for (const callback of this.events[name] || []) callback(event);
      return event;
    }
    focus() {
      focused = this;
    }
    getBoundingClientRect() {
      return { left: 100, right: 800, top: 24, bottom: 700 };
    }
  }
  const el = (selectors = {}) => Object.assign(new Element(), { selectors });
  const grids = [el(), el(), el()];
  const panels = [el(), el()];
  const groupsHome = el();
  panels.forEach((panel, index) => {
    panel.id = `homeward-panel-${index ? 'testimonial' : 'story'}`;
    grids[index].parentElement = panel;
  });
  const groups = grids.map((grid, groupIndex) =>
    Array.from({ length: [27, 8, 3][groupIndex] }, (_, i) => {
      const kind = groupIndex === 1 ? 'testimonial' : 'story';
      const data = {
        slug: `${kind}-${i}`,
        kind,
        title: `${kind} title ${i}`,
        text: '<p>Full <strong>story</strong></p>',
        image: kind === 'story' ? '<img alt="Dog">' : '',
        origin: 'From Mexico',
        received: 'Update received May 2021',
        family: 'Family',
        intro: 'Welcome home,',
      };
      if (unsafe)
        Object.assign(data, {
          title: '<img src=x onerror=alert(1)>',
          dog: '<script>bad()</script>',
          dogLabel: '<img src=x>',
          family: '<svg onload=alert(1)>',
        });
      const json = el();
      json.textContent = JSON.stringify(data);
      const link = el();
      link.href = `https://example.test/stories/${data.slug}/`;
      const card = el({ '.homeward-story-data': [json], '.homeward-story-card__link': [link] });
      card.parents['.homeward-stories'] = grid;
      if (groupIndex === 0) card.parents['[data-homeward-batch]'] = grid;
      if (feature && groupIndex !== 1) {
        card.parents['[data-homeward-story-group]'] = panels[groupIndex] || groupsHome;
        if (i === 0) {
          delete card.parents['.homeward-stories'];
          delete card.parents['[data-homeward-batch]'];
        }
      }
      return { card, link, data };
    })
  );
  grids.forEach((grid, index) => {
    grid.selectors['.homeward-story-card'] = groups[index]
      .filter((_, i) => !feature || index === 1 || i > 0)
      .map((item) => item.card);
  });
  const more = el();
  more.parentElement = el();
  grids[0].dataset.homewardBatch = '12';
  panels[0].selectors['[data-homeward-more]'] = [more];
  const tabs = [el(), el()];
  tabs.forEach((tab, index) => {
    tab.dataset.homewardKind = index ? 'testimonial' : 'story';
    tab.id = `tab-${index}`;
    tab.classList.toggle('is-active', tab.dataset.homewardKind === initial);
  });
  const tablist = el({ '[data-homeward-kind]': tabs });
  const root = el({
    '[data-homeward-tabs]': [tablist],
    '[data-homeward-panel="story"]': [panels[0]],
    '[data-homeward-panel="testimonial"]': [panels[1]],
  });
  root.contains = (card) =>
    groups
      .slice(0, 2)
      .flat()
      .some((item) => item.card === card);
  const title = el(),
    photo = el(),
    text = el(),
    position = el(),
    previous = el(),
    next = el(),
    close = el();
  position.dataset.positionLabel = '%1$s of %2$s';
  const fields = ['intro', 'origin', 'received', 'family', 'dogLabel'].map((name) => {
    const field = el();
    field.dataset.storyField = name;
    return field;
  });
  const dialog = el({
    '.homeward-story-dialog__title': [title],
    '.homeward-story-dialog__photo': [photo],
    '.homeward-story-dialog__text': [text],
    '.homeward-story-dialog__position': [position],
    '[data-story-prev]': [previous],
    '[data-story-next]': [next],
    '.homeward-story-dialog__close': [close],
    '[data-story-field]': fields,
  });
  dialog.open = false;
  if (native)
    dialog.showModal = () => {
      dialog.open = true;
    };
  dialog.close = () => {
    dialog.open = false;
    dialog.fire('close');
  };
  const malformed = el({
    '.homeward-story-data': [Object.assign(el(), { textContent: '{bad json' })],
    '.homeward-story-card__link': [el()],
  });
  const document = el({
    '[data-homeward-happy-tails]': [root],
    '[data-homeward-batch]': [grids[0]],
    '.homeward-story-card': [...groups.flat().map((item) => item.card), malformed],
  });
  document.getElementById = (id) =>
    id === 'homeward-story-dialog' ? dialog : panels.find((panel) => panel.id === id);
  document.body = el();
  document.documentElement = { clientWidth: 1000 };
  document.body.style.setProperty('padding-right', '8px', 'important');
  const window = el();
  window.location = new URL(url);
  window.scrollX = 0;
  window.scrollY = 320;
  window.innerWidth = 1024;
  window.history = {
    state: { retained: true },
    replaceState(state, unused, newURL) {
      if (!history) throw Error('Blocked');
      assert.equal(state.retained, true);
      window.location = new URL(newURL);
    },
  };
  window.getComputedStyle = () => ({ paddingRight: '8px' });
  window.scrollTo = (options) => {
    window.restoredScroll = options;
  };
  vm.runInNewContext(source, { document, window, URL });
  return {
    document,
    window,
    grids,
    groups,
    tabs,
    tablist,
    panels,
    more,
    dialog,
    title,
    photo,
    text,
    fields,
    position,
    previous,
    next,
    close,
    malformed,
    focused: () => focused,
  };
}

const checker = require('./lib/checks').create();
const { check } = checker;
const f = fixture();
const visible = () => f.groups[0].filter(({ card }) => !card.hidden).length;
check(
  'tabs have ARIA roles and roving focus',
  f.tablist.attrs.role === 'tablist' &&
    f.tabs[0].attrs.role === 'tab' &&
    f.tabs[1].tabIndex === -1 &&
    f.panels[0].attrs.role === 'tabpanel'
);
check('stories initially visible', f.tabs[0].attrs['aria-selected'] === 'true' && f.panels[1].hidden);
check('first twelve visible', visible() === 12 && !f.more.parentElement.hidden);
f.tabs[0].fire('keydown', { key: 'ArrowRight' });
check(
  'arrow selects and focuses testimonial tab',
  f.focused() === f.tabs[1] && !f.panels[1].hidden && f.panels[0].hidden
);
check(
  'tab URL preserves unrelated parameters',
  f.window.location.searchParams.get('homeward_kind') === 'testimonial' &&
    f.window.location.searchParams.get('campaign') === 'friend'
);
f.tabs[1].fire('keydown', { key: 'ArrowRight' });
check(
  'arrow wraps tabs and clears default kind',
  f.focused() === f.tabs[0] && !f.window.location.searchParams.has('homeward_kind')
);
f.tabs[0].fire('keydown', { key: 'End' });
f.tabs[1].fire('keydown', { key: 'Home' });
check('Home and End work', f.focused() === f.tabs[0]);
check('modified tab link retains navigation', !f.tabs[1].fire('click', { ctrlKey: true }).defaultPrevented);
f.more.fire('click');
check(
  'second batch reveals twelve and focuses continuation',
  visible() === 24 && f.focused() === f.groups[0][12].link
);
f.more.fire('click');
check(
  'final batch reveals remainder and hides button',
  visible() === 27 && f.more.parentElement.hidden && f.focused() === f.groups[0][24].link
);
const first = f.groups[0][0];
for (const modifier of ['ctrlKey', 'metaKey', 'shiftKey', 'altKey']) {
  check(
    `${modifier} leaves card navigation native`,
    !first.link.fire('click', { [modifier]: true }).defaultPrevented && !f.dialog.open
  );
}
check('middle-click stays native', !first.link.fire('click', { button: 1 }).defaultPrevented);
check(
  'malformed payload keeps normal link',
  !f.malformed.querySelector('.homeward-story-card__link').fire('click').defaultPrevented
);
check('card declares dialog only when enhancement succeeds', first.link.attrs['aria-haspopup'] === 'dialog');
first.link.fire('click');
check(
  'card opens labelled reader content and hash',
  f.dialog.open && f.title.textContent === first.data.title && f.window.location.hash === '#story-story-0'
);
check('full HTML and photo rendered', f.text.innerHTML === first.data.text && !f.photo.hidden);
check(
  'metadata shown and absent dog label hidden',
  f.fields[1].textContent === 'From Mexico' && f.fields[4].hidden
);
check(
  'reader starts with title focus and correct bounds',
  f.focused() === f.title && f.previous.disabled && !f.next.disabled && f.position.textContent === '1 of 27'
);
check(
  'scroll locked with scrollbar compensation',
  f.document.body.style.position === 'fixed' && f.document.body.style.paddingRight === '32px'
);
f.next.fire('click');
check(
  'Next updates full content and URL in same group',
  f.title.textContent === 'story title 1' && f.window.location.hash === '#story-story-1'
);
f.dialog.fire('keydown', { key: 'ArrowLeft', target: f.title });
check('left arrow navigates back', f.position.textContent === '1 of 27');
f.dialog.fire('keydown', { key: 'ArrowLeft' });
check('first item does not wrap', f.position.textContent === '1 of 27');
f.close.fire('click');
check(
  'close returns focus and clears story hash',
  !f.dialog.open && f.focused() === first.link && !f.window.location.hash
);
check(
  'close restores previous scroll and inline styles',
  f.window.restoredScroll.top === 320 &&
    f.document.body.style.position === '' &&
    f.document.body.style.getPropertyValue('padding-right') === '8px' &&
    f.document.body.style.getPropertyPriority('padding-right') === 'important'
);
f.tabs[1].fire('click');
f.groups[1][0].link.fire('click');
check(
  'testimonial omits image area and stays in testimonial group',
  f.photo.hidden && f.photo.innerHTML === '' && f.position.textContent === '1 of 8'
);
f.dialog.fire('pointerdown', { clientX: 150, clientY: 150 });
f.dialog.fire('click', { clientX: 0, clientY: 0 });
check('drag out of sheet does not close', f.dialog.open);
f.dialog.fire('pointerdown', { clientX: 0, clientY: 0 });
f.dialog.fire('click', { clientX: 0, clientY: 0 });
check(
  'backdrop closes and retains tab URL',
  !f.dialog.open && f.window.location.searchParams.get('homeward_kind') === 'testimonial'
);
f.groups[2][0].link.fire('click');
check('home block has its own navigation group', f.position.textContent === '1 of 3');
f.close.fire('click');
const deep = fixture({ url: 'https://example.test/stories/?campaign=friend#story-story-25' });
check(
  'deep link opens a story beyond first batch',
  deep.dialog.open && !deep.groups[0][25].card.hidden && deep.position.textContent === '26 of 27'
);
deep.close.fire('click');
check(
  'deep-link close focuses a visible card',
  deep.focused() === deep.groups[0][25].link && !deep.groups[0][25].card.hidden
);
const quote = fixture({ url: 'https://example.test/stories/#story-testimonial-3' });
check(
  'deep link selects correct tab',
  quote.dialog.open &&
    !quote.panels[1].hidden &&
    quote.window.location.searchParams.get('homeward_kind') === 'testimonial'
);
quote.window.location.hash = 'story-story-1';
quote.window.fire('hashchange');
check(
  'external hash switches tabs while open',
  !quote.panels[0].hidden && quote.title.textContent === 'story title 1'
);
quote.close.fire('click');
check('external hash returns focus to corresponding card', quote.focused() === quote.groups[0][1].link);
const anchor = fixture({ url: 'https://example.test/stories/#keep-me' });
anchor.groups[0][0].link.fire('click');
anchor.close.fire('click');
check('non-story hash restored on close', anchor.window.location.hash === '#keep-me');
anchor.groups[0][0].link.fire('click');
anchor.window.location.hash = 'elsewhere';
anchor.window.fire('hashchange');
check(
  'external non-story hash closes without overwriting it',
  !anchor.dialog.open && anchor.window.location.hash === '#elsewhere'
);
const noDialog = fixture({ native: false });
check(
  'unsupported dialog falls back to single links',
  !noDialog.groups[0][0].link.fire('click').defaultPrevented &&
    !noDialog.groups[0][0].link.attrs['aria-haspopup']
);
const blockedHistory = fixture({ history: false });
blockedHistory.groups[0][0].link.fire('click');
check('history restrictions do not break modal', blockedHistory.dialog.open);
const selected = fixture({
  initial: 'testimonial',
  url: 'https://example.test/stories/?homeward_kind=testimonial',
});
check(
  'server-selected testimonial tab is preserved',
  !selected.panels[1].hidden && selected.tabs[1].attrs['aria-selected'] === 'true'
);
const editorial = fixture({ feature: true });
editorial.groups[0][0].link.fire('click');
check(
  'featured link opens reader and joins all archive stories',
  editorial.dialog.open && editorial.position.textContent === '1 of 27'
);
check(
  'feature is outside batching, with twelve regular cards visible',
  !editorial.groups[0][0].card.hidden &&
    editorial.groups[0].slice(1).filter(({ card }) => !card.hidden).length === 12
);
editorial.next.fire('click');
check('feature Next reaches first regular card', editorial.position.textContent === '2 of 27');
editorial.previous.fire('click');
check('regular card Previous reaches feature', editorial.position.textContent === '1 of 27');
editorial.close.fire('click');
check('feature close restores focus to its CTA link', editorial.focused() === editorial.groups[0][0].link);
editorial.more.fire('click');
check('show more continues after twelve regular cards', editorial.focused() === editorial.groups[0][13].link);
editorial.groups[2][0].link.fire('click');
check('home feature shares a group with its two compact cards', editorial.position.textContent === '1 of 3');
editorial.next.fire('click');
check('home feature Next reaches compact card', editorial.position.textContent === '2 of 3');
const editorialDeep = fixture({ feature: true, url: 'https://example.test/stories/#story-story-25' });
check(
  'feature grouping preserves deep-link batch reveal',
  editorialDeep.dialog.open && !editorialDeep.groups[0][25].card.hidden
);
const editorialNoNative = fixture({ feature: true, native: false });
check(
  'featured link keeps native navigation without dialog support',
  !editorialNoNative.groups[0][0].link.fire('click').defaultPrevented
);
const plain = fixture({ unsafe: true });
plain.groups[0][0].link.fire('click');
check(
  'entity-decoded title remains text',
  plain.title.textContent === '<img src=x onerror=alert(1)>' && plain.title.innerHTML === ''
);
check(
  'entity-decoded family and animal labels remain text',
  plain.fields.every((field) => field.innerHTML === '') &&
    plain.fields.some((field) => field.textContent === '<svg onload=alert(1)>')
);
console.log(`${checker.count} interaction checks passed (DOM doubles).`);
