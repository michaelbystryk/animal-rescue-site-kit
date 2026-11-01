/* Happy Tails: links first, instant tabs and a native reader when supported. */
(() => {
  'use strict';

  const plainClick = (event) =>
    event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
  const replaceURL = (url) => {
    // Sandboxed previews can disallow history writes; the reader still works.
    try {
      window.history.replaceState(window.history.state, '', url);
    } catch (_) {
      /* No navigation fallback needed. */
    }
  };
  const archives = [];
  const batches = new Map();

  document.querySelectorAll('[data-homeward-happy-tails]').forEach((root) => {
    const tablist = root.querySelector('[data-homeward-tabs]');
    const tabs = Array.from(tablist.querySelectorAll('[data-homeward-kind]'));
    const select = (kind, syncURL = true, focus = false) => {
      const selected = tabs.find((tab) => tab.dataset.homewardKind === kind) || tabs[0];
      tabs.forEach((tab) => {
        const active = tab === selected;
        tab.setAttribute('aria-selected', String(active));
        tab.classList.toggle('is-active', active);
        tab.tabIndex = active ? 0 : -1;
        document.getElementById(tab.getAttribute('aria-controls')).hidden = !active;
      });
      if (syncURL) {
        const url = new URL(window.location.href);
        if (selected.dataset.homewardKind === 'story') url.searchParams.delete('homeward_kind');
        else url.searchParams.set('homeward_kind', selected.dataset.homewardKind);
        replaceURL(url);
      }
      if (focus) selected.focus();
    };
    tablist.setAttribute('role', 'tablist');
    tabs.forEach((tab, index) => {
      const panel = root.querySelector(`[data-homeward-panel="${tab.dataset.homewardKind}"]`);
      tab.setAttribute('role', 'tab');
      tab.removeAttribute('aria-current');
      tab.setAttribute('aria-controls', panel.id);
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', tab.id);
      panel.removeAttribute('aria-label');
      panel.tabIndex = 0;
      tab.addEventListener('click', (event) => {
        if (!plainClick(event)) return;
        event.preventDefault();
        select(tab.dataset.homewardKind);
      });
      tab.addEventListener('keydown', (event) => {
        if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
        let next;
        if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
        if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = tabs.length - 1;
        if (event.key === ' ') next = index;
        if (next === undefined) return;
        event.preventDefault();
        select(tabs[next].dataset.homewardKind, true, true);
      });
    });
    select(
      new URL(window.location.href).searchParams.get('homeward_kind') ||
        (tabs.find((tab) => tab.classList.contains('is-active')) || tabs[0]).dataset.homewardKind,
      false
    );
    archives.push({ root, select });
  });

  document.querySelectorAll('[data-homeward-batch]').forEach((grid) => {
    const cards = Array.from(grid.querySelectorAll('.homeward-story-card'));
    const button = grid.parentElement.querySelector('[data-homeward-more]');
    if (!button) return;
    const size = Number(grid.dataset.homewardBatch) || 12;
    let visible = size;
    const update = () => {
      cards.forEach((card, index) => {
        card.hidden = index >= visible;
      });
      button.parentElement.hidden = visible >= cards.length;
    };
    button.addEventListener('click', () => {
      const firstNew = cards[visible];
      visible += size;
      update();
      // Continue the reading order, even when the final batch removes the button.
      firstNew?.querySelector('.homeward-story-card__link').focus({ preventScroll: true });
    });
    batches.set(grid, (card) => {
      visible = Math.max(visible, Math.ceil((cards.indexOf(card) + 1) / size) * size);
      update();
    });
    update();
  });

  window.addEventListener('popstate', () => {
    const kind = new URL(window.location.href).searchParams.get('homeward_kind') || 'story';
    archives.forEach((archive) => archive.select(kind, false));
  });
  const dialog = document.getElementById('homeward-story-dialog');
  if (!dialog || typeof dialog.showModal !== 'function') return;
  const items = [];
  document.querySelectorAll('.homeward-story-card').forEach((card) => {
    try {
      const data = JSON.parse(card.querySelector('.homeward-story-data').textContent);
      const link = card.querySelector('.homeward-story-card__link');
      if (!data.slug || !link) return;
      const group = card.closest('[data-homeward-story-group]') || card.closest('.homeward-stories') || card;
      items.push({ card, link, group, data });
      link.setAttribute('aria-haspopup', 'dialog');
      link.addEventListener('click', (event) => {
        if (!plainClick(event) || link.hasAttribute('download') || (link.target && link.target !== '_self'))
          return;
        event.preventDefault();
        open(items.find((item) => item.link === link));
      });
    } catch (_) {
      /* Malformed/missing data keeps its normal single-story link. */
    }
  });

  const title = dialog.querySelector('.homeward-story-dialog__title');
  const photo = dialog.querySelector('.homeward-story-dialog__photo');
  const text = dialog.querySelector('.homeward-story-dialog__text');
  const position = dialog.querySelector('.homeward-story-dialog__position');
  const previous = dialog.querySelector('[data-story-prev]');
  const next = dialog.querySelector('[data-story-next]');
  let current;
  let returnFocus;
  let savedScroll;
  let previousHash = '';
  let preserveHashOnClose = false;

  const siblings = () =>
    items.filter((item) => item.group === current.group && item.data.kind === current.data.kind);
  const reveal = (item) => {
    archives.find((archive) => archive.root.contains(item.card))?.select(item.data.kind);
    batches.get(item.card.closest('[data-homeward-batch]'))?.(item.card);
  };
  const render = () => {
    title.textContent = current.data.title;
    dialog.querySelectorAll('[data-story-field]').forEach((field) => {
      field.textContent = current.data[field.dataset.storyField] || '';
      field.hidden = !field.textContent;
    });
    // Only these two fields contain HTML, both sanitized with wp_kses_post in PHP.
    photo.innerHTML = current.data.image || '';
    photo.hidden = !current.data.image;
    text.innerHTML = current.data.text;
    const group = siblings();
    const index = group.indexOf(current);
    previous.disabled = index === 0;
    next.disabled = index === group.length - 1;
    position.textContent = position.dataset.positionLabel
      .replace('%1$s', String(index + 1))
      .replace('%2$s', String(group.length));
    dialog.scrollTop = 0;
  };
  const syncHash = () => {
    const url = new URL(window.location.href);
    url.hash = `story-${current.data.slug}`;
    replaceURL(url);
  };
  const lockScroll = () => {
    const body = document.body;
    const properties = ['position', 'top', 'left', 'width', 'overflow', 'padding-right', 'box-sizing'];
    savedScroll = {
      x: window.scrollX,
      y: window.scrollY,
      styles: properties.map((property) => [
        property,
        body.style.getPropertyValue(property),
        body.style.getPropertyPriority(property),
      ]),
    };
    const gutter = window.innerWidth - document.documentElement.clientWidth;
    const padding = parseFloat(window.getComputedStyle(body).paddingRight) || 0;
    body.style.position = 'fixed';
    body.style.top = `${-savedScroll.y}px`;
    body.style.left = `${-savedScroll.x}px`;
    body.style.width = '100%';
    body.style.boxSizing = 'border-box';
    body.style.overflow = 'hidden';
    body.style.paddingRight = `${padding + gutter}px`;
  };
  const open = (item) => {
    if (!item) return;
    if (!dialog.open) {
      returnFocus = item.link;
      previousHash = window.location.hash.startsWith('#story-') ? '' : window.location.hash;
      reveal(item);
      lockScroll();
    }
    current = item;
    render();
    if (!dialog.open) dialog.showModal();
    syncHash();
    title.focus({ preventScroll: true });
  };
  const move = (delta) => {
    const group = siblings();
    const item = group[group.indexOf(current) + delta];
    if (item) open(item);
  };
  previous.addEventListener('click', () => move(-1));
  next.addEventListener('click', () => move(1));
  dialog.querySelector('.homeward-story-dialog__close').addEventListener('click', () => dialog.close());
  dialog.addEventListener('keydown', (event) => {
    if (
      event.altKey ||
      event.ctrlKey ||
      event.metaKey ||
      event.shiftKey ||
      event.target.closest('input, textarea, select, [contenteditable]')
    )
      return;
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      event.preventDefault();
      move(event.key === 'ArrowLeft' ? -1 : 1);
    }
  });
  // A drag starting inside the sheet must not accidentally dismiss it.
  let backdropDown = false;
  const outside = (event) => {
    const rect = dialog.getBoundingClientRect();
    return (
      event.target === dialog &&
      (event.clientX < rect.left ||
        event.clientX > rect.right ||
        event.clientY < rect.top ||
        event.clientY > rect.bottom)
    );
  };
  dialog.addEventListener('pointerdown', (event) => {
    backdropDown = outside(event);
  });
  dialog.addEventListener('click', (event) => {
    if (backdropDown && outside(event)) dialog.close();
    backdropDown = false;
  });
  dialog.addEventListener('close', () => {
    if (savedScroll) {
      savedScroll.styles.forEach(([property, value, priority]) => {
        if (value) document.body.style.setProperty(property, value, priority);
        else document.body.style.removeProperty(property);
      });
      window.scrollTo({ left: savedScroll.x, top: savedScroll.y, behavior: 'instant' });
      savedScroll = null;
    }
    if (!preserveHashOnClose) {
      const url = new URL(window.location.href);
      url.hash = previousHash;
      replaceURL(url);
    }
    preserveHashOnClose = false;
    returnFocus?.focus({ preventScroll: true });
  });
  const followLocation = () => {
    let slug;
    try {
      slug = decodeURIComponent(window.location.hash.slice(7));
    } catch (_) {
      return;
    }
    const item =
      window.location.hash.startsWith('#story-') &&
      items.find(
        (candidate) => candidate.data.slug === slug || candidate.data.slug === window.location.hash.slice(7)
      );
    if (item) {
      // External hash navigation may switch tabs while the reader is open.
      if (dialog.open) returnFocus = item.link;
      reveal(item);
      open(item);
    } else {
      if (dialog.open) {
        preserveHashOnClose = true;
        dialog.close();
      }
      const kind = new URL(window.location.href).searchParams.get('homeward_kind') || 'story';
      archives.forEach((archive) => archive.select(kind, false));
    }
  };
  window.addEventListener('hashchange', followLocation);
  window.addEventListener('popstate', followLocation);
  followLocation();
})();
