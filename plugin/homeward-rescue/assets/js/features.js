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
