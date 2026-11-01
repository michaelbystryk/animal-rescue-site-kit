/* Progressive enhancement: all animals, photos and stories remain usable without JS. */
(function () {
  'use strict';
  const l10n = typeof window !== 'undefined' ? window.homewardAnimalsL10n || {} : {};
  const text = (key, fallback) => l10n[key] || fallback;
  const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const storageKey = 'homeward-animals-filters';
  const filterKeys = [
    'q',
    'species',
    'origin',
    'size',
    'age',
    'sex',
    'breed',
    'kids',
    'dogs',
    'cats',
    'special',
  ];
  const keys = [...filterKeys, 'sort'];
  const listings = [];
  const saveFilters = (params) => {
    if (
      l10n.archivePath &&
      window.location.pathname.replace(/\/$/, '') !== l10n.archivePath.replace(/\/$/, '')
    )
      return;
    const saved = new URLSearchParams();
    keys.forEach((key) => {
      if (params.has(key)) saved.set(key, params.get(key));
    });
    try {
      window.sessionStorage.setItem(storageKey, saved.toString());
    } catch (_) {
      /* Optional. */
    }
  };
  const fold = (s) =>
    String(s || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLocaleLowerCase();
  const matches = (dog, filters) =>
    !!dog &&
    filterKeys.every((key) => {
      const value = filters.get(key);
      if (!value) return true;
      if (key === 'q') return fold(dog.name).includes(fold(value.trim()));
      if (['kids', 'dogs', 'cats', 'special'].includes(key)) return value !== '1' || dog[key] === true;
      return dog[key] === value;
    });
  const compare = (a, b, sort) => {
    if (!['newest', 'longest-waiting'].includes(sort)) return 0;
    const key = sort === 'newest' ? 'first_synced' : 'waiting_since';
    const ad = Number(a[key]) || 0,
      bd = Number(b[key]) || 0;
    if (!ad || !bd) return (ad ? 0 : 1) - (bd ? 0 : 1); // Unknown dates last.
    return sort === 'newest' ? bd - ad || b.id - a.id : ad - bd || a.id - b.id;
  };
  const localQR = typeof module !== 'undefined' && module.exports ? require('./qr.js') : window.homewardQR;
  const qrMatrix = (value) => localQR.qrMatrix(value);
  // Export pure filtering/sorting for the dependency-free Node regression suite.
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { matches, fold, compare, qrMatrix };
    return;
  }

  document.querySelectorAll('[data-homeward-animals-listing]').forEach((root) => {
    const form = root.querySelector('[data-homeward-filters]');
    const data = root.querySelector('[data-homeward-animals-json]');
    if (!form || !data) return;
    let dogs, countLabels;
    try {
      dogs = JSON.parse(data.textContent);
      countLabels = JSON.parse(root.dataset?.homewardCountLabels || '[]');
      if (!Array.isArray(dogs) || !Array.isArray(countLabels)) return;
    } catch (_) {
      return;
    }
    const map = new Map(dogs.map((d) => [String(d.id), d]));
    const items = Array.from(root.querySelectorAll('[data-animal-id]'));
    const groups = [...new Set(items.map((item) => item.parentElement))];
    const count = root.querySelector('[data-homeward-count]');
    const empty = root.querySelector('[data-homeward-empty]');
    const more = root.querySelector('[data-homeward-more]');
    const extra = form.querySelector('[data-homeward-extra]');
    const extraCount = form.querySelector('[data-homeward-extra-count]');
    const activeExtra = (filters) =>
      ['age', 'sex', 'breed', 'kids', 'dogs', 'cats', 'special'].filter((key) => filters.has(key)).length;
    let limit = 24;
    const read = () => {
      const params = new URLSearchParams();
      keys.forEach((key) => {
        const field = form.elements.namedItem(key);
        if (!field) return;
        const value = field.type === 'checkbox' ? (field.checked ? '1' : '') : field.value.trim();
        if (value) params.set(key, value);
      });
      return params;
    };
    const set = (params) =>
      keys.forEach((key) => {
        const field = form.elements.namedItem(key);
        if (!field) return;
        if (field.type === 'checkbox') field.checked = params.get(key) === '1';
        else field.value = params.get(key) || '';
      });
    const render = () => {
      const filters = read();
      if (extraCount) {
        const active = activeExtra(filters);
        extraCount.textContent = active ? text('activeFilters', '(%d active)').replace('%d', active) : '';
      }
      // Move real nodes so reading/tab order matches visual order. Courtesy stays separate.
      const ordered = groups.flatMap((group) => {
        const sorted = items
          .filter((item) => item.parentElement === group)
          .sort((a, b) =>
            compare(map.get(a.dataset.animalId), map.get(b.dataset.animalId), filters.get('sort'))
          );
        if (sorted.some((item, i) => group.children[i] !== item)) group.append(...sorted);
        return sorted;
      });
      const visible = ordered.filter((item) => matches(map.get(item.dataset.animalId), filters));
      items.forEach((item) => {
        item.hidden = true;
      });
      visible.slice(0, limit).forEach((item) => {
        item.hidden = false;
      });
      const n = visible.length;
      count.textContent =
        countLabels[n] ?? (n === 1 ? text('one', '%d animal') : text('many', '%d animals')).replace('%d', n);
      empty.hidden = n !== 0;
      more.hidden = n <= limit;
      more.textContent = text('more', 'Show more animals (%d more)').replace('%d', Math.max(0, n - limit));
      root.querySelectorAll('[data-homeward-courtesy-group]').forEach((group) => {
        group.hidden = !visible.slice(0, limit).some((item) => group.contains(item));
      });
      return visible;
    };
    const sync = () => {
      limit = 24;
      const url = new URL(window.location.href);
      const values = read();
      keys.forEach((key) => {
        url.searchParams.delete(key);
        if (values.has(key)) url.searchParams.set(key, values.get(key));
      });
      try {
        window.history.replaceState(null, '', url);
      } catch (_) {
        /* Embedded preview. */
      }
      // Store filters only, never an untrusted destination URL.
      saveFilters(values);
      listings.forEach((list) => {
        list.set(values);
        list.reset();
        list.render();
      });
    };
    listings.push({
      set,
      render,
      reset: () => {
        limit = 24;
      },
    });
    form.hidden = false;
    set(new URLSearchParams(window.location.search));
    // Reveal shared advanced selections once; later updates respect the user's disclosure choice.
    if (extra) extra.open = activeExtra(read()) > 0;
    render();
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      sync();
    });
    form.addEventListener('input', sync);
    form.addEventListener('change', sync);
    form.addEventListener('reset', (e) => {
      e.preventDefault();
      set(new URLSearchParams());
      sync();
    });
    root.querySelectorAll('[data-homeward-clear]').forEach((button) => {
      if (button.type !== 'reset')
        button.addEventListener('click', () => {
          set(new URLSearchParams());
          sync();
          form.elements.namedItem('q').focus();
        });
    });
    more.addEventListener('click', () => {
      const previous = limit;
      limit += 24;
      const visible = render();
      const first = visible[previous] && visible[previous].querySelector('a');
      if (first) first.focus({ preventScroll: true });
    });
  });
  window.addEventListener('popstate', () => {
    listings.forEach((list) => {
      list.set(new URLSearchParams(window.location.search));
      list.reset();
      list.render();
    });
    if (listings.length) saveFilters(new URLSearchParams(window.location.search));
  });
  if (listings.length) saveFilters(new URLSearchParams(window.location.search));
  document.querySelectorAll('[data-homeward-back]').forEach((link) => {
    try {
      const saved = new URLSearchParams(window.sessionStorage.getItem(storageKey) || '');
      const url = new URL(link.href);
      keys.forEach((key) => {
        if (saved.has(key)) url.searchParams.set(key, saved.get(key));
      });
      link.href = url.href;
    } catch (_) {
      /* Plain /dogs/ is still useful. */
    }
  });

  document.querySelectorAll('[data-homeward-carousel]').forEach((root) => {
    const track = root.querySelector('.homeward-animal-carousel__track');
    if (!track) return;
    const prev = root.querySelector('[data-homeward-prev]');
    const next = root.querySelector('[data-homeward-next]');
    root.classList.add('is-enhanced');
    const update = () => {
      prev.disabled = track.scrollLeft <= 2;
      next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 2;
    };
    const step = (direction) =>
      track.scrollBy({
        left: direction * track.clientWidth * 0.85,
        behavior: reduced() ? 'instant' : 'smooth',
      });
    prev.addEventListener('click', () => step(-1));
    next.addEventListener('click', () => step(1));
    track.addEventListener('scroll', update, { passive: true });
    track.addEventListener('keydown', (e) => {
      if (e.target !== track || !['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
      e.preventDefault();
      step(e.key === 'ArrowLeft' ? -1 : 1);
    });
    if (window.ResizeObserver) new ResizeObserver(update).observe(track);
    else window.addEventListener('resize', update);
    update();
  });

  const make = (tag, cls, value) => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (value) node.textContent = value;
    return node;
  };
  document.querySelectorAll('[data-homeward-gallery]').forEach((root) => {
    if (!window.HTMLDialogElement || !HTMLDialogElement.prototype.showModal) return;
    let photos;
    try {
      photos = JSON.parse(root.querySelector('[data-homeward-photos]').textContent);
    } catch (_) {
      return;
    }
    const main = root.querySelector('.homeward-animal-gallery__main');
    const mainImage = main.querySelector('[data-homeward-main]');
    const backdrop = main.querySelector('[data-homeward-backdrop]');
    const thumbs = Array.from(root.querySelectorAll('.homeward-animal-gallery__thumb[data-index]'));
    let index = 0;
    let trigger = main;
    const dialog = make('dialog', 'homeward-animal-lightbox');
    dialog.setAttribute('aria-label', root.getAttribute('aria-label'));
    const bar = make('div', 'homeward-animal-lightbox__bar');
    const counter = make('p');
    counter.setAttribute('aria-live', 'polite');
    const button = (value, label) => {
      const b = make('button', 'homeward-animal-iconbtn', value);
      b.type = 'button';
      b.setAttribute('aria-label', label);
      return b;
    };
    const close = button('×', text('close', 'Close photo viewer'));
    const prev = button('←', text('prev', 'Previous photo'));
    const next = button('→', text('next', 'Next photo'));
    const img = make('img', 'homeward-animal-lightbox__image');
    img.alt = mainImage.alt;
    img.width = 1200;
    img.height = 1200;
    bar.append(counter, close);
    const viewer = make('div', 'homeward-animal-lightbox__viewer');
    viewer.append(prev, img, next);
    dialog.append(bar, viewer);
    document.body.append(dialog);
    prev.hidden = next.hidden = photos.length < 2;
    const show = (n) => {
      index = (n + photos.length) % photos.length;
      img.src = photos[index];
      counter.textContent = text('counter', 'Photo %1$d of %2$d')
        .replace('%1$d', index + 1)
        .replace('%2$d', photos.length);
    };
    const open = (e) => {
      e.preventDefault();
      trigger = e.currentTarget;
      show(index);
      dialog.showModal();
      document.documentElement.classList.add('homeward-lightbox-open');
      close.focus();
    };
    main.addEventListener('click', open);
    main.setAttribute('aria-haspopup', 'dialog');
    main.setAttribute('role', 'button');
    main.addEventListener('keydown', (e) => {
      if (e.key === ' ') {
        e.preventDefault();
        main.click();
      }
    });
    thumbs.forEach((thumb) => {
      thumb.setAttribute('role', 'button');
      thumb.addEventListener('click', (e) => {
        e.preventDefault();
        index = Number(thumb.dataset.index);
        mainImage.src = photos[index];
        main.href = photos[index];
        if (backdrop) backdrop.src = photos[index];
        thumbs.forEach((t) => t.removeAttribute('aria-current'));
        thumb.setAttribute('aria-current', 'true');
      });
      thumb.addEventListener('keydown', (e) => {
        if (e.key === ' ') {
          e.preventDefault();
          thumb.click();
        }
      });
    });
    close.addEventListener('click', () => dialog.close());
    prev.addEventListener('click', () => show(index - 1));
    next.addEventListener('click', () => show(index + 1));
    dialog.addEventListener('click', (e) => {
      if (e.target === dialog) dialog.close();
    });
    dialog.addEventListener('keydown', (e) => {
      if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) {
        e.preventDefault();
        show(
          e.key === 'Home'
            ? 0
            : e.key === 'End'
              ? photos.length - 1
              : index + (e.key === 'ArrowLeft' ? -1 : 1)
        );
      }
    });
    dialog.addEventListener('close', () => {
      document.documentElement.classList.remove('homeward-lightbox-open');
      trigger.focus({ preventScroll: true });
    });
  });

  document.querySelectorAll('[data-homeward-more-details]').forEach((details) => {
    details.addEventListener('toggle', () => {
      if (details.open) details.querySelector('.homeward-animal-desc').focus({ preventScroll: true });
    });
  });
  document.querySelectorAll('[data-homeward-yt]').forEach((facade) => {
    facade.addEventListener('click', (e) => {
      if (!/^[\w-]{11}$/.test(facade.dataset.homewardYt)) return;
      e.preventDefault();
      // The sole iframe exception is the explicitly requested, click-to-load video.
      const frame = make('iframe', 'homeward-animal-video__frame');
      frame.src = 'https://www.youtube-nocookie.com/embed/' + facade.dataset.homewardYt + '?autoplay=1&rel=0';
      frame.title = facade.dataset.title;
      frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
      frame.referrerPolicy = 'strict-origin-when-cross-origin';
      frame.setAttribute('allowfullscreen', '');
      frame.width = 800;
      frame.height = 450;
      facade.replaceWith(frame);
      frame.focus({ preventScroll: true });
    });
  });
  document.querySelectorAll('[data-homeward-share]').forEach((button, index) => {
    const url = button.dataset.url,
      name = button.dataset.title;
    const message = text('shareText', 'Meet %s').replace('%s', () => name);
    const status = button.closest('.homeward-animal-actions').querySelector('[data-homeward-share-status]');
    status.classList.add('homeward-animal-share__status');
    const nativeShare = () =>
      typeof navigator.share === 'function' &&
      (window.matchMedia('(pointer: coarse)').matches || window.matchMedia('(max-width: 600px)').matches);
    const panel = make('div', 'homeward-animal-share');
    panel.id = 'homeward-animal-share-' + index;
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-labelledby', panel.id + '-heading');
    const heading = make('div', 'homeward-animal-share__header');
    if (button.dataset.thumb) {
      const thumb = make('img', 'homeward-animal-share__thumb');
      thumb.src = button.dataset.thumb;
      thumb.alt = '';
      thumb.width = thumb.height = 48;
      thumb.loading = 'lazy';
      thumb.addEventListener('error', () => {
        thumb.hidden = true;
      });
      heading.append(thumb);
    }
    const intro = make('div', 'homeward-animal-share__intro');
    const caption = make(
      'p',
      '',
      text('shareHeading', "Share %s's page").replace('%s', () => name)
    );
    caption.id = panel.id + '-heading';
    intro.append(make('strong', '', name), caption);
    const close = make('button', 'homeward-animal-share__close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', text('shareClose', 'Close sharing options'));
    heading.append(intro, close);
    const label = make('label', 'homeward-animal-share__label', text('copyLink', 'Copy link'));
    const input = make('input', 'homeward-animal-share__input');
    input.type = 'text';
    input.readOnly = true;
    input.value = url;
    input.id = panel.id + '-url';
    label.htmlFor = input.id;
    input.addEventListener('click', () => input.select());
    const copy = make('button', 'homeward-animal-share__copy', text('copy', 'Copy'));
    copy.type = 'button';
    const field = make('div', 'homeward-animal-share__field');
    field.append(input, copy);
    const socials = make('div', 'homeward-animal-share__socials');
    const encodedURL = encodeURIComponent(url),
      encodedMessage = encodeURIComponent(message);
    const destinations = [
      ['facebook', 'Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' + encodedURL],
      ['x', 'X', 'https://x.com/intent/post?text=' + encodedMessage + '&url=' + encodedURL],
      [
        'email',
        'Email',
        'mailto:?subject=' +
          encodeURIComponent(text('shareSubject', 'Meet %s').replace('%s', () => name)) +
          '&body=' +
          encodeURIComponent(message + '\n\n' + url),
      ],
      ['whatsapp', 'WhatsApp', 'https://wa.me/?text=' + encodeURIComponent(message + '\n' + url)],
    ];
    const networks = Array.isArray(l10n.shareNetworks)
      ? l10n.shareNetworks
      : ['facebook', 'x', 'email', 'whatsapp'];
    destinations
      .filter(([key]) => networks.includes(key))
      .forEach(([key, fallback, href]) => {
        const title = text(key, fallback),
          link = make('a', 'homeward-animal-share__social', title);
        link.href = href;
        link.target = '_blank';
        link.rel = 'noopener';
        link.setAttribute('aria-label', title + text('shareNewTab', ' (opens in a new tab)'));
        socials.append(link);
      });
    const qrToggle = make('button', 'homeward-animal-share__qr-toggle', text('qrShow', 'Show QR code'));
    qrToggle.type = 'button';
    const qr = make('div', 'homeward-animal-share__qr');
    qr.id = panel.id + '-qr';
    qr.hidden = true;
    qrToggle.setAttribute('aria-controls', qr.id);
    qrToggle.setAttribute('aria-expanded', 'false');
    panel.append(heading, label, field, socials, qrToggle, qr, status);
    document.body.append(panel);
    button.setAttribute('aria-controls', panel.id);
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-haspopup', 'dialog');
    button.classList.remove('homeward-js-only');

    const position = () => {
      if (panel.hidden) return;
      const viewport = window.visualViewport;
      const width = viewport ? viewport.width : document.documentElement.clientWidth;
      const height = viewport ? viewport.height : window.innerHeight;
      const left = viewport ? viewport.offsetLeft : 0,
        top = viewport ? viewport.offsetTop : 0;
      const rect = button.getBoundingClientRect(),
        gap = 10,
        margin = 12;
      // Keep clear of the sticky site header (and the admin bar) so the panel never tucks under it.
      const covers = document.querySelectorAll(
        'header, [role="banner"], .wp-block-template-part, #wpadminbar'
      );
      const covered = Array.from(covers).reduce((max, el) => {
        const box = el.getBoundingClientRect(),
          style = window.getComputedStyle(el);
        return ['sticky', 'fixed'].includes(style.position) &&
          box.bottom > top &&
          box.top <= top + (parseFloat(style.top) || 0) + 1
          ? Math.max(max, box.bottom)
          : max;
      }, top);
      const minTop = Math.max(top + margin, covered + margin);
      panel.style.maxWidth = Math.max(0, width - margin * 2) + 'px';
      panel.style.maxHeight = Math.max(0, top + height - margin - minTop) + 'px';
      const panelWidth = panel.offsetWidth,
        panelHeight = panel.offsetHeight;
      const below = rect.bottom + gap,
        above = rect.top - panelHeight - gap;
      const y = below + panelHeight <= top + height - margin || above < minTop ? below : above;
      panel.style.left =
        Math.max(left + margin, Math.min(rect.right - panelWidth, left + width - panelWidth - margin)) + 'px';
      panel.style.top = Math.max(minTop, Math.min(y, top + height - panelHeight - margin)) + 'px';
      panel.style.transformOrigin = y < rect.top ? 'right bottom' : 'right top';
    };
    const open = () => {
      status.textContent = '';
      panel.hidden = false;
      button.setAttribute('aria-expanded', 'true');
      position();
      copy.focus({ preventScroll: true });
    };
    const dismiss = (refocus = true) => {
      panel.hidden = true;
      button.setAttribute('aria-expanded', 'false');
      if (refocus) button.focus({ preventScroll: true });
    };
    close.addEventListener('click', () => dismiss());
    document.addEventListener('focusin', (event) => {
      if (!panel.hidden && !panel.contains(event.target) && event.target !== button) dismiss(false);
    });
    document.addEventListener('click', (event) => {
      if (!panel.hidden && !panel.contains(event.target) && !button.contains(event.target)) dismiss();
    });
    document.addEventListener('keydown', (event) => {
      if (!panel.hidden && event.key === 'Escape') {
        event.preventDefault();
        dismiss();
      }
    });
    window.addEventListener('resize', position);
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', position);
      window.visualViewport.addEventListener('scroll', position);
    }
    let queued = false;
    document.addEventListener(
      'scroll',
      (event) => {
        if (panel.hidden || panel.contains(event.target) || queued) return;
        queued = true;
        window.requestAnimationFrame(() => {
          queued = false;
          position();
        });
      },
      { capture: true, passive: true }
    );
    if (window.ResizeObserver) new ResizeObserver(position).observe(panel);

    let copyTimer;
    copy.addEventListener('click', async () => {
      status.textContent = '';
      let copied = false;
      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(url);
          copied = true;
        }
      } catch (_) {
        /* Try the selected field next. */
      }
      if (!copied && !panel.hidden) {
        input.focus({ preventScroll: true });
        input.select();
        try {
          copied = document.execCommand('copy');
        } catch (_) {
          /* Keep the selected URL available for manual copy. */
        }
        if (copied) copy.focus({ preventScroll: true });
      }
      if (copied) {
        copy.textContent = text('copyDone', 'Copied ✓');
        status.textContent = text('copied', 'Link copied');
        window.clearTimeout(copyTimer);
        copyTimer = window.setTimeout(() => {
          copy.textContent = text('copy', 'Copy');
          status.textContent = '';
        }, 2000);
      } else if (!panel.hidden)
        status.textContent = text('copyFail', 'Select and copy the link to share it.');
    });
    qrToggle.addEventListener('click', () => {
      if (!qr.children.length) {
        try {
          const source = localQR.qrSource(url);
          const code = make('img');
          code.src = source;
          code.width = code.height = 180;
          code.alt = text('qrLabel', "QR code for %s's page").replace('%s', () => name);
          const save = make('a', 'homeward-animal-share__save', text('qrSave', 'Save QR code'));
          save.href = source;
          save.download = 'animal-page-qr.svg';
          qr.append(
            code,
            make('p', '', text('qrHint', 'Scan to view this page. Save the code for an adoption poster.')),
            save
          );
        } catch (_) {
          qr.append(
            make('p', '', text('qrFail', 'A QR code could not be created. Please use the link instead.'))
          );
        }
      }
      qr.hidden = !qr.hidden;
      qrToggle.setAttribute('aria-expanded', String(!qr.hidden));
      qrToggle.textContent = qr.hidden ? text('qrShow', 'Show QR code') : text('qrHide', 'Hide QR code');
      position();
    });

    let sharing = false;
    button.addEventListener('click', async () => {
      if (!panel.hidden) {
        dismiss();
        return;
      }
      if (sharing) return;
      if (nativeShare()) {
        sharing = true;
        status.textContent = '';
        try {
          const data = { title: name, text: message, url };
          await navigator.share(data);
          return;
        } catch (err) {
          if (err.name === 'AbortError') return;
        } finally {
          sharing = false;
        }
      }
      open();
    });
  });
})();

