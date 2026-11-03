/* DOM doubles exercise event wiring without opening a browser. Native dialog,
 * layout and assistive technology still need real WordPress/browser QA. */
'use strict';
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
let focus = null;
class Element {
  constructor(tag = 'div') {
    this.style = {};
    this.tagName = tag;
    this.dataset = {};
    this.events = {};
    this.selectors = {};
    this.attrs = {};
    this.children = [];
    this.hidden = false;
    this.value = '';
    this.checked = false;
    this.type = '';
    this.textContent = '';
    const classes = new Set();
    this.classList = {
      add: (v) => classes.add(v),
      remove: (v) => classes.delete(v),
      contains: (v) => classes.has(v),
    };
  }
  querySelector(s) {
    return (this.selectors[s] || [])[0] || null;
  }
  querySelectorAll(s) {
    return this.selectors[s] || [];
  }
  addEventListener(n, fn) {
    (this.events[n] ||= []).push(fn);
  }
  async fire(n, extra = {}) {
    for (const fn of this.events[n] || [])
      await fn({ target: this, currentTarget: this, preventDefault() {}, ...extra });
  }
  click() {
    return this.fire('click');
  }
  focus() {
    focus = this;
  }
  setAttribute(n, v) {
    this.attrs[n] = v;
  }
  getAttribute(n) {
    return this.attrs[n];
  }
  removeAttribute(n) {
    delete this.attrs[n];
  }
  append(...nodes) {
    for (const n of nodes) {
      if (n.parentElement) n.parentElement.children = n.parentElement.children.filter((c) => c !== n);
      n.parentElement = this;
      this.children.push(n);
    }
  }
  contains(n) {
    return this.children.includes(n) || this.children.some((c) => c.contains(n));
  }
  replaceWith(n) {
    this.replacement = n;
  }
  showModal() {
    this.open = true;
  }
  close() {
    this.open = false;
    return this.fire('close');
  }
  remove() {
    this.removed = true;
  }
  getBoundingClientRect() {
    return { top: 400, bottom: 444, right: 500 };
  }
  scrollBy(opts) {
    this.scrollOptions = opts;
  }
}
const el = (selectors = {}) => Object.assign(new Element(), { selectors });
const fields = Object.fromEntries(
  ['q', 'breed', 'origin', 'size', 'age', 'sex', 'kids', 'dogs', 'cats', 'special', 'sort'].map((k) => [
    k,
    Object.assign(el(), { type: ['kids', 'dogs', 'cats', 'special'].includes(k) ? 'checkbox' : 'text' }),
  ])
);
const extra = el(),
  extraCount = el();
const filtersToggle = el(),
  filtersBadge = el();
filtersToggle.setAttribute('aria-expanded', 'false');
filtersBadge.hidden = true;
const form = el({
  '[data-homeward-extra]': [extra],
  '[data-homeward-extra-count]': [extraCount],
  '[data-homeward-filters-toggle]': [filtersToggle],
  '[data-homeward-filters-badge]': [filtersBadge],
});
form.elements = { namedItem: (k) => fields[k] };
const dogs = Array.from({ length: 30 }, (_, i) => ({
  id: i + 1,
  name: 'Dog ' + i,
  origin: i ? 'usa' : 'local-bc',
  size: 'small',
  age: 'adult',
  sex: 'female',
  kids: i ? null : true,
  dogs: true,
  cats: false,
  special: false,
  first_synced: 1000 + i,
  waiting_since: 2000 - i,
}));
const items = dogs.map((d) => {
  const item = el({ a: [el()] });
  item.dataset.animalId = String(d.id);
  return item;
});
const grid = el(),
  courtesyGrid = el(),
  courtesyGroup = el();
grid.append(...items.slice(0, 28));
courtesyGrid.append(...items.slice(28));
courtesyGroup.append(courtesyGrid);
const more = el(),
  count = el(),
  empty = el(),
  clear = el();
