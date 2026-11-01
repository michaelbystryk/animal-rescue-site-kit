/* The server renders the same validation errors when JavaScript is unavailable. */
(() => {
  'use strict';
  if (!window.fetch || !window.FormData) return;
  document.querySelectorAll('[data-homeward-contact]').forEach((form) => {
    const status = form.querySelector('[data-contact-status]');
    const button = form.querySelector('[type="submit"]');
    // Keep native form submission when the enhancement's required nodes are absent.
    if (!status || !button) return;
    form.addEventListener('click', (event) => {
      if (!event.target.closest || !event.target.closest('[data-contact-again]')) return;
      form.classList.remove('is-sent');
      const first = form.elements.namedItem('contact_name');
      if (first && first.focus) first.focus();
    });
    let pending = false;
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (pending) return;
      pending = true;
      button.disabled = true;
      form.setAttribute('aria-busy', 'true');
      status.textContent = '';
      form.querySelectorAll('[data-contact-error]').forEach((node) => {
        node.textContent = '';
      });
      form.querySelectorAll('[aria-invalid]').forEach((node) => node.removeAttribute('aria-invalid'));
      const data = new FormData(form);
      data.set('homeward_fetch', '1');
      try {
        // form.action is shadowed by the hidden <input name="action">, so read the attribute.
        const response = await fetch(form.getAttribute('action'), {
          method: 'POST',
          body: data,
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        const result = await response.json();
        if (
          typeof result.success !== 'boolean' ||
          typeof result.message !== 'string' ||
          (result.success && !response.ok)
        )
          throw new Error('Unexpected response');
        status.textContent = result.message;
        // Turnstile tokens work once: get a fresh one for the next attempt.
        const widget = form.querySelector('.cf-turnstile');
        if (widget && window.turnstile && window.turnstile.reset) window.turnstile.reset(widget);
        Object.entries(result.errors || {}).forEach(([key, message]) => {
          const node = Array.from(form.querySelectorAll('[data-contact-error]')).find(
            (item) => item.dataset.contactError === key
          );
          if (node) node.textContent = message;
          const field = form.elements.namedItem(key);
          if (field) field.setAttribute('aria-invalid', 'true');
        });
        if (result.success) {
          form.reset();
          // Swap the fields for a thank-you panel; "Send another" brings them back.
          if (form.classList) form.classList.add('is-sent');
        } else status.textContent += ' ' + Object.values(result.errors || {}).join(' ');
        status.focus();
      } catch (_) {
        status.textContent = window.homewardContactL10n.connectionError;
        status.focus();
      } finally {
        pending = false;
        button.disabled = false;
        form.removeAttribute('aria-busy');
      }
    });
  });
})();