/* Disclosures slide open and closed (height animation) in every browser,
   not only those with ::details-content transitions. */
(() => {
  'use strict';
  if (typeof document === 'undefined') return;
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const px = (style, ...keys) => keys.reduce((sum, key) => sum + (parseFloat(style[key]) || 0), 0);
  const closedHeight = (details, summary) => {
    const box = getComputedStyle(details);
    const head = getComputedStyle(summary);
    return (
      summary.offsetHeight +
      px(head, 'marginTop', 'marginBottom') +
      px(box, 'paddingTop', 'paddingBottom', 'borderTopWidth', 'borderBottomWidth')
    );
  };
  document.querySelectorAll('.homeward-animals-extra, .homeward-animal-more').forEach((details) => {
    const summary = details.querySelector(':scope > summary');
    if (!summary || details.dataset.homewardSlide) return;
    details.dataset.homewardSlide = '1';
    let run = null;
    summary.addEventListener('click', (event) => {
      if (motion.matches || typeof details.animate !== 'function') return;
      event.preventDefault();
      const from = details.getBoundingClientRect().height;
      const opening = !details.open || details.classList.contains('is-closing');
      if (run) run.cancel();
      details.style.overflow = 'hidden';
      let to;
      if (opening) {
        details.classList.remove('is-closing');
        details.open = true;
        to = details.scrollHeight + px(getComputedStyle(details), 'borderTopWidth', 'borderBottomWidth');
      } else {
        details.classList.add('is-closing');
        to = closedHeight(details, summary);
      }
      run = details.animate(
        { height: [`${from}px`, `${to}px`] },
        { duration: opening ? 340 : 260, easing: 'cubic-bezier(.2, .7, .2, 1)' }
      );
      const done = () => {
        run = null;
        details.style.overflow = '';
        if (!opening) {
          details.open = false;
          details.classList.remove('is-closing');
        }
      };
      run.onfinish = done;
      run.oncancel = () => {
        details.style.overflow = '';
      };
    });
  });
})();