clear.type = 'button';
const json = el();
json.textContent = JSON.stringify(dogs);
const listing = el({
  '[data-homeward-filters]': [form],
  '[data-homeward-animals-json]': [json],
  '[data-animal-id]': items,
  '[data-homeward-count]': [count],
  '[data-homeward-empty]': [empty],
  '[data-homeward-more]': [more],
  '[data-homeward-clear]': [clear],
  '[data-homeward-courtesy-group]': [courtesyGroup],
});
const mainImg = el();
mainImg.alt = 'Photo of Scout';
mainImg.src = 'https://bucket.s3.amazonaws.com/first.jpg';
const backdrop = el();
backdrop.src = mainImg.src;
const main = el({ '[data-homeward-main]': [mainImg], '[data-homeward-backdrop]': [backdrop] });
main.dataset.index = '0';
main.clientWidth = 400;
const strip = el();
strip.scrollLeft = 0;
strip.getBoundingClientRect = () => ({ left: 0, width: 300 });
strip.scrollTo = (opts) => {
  strip.scrolled = opts;
};
const thumbs = [el(), el()];
thumbs.forEach((t, i) => {
  t.dataset.index = String(i);
  t.getBoundingClientRect = () => ({ left: 400 + i * 80, width: 72 });
  t.scrollIntoView = () => {
    t.pageScrolled = true;
  };
});
const cardImage = el(),
  cardMedia = el({ img: [cardImage] });
cardImage.complete = false;
const photos = el();
photos.textContent = JSON.stringify([
  'https://bucket.s3.amazonaws.com/first.jpg',
  'https://bucket.s3.amazonaws.com/second.jpg',
]);
const gallery = el({
  '[data-homeward-photos]': [photos],
  '.homeward-animal-gallery__main': [main],
  '[data-index]': [main, ...thumbs],
  '.homeward-animal-gallery__thumb[data-index]': thumbs,
  '.homeward-animal-gallery__thumbs': [strip],
});
gallery.setAttribute('aria-label', 'Photos of Scout');
const track = el();
track.clientWidth = 400;
track.scrollWidth = 800;
track.scrollLeft = 0;
const previous = el(),
  next = el();
const carousel = el({
  '.homeward-animal-carousel__track': [track],
  '[data-homeward-prev]': [previous],
  '[data-homeward-next]': [next],
});
const continuation = el(),
  details = el({ '.homeward-animal-desc': [continuation] });
const facade = el();
facade.dataset = { homewardYt: 'abcdefghijk', title: 'Video of Scout' };
const back = el();
back.href = 'https://example.test/dogs/';
const shareStatus = el(),
  share = el();
