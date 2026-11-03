/**
 * Optional profile features (includes/class-features.php): PostHog A/B decisions and feature events.
 *
 * Server markup:
 *   <div data-homeward-feature="fee-explainer">...</div>                    switched on, shown at once
 *   <div data-homeward-feature="fee-explainer" data-homeward-flag="homeward-fee-explainer"
 *        data-homeward-fallback="on|off" hidden>...</div>                    PostHog decides
 *
 * Decisions follow the rules that keep a test fair:
 * - Only features on this page are asked about, so PostHog records an exposure ($feature_flag_called)
 *   only where the visitor could see the feature.
 * - Flags saved by posthog-js decide as soon as the real library has loaded; a first visit waits for
 *   onFeatureFlags. Undefined means "not evaluated yet".
 * - test shows the feature, control removes it. No PostHog (logged in, Do Not Track, Global Privacy
 *   Control, blocked), opted out, a missing flag or no answer within the timeout: the page follows the
 *   on/off switch (data-homeward-fallback). A late answer is ignored.
 * Events go through window.homewardAnalytics, which exists only when visitor statistics are running.
 */
(function (window, document) {
  'use strict';

  const POLL = 100;
  let decided = {};
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

  const ymd = (value) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
    return match ? [+match[1], +match[2], +match[3]] : null;
  };

  // Same rule as Features::days_label(): "1 days" becomes "1 day".
  const daysLabel = (template, days) => {
    if (days === 1) template = template.replace(/\{days\}(\s+\S+?)s(?=$|[\s,.;:!?])/u, '{days}$1');
    let number = String(days);
    try {
      number = new Intl.NumberFormat(document.documentElement.lang || undefined).format(days);
    } catch (_) {
      /* Plain digits. */
    }
    return template.split('{days}').join(number);
  };

  // Count whole UTC days again, since cached pages keep the day they were built. Returns the days shown.
  const recount = (el) => {
    const server = parseInt(el.getAttribute('data-days'), 10) || 0;
    const from = ymd(el.getAttribute('data-since'));
    const template = el.getAttribute('data-template') || '';
    if (!from || !template) return server;
    const now = new Date(api.now());
    const days = Math.floor(
      (Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()) -
        Date.UTC(from[0], from[1] - 1, from[2])) /
        86400000
    );
    // A visitor clock running behind the cached page keeps the server text.
    if (!(days > server)) return server;
    el.textContent = daysLabel(template, days);
    return days;
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
    'waiting-counter': (el, key) => {
      const pill = el.querySelector('[data-homeward-waiting-counter]');
      if (!pill) return;
      const days = recount(pill);
      whenSeen(pill, () => capture(key, 'waiting_counter_viewed', { days }));
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

  // variant is 'test', 'control' or 'fallback'.
  const decide = (key, variant, elements) => {
    if (decided[key]) return;
    decided[key] = variant;
    elements.forEach((el) => {
      const show =
        variant === 'test' || (variant === 'fallback' && el.getAttribute('data-homeward-fallback') === 'on');
      if (show) {
        el.hidden = false;
        activate(el);
      } else {
        el.remove();
      }
    });
  };

  // The real posthog-js, or null while only the snippet's queueing stub is there. Always read it fresh:
  // window.posthog is replaced once the library loads.
  const live = () => {
    const ph = window.posthog;
    return ph && ph.__loaded && typeof ph.getFeatureFlag === 'function' ? ph : null;
  };

  const optedOut = (ph) => typeof ph.has_opted_out_capturing === 'function' && ph.has_opted_out_capturing();

  // A flag's value without recording an exposure. undefined means "not evaluated yet".
  const peek = (ph, name) => {
    const all = typeof ph.getAllFeatureFlags === 'function' ? ph.getAllFeatureFlags() : null;
    // Newer posthog-js returns a list of { key, enabled, variant }; older versions a { key: value } map.
    if (Array.isArray(all)) {
      const flag = all.find((item) => item && item.key === name);
      if (flag) return flag.variant !== undefined && flag.variant !== null ? flag.variant : flag.enabled;
    } else if (all && typeof all === 'object' && name in all) {
      return all[name];
    }
    return ph.getFeatureFlag(name, { send_event: false });
  };

  function start() {
    if (started) return;
    started = true;
    const all = Array.from(document.querySelectorAll('[data-homeward-feature]'));
    all.filter((el) => !el.hasAttribute('data-homeward-flag')).forEach(activate);
    const groups = {};
    all
      .filter((el) => el.hasAttribute('data-homeward-flag'))
      .forEach((el) => {
        const flag = el.getAttribute('data-homeward-flag');
        (groups[flag] = groups[flag] || []).push(el);
      });
    const flags = Object.keys(groups);
    if (!flags.length) return;
    const keyOf = (flag) => groups[flag][0].getAttribute('data-homeward-feature');
    let done = false,
      timer = null,
      poller = null;
    const settle = (variantFor) => {
      done = true;
      window.clearTimeout(timer);
      window.clearTimeout(poller);
      flags.forEach((flag) => decide(keyOf(flag), variantFor(flag), groups[flag]));
    };
    const ph = window.posthog;
    if (!ph || typeof ph.onFeatureFlags !== 'function' || (live() && optedOut(live()))) {
      settle(() => 'fallback');
      return;
    }
    const attempt = (flagsLoaded) => {
      if (done) return true;
      const current = window.posthog;
      const real =
        live() || (flagsLoaded && current && typeof current.getFeatureFlag === 'function' ? current : null);
      if (!real) return false;
      if (optedOut(real)) {
        settle(() => 'fallback');
        return true;
      }
      const values = {};
      for (const flag of flags) {
        values[flag] = peek(real, flag);
        if (values[flag] === undefined && !flagsLoaded) return false;
      }
      const known = (flag) => values[flag] === 'test' || values[flag] === 'control';
      // Record the exposure once per flag on the page, with the value the decision uses.
      flags.filter(known).forEach((flag) => real.getFeatureFlag(flag));
      settle((flag) => (known(flag) ? values[flag] : 'fallback'));
      return true;
    };
    const poll = () => {
      if (!attempt(false)) poller = window.setTimeout(poll, POLL);
    };
    timer = window.setTimeout(() => {
      if (!done) settle(() => 'fallback');
    }, api.timeout);
    ph.onFeatureFlags(() => attempt(true));
    poll();
  }

  const api = {
    timeout: 3000,
    now: () => Date.now(),
    start,
    variant: (key) => decided[key] || null,
    capture,
    _reset: () => {
      decided = {};
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
