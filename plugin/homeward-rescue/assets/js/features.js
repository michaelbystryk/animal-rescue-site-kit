/**
 * Optional profile features (includes/class-features.php): what each feature does in the browser, and its
 * events. Pages work without this script.
 *
 * Server markup: <div data-homeward-feature="donate-card">...</div> for each feature that is switched on.
 * Events go through window.homewardAnalytics, which exists only when visitor statistics are running.
 */
(function (window, document) {
  'use strict';

  let started = false;

  const capture = (key, event, props) => {
    const analytics = window.homewardAnalytics;
    if (!analytics || typeof analytics.capture !== 'function') return;
    const data = { feature: key, page: window.location.pathname };
    Object.keys(props || {}).forEach((name) => {
      data[name] = props[name];
    });
    analytics.capture(event, data);
  };

  // Run fn once, when target is at least half visible (at once without IntersectionObserver).
  const whenSeen = (target, fn) => {
    let sent = false;
    const once = () => {
      if (!sent) {
        sent = true;
        fn();
      }
    };
    if (typeof window.IntersectionObserver !== 'function') {
      once();
      return;
    }
    const observer = new window.IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            observer.disconnect();
            once();
          }
        });
      },
      { threshold: 0.5 }
    );
    observer.observe(target);
  };


  const behaviours = {
    'donate-card': donate,
    'adopted-donate': donate,
    'fee-explainer': (el, key) => {
      const details = el.querySelector('[data-homeward-fee-explainer]');
      if (!details) return;
      let sent = false;
      details.addEventListener('toggle', () => {
        if (details.open && !sent) {
          sent = true;
          capture(key, 'fee_explainer_opened', {});
        }
      });
      details.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && details.open) {
          details.open = false;
          const summary = details.querySelector('summary');
          if (summary) summary.focus();
        }
      });
    },
    'household-share': (el, key) => {
      const card = el.querySelector('[data-homeward-household-share]');
      const open = el.querySelector('[data-homeward-household-open]');
      const page = (el.closest && el.closest('.homeward-animal-page')) || document;
      const share = page.querySelector('[data-homeward-share]');
      // Without the page's Share button there is nothing to open: the card stays hidden.
      if (!card || !open || !share) return;
      card.hidden = false;
      open.addEventListener('click', (event) => {
        // The Share box closes on outside clicks, which would include this one.
        if (event && typeof event.stopPropagation === 'function') event.stopPropagation();
        capture(key, 'household_share_clicked', {});
        // Inside the same tap, so phones still allow the native share sheet.
        share.click();
      });
    },
    'adoption-path': (el, key) => {
      const list = el.querySelector('[data-homeward-adoption-path]') || el;
      whenSeen(list, () => capture(key, 'adoption_path_viewed', {}));
    },
  };

  function donate(el, key) {
    el.querySelectorAll('[data-homeward-donate-link]').forEach((link) => {
      link.addEventListener('click', () =>
        capture(key, 'donate_click', { source: link.getAttribute('data-homeward-donate-link') || '' })
      );
    });
  }

  const activate = (el) => {
    const key = el.getAttribute('data-homeward-feature');
    if (el.getAttribute('data-homeward-ready')) return;
    el.setAttribute('data-homeward-ready', '1');
    if (behaviours[key]) behaviours[key](el, key);
  };

  function start() {
    if (started) return;
    started = true;
    document.querySelectorAll('[data-homeward-feature]').forEach(activate);
  }

  const api = {
    now: () => Date.now(),
    start,
    capture,
    _reset: () => {
      started = false;
    },
  };
  window.homewardFeatures = api;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    window.setTimeout(start, 0);
  }
})(window, document);