share.dataset = { url: 'https://example.test/dogs/scout/', title: 'Scout' };
share.closest = () => el({ '[data-homeward-share-status]': [shareStatus] });
const body = el();
const html = el();
const themeMeta = el();
themeMeta.setAttribute('content', '#ffffff');
const document = el({
  'meta[name="theme-color"]': [themeMeta],
  '.homeward-animal-card__media': [cardMedia],
  '[data-homeward-animals-listing]': [listing],
  '[data-homeward-back]': [back],
  '[data-homeward-carousel]': [carousel],
  '[data-homeward-gallery]': [gallery],
  '[data-homeward-more-details]': [details],
  '[data-homeward-yt]': [facade],
  '[data-homeward-share]': [share],
});
document.body = body;
document.documentElement = html;
const created = [];
document.createElement = (tag) => {
  const node = new Element(tag);
  created.push(node);
  return node;
};
document.head = el();
const storage = new Map();
let copied = '';
let reduced = true;
let phone = false;
const timers = [];
const window = el();
window.location = new URL('https://example.test/dogs/?origin=local-bc&age=adult&sort=newest');
window.matchMedia = (query) => ({ matches: /max-width: 639px/.test(query) ? phone : reduced });
window.getComputedStyle = () => ({ backgroundColor: 'rgb(250, 243, 237)' });
window.requestAnimationFrame = (fn) => fn();
window.HTMLDialogElement = Element;
window.sessionStorage = { getItem: (k) => storage.get(k), setItem: (k, v) => storage.set(k, v) };
window.history = {
  replaceState: (a, b, url) => {
    window.location = new URL(url);
  },
};
window.isSecureContext = true;
window.innerHeight = 800;
html.clientWidth = 1000;
window.setTimeout = (fn) => timers.push(fn);
window.clearTimeout = () => {};
const runTimers = () => timers.splice(0).forEach((fn) => fn());
const navigator = {
  clipboard: {
    writeText: async (v) => {
      copied = v;
    },
  },
};
let now = 1000;
const context = {
  window,
  document,
  navigator,
  URL,
  URLSearchParams,
  HTMLDialogElement: Element,
  Date: { now: () => now },
};
vm.runInNewContext(
  fs.readFileSync(require.resolve('../../plugin/homeward-rescue/assets/js/animals.js'), 'utf8'),
  context
);
const checker = require('./lib/checks').create();
const { check } = checker;
(async () => {
  check(
    'shared query applies on load',
    !items[0].hidden && items[1].hidden && count.textContent === '1 animal'
  );
  check('filters badge counts active filters', filtersBadge.textContent === '2' && !filtersBadge.hidden);
  check(
    'filters button is labelled with the active count',
    filtersToggle.getAttribute('aria-label') === 'Filters, 2 active'
  );
  check(
    'phone filters start closed',
    filtersToggle.getAttribute('aria-expanded') === 'false' && !form.classList.contains('is-open')
  );
  extra.open = false;
  await filtersToggle.click();
  check(
    'filters button opens every filter',
    filtersToggle.getAttribute('aria-expanded') === 'true' && form.classList.contains('is-open') && extra.open
  );
  await filtersToggle.click();
  check(
    'filters button closes the panel again',
    filtersToggle.getAttribute('aria-expanded') === 'false' && !form.classList.contains('is-open')
  );
  check(
    'initial shared filters and sort saved and restored by back link',
    back.href.endsWith('?origin=local-bc&age=adult&sort=newest')
  );
  check(
    'shared advanced filters open disclosure and exclude primary fields from count',
    extra.open && extraCount.textContent === '(1 active)'
  );
  extra.open = false;
  check('JS reveals filters', !form.hidden);
  await form.fire('reset');
  check('reset removes URL filters', !window.location.search);
  check('reset hides the filters badge', filtersBadge.hidden && !filtersToggle.getAttribute('aria-label'));
  check(
    'reset clears advanced count without reopening disclosure',
    !extra.open && extraCount.textContent === ''
  );
  check('card photo spinner waits before showing', !cardMedia.classList.contains('is-loading'));
  runTimers();
  check('slow card photo shows spinner', cardMedia.classList.contains('is-loading'));
  await cardImage.fire('load');
  check('loaded card photo clears spinner', !cardMedia.classList.contains('is-loading'));
  check('paging shows first 24', items.filter((i) => !i.hidden).length === 24 && !more.hidden);
  await more.click();
  check('load more reveals remainder', items.filter((i) => !i.hidden).length === 30 && more.hidden);
  check('load more focuses first new dog', focus === items[24].querySelector('a'));
  fields.kids.checked = true;
  await form.fire('input');
  check('unknown compatibility excluded', items.filter((i) => !i.hidden).length === 1);
  check('query synchronizes checkbox', window.location.search === '?kids=1');
  check(
    'advanced count updates while respecting closed disclosure',
    extraCount.textContent === '(1 active)' && !extra.open
  );
  fields.sex.value = 'female';
  fields.special.checked = true;
  await form.fire('change');
  check('advanced count includes selects and toggles', extraCount.textContent === '(3 active)');
  fields.sex.value = '';
  fields.special.checked = false;
  await form.fire('change');
  fields.q.value = 'Nobody';
  await form.fire('input');
  check('empty state shown', !empty.hidden && count.textContent === '0 animals');
  await clear.click();
  check('empty clear restores focus to search', empty.hidden && focus === fields.q);
  fields.sort.value = 'newest';
  await form.fire('change');
  check(
    'newest moves DOM nodes before paginating',
    grid.children[0] === items[27] &&
      !items[27].hidden &&
      items[0].hidden &&
      items.filter((i) => !i.hidden).length === 24
  );
  check(
    'sort shares URL and session state without counting as a filter',
    window.location.search === '?sort=newest' &&
      storage.get('homeward-animals-filters') === 'sort=newest' &&
      extraCount.textContent === ''
  );
  check(
    'courtesy stays separate and sorts within its own group',
    courtesyGrid.children[0] === items[29] && courtesyGroup.hidden
  );
  await more.click();
  check(
    'sorted pagination focuses the first newly shown card',
    focus === items[3].querySelector('a') && !courtesyGroup.hidden
  );
  fields.origin.value = 'usa';
  fields.sort.value = 'longest-waiting';
  await form.fire('change');
  check(
    'waiting sort combines with filters and resets paging',
    grid.children[0] === items[27] &&
      items[0].hidden &&
      items.filter((i) => !i.hidden).length === 24 &&
      count.textContent === '29 animals'
  );
  await form.fire('reset');
  check(
    'reset restores original featured DOM order and paging',
    grid.children[0] === items[0] &&
      courtesyGrid.children[0] === items[28] &&
      !window.location.search &&
      items[24].hidden
  );
  window.location = new URL('https://example.test/dogs/?q=Dog+29&sort=longest-waiting');
  await window.fire('popstate');
  check('browser history restores matching dog', !items[29].hidden && items[0].hidden);
  check(
    'browser history restores sort and courtesy visibility',
    fields.sort.value === 'longest-waiting' && courtesyGrid.children[0] === items[29] && !courtesyGroup.hidden
  );
  check('browser history updates advanced count', extraCount.textContent === '' && !extra.open);
  const dialog = body.children.find((n) => n.tagName === 'dialog');
  check('lightbox image not requested on load', !dialog.children[1].children[1].src);
  await thumbs[1].click();
  await main.click();
  check(
    'main opens selected photo without resetting selection',
    dialog.open &&
      dialog.children[1].children[1].src.endsWith('second.jpg') &&
      mainImg.src.endsWith('second.jpg')
  );
  check('backdrop follows selected main photo', backdrop.src === mainImg.src);
  check('lightbox focuses close', focus === dialog.children[0].children[1]);
  const viewer = dialog.children[1];
  viewer.clientWidth = 400; // A real photo width, so a 10px drag is too short to count.
  const lightImg = viewer.children[1];
  const lightDots = dialog.children[2];
  const soft = dialog.children[3];
  check('lightbox has a dot per photo', lightDots.children.length === 2);
  check('lightbox dot follows selected photo', lightDots.children[1].classList.contains('is-active'));
  check('blurred backdrop follows selected photo', soft.src.endsWith('second.jpg') && soft.alt === '');
  check(
    'neighbouring photos are preloaded',
    created.some((n) => n.tagName === 'img' && String(n.src).endsWith('first.jpg'))
  );
  lightImg.complete = false;
  await dialog.fire('keydown', { key: 'ArrowRight' });
  runTimers();
  check('slow viewer photo shows spinner', viewer.classList.contains('is-loading') && viewer.attrs['aria-busy']);
  await lightImg.fire('load');
  check('loaded viewer photo clears spinner', !viewer.classList.contains('is-loading'));
  await viewer.children[2].click();
  check('next button still works', lightImg.src.endsWith('second.jpg'));
  await viewer.children[0].click();
  check('previous button still works', lightImg.src.endsWith('first.jpg'));
  await viewer.children[2].click();
  const swipeOn = async (node, from, to, over = {}) => {
    await node.fire('touchstart', { touches: [from] });
    await node.fire('touchmove', { touches: [to], cancelable: true, ...over });
    await node.fire('touchend', { changedTouches: [to] });
    runTimers();
  };
  let held = false;
  const hold = { preventDefault() { held = true; } };
  await swipeOn(viewer, { clientX: 300, clientY: 200 }, { clientX: 120, clientY: 215 }, hold);
  check('swiping left in the viewer shows the next photo', lightImg.src.endsWith('first.jpg') && held);
  held = false;
  await swipeOn(viewer, { clientX: 200, clientY: 100 }, { clientX: 230, clientY: 400 }, hold);
  check('a vertical drag in the viewer neither swipes nor holds the page', !held && lightImg.src.endsWith('first.jpg'));
  await viewer.fire('touchstart', { touches: [{ clientX: 300, clientY: 200 }] });
  await viewer.fire('touchmove', { touches: [{ clientX: 280, clientY: 212 }], cancelable: true, ...hold });
  check('an angled sideways drag holds the page still', held);
  check('the photo follows the finger', lightImg.style.transform === 'translateX(-20px)');
  held = false;
  await viewer.fire('touchmove', { touches: [{ clientX: 290, clientY: 205 }], cancelable: true, ...hold });
  await viewer.fire('touchend', { changedTouches: [{ clientX: 290, clientY: 205 }] });
  check('a short drag springs back without changing photo', lightImg.style.transform === '' && lightImg.src.endsWith('first.jpg'));
  await viewer.fire('touchstart', { touches: [{ clientX: 1, clientY: 1 }, { clientX: 5, clientY: 5 }] });
  await viewer.fire('touchmove', { touches: [{ clientX: 200, clientY: 5 }], cancelable: true, ...hold });
  check('a two finger gesture is left to pinch zoom', lightImg.style.transform === '');
  reduced = false;
  await swipeOn(viewer, { clientX: 100, clientY: 200 }, { clientX: 300, clientY: 190 });
  reduced = true;
  check('animated swipe ends at rest on the previous photo', lightImg.style.transform === '' && lightImg.src.endsWith('second.jpg'));
  await thumbs[0].click();
  check('thumbnail click selects that photo', mainImg.src.endsWith('first.jpg') && thumbs[0].attrs['aria-current'] === 'true');
  now = 5000;
  await swipeOn(main, { clientX: 300, clientY: 200 }, { clientX: 100, clientY: 205 });
  check('swiping the main photo shows the next one', mainImg.src.endsWith('second.jpg') && backdrop.src === mainImg.src);
  const mainDots = main.children.find((n) => n.className === 'homeward-animal-gallery__dots');
  check('main photo dots follow the selected photo', mainDots.children[1].classList.contains('is-active'));
  check(
    'thumbnail strip slides to the photo without scrolling the page',
    thumbs[1].attrs['aria-current'] === 'true' && strip.scrolled.left === 480 - 114 && !thumbs.some((t) => t.pageScrolled)
  );
  dialog.open = false;
  await main.click();
  check('a swipe on the main photo does not open the viewer', !dialog.open);
  now = 6000;
  await dialog.fire('keydown', { key: 'Home' });
  check('Home selects first photo', dialog.children[1].children[1].src.endsWith('first.jpg'));
  await dialog.fire('keydown', { key: 'ArrowLeft' });
  check('arrow key wraps gallery', dialog.children[1].children[1].src.endsWith('second.jpg'));
  await dialog.close();
  check(
    'closing returns focus and unlocks scroll',
    focus === main && !html.classList.contains('homeward-lightbox-open')
  );
  phone = true;
  await main.click();
  check(
    'phone viewer tints the status bar with the theme background',
    themeMeta.getAttribute('content') === 'rgb(250, 243, 237)' &&
      html.style.backgroundColor === 'rgb(250, 243, 237)'
  );
  await dialog.close();
  check(
    'closing restores the site colours',
    themeMeta.getAttribute('content') === '#ffffff' && html.style.backgroundColor === ''
  );
  phone = false;
  check('carousel starts with previous disabled', previous.disabled && !next.disabled);
  await next.click();
  check(
    'reduced motion carousel uses instant scrolling',
    track.scrollOptions.behavior === 'instant' && track.scrollOptions.left > 0
  );
  track.scrollLeft = 400;
  await track.fire('scroll');
  check('carousel disables next at the end', !previous.disabled && next.disabled);
  track.scrollLeft = 200;
  await track.fire('scroll');
  check('carousel enables both controls between ends', !previous.disabled && !next.disabled);
  track.scrollLeft = 0;
  track.scrollWidth = 400;
  await window.fire('resize');
  check('carousel disables both when all cards fit after resize', previous.disabled && next.disabled);
  reduced = false;
  track.scrollWidth = 800;
  await window.fire('resize');
  await track.fire('keydown', { key: 'ArrowRight' });
  check(
    'carousel keyboard scroll is smooth without reduced motion',
    track.scrollOptions.behavior === 'smooth'
  );
  details.open = true;
  await details.fire('toggle');
  check('expander focuses continuation', focus === continuation);
  check('video has no frame before click', !facade.replacement);
  await facade.click();
  check(
    'video loads nocookie only on click',
    facade.replacement.src.startsWith('https://www.youtube-nocookie.com/embed/') &&
      focus === facade.replacement
  );
  await share.click();
  const panel = body.children.find((n) => n.className === 'homeward-animal-share');
  await panel.children[2].children[1].click();
  check(
    'share panel copies link',
    copied === share.dataset.url && shareStatus.textContent.includes('copied')
  );
  console.log(checker.count + ' interaction checks passed (DOM doubles).');
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
